<?php

namespace Quantimodo\Connectors;

use Exception;
use Guzzle\Common\Exception\GuzzleException;
use Guzzle\Http\Client;
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

class MoodscopeConnector extends Connector
{
    private static $CONNECTOR_NAME = "moodscope";

    private static $URL_BASE = "https://www.moodscope.com";
    private static $URL_LOGIN = "https://www.moodscope.com/login";
    private static $URL_MOODS = "https://www.moodscope.com/chart?month=%s-%s"; // year/month

    // If the POST result contains /login we're being redirected to the login page again, so login failed.
    private static $LOGIN_FAILEDLOGINMESSAGE = '/login';

    // Extract the user's mood data
    private static $REGEX_MOODS = "!name: '(.*)',fillColor: '#FF0033',\\s+x:\\s+Date.UTC\\((\\d+),\\s+(\\d+),\\s+(\\d+)\\),\\s+y:\\s+(\\d+),\\s+lineWidth!";

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
    }

    public function getConnectInstructions()
    {
        $parameters = [
            new ConnectParameter('Username', 'username', 'text'),
            new ConnectParameter('Password', 'password', 'password')
        ];
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
                "No password specified"
            );
        } else {
            $client = $this->getGuzzleClient($parameters['username'], $parameters['password']);

            if ($client instanceof Client) {
                $credentials = array(
                    'username' => $parameters['username'],
                    'password' => $parameters['password']
                );
                $this->credentialsManager->store($credentials);

                return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
            } elseif ($client instanceof ErrorResponseMessage) {
                // If client is an ErrorResponseMessage we return it
                return $client;
            } else {
                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    500,
                    "Couldn't connect",
                    "We couldn't contact Moodscope, please try again later!"
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

        $client = $this->getGuzzleClient($credentials['username'], $credentials['password']);

        if ($client instanceof Client) {
            $allMeasurements = [];

            // Convert time to array containing year and month.
            $nowDateArray = explode('-', date('Y-n', time()));
            $fromDateArray = explode('-', date('Y-n', $fromTime));

            $currentMoodscopeYear = $nowDateArray[0];
            $currentMoodscopeMonth = $nowDateArray[1];
            $fromYear = $fromDateArray[0];
            $fromMonth = $fromDateArray[1];

            // Loop over at most 24 months of data.
            for ($monthsDone = 0; $monthsDone < 36; $monthsDone++) {
                // Get the page that contains the moods for this month.
                $moodsPage = $this->getMoodsPage($client, $currentMoodscopeYear, $currentMoodscopeMonth);

                if (gettype($moodsPage) != 'string' && get_class($moodsPage) == 'ErrorResponseMessage') {
                    // Something went wrong.
                    return $moodsPage;
                }

                // Parse moods and merge it into the array of previously received measurements.
                $allMeasurements = array_merge($allMeasurements, $this->parseMoodsPage($moodsPage, $fromTime));

                // Decrease month.
                $currentMoodscopeMonth--;

                // If we are in the same year as $currentMoodScopeYear and our month is earlier we're done.
                if ($currentMoodscopeYear == $fromYear && $currentMoodscopeMonth < $fromMonth) {
                    break;
                }

                // When month reaches zero we reached the previous year.
                if ($currentMoodscopeMonth === 0) {
                    $currentMoodscopeYear--;
                    $currentMoodscopeMonth = 12;
                }
            }

            return [
                new MeasurementSet('Overall Mood', 'Mood', '%', $this->displayName, "MEAN", $allMeasurements)
            ];
        } elseif ($client instanceof ErrorResponseMessage) {
            // If client is an ErrorResponseMessage we return it
            return $client;
        } else {
            // Something went horribly wrong :(.
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "We couldn't contact Moodscope, please try again later!"
            );
        }
    }

    private function getGuzzleClient($username, $password)
    {
        // This'll hold the cookies for this session
        $cookiePlugin = new CookiePlugin(new ArrayCookieJar());

        // Add the cookie plugin to a client
        $client = new Client(self::$URL_BASE);
        $client->addSubscriber($cookiePlugin);

        try {
            // Send our initial request to the home page to get our PHPSESSID cookie.
            $request = $client->get();
            $request->send();

            // Create an array of POST parameters, and send it to login
            $loginParameters = [
                '_username' => $username,
                '_password' => $password,
                'login.x' => rand(2, 80),
                'login.y' => rand(2, 20),
                'login' => 'Login!',
            ];

            // Disable redirecting so that we aren't redirected after authentication
            $request = $client->post(
                self::$URL_LOGIN,
                null,
                $loginParameters,
                ['allow_redirects' => false]
            );
            $response = $request->send();
            $responseBody = $response->getBody();

            // If the response contains this the login was unsuccessful
            if (strpos($responseBody, self::$LOGIN_FAILEDLOGINMESSAGE) !== false) {
                $this->disconnect();

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/update',
                    403,
                    "Couldn't connect",
                    "Invalid username or password"
                );
            } else {
                return $client;
            }
        } catch (GuzzleException $e) {
            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't connect",
                "We couldn't contact Moodscope, please try again later!"
            );
        }
    }

    /**
     * Note: $timeDiffDays has been calculated using the following logic before it has been hardcoded to 120
     * $timeDiffSeconds = time() - $fromTime;
     * $timeDiffDays = round($timeDiffSeconds / (60*60*24));
     *
     * @param Client $client
     *
     * @return string|ErrorResponseMessage
     */
    private function getMoodsPage($client, $year, $month)
    {
        try {
            $url = sprintf(self::$URL_MOODS, $year, $month);
            $request = $client->get($url);
            $response = $request->send();
            $responseHtml = $response->getBody();

            return $responseHtml;
        } catch (Exception $e) {
            echo " [ERROR] Couldn't contact MoodScope. " . $e->getMessage() . "\n";

            return new ErrorResponseMessage(
                PhpConnect::$currentUserId,
                $this->name . '/update',
                500,
                "Couldn't update",
                "We couldn't contact MoodPulse, please try again later"
            );
        }
    }

    /**
     * @param string $moodsPage
     * @param int $fromTime
     *
     * @return array Array containing Measurements
     */
    private function parseMoodsPage($moodsPage, $fromTime)
    {
        $moodMeasurements = [];
        $numMatches = preg_match_all(self::$REGEX_MOODS, $moodsPage, $matches);

        for ($i = 0; $i < $numMatches; $i++) {
            $year = $matches[2][$i];
            $month = $matches[3][$i];
            $day = $matches[4][$i];
            $score = $matches[5][$i];

            $timestamp = mktime(0, 0, 0, $month, $day, $year);

            if ($timestamp < $fromTime) {
                break;
            }

            $moodMeasurements[] = new Measurement($timestamp, $score);
        }

        return $moodMeasurements;
    }
}
