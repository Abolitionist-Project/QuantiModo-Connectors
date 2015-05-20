<?php

namespace Quantimodo\Connectors;

use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\Common\Storage\Exception\TokenNotFoundException;
use OAuth\OAuth1\Service\FitBit;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class FitbitConnector extends Connector
{
    private static $CONNECTOR_NAME = "fitbit";

    // Keys for callback https://local.quantimo.do/api/connectors/fitbit/connect
    // Developer Console: https://dev.fitbit.com/apps
    // Developer User: mike@thinkbynumbers.org
    // Developer PW: B1ggerstaff!
    // Contact m@thinkbynumbers.org to add you to the app admins
    // NOTE: This are overwritten if you have values in the database.
    // Test User: mike@thinkbynumbers.org
    // Test PW: B1ggerstaff!
    // Temporary Credentials (Request Token) URL: https://api.fitbit.com/oauth/request_token
    // Token Credentials (Access Token) URL: https://api.fitbit.com/oauth/access_token
    // Authorize URL: https://www.fitbit.com/oauth/authorize

    private static $CLIENT_ID = "70df1fbe835441b2aec0a14464bde848";
    private static $CLIENT_SECRET = "598d3939ef8e48fab5533f0cf22f430c";

    /**
     * @var  FitBit The FitBit OAuth1 service.
     */
    private $fitbitService;

    /**
     * @var array The endpoints we'll visit to gather data.
     */
    private $endpoints;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);

        $this->endpoints = [
            'body/weight' => function ($measurements) {
                return new MeasurementSet(
                    'Weight',
                    'Physique',
                    'kg',
                    $this->displayName,
                    "MEAN",
                    $measurements
                );
            },
            'body/bmi' => function ($measurements) {
                return new MeasurementSet(
                    'BMI',
                    'Physique',
                    'index',
                    $this->displayName,
                    "MEAN",
                    $measurements
                );
            },
            'body/fat' => function ($measurements) {
                return new MeasurementSet(
                    'Body Fat',
                    'Physique',
                    '%',
                    $this->displayName,
                    "MEAN",
                    $measurements
                );
            },

            'sleep/minutesAsleep' => function ($measurements) {
                return new MeasurementSet(
                    'Sleep Duration',
                    'Sleep',
                    'min',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'sleep/awakeningsCount' => function ($measurements) {
                return new MeasurementSet(
                    'Awakenings',
                    'Sleep',
                    'count',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },

            'activities/calories' => function ($measurements) {
                return new MeasurementSet(
                    "Calories Burned",
                    "Physical Activity",
                    "cal",
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'activities/steps' => function ($measurements) {
                return new MeasurementSet(
                    "Steps",
                    "Physical Activity",
                    "count",
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'activities/distance' => function ($measurements) {
                return new MeasurementSet(
                    "Distance",
                    "Physical Activity",
                    "km",
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'activities/elevation' => function ($measurements) {
                return new MeasurementSet(
                    'Elevation',
                    'Physical Activity',
                    'm',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },

            'foods/log/caloriesIn' => function ($measurements) {
                return new MeasurementSet(
                    'CaloriesIn',
                    'Nutrition',
                    'cal',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'foods/log/water' => function ($measurements) {
                return new MeasurementSet(
                    'Water',
                    'Foods',
                    'mL',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
        ];
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        // Point to /connect endpoint so that we don't have to make an expensive call to Fitbit just to get the URL
        $url = $this->getBaseUrl() . "/connect";
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['oauth_token']) || empty($parameters['oauth_verifier'])) {
            $token = $this->fitbitService->requestRequestToken();

            $url = $this->fitbitService->getAuthorizationUri(
                [
                    'oauth_token' => $token->getRequestToken()
                ]
            )->getAbsoluteUri();

            return new RedirectResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect', $url);
        } else {
            try {
                // Request a proper access token.
                $accessToken = $this->fitbitService->requestAccessToken(
                    $parameters['oauth_token'],
                    $parameters['oauth_verifier']
                );

                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get Fitbit access token, accessToken null\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process, Fitbit failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get Fitbit access token:\n";
                echo " [ERROR] " . str_replace("\n", "\n         ", $e) . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Fitbit, please try again!"
                );
            } catch (TokenNotFoundException $e) {
                echo " [ERROR] Couldn't connect to Fitbit, token not found:\n";
                echo " [ERROR] " . str_replace("\n", "\n         ", $e) . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Fitbit, please try again!"
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
                "Couldn't update.",
                "No credentials for user. This connector isn't connected."
            );
        }

        // Limit date range to two years (so fitbit won't crap out)
        if ($fromTime == 0) {
            $fromTime = time() - 63113852;
        }

        $allMeasurementSets = array();
        $allMeasurementSets = array_merge($allMeasurementSets, $this->getMeasurements($fromTime));

        return $allMeasurementSets;
    }

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
        $this->fitbitService = $serviceFactory->createService(
            'FitBit',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            array()
        );
    }

    /**
     * @param int $fromTime
     *
     * @return MeasurementSet[]
     */
    private function getMeasurements($fromTime)
    {
        $measurements = array();
        $measurementSet = array();

        foreach (array_keys($this->endpoints) as $endpoint) {
            try {
                $url = "https://api.fitbit.com/1/user/-/" . $endpoint . "/date/" . date("Y-m-d", $fromTime)
                    . "/" . date("Y-m-d", time()) . ".json";

                try {
                    $responseBody = $this->fitbitService->request($url);
                } catch (GuzzleException $e) {
                    $responseBody = null;
                }

                $guzzleResponse = $this->fitbitService->getHttpClient()->getLastResponse();
                $statusCode = $guzzleResponse->getStatusCode();
                switch ($statusCode) {
                    case 200:
                        $responseObject = json_decode($responseBody, true);
                        $previousMeasurement = null;
                        $setName = str_replace('/', '-', $endpoint);
                        foreach ($responseObject[$setName] as $measurementArr) {
                            // If this measurement isn't a duplicate, add it to the measurements array
                            if ($previousMeasurement == null || ($previousMeasurement instanceof Measurement &&
                                    $previousMeasurement->value != $measurementArr['value'])
                            ) {
                                $previousMeasurement = new Measurement(
                                    strtotime($measurementArr['dateTime']),
                                    $measurementArr['value']
                                );
                                $measurements[$endpoint][] = $previousMeasurement;
                            }
                        }
                        break;
                    case 401:
                        $this->disconnect();
                        break;
                    case 409:
                        echo " [WARNING] Rate limit reached\n";
                        sleep(0.5);
                        break;
                }
            } catch (GuzzleException $e) {
                $guzzleResponse = $this->fitbitService->getHttpClient()->getLastResponse();

                echo " [ERROR] Exception during fitbit update: " . $e->getMessage() . "\n";
                echo " [ERROR] Fitbit says: " . $guzzleResponse->getBody() . "\n";
            }

        }

        foreach ($measurements as $key => $value) {
            $measurementSet[] = $this->endpoints[$key]($value);
        }

        return $measurementSet;
    }
}
