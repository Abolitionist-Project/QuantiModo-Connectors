<?php

namespace Quantimodo\Connectors;

use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\OAuth2\Service\Google;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class SleepCloudConnector extends Connector
{
    private static $CONNECTOR_NAME = "sleepcloud";

    private static $URL_SLEEP_RECORDS = "https://sleep-cloud.appspot.com/fetchRecords?timestamp=";

    private static $CLIENT_ID = '1052648855194.apps.googleusercontent.com';
    private static $CLIENT_SECRET = 'GrEHxColZ3LYEVVoVdWOXHoY';

    private $sleepcloudService;    // Uses Google's OAuth2 service in the background

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
        $this->initService();    // Init service to make sure $sleepcloudService is populated for getConnectInstructions
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        $url = $this->sleepcloudService->getAuthorizationUri()->getAbsoluteUri();
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['code'])) {
            $url = $this->sleepcloudService->getAuthorizationUri()->getAbsoluteUri();

            return new RedirectResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect', $url);
        } else {
            try {
                $accessToken = $this->sleepcloudService->requestAccessToken($parameters['code']);
                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get SleepCloud access token from code\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process, SleepCloud failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get SleepCloud access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to SleepCloud, please try again!"
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

        return $this->getSleepRecords($fromTime);
    }

    /*
    **	Resets the service by instantiating a new HttpClient for this session
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
        $this->sleepcloudService = $serviceFactory->createService(
            'Google',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            array(Google::SCOPE_USERINFO_EMAIL)
        );
    }


    /**
     * Get sleep records
     * sleeps:
     * [{
     * fromTime: Unix timestamp of sleep start time
     * toTime: Unix timestamp of end of sleep
     * lenghtMinutes: Duration of the sleep in minutes (does not need to match to - from time, due to pausing,
     *                delayed tracking, etc..).
     * rating: A floating point number about user perceived quality rating of the sleep.
     *         Range from 0.0 to 5.0, where 5.0 is the best.
     * deepSleep: A floating point number, representing a percentage of night spent in deep sleep. Range from 0.0 to 1.0
     * cycles: An integer representing a number of sleep cycle phases, see here.
     * timezone: in the GMT sign hours : minutes format (example GMT+03:00)
     * noiseLevel: Average level of noise during the night. The higher number the more noisy the night was.
     * snoringSeconds: A total number of seconds when snoring was detected.
     * actigraph: [....]: An array of integers, containing levels of movement. The values themselves
     *                    are floating point numbers, the higher number the more movement was detected.
     *                    The timestamps of values are not present, but the values are uniformly spread throughout
     *                    all night. The values are present only if "actigraph=true" is passed in the request.
     * labels: [
     * timestamp: timestamp of an event (in milliseconds)
     * label: label of a specific event. See labels for events descriptions
     * ]: Returned only in case labels=true argument was passed. The labels are sorting in time ascending order.
     * },
     * {
     * fromTime: .....
     * ....
     * },
     * .......
     * ]
     * @param int $fromTime
     *
     * @return MeasurementSet[]
     */
    private function getSleepRecords($fromTime)
    {
        $durationMeasurements = array();
        $cyclesMeasurements = array();
        $noiseMeasurements = array();
        $ratingMeasurements = array();
        $qualityMeasurements = array();

        try {
            $responseBody = $this->sleepcloudService->request(self::$URL_SLEEP_RECORDS . $fromTime);
        } catch (GuzzleException $e) {
            $responseBody = null;
            echo $e->getMessage();
        }

        $guzzleResponse = $this->sleepcloudService->getHttpClient()->getLastResponse();
        $statusCode = $guzzleResponse->getStatusCode();

        switch ($statusCode) {
            case 200:
                $responseObject = json_decode($responseBody);

                foreach ($responseObject->sleeps as $sleepRecord) {
                    $fromTime = $sleepRecord->fromTime / 1000;
                    if ($fromTime > $fromTime) {
                        break;
                    }

                    $toTime = $sleepRecord->toTime / 1000;
                    $duration = $toTime - $fromTime;

                    $durationMeasurements[] = new Measurement($fromTime, $duration, $duration);
                    if (property_exists($sleepRecord, 'cycles') && $sleepRecord->rating > 0) {
                        $cyclesMeasurements[] = new Measurement($fromTime, $sleepRecord->cycles, $duration);
                    }
                    if (property_exists($sleepRecord, 'rating') && $sleepRecord->rating > 0) {
                        $ratingMeasurements[] = new Measurement($fromTime, $sleepRecord->rating);
                    }
                    if (property_exists($sleepRecord, 'noiseLevel') && $sleepRecord->noiseLevel > 0) {
                        $noiseMeasurements[] = new Measurement($fromTime, $sleepRecord->noiseLevel, $duration);
                    }
                    if (property_exists($sleepRecord, 'deepSleep') && $sleepRecord->deepSleep > 0) {
                        $qualityMeasurements[] = new Measurement($fromTime, $sleepRecord->deepSleep, $duration);
                    }
                }

                break;
            case 403:
                echo " [INFO] Token is no longer valid\n";
                $this->disconnect();
                break;
        }

        $measurementSets = array();

        if (!empty($durationMeasurements)) {
            $measurementSets[] = new MeasurementSet(
                "Sleep Duration",
                "Sleep",
                "min",
                $this->displayName,
                "SUM",
                $durationMeasurements
            );
        }
        if (!empty($cyclesMeasurements)) {
            $measurementSets[] = new MeasurementSet(
                "Sleep Cycles",
                "Sleep",
                "event",
                $this->displayName,
                "SUM",
                $cyclesMeasurements
            );
        }
        if (!empty($noiseMeasurements)) {
            $measurementSets[] = new MeasurementSet(
                "Sleep Noise Level",
                "Sleep",
                "/1",
                $this->displayName,
                "MEAN",
                $noiseMeasurements
            );
        }
        if (!empty($ratingMeasurements)) {
            $measurementSets[] = new MeasurementSet(
                "Sleep Rating",
                "Sleep",
                "/6",
                "Sleep as Android",
                "MEAN",
                $ratingMeasurements
            );
        }
        if (!empty($qualityMeasurements)) {
            $measurementSets[] = new MeasurementSet(
                "Deep Sleep",
                "Sleep",
                "/1",
                "Sleep as Android",
                "MEAN",
                $qualityMeasurements
            );
        }

        return $measurementSets;
    }
}
