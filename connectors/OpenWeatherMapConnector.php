<?php

namespace Quantimodo\Connectors;

use DateTime;
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

class OpenWeatherMapConnector extends Connector
{

    // TODO: I think this is not operational?

    private static $CONNECTOR_NAME = "openweathermap";
    private static $URL_BASE = "http://api.openweathermap.org";

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
    }

    public function getConnectInstructions()
    {
        $parameters = array(
            new ConnectParameter('Location', 'location', 'text')
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
            $client = $this->getGuzzleClient();
            $path = '/data/2.5/weather?q=' . $parameters['location'];
            $request = $client->get($path);
            $response = $request->send();
            $responseJSON = $response->getBody();
            $responseObject = json_decode($responseJSON);

            // if $client is a string it's an error message we'll need to return to the client
            if ($responseObject->cod == 404) {
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    404,
                    "Couldn't connect",
                    $responseObject->message
                );
            } else {
                //print_r($responseObject->main);
                $credentials = array(
                    'location' => $parameters['location']
                );
                $this->credentialsManager->store($credentials);

                return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
            }
        }
    }

    public function update($parameters)
    {
        ini_set('date.timezone', 'America/New_York');
        $measurementSets = array();
        $temperatureMeasurements = array();
        $humidityMeasurements = array();
        $pressureMeasurements = array();
        $credentials = $this->credentialsManager->get();

        try {
            $client = $this->getGuzzleClient();
            $path = '/data/2.5/weather?q=' . $credentials['location'];
            $request = $client->get($path);
            $response = $request->send();
            $responseJSON = $response->getBody();
            $responseObject = json_decode($responseJSON);

            //print_r($responseObject->main);

            $temperature = $responseObject->main->temp;
            $humidity = $responseObject->main->humidity;
            $pressure = $responseObject->main->pressure;
            $dateTime = new DateTime('midnight');
            $timestamp = $dateTime->getTimestamp();
            //new DateTime('today')

            $temperatureMeasurement = new Measurement($timestamp, $temperature);
            $humidityMeasurement = new Measurement($timestamp, $humidity);
            $pressureMeasurement = new Measurement($timestamp, $pressure);

            $temperatureMeasurements[] = $temperatureMeasurement;
            $humidityMeasurements[] = $humidityMeasurement;
            $pressureMeasurements[] = $pressureMeasurement;
        } catch (Exception $e) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                $e->getMessage()
            );
        }

        $measurementSets[] = new MeasurementSet(
            'Temperature at ' . $credentials['location'],
            'Environment',
            'K',
            $this->displayName,
            "MEAN",
            $temperatureMeasurements
        );
        $measurementSets[] = new MeasurementSet(
            'Pressure at ' . $credentials['location'],
            'Environment',
            'Pa',
            $this->displayName,
            "MEAN",
            $pressureMeasurements
        );
        $measurementSets[] = new MeasurementSet(
            'Humidity at ' . $credentials['location'],
            'Environment',
            '%',
            $this->displayName,
            "MEAN",
            $humidityMeasurements
        );

        return $measurementSets;
    }

    private function getGuzzleClient()
    {
        //TODO: Make sure this user exists
        return new Client(self::$URL_BASE);
    }
}
