<?php

namespace Quantimodo\Connectors;

use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class RunKeeperConnector extends Connector
{
    private static $CONNECTOR_NAME = "runkeeper";

    private static $URL_FITNESS_ACTIVITIES = "https://api.runkeeper.com/fitnessActivities";

    private static $CLIENT_ID = 'aa7233e520fa4b34aa5f9c3f27b22803';
    private static $CLIENT_SECRET = '36b04a3bc4a64d9ba9fbde17eb3ba68d';

    private $runkeeperService;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
        $this->initService(); // Init service to make sure $runkeeperService is populated for getConnectInstructions
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        $url = $this->runkeeperService->getAuthorizationUri()->getAbsoluteUri();
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['code'])) {
            $url = $this->runkeeperService->getAuthorizationUri()->getAbsoluteUri();

            return new RedirectResponseMessage($this->name, 'connect', $url);
        } else {
            try {
                $accessToken = $this->runkeeperService->requestAccessToken($parameters['code']);
                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get RunKeeper access token from code\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process, RunKeeper failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get RunKeeper access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to RunKeeper, please try again!"
                );
            }
        }
    }

    public function update($fromTime)
    {
        $this->initService();

        if (!$this->credentialsManager->tokenCredentials->hasAccessToken($this->id)) {
            echo " [ERROR] No credentials for user " . PhpConnect::$currentUserId . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                403,
                "Couldn't update",
                "This connector isn't connected"
            );
        }

        $allMeasurementSets = array();

        $allMeasurementSets[] = $this->getFitnessActivities($fromTime);

        return $allMeasurementSets;
    }

    /**
     * Resets the service by instantiating a new HttpClient for this session
     */
    private function initService()
    {
        $credentials = new Credentials(
            self::$CLIENT_ID,
            self::$CLIENT_SECRET,
            $this->getBaseUrl() . "/connect" // Callback URL
        );
        // Create the service
        $serviceFactory = new ServiceFactory();
        $serviceFactory->setHttpClient(new GuzzleClient());
        $this->runkeeperService = $serviceFactory->createService(
            'RunKeeper',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            array()
        );
    }

    /**
     * Get fitness activity (cardio)
     *
     * @param int $fromTime
     *
     * @return MeasurementSet
     */
    private function getFitnessActivities($fromTime)
    {
        $nextPage = self::$URL_FITNESS_ACTIVITIES;

        $caloriesBurnedMeasurements = array();

        // Loop through the feed until we no longer have a nextPage
        while ($nextPage != null) {
            try {
                $responseBody = $this->runkeeperService->request($nextPage);
            } catch (GuzzleException $e) {
                $responseBody = null;
            }

            $guzzleResponse = $this->runkeeperService->getHttpClient()->getLastResponse();
            $statusCode = $guzzleResponse->getStatusCode();

            switch ($statusCode) {
                case 200:
                    $responseObject = json_decode($responseBody);
                    if (property_exists($responseObject, 'next')) {
                        $nextPage = $responseObject->next;
                        echo " [INFO] Next page: " . $nextPage . "\n";
                    } else {
                        $nextPage = null;
                        echo " [INFO] No more pages available\n";
                    }

                    foreach ($responseObject->items as $currentActivity) {
                        $timestamp = strtotime($currentActivity->start_time);
                        // If this timestamp is older than our lower limit we break out
                        // so we don't store old measurements
                        if ($timestamp < $fromTime) {
                            $nextPage = null;
                            break;
                        }

                        $duration = round($currentActivity->duration);

                        if ($currentActivity->source == "RunKeeper") {
                            $caloriesBurnedMeasurements[] = new Measurement(
                                $timestamp,
                                $currentActivity->total_calories,
                                $duration
                            );
                        } else {
                            // TODO: Figure out what other sources runkeeper can return
                        }
                    }
                    break;
                case 403:
                    echo " [INFO] Token is no longer valid\n";
                    $this->disconnect();
                    $nextPage = null;
                    break;
            }
        }

        $measurementSet = new MeasurementSet(
            'Calories Burned',
            'Physical Activity',
            'kcal',
            $this->displayName,
            "SUM",
            $caloriesBurnedMeasurements
        );

        return $measurementSet;
    }
}
