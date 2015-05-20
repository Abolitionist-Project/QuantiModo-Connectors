<?php

namespace Quantimodo\Connectors;

use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Client\GuzzleClient;
use OAuth\Common\Http\Exception\GuzzleException;
use OAuth\ServiceFactory;
use Quantimodo\Messaging\Messages\ErrorResponseMessage;
use Quantimodo\Messaging\Messages\RedirectResponseMessage;
use Quantimodo\Messaging\Messages\ResponseMessage;
use Quantimodo\PhpConnect\Model\Connectors\ConnectInstructions;
use Quantimodo\PhpConnect\Model\Connectors\Connector;
use Quantimodo\PhpConnect\Model\Measurement;
use Quantimodo\PhpConnect\Model\MeasurementSet;
use Quantimodo\PhpConnect\PhpConnect;

class FacebookConnector extends Connector
{
    private static $CONNECTOR_NAME = "facebook";

    // Dev Console: https://developers.facebook.com/apps/593060094090917/settings/basic/
    // Contact m@thinkbynumbers.org to add you to the app admins
    // Test User: You
    // Test PW: Yours

    // NOTE: These local.quantimo.do keys are overwritten if you have values in the database.
    private static $CLIENT_ID = '593060094090917';
    private static $CLIENT_SECRET = '80cb4638ec18d087d8624869c421bea8';
    private static $SCOPES = array('read_stream', 'user_likes', 'user_status');

    private static $URL_PAGELIKES = 'https://graph.facebook.com/me/likes?fields=created_time&limit=_LIMIT_';
    private static $URL_POSTS = 'https://graph.facebook.com/me/statuses?fields=updated_time&limit=1000';

    private $facebookService;

    public function __construct($connectionManager, $credentialsManager, $baseUrl)
    {
        parent::__construct($connectionManager, $credentialsManager, self::$CONNECTOR_NAME, $baseUrl);
        $this->initService(); // Init service to make sure $facebookService is populated for getConnectInstructions
    }

    public function getConnectInstructions()
    {
        $parameters = array();
        $url = $this->facebookService->getAuthorizationUri()->getAbsoluteUri();
        $usePopup = true;

        return new ConnectInstructions($url, $parameters, $usePopup);
    }

    public function connect($parameters)
    {
        $this->initService();

        if (empty($parameters['code'])) {
            $url = $this->facebookService->getAuthorizationUri()->getAbsoluteUri();

            return new RedirectResponseMessage($this->name, 'connect', $url);
        } else {
            try {
                $accessToken = $this->facebookService->requestAccessToken($parameters['code']);
                if ($accessToken != null) {
                    echo " [INFO] Received access token\n";

                    return new ResponseMessage(PhpConnect::$currentUserId, $this->name . '/connect');
                } else {
                    echo " [ERROR] Couldn't get Facebook access token from code\n";

                    return new ErrorResponseMessage(
                        PhpConnect::$currentUserId,
                        $this->name . '/connect',
                        500,
                        "Couldn't connect",
                        "Error during the connecting process,
                         Facebook failed to return an access token"
                    );
                }
            } catch (GuzzleException $e) {
                echo " [ERROR] Couldn't get RunKeeper access token: " . $e->getMessage() . "\n";

                return new ErrorResponseMessage(
                    PhpConnect::$currentUserId,
                    $this->name . '/connect',
                    500,
                    "Couldn't connect",
                    "Couldn't connect to Facebook, please try again!"
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

        $allMeasurementSets = array();

        $allMeasurementSets[] = $this->getPageLikes($fromTime);
        $allMeasurementSets[] = $this->getPosts($fromTime);
        //$allMeasurementSets[] = $this->getPostLikes($fromTime);
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
        $this->facebookService = $serviceFactory->createService(
            'facebook',
            $credentials,
            $this->credentialsManager->tokenCredentials,
            self::$SCOPES
        );
    }

    /**
     * Gets Facebook likes
     *
     * @param int $fromTime
     *
     * @return MeasurementSet
     */
    private function getPageLikes($fromTime)
    {
        $timeDiffSeconds = time() - $fromTime;
        $timeDiffDays = round($timeDiffSeconds / (60 * 60 * 24));
        $likesPerRequest = max(10, min(1000, $timeDiffDays * 20)); // Request a reasonable number of likes per day
        $nextPage = str_replace("_LIMIT_", $likesPerRequest, self::$URL_PAGELIKES);

        $likesMeasurements = array();

        // Loop through the feed as long as the activities are newer
        // than our last update and we have a nextPage
        while ($nextPage != null) {
            try {
                $responseBody = $this->facebookService->request($nextPage);
            } catch (GuzzleException $e) {
                $responseBody = null;
            }

            $guzzleResponse = $this->facebookService->getHttpClient()->getLastResponse();
            $statusCode = $guzzleResponse->getStatusCode();

            switch ($statusCode) {
                case 200:
                    $responseObject = json_decode($responseBody);
                    if (property_exists($responseObject, 'paging')
                        && property_exists($responseObject->paging, 'next')
                    ) {
                        $nextPage = $responseObject->paging->next;
                        echo " [INFO] Next page: " . $nextPage . "\n";
                    } else {
                        $nextPage = null;
                        echo " [INFO] No more pages available\n";
                    }

                    foreach ($responseObject->data as $currentPageLike) {
                        $timestamp = strtotime($currentPageLike->created_time);
                        if ($timestamp < $fromTime) {
                            $nextPage = null;
                            break;
                        }
                        $likesMeasurements[] = new Measurement($timestamp, 1);
                    }
                    break;
                case 403:
                    echo " [INFO] Token is no longer valid\n";
                    $this->disconnect();
                    $nextPage = null;
                    break;
            }
        }

        $measurementSet = new MeasurementSet(
            'Facebook Likes',
            'Activity',
            'event',
            $this->displayName,
            "SUM",
            $likesMeasurements
        );

        return $measurementSet;
    }

    /**
     * Gets Facebook posts
     * Thanks to Graph API limitations we can only get the most recent 100 posts
     *
     * @param int $fromTime
     *
     * @return MeasurementSet
     */
    private function getPosts($fromTime)
    {
        // Time-based pagination
        $nextPage = self::$URL_POSTS . "&since=" . $fromTime;

        $postMeasurements = array();

        // Loop through the feed as long as the activities are newer
        // than our last update and we have a nextPage
        while ($nextPage != null) {
            $responseBody = $this->facebookService->request($nextPage);
            $guzzleResponse = $this->facebookService->getHttpClient()->getLastResponse();
            $statusCode = $guzzleResponse->getStatusCode();

            switch ($statusCode) {
                case 200:
                    $responseObject = json_decode($responseBody, false);
                    if (property_exists($responseObject, 'paging')
                        && property_exists($responseObject->paging, 'next')
                    ) {
                        $nextPage = $responseObject->paging->next;
                        echo " [INFO] Next page: " . $nextPage . "\n";
                    } else {
                        $nextPage = null;
                        echo " [INFO] No more pages available\n";
                    }

                    foreach ($responseObject->data as $currentPost) {
                        $timestamp = strtotime($currentPost->updated_time);
                        if ($timestamp < $fromTime) {
                            $nextPage = null;
                            break;
                        }
                        $postMeasurements[] = new Measurement($timestamp, 1);
                    }

                    break;
                case 403:
                    echo " [INFO] Token is no longer valid\n";
                    $this->disconnect();
                    $nextPage = null;
                    break;
            }
        }

        $measurementSet = new MeasurementSet(
            'Facebook Posts',
            'Activity',
            'event',
            $this->displayName,
            "SUM",
            $postMeasurements
        );

        return $measurementSet;
    }
}
