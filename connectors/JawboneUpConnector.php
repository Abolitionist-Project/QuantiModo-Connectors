<?php

namespace Quantimodo\Connectors;

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

class JawboneUpConnector extends Connector
{
    
    // NOTE: You cannot currently pull data from Jawbone UP
    // See: http://forums.jawbone.com/t5/LIVING-UP/UP-API-Development/td-p/94260

    private static $CONNECTOR_NAME = "up";

    private static $PERMISSIONS_SCOPES = array(
        "basic_read",
        "extended_read",
        "location_read",
        "friends_read",
        "mood_read",
        "move_read",
        "sleep_read",
        "meal_read",
        "weight_read",
        "cardiac_read",
        "generic_event_read"
    );
    private static $BASE_URL = "https://jawbone.com";
    private static $ENDPOINT_URL = "https://jawbone.com/nudge/api/v.1.0/users/@me/";

    private $endpoints;

    // Keys for callback https://local.quantimo.do/api/connectors/up/connect
    // Dev Console: https://github.com/settings/applications/new
    // Developer Username: quantimodo1
    // Developer PW: Iamapassword1!
    // Test User: mike@thinkbynumbers.org
    // Test PW: B1ggerstaff!

    // NOTE: These local.quantimo.do keys are overwritten if you have values in the database.
    private static $CLIENT_ID = '10RfjEgKr8U';
    private static $CLIENT_SECRET = 'e17fd34e4bc4642f0c4c99d7acb6e661';

    private $upService;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);

        $this->endpoints = array(
            'body_events' => array(
                'bmi' => function ($measurements) {
                    return new MeasurementSet(
                        'BMI',
                        'Physique',
                        'index',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                'weight' => function ($measurements) {
                    return new MeasurementSet(
                        'Weight',
                        'Physique',
                        'kg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                'body_fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Body Fat',
                        'Physique',
                        '%',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                // lean_mass
            ),
            'sleeps' => array(
                'quality' => function ($measurements) {
                    return new MeasurementSet(
                        "Sleep Quality",
                        "Sleep",
                        "%",
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                'duration' => function ($measurements) {
                    return new MeasurementSet(
                        'Sleep Duration',
                        'Sleep',
                        's',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
            ),
            'cardiac_events' => array(
                'heart_rate' => function ($measurements) {
                    return new MeasurementSet(
                        'Heart Rate',
                        'Vital Signs',
                        'bpm',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                'systolic_pressure' => function ($measurements) {
                    return new MeasurementSet(
                        'Systolic Pressure',
                        'Vital Signs',
                        'mmHg',
                        $this->displayName,
                        "MEAN",
                        $measurements
                    );
                },
                'diastolic_pressure' => function ($measurements) {
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
            'meals' => array(
                'polyunsaturated_fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Polyunsaturated Fat',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Fat',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'saturated_fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Saturated Fat',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'monounsaturated_fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Monounsaturated Fat',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'unsaturated_fat' => function ($measurements) {
                    return new MeasurementSet(
                        'Unsaturated Fat',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'fiber' => function ($measurements) {
                    return new MeasurementSet(
                        'Fiber',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'potassium' => function ($measurements) {
                    return new MeasurementSet(
                        'Potassium',
                        'Nutrition',
                        'mg',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'carbohydrate' => function ($measurements) {
                    return new MeasurementSet(
                        'Carbs',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'protein' => function ($measurements) {
                    return new MeasurementSet(
                        'Protein',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'sodium' => function ($measurements) {
                    return new MeasurementSet(
                        'Sodium',
                        'Nutrition',
                        'mg',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'vitamin_c' => function ($measurements) {
                    return new MeasurementSet(
                        'Vitamin C',
                        'Nutrition',
                        '%RDA',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'vitamin_a' => function ($measurements) {
                    return new MeasurementSet(
                        'Vitamin A',
                        'Nutrition',
                        '%RDA',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'calories' => function ($measurements) {
                    return new MeasurementSet(
                        'CaloriesIn',
                        'Nutrition',
                        'kcal',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'sugar' => function ($measurements) {
                    return new MeasurementSet(
                        'Sugar',
                        'Nutrition',
                        'g',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'calcium' => function ($measurements) {
                    return new MeasurementSet(
                        'Calcium',
                        'Nutrition',
                        'mg',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'iron' => function ($measurements) {
                    return new MeasurementSet(
                        'Iron',
                        'Nutrition',
                        '%RDA',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'cholesterol' => function ($measurements) {
                    return new MeasurementSet(
                        'Cholesterol',
                        'Nutrition',
                        'mg',
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
            ),
            'mood' => array(
                // TODO: this endpoint returns data only for today, and not in the items property, need to be fixed
                'mood' => array(
                    'request_params' => array(), // TODO: add a possibility to pass custom request parameters
                    'processor' => function ($value) {
                        return $value == 0 ? false : array_search($value, array(7, 6, 5, 4, 8, 3, 2, 1) * 100 / 7);
                    },
                    'set' => function ($measurements) {
                        return new MeasurementSet(
                            'Mood',
                            'Mood',
                            '%',
                            $this->displayName,
                            "MEAN",
                            $measurements
                        );
                    },
                ),
            ),
            'moves' => array(
                'km' => function ($measurements) {
                    return new MeasurementSet(
                        "Distance",
                        "Physical Activity",
                        "km",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'steps' => function ($measurements) {
                    return new MeasurementSet(
                        "Steps",
                        "Physical Activity",
                        "count",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'active_time' => function ($measurements) {
                    return new MeasurementSet(
                        "Active Time",
                        "Physical Activity",
                        "s",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'calories' => function ($measurements) {
                    return new MeasurementSet(
                        "Calories Burned",
                        "Physical Activity",
                        "kcal",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
            ),
            'generic_events' => array(
                'place_lat' => function ($measurements) {
                    return new MeasurementSet(
                        "Geo latitude",
                        "Location",
                        "°E",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
                'place_lon' => function ($measurements) {
                    return new MeasurementSet(
                        "Geo longitude",
                        "Location",
                        "°N",
                        $this->displayName,
                        "SUM",
                        $measurements
                    );
                },
            ),
        );

        // Init service to make sure $upService is populated for getConnectInstructions
        $this->initService();
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        $url = $this->upService->getAuthorizationUri()->getAbsoluteUri();
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['code'])) {
            $url = $this->upService->getAuthorizationUri()->getAbsoluteUri();

            return new RedirectResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect', $url);
        } else {
            try {
                $accessToken = $this->upService->requestAccessToken($parameters['code']);
                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get JawboneUp access token from code\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process, JawboneUp failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get JawboneUp access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to JawboneUp, please try again!"
                );
            } catch (TokenNotFoundException $e) {
                echo " [ERROR] Couldn't connect to JawboneUp, token not found\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to JawboneUp, please try again!"
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

        // Limit date range to two years
        if ($fromTime == 0) {
            $fromTime = time() - 63113852;
        }

        $allMeasurementSets = array();

        $allMeasurementSets = array_merge($allMeasurementSets, $this->getMeasurements($fromTime));

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
        $this->upService = $serviceFactory->createService(
            'JawboneUp',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            self::$PERMISSIONS_SCOPES
        );
    }

    /**
     * Get sleep data
     *
     * @param int $fromTime
     *
     * @return array
     */
    private function getMeasurements($fromTime)
    {
        $measurementSet = array();

        // Loop through all endpoints
        foreach ($this->endpoints as $endpointName => $measurementTypeArray) {

            $nextPage = self::$ENDPOINT_URL . $endpointName . '?start_time=' . $fromTime
                . '&end_time=' . time();

            $measurements = array();

            // Loop through the feed until we no longer have a nextPage
            while ($nextPage != null) {
                // don't crash on jawbone up api 404 errors
                try {
                    $responseBody = $this->upService->request($nextPage);
                } catch (GuzzleException $e) {
                    echo " [ERROR] JawboneUp: " . $e->getMessage() . " on: " . $nextPage . " \n";
                    break;
                } catch (TokenNotFoundException $e) {
                    echo " [ERROR] JawboneUp: " . $e->getMessage() . "\n";

                    return $measurementSet;
                }

                $guzzleResponse = $this->upService->getHttpClient()->getLastResponse();
                $statusCode = $guzzleResponse->getStatusCode();

                switch ($statusCode) {
                    case 200:

                        $responseObject = json_decode($responseBody);
                        $responseData = $responseObject->data;
                        if (property_exists($responseData, 'links') && property_exists($responseData->links, 'next')) {
                            if ($nextPage != self::$BASE_URL . $responseData->links->next) {
                                $nextPage = self::$BASE_URL . $responseData->links->next;
                                echo " [INFO] Next page: " . $nextPage . "\n";
                            } else {
                                $nextPage = null;
                                echo " [INFO] Duplicate next page\n";
                            }
                        } else {
                            $nextPage = null;
                            echo " [INFO] No more pages available\n";
                        }

                        if (property_exists($responseData, 'items')) {
                            foreach ($responseData->items as $currentDatapoint) {

                                $timestamp = $currentDatapoint->time_created;

                                // If this timestamp is older than our lower limit we break out
                                // so we don't store old measurements
                                if ($timestamp < $fromTime) {
                                    $nextPage = null;
                                    break;
                                }

                                if (property_exists($currentDatapoint, 'time_completed')) {
                                    $completed = $currentDatapoint->time_completed;
                                }
                                $duration = isset($completed) ? $completed - $timestamp : null;

                                $measurementNameArray = array_keys($measurementTypeArray);

                                foreach ($measurementNameArray as $measurementName) {
                                    // tries to find the passed measurement name property in main object
                                    // and then in the objects details property
                                    if (property_exists($currentDatapoint, $measurementName)) {
                                        $value = $currentDatapoint->$measurementName;

                                    } elseif (property_exists($currentDatapoint->details, $measurementName)) {
                                        $value = $currentDatapoint->details->$measurementName;
                                    }

                                    // do we have processor function for this measurement or no
                                    if (isset($value) && is_array($measurementTypeArray[$measurementName])) {
                                        $value = $measurementTypeArray[$measurementName]['processor']($value);
                                    }

                                    // if property is found and has proper value
                                    if (isset($value) && $value !== false) {
                                        $measurements[$measurementName][] = new Measurement(
                                            $timestamp,
                                            $value,
                                            $duration
                                        );
                                    }
                                }
                            }
                        }
                        break;
                    case 401:
                    case 403:
                        $nextPage = null;
                        $this->disconnect();
                        break;
                    default:
                        $nextPage = null;
                }
            }

            foreach ($measurements as $measurementName => $measurementsArray) {
                if (is_array($measurementTypeArray[$measurementName])) {
                    $measurementSet[] = $measurementTypeArray[$measurementName]['set']($measurementsArray);
                } else {
                    $measurementSet[] = $measurementTypeArray[$measurementName]($measurementsArray);
                }
            }

        }

        return $measurementSet;
    }
}
