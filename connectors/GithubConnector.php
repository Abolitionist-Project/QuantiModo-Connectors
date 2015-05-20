<?php

namespace Quantimodo\Connectors;

use DateTime;
use DateTimeZone;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class GithubConnector extends Connector
{
    // Keys for callback https://local.quantimo.do/api/connectors/github/connect
    // Dev Console: https://github.com/settings/applications/new
    // Contact m@thinkbynumbers.org to add you to the app admins
    // Test User: You
    // Test PW: Yours

    private static $CONNECTOR_NAME = "github";

    // NOTE: These local.quantimo.do keys are overwritten if you have values in the database.
    private static $CLIENT_ID = '878eaff7e4bddd153dcd';
    private static $CLIENT_SECRET = 'd7dd541b35123b5513924baeb1550f0c3e9a00d6';
    private static $SCOPES = array('user', 'repo');

    private $githubService;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
        $this->initService();    // Init service to make sure $githubService is populated for getConnectInstructions
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        $url = $this->githubService->getAuthorizationUri()->getAbsoluteUri();
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['code'])) {
            $url = $this->githubService->getAuthorizationUri()->getAbsoluteUri();

            return new RedirectResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect', $url);
        } else {
            try {
                try {
                    $accessToken = $this->githubService->requestAccessToken($parameters['code']);
                } catch (TokenResponseException $e) {
                    $accessToken = null;
                }

                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get Github access token from code\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process,
                         Github failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get Github access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Github, please try again!"
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

        $loginName = $this->getLoginName();
        echo " [INFO] Login name: " . $loginName . "\n";

        $allMeasurementSets = array();

        $allMeasurementSets[] = $this->getCommits($fromTime, $loginName);

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
        $this->githubService = $serviceFactory->createService(
            'GitHub',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            self::$SCOPES
        );
    }

    private function getLoginName()
    {
        $userResponseBody = $this->githubService->request("https://api.github.com/user");
        $userResponseObject = json_decode($userResponseBody);

        return $userResponseObject->login;
    }

    /**
     * Get fitness activity (cardio)
     *
     * @param int $fromTime
     * @param string $loginName
     *
     * @return MeasurementSet
     */
    private function getCommits($fromTime, $loginName)
    {
        // Convert fromTime to ISO8601 for Github's api
        $datetime = new DateTime();
        $datetime = $datetime->setTimezone(new DateTimeZone('UTC'));
        $datetime = $datetime->setTimestamp($fromTime);
        $fromTimeString = $datetime->format(DateTime::ISO8601);

        $measurements = array();

        try {
            // Get the user's repos
            $repoResponseBody = $this->githubService->request("https://api.github.com/user/repos");
            $repoResponseObject = json_decode($repoResponseBody);

            // Loop through repositories
            $repoCount = count($repoResponseObject);
            for ($i = 0; $i < $repoCount; $i++) {
                $repository = $repoResponseObject[$i];
                echo " [INFO] Getting commits for repo: " . $repository->full_name . "\n";

                // Set nextPage to the first one.
                $nextPage = "https://api.github.com/repos/" . $repository->full_name . "/commits?author="
                    . $loginName . "&per_page=999&since=" . $fromTimeString;
                while ($nextPage != null) {
                    try {
                        // Request commits for this repository
                        $commitsResponseBody = $this->githubService->request($nextPage);
                        $commitsResponseObj = json_decode($commitsResponseBody);

                        // Loop through commits, and store them with timestamp as a measurement
                        foreach ($commitsResponseObj as $commitObject) {
                            $timestamp = strtotime($commitObject->commit->author->date);
                            $measurements[] = new Measurement($timestamp, 1);
                        }

                        // Check if there's a next page. The next page is sent via the 'link' header with ref 'next'
                        $guzzleResponse = $this->githubService->getHttpClient()->getLastResponse();
                        $linkHeader = $guzzleResponse->getHeader('link');
                        if ($linkHeader != null) {
                            $linkObject = $linkHeader->getLink('next');
                            $nextPage = $linkObject['url'];
                        } else {
                            $nextPage = null;
                        }
                    } catch (GuzzleException $e) {
                        // Get the last response to figure out what went wrong
                        $guzzleResponse = $this->githubService->getHttpClient()->getLastResponse();
                        $statusCode = $guzzleResponse->getStatusCode();
                        if ($statusCode == 409) {
                            // Repository is empty
                            $nextPage = null;
                        } else {
                            echo " [ERROR] Exception during Github update: " . $e->getMessage() . "\n";
                            echo " [ERROR] Github says: " . $guzzleResponse->getBody() . "\n";
                            $nextPage = null;
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            // Get the last response to figure out what went wrong when getting the repositories
            $guzzleResponse = $this->githubService->getHttpClient()->getLastResponse();

            echo " [ERROR] Exception during Github update: " . $e->getMessage() . "\n";
            echo " [ERROR] Github says: " . $guzzleResponse->getBody() . "\n";
        }

        $measurementSet = new MeasurementSet('Commits', 'Activity', 'event', $this->displayName, "SUM", $measurements);

        return $measurementSet;
    }
}
