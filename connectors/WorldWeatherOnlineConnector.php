<?php

namespace Quantimodo\Connectors;

use DateTime;
use Exception;
use Guzzle\Common\Exception\GuzzleException;
use Guzzle\Http\Client;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Connectors\ConnectParameter;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

/**
 * TODO LIST
 * 1. Build initial connector
 * 2. Check if location is already stored by another user. No need to duplicate data in our DB
 *    if we have more than 1 user in the same city.
 * 3. Get data for entire year in the update method.
 *
 * Key Obtained 3-31-2015
 * https://developer.worldweatheronline.com/
 * User: m@thinkbynumbers.org
 * PW: B1ggerstaff!
 * Premium API Key: e019cabf1f1e000849b65adcdd3f5
 *
 * Old Key
 * User: quantimo.do
 * PW: B1ggerstaff!
 * Premium API Key: 09eae8ec53d248cfd05957cc4999318ed66f8154
 *
 */
class WorldWeatherOnlineConnector extends Connector
{
    private static $CONNECTOR_NAME = "worldweatheronline";
    private static $URL_BASE = "http://api.worldweatheronline.com";
    private static $API_KEY = "e019cabf1f1e000849b65adcdd3f5";
    private static $PLACEHOLDER = "Enter Post/Zip Code, City/Town, IATA, IP";

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
    }

    public function getConnectInstructions()
    {
        $parameters = array(
            new ConnectParameter('Location', 'location', 'text', self::$PLACEHOLDER)
        );
        $url = $this->getBaseUrl() . "/connect";
        $usePopup = false;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        if (empty($parameters['location'])) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/connect',
                400,
                "Couldn't connect",
                "No location specified"
            );
        } else {
            try {
                $client = $this->getGuzzleClient();
                $path = '/premium/v1/search.ashx';
                $request = $client->get($path);

                $request->getQuery()
                    ->set('query', $parameters['location'])
                    ->set('num_of_results', 1);

                $response = $request->send();
                $responseJSON = $response->getBody();
                $responseObject = json_decode($responseJSON);

                $locations = $responseObject->search_api->result;

                // if $client is a string it's an error message we'll need to return to the client
                if (count($locations) == 0) {
                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/update',
                        404,
                        "Couldn't connect",
                        'Could not find this location'
                    );
                } else {
                    $location = $locations[0];

                    // Lengthy variable name, but it'll do.
                    $locationName = $location->areaName[0]->value . ', ' .
                        $location->region[0]->value . ', ' .
                        $location->country[0]->value;

                    $credentials = array(
                        'location' => $locations[0]->latitude . ',' . $locations[0]->longitude,
                        'locationName' => $locationName
                    );

                    $this->credentialsManager->store($credentials);

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't contact WorldWeatherOnline. " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't connect",
                    "We couldn't contact World Weather Online, please try again later"
                );
            } catch (Exception $e) {
                echo " [ERROR] Couldn't connect WorldWeatherOnline. " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't connect",
                    "We cannot connect at this time. Please report this error!"
                );
            }
        }
    }

    public function update($fromTime)
    {
        $measurementSets = array();
        $temperatureMeasurements = array();
        $humidityMeasurements = array();
        $pressureMeasurements = array();
        $visibilityMeasurements = array();
        $precipMeasurements = array();
        $cloudcoverMeasurements = array();

        $credentials = $this->credentialsManager->get();

        try {
            $nowDateTime = new DateTime();
            $fromDateTime = new DateTime("@$fromTime");

            $fromYear = $fromDateTime->format('Y');
            $fromMonth = $fromDateTime->format('m');

            $nowYear = $nowDateTime->format('Y');
            $nowMonth = $nowDateTime->format('m');
            $nowDay = $nowDateTime->format('d');

            // We start at "now" and loop back to "fromTime"
            $currentYear = $nowYear;
            $currentMonth = $nowMonth;
            $currentDay = $nowDay;

            $client = $this->getGuzzleClient();
            $path = '/premium/v1/past-weather.ashx';
            $request = $client->get($path);

            $done = false;
            for ($numMonthsDone = 0; $numMonthsDone <= 12 && !$done; $numMonthsDone++) {
                if ($currentMonth == $nowMonth && $currentYear == $nowYear) {
                    $currentDay = $nowDay;
                } else {
                    switch ($currentMonth) {
                        case '01':
                            $currentDay = '31';
                            break;
                        case '02':
                            //account for leap years with the modulus operator(yay Gregorian!)
                            if ($currentYear % 4 == 0) {
                                $currentDay = '29';
                            } else {
                                $currentDay = '28';
                            }
                            break;
                        case '03':
                            $currentDay = '31';
                            break;
                        case '04':
                            $currentDay = '30';
                            break;
                        case '05':
                            $currentDay = '31';
                            break;
                        case '06':
                            $currentDay = '30';
                            break;
                        case '07':
                            $currentDay = '31';
                            break;
                        case '08':
                            $currentDay = '31';
                            break;
                        case '09':
                            $currentDay = '30';
                            break;
                        case '10':
                            $currentDay = '31';
                            break;
                        case '11':
                            $currentDay = '30';
                            break;
                        case '12':
                            $currentDay = '31';
                            break;
                    }
                }

                if ($currentMonth == $fromMonth && $currentYear == $fromYear) {
                    $fromDay = $fromDateTime->format('d');
                    $done = true; // Set done to true, we reached the end so we want to break out the 12 month loop
                } else {
                    $fromDay = '01'; // We're not in the final month yet, so we set fromDay to 01.
                }

                echo " [INFO] Getting weather from " . $fromDay . "/" . $currentMonth . "/" . $currentYear .
                    " till " . $currentDay . "/" . $currentMonth . "/" . $currentYear . "\n";

                $request->getQuery()
                    ->set('q', $credentials['location'])
                    ->set('date', $currentYear . '-' . $currentMonth . '-' . $fromDay)
                    ->set('enddate', $currentYear . '-' . $currentMonth . '-' . $currentDay);

                $response = $request->send();
                $responseObject = json_decode($response->getBody());

                // Parse the responseObject to get our measurements
                $allMeasurements = $this->parser($fromTime, $responseObject);

                $temperatureMeasurements = array_merge($temperatureMeasurements, $allMeasurements[0]);
                $humidityMeasurements = array_merge($humidityMeasurements, $allMeasurements[1]);
                $pressureMeasurements = array_merge($pressureMeasurements, $allMeasurements[2]);
                if (count($allMeasurements[3]) > 0) {
                    $visibilityMeasurements = array_merge($visibilityMeasurements, $allMeasurements[3]);
                }
                if (count($allMeasurements[4]) > 0) {
                    $precipMeasurements = array_merge($precipMeasurements, $allMeasurements[4]);
                }
                if (count($allMeasurements[5]) > 0) {
                    $cloudcoverMeasurements = array_merge($cloudcoverMeasurements, $allMeasurements[5]);
                }

                $currentMonth = $currentMonth - 1;
                // looping backwards, make sure to go from January(1) to December(12)
                // and the previous Year
                if ($currentMonth == 0) {
                    $currentMonth = 12;
                    $currentYear--;
                }
                if ($currentMonth < 10) {
                    $currentMonth = '0' . $currentMonth;
                }
            }
        } catch (Exception $e) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                $e->getMessage()
            );
        }

        // Make sure we have a locationName property (legacy stuff)
        if (array_key_exists('locationName', $credentials)) {
            $locationName = $credentials['locationName'];
        } else {
            $locationName = $credentials['location'];
        }
        $measurementSets[] = new MeasurementSet(
            'Temperature at ' . $locationName,
            'Environment',
            '°C',
            $this->displayName,
            "MEAN",
            $temperatureMeasurements
        );
        $measurementSets[] = new MeasurementSet(
            'Pressure at ' . $locationName,
            'Environment',
            'Pa',
            $this->displayName,
            "MEAN",
            $pressureMeasurements
        );
        $measurementSets[] = new MeasurementSet(
            'Humidity at ' . $locationName,
            'Environment',
            '%',
            $this->displayName,
            "MEAN",
            $humidityMeasurements
        );

        if (count($visibilityMeasurements) > 0) {
            $measurementSets[] = new MeasurementSet(
                'Visibility at ' . $locationName,
                'Environment',
                'km',
                $this->displayName,
                "MEAN",
                $visibilityMeasurements
            );
        }

        if (count($precipMeasurements) > 0) {
            $measurementSets[] = new MeasurementSet(
                'Precipitation at ' . $locationName,
                'Environment',
                'mm',
                $this->displayName,
                "MEAN",
                $precipMeasurements
            );
        }

        if (count($cloudcoverMeasurements) > 0) {
            $measurementSets[] = new MeasurementSet(
                'Cloud cover amount at ' . $locationName,
                'Environment',
                '%',
                $this->displayName,
                "MEAN",
                $cloudcoverMeasurements
            );
        }

        return $measurementSets;
    }

    /**
     * @param int $fromTime
     *
     * @param $responseObject
     * @return array
     */
    private function parser($fromTime, $responseObject)
    {
        $temperatureMeasurements = array();
        $humidityMeasurements = array();
        $pressureMeasurements = array();
        $visibilityMeasurements = array();
        $precipMeasurements = array();
        $cloudcoverMeasurements = array();
        // loop through every day of the month
        // MTD for current month
        $maxDays = count($responseObject->data->weather);
        if ($maxDays == 0) {
            return array($temperatureMeasurements, $humidityMeasurements, $pressureMeasurements);
        }
        for ($i = 0; $i < $maxDays; $i++) {
            $maxData = count($responseObject->data->weather[$i]->hourly);
            $timestamp = strtotime($responseObject->data->weather[$i]->date) + 3600;

            // 8 results returned in $responseObject for each day
            // using $maxData in case this ever changes.
            for ($n = 0; $n < $maxData; $n++) {
                if ($timestamp < $fromTime) { // Don't insert measurements that have a timestamp before the last update
                    break;
                }

                $temperature = $responseObject->data->weather[$i]->hourly[$n]->tempC;
                $humidity = $responseObject->data->weather[$i]->hourly[$n]->humidity;
                // api uses millibar, mb*100 is conversion to Pascal(Pa)
                $pressure = $responseObject->data->weather[$i]->hourly[$n]->pressure * 100;

                $temperatureMeasurement = new Measurement($timestamp, $temperature);
                $humidityMeasurement = new Measurement($timestamp, $humidity);
                $pressureMeasurement = new Measurement($timestamp, $pressure);

                $temperatureMeasurements[] = $temperatureMeasurement;
                $humidityMeasurements[] = $humidityMeasurement;
                $pressureMeasurements[] = $pressureMeasurement;

                if (isset($responseObject->data->weather[$i]->hourly[$n]->visibility)) {
                    $visibility = $responseObject->data->weather[$i]->hourly[$n]->visibility;
                    $visibilityMeasurement = new Measurement($timestamp, $visibility);
                    $visibilityMeasurements[] = $visibilityMeasurement;
                }

                if (isset($responseObject->data->weather[$i]->hourly[$n]->precipMM)) {
                    $precipMM = $responseObject->data->weather[$i]->hourly[$n]->precipMM;
                    $precipMeasurement = new Measurement($timestamp, $precipMM);
                    $precipMeasurements[] = $precipMeasurement;
                }

                if (isset($responseObject->data->weather[$i]->hourly[$n]->cloudcover)) {
                    $cloudcover = $responseObject->data->weather[$i]->hourly[$n]->cloudcover;
                    $cloudcoverMeasurement = new Measurement($timestamp, $cloudcover);
                    $cloudcoverMeasurements[] = $cloudcoverMeasurement;
                }

                $timestamp += 10800;
            }
        }

        return array(
            $temperatureMeasurements,
            $humidityMeasurements,
            $pressureMeasurements,
            $visibilityMeasurements,
            $precipMeasurements,
            $cloudcoverMeasurements
        );
    }

    private function getGuzzleClient()
    {
        //TODO: Make sure this user exists
        return new Client(
            self::$URL_BASE,
            array(
                'request.options' => array(
                    'query' => array(
                        'key' => self::$API_KEY,
                        'format' => 'json'
                    )
                )
            )
        );
    }
}
