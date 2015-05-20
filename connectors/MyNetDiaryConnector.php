<?php

namespace Quantimodo\Connectors;

use Exception;
use Guzzle\Http\Client;
use Guzzle\Http\EntityBodyInterface;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;
use Guzzle\Plugin\Cookie\CookiePlugin;
use phpQuery;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Connectors\ConnectParameter;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class MyNetDiaryConnector extends Connector
{
    private static $CONNECTOR_NAME = "mynetdiary";

    private static $URL_LOGIN = "https://www.mynetdiary.com/logon.do";

    private $endpoints;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);

        $this->endpoints = array(
            'foodDiary' => 'https://www.mynetdiary.com/reportRefresh.do',
            'exerciseDiary' => 'https://www.mynetdiary.com/reportRefresh.do',
            'diabetesDiary' => 'https://www.mynetdiary.com/dailyTracking.do?date=',
            'diabetesDiary2' => 'http://www.mynetdiary.com/loadDayParts.do'
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
        $parseAll = ($fromTime == 0) ? true : false; // If $fromTime==0 parse history
        $period = ($fromTime == 0) ? 365 : 7; // If $fromTime==0 request annual report, else request weekly report
        $foodDiaryPeriod = ($fromTime == 0) ? 'period1y' : 'period7d';
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

        $measurementsSets = array();
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

        /*
        **	Get food measurements
        */
        $url = $this->endpoints['foodDiary'];
        $formData = array(
            'period' => $foodDiaryPeriod,
            'periodFake' => 'period7d',
            'details' => 'allFoods',
            'nutrients' => 'trackedNutrients'
        );

        echo " [INFO] Request to: " . $url . "\n";
        try {
            $request = $client->post($url, array('Accept-Encoding' => 'gzip,deflate,sdch'), $formData);
            $response = $request->send();

            $measurementsSets = array_merge(
                $measurementsSets,
                $this->parseFoodDiary($response->getBody(), $fromTime, $parseAll)
            );
        } catch (BadResponseException $e) {
            echo " [ERROR] Bad respose: " . $e->getMessage() . "\n";
        }

        /*
        **	Get diabetes and exercises measurements
        */
        $formData = array(
            'numOfDays' => $period,
            'checkedTrackers' => '0',
            'checkedLabels' => '',
            'allTrackers' => '',
            'allLabels' => '',
            'event' => 'period',
            'intervalNum' => 0,
            'showEntryNotes' => false
        );

        $url = $this->endpoints['diabetesDiary2'];
        echo " [INFO] Request to: " . $url . "\n";
        try {
            $request = $client->post($url, array('Accept-Encoding' => 'gzip,deflate,sdch'), $formData);
            $response = $request->send();
            $measurementsSets = array_merge(
                $measurementsSets,
                $this->parseDiabetesExerciseDiary($response->getBody(), $parseAll, $timeDiffDays)
            );
        } catch (BadResponseException $e) {
            echo " [ERROR] Bad respose: " . $e->getMessage() . "\n";
        }

        return $measurementsSets;
    }

    private function getGuzzleClient($username, $password)
    {
        // This'll hold the cookies for this session
        $cookieJar = new ArrayCookieJar();
        $cookiePlugin = new CookiePlugin($cookieJar);

        // Add the cookie plugin to a client
        $client = new Client('https://www.mynetdiary.com');
        $client->addSubscriber($cookiePlugin);

        try {
            $loginParameters = array(
                'logonName' => $username,
                'password' => $password,
                'cmdOK' => 'Secure Sign In'
            );

            // Post login parameters
            $request = $client->post(
                self::$URL_LOGIN,
                null,
                $loginParameters,
                array('allow_redirects' => false)
            );
            $response = $request->send();

            // Check if we can redirect to daily.do, if not the username or password was incorrect
            if (strpos($response->getRawHeaders(), 'daily.do') !== false) {
                // Set the cookies we got to the root, as if we went to mynetdiary.com/ before anything else
                $cookies = $cookieJar->all();
                foreach ($cookies as $cookie) {
                    $cookie->setPath("/");
                }

                // We're done here, return the client
                return $client;
            } else {
                $this->disconnect();
                return "Invalid username or password";
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    //$parseAll: true to fetch all the records if this is the history run, false otherwise
    //$days: nymber of days since the last update

    /**
     * @param EntityBodyInterface $page
     * @param int $fromTime
     * @param bool $parseAll
     *
     * @return MeasurementSet[]
     */
    private function parseFoodDiary($page, $fromTime, $parseAll = false)
    {
        $measurementsSets = array();
        $ingredients = array();
        $matches = array();
        $i = 1;
        $pqdoc = phpQuery::newDocument($page);
        $ingredient_cells = pq('#divReportColHeaders td:gt(0)', $pqdoc);

        foreach ($ingredient_cells as $ingred_cell) {
            if ($i == 2 || (strpos(pq($ingred_cell)->text(), "Time") !== false)) {
                $i++;
                continue;
            } //ignore food score and time cells
            preg_match("#([\w\s\.-]+)\s*(?:<br/?>([\w%]+))?#", pq($ingred_cell)->html(), $matches);
            if (isset($matches[1])) {
                $name = $matches[1];
            } else {
                continue;
            }
            if ($name == 'Calories') {
                $unit = 'cal';
            } elseif (isset($matches[2])) {
                $unit = strpos($matches[2], '%') !== false ? '%RDA' : trim($matches[2]);
            } else {
                $unit = 'count';
            }
            $index = $i++;
            $ingredients[$index] = array('name' => $name, 'unit' => $unit);
        }

        //Find Days
        preg_match_all('/(<tr class="day">[\w\W]+?)(?=<tr class="day">|<\/tbody>)/', $page, $matches);
        $days = $matches[1];

        foreach ($days as $day) {
            $mealsRows = array();        //meals details of this day
            preg_match("/<span class='dailyDateNoLink'[^>]*>([^<]+)/", $day, $matches);
            $date = $matches[1];
            preg_match("/date=(\d{4})/", $day, $matches);
            if (isset($matches[1])) {
                $year = $matches[1];
            } else {
                $year = date('Y');
            }

            $date .= ' ' . $year;

            // if this is not a history run and the date of this day is less than the date of the last update,
            // skip this day
            if (!$parseAll && (strtotime($date) <= $fromTime)) {
                continue;
            }

            //Find Meals
            preg_match(
                '/(<tr>\s*<td class="meal"[^>]*>Breakfast[\w\W]*?)(?=<tr>\s*<td class="meal"[^>]*>Lunch|$)/',
                $day,
                $matches
            );
            if (isset($matches[1])) {
                $mealsRows['Breakfast'] = array('rows' => $this->cleanHtml($matches[1]), 'hour' => '8am');
            }

            preg_match(
                '/(<tr>\s*<td class="meal"[^>]*>Lunch[\w\W]*?)(?=<tr>\s*<td class="meal"[^>]*>Dinner|$)/',
                $day,
                $matches
            );
            if (isset($matches[1])) {
                $mealsRows['Lunch'] = array('rows' => $this->cleanHtml($matches[1]), 'hour' => '12pm');
            }

            preg_match(
                '/(<tr>\s*<td class="meal"[^>]*>Dinner[\w\W]*?)(?=<tr>\s*<td class="meal"[^>]*>Snacks|$)/',
                $day,
                $matches
            );
            if (isset($matches[1])) {
                $mealsRows['Dinner'] = array('rows' => $this->cleanHtml($matches[1]), 'hour' => '6pm');
            }

            preg_match('/(<tr>\s*<td class="meal"[^>]*>Snacks[\w\W]*)/', $day, $matches);
            if (isset($matches[1])) {
                $mealsRows['Snacks'] = array('rows' => $this->cleanHtml($matches[1]), 'hour' => '3pm');
            }

            foreach ($mealsRows as $mealdetails) {
                foreach ($ingredients as $index => $ingredetails) {
                    $meal_timestamp = strtotime($date . ' ' . $mealdetails['hour']);
                    preg_match_all('#<td>([\w\W]*?)</td>#', $mealdetails['rows'], $matches);
                    if (isset($matches[1][$index])) {
                        $meal_value = intval($matches[1][$index]);
                        if ($meal_value == 0) {
                            continue;
                        }
                        $measurement = new Measurement($meal_timestamp, $meal_value);
                        $measurementsSets[] = new MeasurementSet(
                            $ingredetails['name'],
                            'Nutrition',
                            $ingredetails['unit'],
                            $this->displayName,
                            'SUM',
                            array($measurement)
                        );
                    }
                }

                //Find Foods
                preg_match_all(
                    '#<tr>\s*<td>([^><]+)</td>\s*<td>[^><]*</td>\s*<td>(\d+)\w*</td>#',
                    $mealdetails['rows'],
                    $matches
                );
                for ($i = 1; $i < count($matches[0]); $i++) {
                    $food = $matches[1][$i];
                    $amount = intval($matches[2][$i]);
                    if ($food && $amount) {
                        $measurement = new Measurement(strtotime($date . ' ' . $mealdetails['hour']), $amount);
                        $measurementsSets[] = new MeasurementSet(
                            $food,
                            'Foods',
                            'g',
                            $this->displayName,
                            'SUM',
                            array($measurement)
                        );
                    }

                }
            }
        }

        return $measurementsSets;
    }

    //$parseAll: true to fetch all the records if this is the history run, false otherwise
    //$days: the days since the last update

    /**
     * @param EntityBodyInterface $page
     * @param bool $parseAll
     * @param int $days
     *
     * @return MeasurementSet[]
     */
    private function parseDiabetesExerciseDiary($page, $parseAll = false, $days = 1)
    {
        $doc = phpQuery::newDocument($page);
        $matches = array();
        $measurementsSets = array();
        $rows = pq('tr:gt(0)', $doc);
        $i = 1;
        foreach ($rows as $row) {
            if (!$parseAll && $i++ > $days) {
                break;
            }
            $cells = pq('td', $row);
            $date = '';
            foreach ($cells as $cell) {
                if (strtotime(pq($cell)->text())) {
                    $date = pq($cell)->text();
                }
                $trackers = pq('div', $cell);
                foreach ($trackers as $tracker) {
                    if (strpos(pq($tracker)->text(), 'kcal') !== false) {  //This is exercise entry
                        preg_match(
                            '#(\d+:\d+(?:PM|AM))?\s*<strong>([\w\W]+)</strong>:\s*([\d\.]+)?([\w]+) (\d+)kcal#',
                            pq($tracker)->html(),
                            $matches
                        );
                        if (!isset($matches[0])) {
                            //if no matches found, skip
                            continue;
                        }
                        $time = isset($matches[1]) ? $matches[1] : '';
                        $name = trim($matches[2]);
                        // if interval undefined and unit defined, then interval equals one
                        $interval = isset($matches[3]) ? intval($matches[3]) : 1;
                        $matches[4] = trim($matches[4]);
                        $unit = $matches[4] == 'hour' ? 'h' : $matches[4];
                        $cals = intval($matches[5]);
                        if ($cals == 0 || empty($name)) {
                            continue;
                        }
                        $timestamp = strtotime("$date $time");
                        $measurement = new Measurement($timestamp, $interval);
                        $measurementsSets[] = new MeasurementSet(
                            $name,
                            'Physical Activity',
                            $unit,
                            $this->displayName,
                            'SUM',
                            array($measurement)
                        );
                        $measurement = new Measurement($timestamp, $cals);
                        $measurementsSets[] = new MeasurementSet(
                            'Calories Burned',
                            'Physical Activity',
                            'kcal',
                            $this->displayName,
                            'SUM',
                            array($measurement)
                        );
                    } else {
                        //This is diabetes entry
                        preg_match(
                            '#(\d+:\d+(?:PM|AM))?\s*<strong>([\w\W]+)</strong>:\s*([\d\./]+)([\w/%]+)?#',
                            pq($tracker)->html(),
                            $matches
                        );
                        if (!isset($matches[0])) {
                            //if no matches found, skip
                            continue;
                        }
                        $time = $matches[1];
                        $name = trim($matches[2]);
                        // if unit undefined, set unit to 'count'
                        $unit = isset($matches[4]) ? ($matches[4] == 'lbs' ? 'lb' : $matches[4]) : 'count';
                        $timestamp = strtotime("$date $time");

                        if (strpos($name, 'pressure')) {
                            // This is a blood pressure tracker
                            $arr = explode('/', $matches[3]);
                            $measurement = new Measurement($timestamp, intval($arr[0]));
                            $measurementsSets[] = new MeasurementSet(
                                'Blood Pressure (Systolic)',
                                'Vital Signs',
                                $unit,
                                $this->displayName,
                                'SUM',
                                array($measurement)
                            );
                            $measurement = new Measurement($timestamp, intval($arr[1]));
                            $measurementsSets[] = new MeasurementSet(
                                'Blood Pressure (Diastolic)',
                                'Vital Signs',
                                $unit,
                                $this->displayName,
                                'SUM',
                                array($measurement)
                            );
                        } else {
                            $val = intval($matches[3]);
                            if ($val == 0) {
                                continue;
                            }
                            $measurement = new Measurement($timestamp, $val);
                            $measurementsSets[] = new MeasurementSet(
                                $name,
                                'Vital Signs',
                                $unit,
                                $this->displayName,
                                'SUM',
                                array($measurement)
                            );
                        }
                    }
                }
            }
        }

        return $measurementsSets;
    }

    /**
     * @param string $html
     *
     * @return string
     */
    private function cleanHtml($html)
    {
        return preg_replace(
            array("#\w+='[\w\W]+?'#", '#\w+="\w+"#', '#&nbsp;#', '#\s*(?=>)#', '#<b>#', '#</b>#', '#<img\s*/>#'),
            '',
            $html
        );
    }
}
