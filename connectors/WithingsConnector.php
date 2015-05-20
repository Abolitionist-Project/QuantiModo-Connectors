<?php

namespace Quantimodo\Connectors;

use DateTime;
use DateTimeZone;
use Guzzle\Http\Exception\BadResponseException;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\Common\Storage\Exception\TokenNotFoundException;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class WithingsConnector extends Connector
{
    private static $CONNECTOR_NAME = "withings";

    // Developer Console: http://oauth.withings.com/partner/dashboard
    // Developer User: mike@thinkbynumbers.org
    // Developer PW: B1ggerstaff!
    // Test User: mike@thinkbynumbers.org
    // Test PW: B1ggerstaff!
    
    // Callback is set by sending an oauth_callback while obtaining a request_token.

    // NOTE: This are overwritten if you have values in the database.
    private static $CLIENT_ID = "e4d28f45e14b8cc97e8c585127db3f2132e61ef0ed20be490e7b5f7417d";
    private static $CLIENT_SECRET = "f3f6322956e995f9988eb4e58bff81c9e816f01b30e48c26f3f3ed42118037";

    private $withingsService;

    private static $wbsapiErrors = array(
        0 => 'Operation was successful',
        247 => 'The userid provided is absent, or incorrect',
        250 => 'The provided userid and/or Oauth credentials do not match',
        286 => 'No such subscription was found',
        293 => 'The callback URL is either absent or incorrect',
        294 => 'No such subscription could be deleted',
        304 => 'The comment is either absent or incorrect',
        305 => 'Too many notifications are already set',
        342 => 'The signature (using Oauth) is invalid',
        343 => 'Wrong Notification Callback Url don\'t exist',
        601 => 'Too Many Requests',
        2554 => 'Unspecifed unknown error occured',
        2555 => 'An unknown error occurred'
    );

    private $measureTypesBody;
    private $measureTypesActivity;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);

        $this->measureTypesBody = array(
            1 => array(
                'name' => 'weight',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Weight',
                        'Physique',
                        'kg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            4 => array(
                'name' => 'height',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Height',
                        'Physique',
                        'm',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            5 => array(
                'name' => 'fat_free_mass',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'fatFreeMass',
                        'Physique',
                        'kg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            6 => array(
                'name' => 'fat_ratio',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Fat Ratio',
                        'Physique',
                        '%',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            8 => array(
                'name' => 'fat_mass_weight',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'fatMassWeight',
                        'Physique',
                        'kg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            9 => array(
                'name' => 'diastolic_blood_pressure',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Diastolic Pressure',
                        'Vital Signs',
                        'mmHg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            10 => array(
                'name' => 'systolic_blood_pressure',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Systolic Pressure',
                        'Vital Signs',
                        'mmHg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            ),
            11 => array(
                'name' => 'heart_pulse',
                'set' => function ($measurements) {
                    return new MeasurementSet(
                        'Heart Rate',
                        'Vital Signs',
                        'bpm',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
            )
        );

        $this->measureTypesActivity = array(
            'steps' => function ($measurements) {
                return new MeasurementSet(
                    'Steps',
                    'Physical Activity',
                    'count',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'distance' => function ($measurements) {
                return new MeasurementSet(
                    'Distance',
                    'Physical Activity',
                    'm',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'calories' => function ($measurements) {
                return new MeasurementSet(
                    'Calories Burned',
                    'Physical Activity',
                    'kcal',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
            'elevation' => function ($measurements) {
                return new MeasurementSet(
                    'Elevation',
                    'Physical Activity',
                    'm',
                    $this->displayName,
                    "SUM",
                    $measurements
                );
            },
        );
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        // Point to /connect endpoint so that we don't have to make an expensive call to Withings just to get the URL
        $url = $this->getBaseUrl() . "/connect";
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['oauth_token']) || empty($parameters['oauth_verifier'])) {
            $token = $this->withingsService->requestRequestToken();
            $url = $this->withingsService->getAuthorizationUri(
                array('oauth_token' => $token->getRequestToken())
            )->getAbsoluteUri();

            return new RedirectResponseMessage($this->name, 'connect', $url);
        } else {
            try {
                $accessToken = $this->withingsService->requestAccessToken(
                    $parameters['oauth_token'],
                    $parameters['oauth_verifier']
                );

                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get Withings access token, accessToken null\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process, Withings failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get Withings access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Withings, please try again!"
                );
            } catch (TokenNotFoundException $e) {
                echo " [ERROR] Couldn't connect to withings, token not found\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Withings, please try again!"
                );
            } catch (BadResponseException $e) {
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Withings, please try again!"
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

        // Limit date range to two years (so withings won't crap out)
        if ($fromTime == 0) {
            $fromTime = time() - 63113852;
        }

        $allMeasurementSets = array();

        $bodyMetrics = $this->getBodyMetrics($fromTime);
        $activityMetrics = $this->getActivityMetrics($fromTime);

        if (!is_array($bodyMetrics)) {
            return $bodyMetrics;
        }

        if (!is_array($activityMetrics)) {
            return $activityMetrics;
        }

        $allMeasurementSets = array_merge($allMeasurementSets, $bodyMetrics);
        $allMeasurementSets = array_merge($allMeasurementSets, $activityMetrics);

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
        $this->withingsService = $serviceFactory->createService(
            'Withings',
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
    private function getBodyMetrics($fromTime)
    {
        $measurementSet = array();
        $measurements = array();
        try {
            $url = "http://wbsapi.withings.net/measure?action=getmeas&startdate=" . $fromTime . "&enddate=" . time();

            $responseBody = $this->withingsService->request($url);
            $guzzleResponse = $this->withingsService->getHttpClient()->getLastResponse();
            $statusCode = $guzzleResponse->getStatusCode();

            if ($statusCode == 200) {
                $responseObject = json_decode($responseBody, true);

                $wbsapiStatusCode = $responseObject['status'];
                if ($wbsapiStatusCode == 0) {
                    foreach ($responseObject['body']['measuregrps'] as $measureGroup) {
                        // we need only Measure category
                        if ($measureGroup['category'] == 1) {
                            $timestamp = $measureGroup['date'];
                            foreach ($measureGroup['measures'] as $measure) {
                                $measureValue = $measure['value'] * pow(10, $measure['unit']);
                                if (isset($this->measureTypesBody[$measure['type']])) {
                                    $measurements[$measure['type']][] = new Measurement($timestamp, $measureValue);
                                }
                            }
                        }
                    }
                } elseif (isset(self::$wbsapiErrors[$wbsapiStatusCode])) {
                    $errorMsg = " [ERROR] Withings says: " . self::$wbsapiErrors[$wbsapiStatusCode] . "\n";
                    echo $errorMsg;

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/update',
                        500,
                        "Couldn't update",
                        $errorMsg
                    );
                } else {
                    $errorMsg = " [ERROR] Withings says: unknown error code " . $statusCode . "\n";
                    echo $errorMsg;

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/update',
                        500,
                        "Couldn't update",
                        $errorMsg
                    );
                }

            } else {
                // api is down
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't update",
                    "API is down"
                );
            }
        } catch (GuzzleException $e) {
            $guzzleResponse = $this->withingsService->getHttpClient()->getLastResponse();

            $errorMsg = " [ERROR] Exception during withings update: " . $e->getMessage() . "\n";
            $errorMsg .= " [ERROR] Withings says: " . $guzzleResponse->getBody() . "\n";
            echo $errorMsg;

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                $errorMsg
            );
        }

        foreach ($measurements as $key => $value) {
            $measurementSet[] = $this->measureTypesBody[$key]['set']($value);
        }

        return $measurementSet;
    }

    /**
     * @param int $fromTime
     *
     * @return MeasurementSet[]
     */
    private function getActivityMetrics($fromTime)
    {
        $measurementSet = array();
        $measurements = array();
        try {
            $url = "http://wbsapi.withings.net/v2/measure?action=getactivity&startdateymd="
                . date("Y-m-d", $fromTime) . "&enddateymd=" . date("Y-m-d", time());

            try {
                $responseBody = $this->withingsService->request($url);
            } catch (GuzzleException $e) {
                $responseBody = null;
            }

            $guzzleResponse = $this->withingsService->getHttpClient()->getLastResponse();
            $statusCode = $guzzleResponse->getStatusCode();

            if ($statusCode == 200) {
                $responseObject = json_decode($responseBody, true);

                $wbsapiStatusCode = $responseObject['status'];
                if ($wbsapiStatusCode == 0) {
                    $activityNameArray = array_keys($this->measureTypesActivity);
                    foreach ($responseObject['body']['activities'] as $activities) {
                        $date = new DateTime($activities['date'], new DateTimeZone($activities['timezone']));
                        $timestamp = $date->getTimestamp();
                        foreach ($activityNameArray as $activityName) {
                            if (isset($activities[$activityName])) {
                                $measurements[$activityName][] = new Measurement(
                                    $timestamp,
                                    $activities[$activityName]
                                );
                            }
                        }
                    }
                } elseif (isset(self::$wbsapiErrors[$wbsapiStatusCode])) {
                    $errorMsg = " [ERROR] Withings says: " . self::$wbsapiErrors[$wbsapiStatusCode] . "\n";
                    echo $errorMsg;

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/update',
                        500,
                        "Couldn't update",
                        $errorMsg
                    );
                } else {
                    $errorMsg = " [ERROR] Withings says: unknown error code " . $statusCode . "\n";
                    echo $errorMsg;

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/update',
                        500,
                        "Couldn't update",
                        $errorMsg
                    );
                }

            } else {
                // api is down
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't update",
                    "API is down"
                );
            }
        } catch (GuzzleException $e) {
            $guzzleResponse = $this->withingsService->getHttpClient()->getLastResponse();

            $errorMsg = " [ERROR] Exception during withings update: " . $e->getMessage() . "\n";
            $errorMsg .= " [ERROR] Withings says: " . $guzzleResponse->getBody() . "\n";
            echo $errorMsg;

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                $errorMsg
            );
        }

        foreach ($measurements as $key => $value) {
            $measurementSet[] = $this->measureTypesActivity[$key]($value);
        }

        return $measurementSet;
    }
}
