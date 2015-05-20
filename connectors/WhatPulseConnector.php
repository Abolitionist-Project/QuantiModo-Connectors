<?php

namespace Quantimodo\Connectors;

use Exception;
use Guzzle\Http\Client;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Connectors\ConnectParameter;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

/*
 * Test user: mikepsinn
*/

class WhatPulseConnector extends Connector
{
    private static $CONNECTOR_NAME = "whatpulse";

    private static $URL_BASE = "http://www.whatpulse.org";

    private static $URL_PULSES_API = "http://api.whatpulse.org/pulses.php?format=json&user=_USERNAME_";

    private static $URL_USER_API = "http://api.whatpulse.org/user.php?format=json&user=_USERNAME_";

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
    }

    public function getConnectInstructions()
    {
        $parameters = array(
            new ConnectParameter('Username', 'username', 'text')
        );
        $url = $this->getBaseUrl() . "/connect";
        $usePopup = false;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        if (empty($parameters['username'])) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/connect',
                400,
                "Couldn't connect",
                "No username specified"
            );
        } else {
            $client = $this->getGuzzleClient();

            try {
                $url = str_replace("_USERNAME_", $parameters['username'], self::$URL_USER_API);

                $request = $client->get($url);
                $response = $request->send();
                $responseJson = $response->json();

                if (!is_array($responseJson) || !array_key_exists('AccountName', $responseJson) ||
                    !array_key_exists('UserID', $responseJson)
                ) {
                    if (array_key_exists('error', $responseJson)) {
                        echo " [WARNING] WhatPulse says " . $responseJson['error'] . ", can't connect\n";

                        return new ErrorResponseMessage(
                            PhpConnect::$currentUserId,
                            $this->name . '/update',
                            500,
                            "Couldn't update",
                            "WhatPulse couldn't find this user, is your profile public?"
                        );
                    } else {
                        echo " [ERROR] Unexpected response from WhatPulse\n";
                        print_r($responseJson);

                        return new ErrorResponseMessage(
                            PhpConnect::$currentUserId,
                            $this->name . '/update',
                            500,
                            "Couldn't update",
                            "Unexpected response from WhatPulse"
                        );
                    }
                } else {
                    $credentials = array(
                        'username' => $responseJson['AccountName'],
                        'userid' => $responseJson['UserID']
                    );
                    $this->credentialsManager->store($credentials);

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                }

            } catch (Exception $e) {
                echo " [ERROR] Couldn't contact WhatPulse. " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't update",
                    "We couldn't contact WhatPulse, please try again later"
                );
            }
        }
    }

    public function update($fromTime)
    {
        $credentials = $this->credentialsManager->get();
        if (!array_key_exists('username', $credentials)) {
            echo " [ERROR] Update request for disconnected connector\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "This connector isn't connected"
            );
        }

        $client = $this->getGuzzleClient();

        // Get the page that contains the pulses from WhatPulse
        $pulses = $this->getPulses($client, $credentials['username'], $fromTime);
        if (!is_array($pulses) && get_class($pulses) == 'ErrorResponseMessage') {
            // If it's an error we return right away
            return $pulses;
        }

        $allMeasurementSets = array();
        $allMeasurementSets = array_merge($allMeasurementSets, $pulses);

        return $allMeasurementSets;
    }

    private function getGuzzleClient()
    {
        return new Client(self::$URL_BASE);
    }

    /**
     * @param Client $client
     * @param string $username
     * @param int $fromTime
     *
     * @return MeasurementSet[]
     */
    private function getPulses($client, $username, $fromTime)
    {
        // This'll hold the measurements, we'll track two different variables here
        $keysMeasurements = array();
        $clicksMeasurements = array();

        try {
            $url = str_replace("_USERNAME_", $username, self::$URL_PULSES_API);

            $request = $client->get($url);
            $response = $request->send();
            $responseJson = $response->json();

            // TODO : How to check if error is returned? $responseJson is always an array!
            /*
            if (property_exists($responseJson, 'error')) {
                $this->credentialsManager->remove();
                echo " [ERROR] WhatPulse says $responseJson->error, removed possible credentials\n";

                return new ErrorResponseMessage(
                    $this->name . '/update', 500,
                    "Couldn't update",
                    "WhatPulse couldn't find this user or the user has no pulses, is your profile public?");
            }
             */

            if (!is_array($responseJson)) {
                echo " [ERROR] Unexpected response from WhatPulse\n";
                print_r($responseJson);

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't update",
                    "Unexpected response from WhatPulse"
                );
            }

            foreach ($responseJson as $pulse) {
                // Only insert newer measurements
                if ($pulse['Timestamp'] > $fromTime) {
                    $keysMeasurements[] = new Measurement(
                        $pulse['Timestamp'],
                        $pulse['Keys'],
                        $pulse['UptimeSeconds']
                    );
                    $clicksMeasurements[] = new Measurement(
                        $pulse['Timestamp'],
                        $pulse['Clicks'],
                        $pulse['UptimeSeconds']
                    );
                }
            }
        } catch (Exception $e) {
            echo " [ERROR] Couldn't contact WhatPulse. " . $e->getMessage() . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "We couldn't contact WhatPulse, please try again later"
            );
        }

        return array(
            new MeasurementSet('Mouse Clicks', 'Activity', 'event', $this->displayName, "SUM", $clicksMeasurements),
            new MeasurementSet('Keystrokes', 'Activity', 'event', $this->displayName, "SUM", $keysMeasurements)
        );
    }
}
