<?php

namespace Quantimodo\Connectors;

use Closure;
use Exception;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Connectors\ConnectParameter;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class MyFitnessPalConnector extends Connector
{
    private static $CONNECTOR_NAME = "myfitnesspal";

    private static $URL_BASE = "http://www.myfitnesspal.com/";
    private static $URL_LOGIN = "https://www.myfitnesspal.com/account/login";

    private static $EXTRACT_TOKEN_PATTERN = '/authenticity_token\" type=\"hidden\" value=\"(.+)\"/';
    private static $LOGIN_INVALIDUSERPASS_MESSAGE = "Incorrect username or password";
    private static $LOGIN_TOOMANYFAILED_MESSAGE = "exceeded the maximum number of consecutive failed login attempts";

    private $endpoints;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);

        $this->endpoints = array(
            'http://www.myfitnesspal.com/reports/results/nutrition/Carbs/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Carbs", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Fat/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Fat", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Protein/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Protein", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Saturated Fat/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Saturated Fat", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Polyunsaturated Fat/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Polyunsaturated Fat", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Monounsaturated Fat/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Monounsaturated Fat", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Trans Fat/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Trans Fat", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Cholesterol/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Cholesterol", "Nutrition", "mg", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Sodium/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Sodium", "Nutrition", "mg", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Potassium/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Potassium", "Nutrition", "mg", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Fiber/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Fiber", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Sugar/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Sugar", "Nutrition", "g", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Vitamin A/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Vitamin A", "Nutrition", "%RDA", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Vitamin C/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Vitamin C", "Nutrition", "%RDA", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Iron/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Iron", "Nutrition", "%RDA", $this->displayName, "SUM", $measurements);
            },
            'http://www.myfitnesspal.com/reports/results/nutrition/Calcium/_DAYS_.json?report_name=1' => function ($measurements) {
                return new MeasurementSet("Calcium", "Nutrition", "%RDA", $this->displayName, "SUM", $measurements);
            }
        );
    }

    public function getConnectInstructions()
    {
        $parameters = array(
            new ConnectParameter('Username', 'username', 'text'),
            new ConnectParameter('Password', 'password', 'password')
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
        } elseif (empty($parameters['password'])) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/connect',
                400,
                "Couldn't connect",
                "No username specified"
            );
        } else {
            $client = $this->getGuzzleClient($parameters['username'], $parameters['password']);

            // if $client is a string it's an error message we'll need to return to the client
            if (is_string($client)) {
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    403,
                    "Couldn't connect",
                    $client
                );
            } else {
                $credentials = array(
                    'username' => $parameters['username'],
                    'password' => $parameters['password']
                );
                $this->credentialsManager->store($credentials);

                return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
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
        // Limit to two years of data
        if ($timeDiffDays > 730) {
            $timeDiffDays = 730;
        }

        $credentials = $this->credentialsManager->get();
        if (!array_key_exists('username', $credentials) || !array_key_exists('password', $credentials)) {
            echo " [ERROR] Update request for disconnected connector\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "This connector isn't connected"
            );
        }

        echo " [INFO] Getting guzzle client\n";
        $client = $this->getGuzzleClient($credentials['username'], $credentials['password']);
        if (is_string($client)) {
            echo " [ERROR] Couldn't get guzzle client: " . $client . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                $client
            );
        }

        $allMeasurementSets = array();
        foreach ($this->endpoints as $url => $createMeasurementSetFunction) {
            $url = str_replace("_DAYS_", $timeDiffDays, $url);
            echo " [INFO] Request to: " . $url . "\n";

            try {
                $request = $client->get($url);
                $response = $request->send();

                $allMeasurementSets[] = $this->parseResponse($response->json(), $createMeasurementSetFunction);
            } catch (BadResponseException $e) {
                echo " [ERROR] Bad respose: " . $e->getMessage() . "\n";
            }

        }

        return $allMeasurementSets;
    }

    private function getGuzzleClient($username, $password)
    {
        // This'll hold the cookies for this session
        $cookiePlugin = new CookiePlugin(new ArrayCookieJar());

        // Add the cookie plugin to a client
        $client = new Client(self::$URL_BASE);
        $client->addSubscriber($cookiePlugin);

        try {
            // Send our initial request to the home page to get a special token required for login
            $request = $client->get();
            $response = $request->send();
            $responseBody = $response->getBody();

            // Get authenticity token (their protection against CSRF).
            preg_match(self::$EXTRACT_TOKEN_PATTERN, $responseBody, $matches);
            $authenticityToken = $matches[1];

            // Create an array of POST parameters
            $loginParameters = array(
                'utf8' => '✓',
                'authenticity_token' => $authenticityToken,
                'username' => $username,
                'password' => $password,
                'remember_me' => 1,
            );

            // Do the POST request to log in the user
            $request = $client->post(self::$URL_LOGIN, null, $loginParameters);
            $response = $request->send();
            $responseBody = $response->getBody();

            // If the response contains one of our error messages the login was unsuccessful
            if (strpos($responseBody, self::$LOGIN_INVALIDUSERPASS_MESSAGE) !== false) {
                $this->disconnect();

                return "Invalid username or password";
            } elseif (strpos($responseBody, self::$LOGIN_TOOMANYFAILED_MESSAGE) !== false) {
                return "Invalid username or password";
                //return "Too many failed login attempts";
            } else {
                return $client;
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Parses measurements received from MyFitnessPal
     * $createMeasurementSetFunction should take an associative array of measurements
     * and convert it into a measurement
     *
     * @param array $jsonObject
     * @param Closure $createMeasurementSetFunction
     *
     * @return MeasurementSet
     */
    private function parseResponse($jsonObject, Closure $createMeasurementSetFunction)
    {
        // MyFitnessPal doesn't return a year, so keep track of the month so that
        // we can decrease currentMeasurementMonth
        // when going from month #1 to month #12 in the previous year
        $previousMeasurementMonth = intval(date('m'));
        $currentMeasurementYear = intval(date('Y'));

        $measurements = array();
        $numRawMeasurements = count($jsonObject['data']);

        // Loop in reverse order so that we can track the year
        for ($i = $numRawMeasurements - 1; $i >= 0; $i--) {
            $myFitnessPalMeasurement = $jsonObject['data'][$i];

            // Filter out 0 values, we can't differentiate between "didn't track" or "didn't take",
            // so we don't store them
            // as measurements. The API can figure out what to do about it.
            if ($myFitnessPalMeasurement['total'] == 0) {
                continue;
            }

            $dateComponents = explode("/", $myFitnessPalMeasurement['date']);
            $currentMeasurementMonth = intval($dateComponents[0]);
            $currentMeasurementDay = intval($dateComponents[1]);

            // Happens when we went from month 1 to month 12. Breaks if you skip an entire year worth of data,
            // but that'll be very rare
            if ($currentMeasurementMonth > $previousMeasurementMonth) {
                $currentMeasurementYear--;
            }

            $timestamp = mktime(0, 0, 0, $currentMeasurementMonth, $currentMeasurementDay, $currentMeasurementYear);
            $measurements[] = new Measurement($timestamp, $myFitnessPalMeasurement['total']);

            $previousMeasurementMonth = $currentMeasurementMonth;
        }

        return $createMeasurementSetFunction($measurements);
    }
}
