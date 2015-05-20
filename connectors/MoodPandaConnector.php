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

class MoodPandaConnector extends Connector
{
    private static $CONNECTOR_NAME = 'moodpanda';

    // Keys for callback https://local.quantimo.do/api/connectors/moodpanda/connect
    // Dev Console: http://www.moodpanda.com/api/apply.aspx
    // Key is emailed to you.
    // Test User: connector@quantimo.do
    // Test PW: B1ggerstaff!

    // NOTE: These keys are overwritten if you have values in the database.
    private static $API_KEY = 'a9cbda73-df94-4122-81bf-d7b6d1a9d5df';

    private static $URL_BASE = 'http://www.moodpanda.com/api/user';

    private static $URL_USER = '_URL_/data.ashx?email=_EMAIL_&format=xml&key=_KEY_';

    private static $URL_MOODS = '_URL_/feed/data.ashx?userid=_USERID_&from=_FROMDATE_&to=_TODATE_&format=xml&key=_KEY_';

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
    }

    public function getConnectInstructions()
    {
        $parameters = [
            new ConnectParameter('Email', 'email', 'text')
        ];
        $url = $this->getBaseUrl() . '/connect';
        $usePopup = false;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        if (empty($parameters['email'])) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/connect',
                400,
                "Couldn't connect",
                "No email specified"
            );
        } else {
            $client = $this->getGuzzleClient();

            // Attempt to get the user's "mood feed".
            $userIdResponse = $this->getUserId($client, $parameters['email']);

            if (gettype($userIdResponse) === 'integer'){
                // Otherwise we apparently got a valid moods page back, so we store the credentials
                $credentials = [
                    'userId' => $userIdResponse
                ];

                $this->credentialsManager->store($credentials);

                return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
            } elseif ($userIdResponse instanceof ErrorResponseMessage) {
                // If userIdResponse is an ErrorResponseMessage we return it
                return $userIdResponse;
            } else {
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't connect",
                    "We couldn't contact MoodPanda to get your user ID, please try again later!"
                );
            }
        }
    }

    public function update($fromTime)
    {
        $timeDiffSeconds = time() - $fromTime;
        $timeDiffDays = ceil(($timeDiffSeconds / (60 * 60 * 24)) - 0.5);    // -0.5 to allow two syncs per day
        if ($timeDiffDays < 1) {
            echo " [WARNING] Not syncing, last sync less than half a day ago\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                403,
                "Couldn't update",
                "Last update less than half a day ago"
            );
        }

        $credentials = $this->credentialsManager->get();
        if (!array_key_exists('userId', $credentials)) {
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

        $allMeasurementSets = array();

        // Get the moods from MoodPanda
        $allMeasurementSets[] = $this->getMoodFeed($client, $credentials['userId'], $fromTime);

        return $allMeasurementSets;
    }

    private function getGuzzleClient()
    {
        return new Client(self::$URL_BASE);
    }

    /**
     * @param Client $client
     * @param string $email
     *
     * @return int|ErrorResponseMessage
     */
    private function getUserId($client, $email)
    {
        try {
            $url = str_replace(
                array('_EMAIL_', '_KEY_', '_URL_'),
                array($email, self::$API_KEY, self::$URL_BASE),
                self::$URL_USER
            );
            $request = $client->get($url);
            $response = $request->send();
            $responseXml = $response->xml();
            $userId = intval($responseXml->User->UserID);

            if ($userId == 0) {
                $this->disconnect();
                echo " [ERROR] MoodPanda user not found, removed possible credentials\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't connect",
                    "MoodPanda couldn't find this user, do you have Privacy disabled?"
                );
            }

            return intval($userId);
        } catch (Exception $e) {
            echo " [ERROR] Couldn't contact MoodPanda. " . $e->getMessage() . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "We couldn't contact MoodPanda, please try again later"
            );
        }
    }

    /**
     * Get the mood feed of the user.
     * MoodPanda lets us request the mood feed up to 12 months earlier.
     *
     * @param Client $client
     * @param int $userId
     * @param int $fromTime
     *
     * @return MeasurementSet|ErrorResponseMessage
     */
    private function getMoodFeed($client, $userId, $fromTime)
    {
        //	If no "fromTime" has been entered, set fromTime to a month ago
        if ($fromTime == null) {
            $fromTime = round(microtime(true) - 2629743);        // 1 months = 2629743 milliseconds
        }
        try {
            //	Replace values in pre-defined URL
            $replaceParams = array(
                '_USERID_',
                '_FROMDATE_',
                '_TODATE_',
                '_KEY_',
                '_URL_',
            );
            $replaceValues = array(
                $userId,
                date('Y-m-d', $fromTime),
                date('Y-m-d', round(microtime(true))),
                self::$API_KEY,
                self::$URL_BASE,
            );

            $url = str_replace($replaceParams, $replaceValues, self::$URL_MOODS);
            $request = $client->get($url);
            $response = $request->send();
            $responseXml = $response->xml();

            $moodMeasurements = array();
            foreach ($responseXml->children() as $moodData) {
                $moodMeasurements[] = new Measurement(strtotime($moodData->Date), intval($moodData->Rating));
            }

            $measurementSet = new MeasurementSet(
                'MoodPanda Moods',
                'Activity',
                'event',
                $this->displayName,
                'MEAN',
                $moodMeasurements
            );

            return $measurementSet;
        } catch (Exception $e) {
            echo " [ERROR] Couldn't contact MoodPanda. " . $e->getMessage() . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "We couldn't contact MoodPanda, please try again later"
            );
        }
    }
}
