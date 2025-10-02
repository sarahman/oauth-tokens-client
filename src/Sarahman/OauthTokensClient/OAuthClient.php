<?php

namespace Sarahman\OauthTokensClient;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Cache\Repository as CacheRepository;
use RuntimeException;
use Sarahman\HttpRequestApiLog\Traits\WritesHttpLogs;

class OAuthClient
{
    use WritesHttpLogs;

    const MAX_RETRY_ATTEMPTS = 3;
    const RETRY_DELAY_MS = 1000;
    const LOCK_WAIT_MS = 50000; // 50ms
    const LOCK_TTL_SECONDS = 10;

    private static $accessTokenKey;
    private static $refreshTokenKey;
    private static $lockKey;

    /** @var Client */
    private $httpClient;

    /** @var CacheRepository */
    private $cache;

    private $tokenUrl;
    private $refreshUrl;
    private $grantType;
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $scope = '';

    public function __construct(Client $httpClient, CacheRepository $cache, array $oauthConfig, array $tokenPrefixes, $lockKey)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->tokenUrl = $oauthConfig['TOKEN_URL'];
        $this->refreshUrl = $oauthConfig['REFRESH_URL'];
        $this->grantType = $oauthConfig['GRANT_TYPE'];
        $this->clientId = $oauthConfig['CLIENT_ID'];
        $this->clientSecret = $oauthConfig['CLIENT_SECRET'];
        $this->username = $oauthConfig['USERNAME'];
        $this->password = $oauthConfig['PASSWORD'];
        $this->scope = $oauthConfig['SCOPE'];

        self::$accessTokenKey = $tokenPrefixes['ACCESS'];
        self::$refreshTokenKey = $tokenPrefixes['REFRESH'];
        self::$lockKey = $lockKey;
    }

    public function request($method, $uri, array $options = array(), $retryCount = 1)
    {
        isset($options['headers']) || $options['headers'] = array();
        $options['headers'] = array_merge($options['headers'], $this->getHeaders());
        $options['headers']['Authorization'] = "Bearer {$this->getAccessToken()}";

        try {
            $response = $this->httpClient->request($method, $uri, $options);

            if ($response && $response->getStatusCode() === 401 && $retryCount > 0) {
                throw new RequestException('Unauthorized Access Token!', new GuzzleRequest($method, $uri, $options['headers'], isset($options['json']) ? json_encode($options['json']) : null), $response);
            }

            $this->log($method, $uri, $options, new GuzzleResponse($response->getStatusCode(), [], $response->getBody()));

            return $response;
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response && $response->getStatusCode() === 401 && $retryCount > 0) {
                $options['headers']['Authorization'] = "Bearer {$this->refreshAccessToken()}";

                return $this->request($method, $uri, $options, $retryCount - 1);
            }

            $this->log($method, $uri, $options, new GuzzleResponse($e->getCode(), [], $response->getBody()));

            throw $e;
        }
    }

    private function getHeaders()
    {
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function getAccessToken($waitForToken = true)
    {
        $token = $this->cache->get(self::$accessTokenKey);

        if ($token) {
            return $token;
        }

        if ($waitForToken) {
            $this->waitForLock();

            return $this->getAccessToken(false);
        }

        return $this->refreshAccessToken();
    }

    private function waitForLock()
    {
        while ($this->cache->has(self::$lockKey)) {
            usleep(self::LOCK_WAIT_MS);
        }
    }

    private function refreshAccessToken()
    {
        if ($this->cache->has(self::$lockKey)) {
            $this->waitForLock();

            return $this->cache->get(self::$accessTokenKey);
        }

        $this->cache->put(self::$lockKey, true, self::LOCK_TTL_SECONDS);

        try {
            $token = '';
            $refreshToken = $this->cache->get(self::$refreshTokenKey);

            if (!$refreshToken) {
                $token = $this->fetchAccessTokenWithRetry();
            } else {
                $response = $this->httpClient->post($this->refreshUrl, $options = array(
                    'headers' => $this->getHeaders(),
                    'json'    => array(
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'client_id'     => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope'         => $this->scope,
                    ),
                ));

                if ($response && $response->getStatusCode() === 401) {
                    throw new RequestException('Unauthorized Access Token!', new GuzzleRequest('post', $this->refreshUrl, $options['headers'], json_encode($options['json'])), $response);
                }

                $this->log('POST', $this->refreshUrl, $options, new GuzzleResponse($response->getStatusCode(), [], $response->getBody()));

                $token = $this->parseAndStoreTokens($response);
            }
        } catch (RequestException $e) {
            $response = $e->getResponse();

            $this->log('POST', $this->refreshUrl, empty($options) ? [] : $options, new GuzzleResponse($e->getCode(), [], $response->getBody()));

            if ($response && $response->getStatusCode() !== 401) {
                $this->cache->forget(self::$lockKey);
                throw $e;
            }

            $token = $this->fetchAccessTokenWithRetry();
        } catch (Exception $e) {
            $this->cache->forget(self::$lockKey);
            throw $e;
        }

        $this->cache->forget(self::$lockKey);

        return $token;
    }

    private function fetchAccessTokenWithRetry()
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            $attempt++;

            try {
                return $this->fetchInitialTokens();
            } catch (Exception $e) {
                $lastException = $e;

                // Log the retry attempt
                error_log(sprintf('OAuth token fetch attempt %d/%d failed: %s', $attempt, self::MAX_RETRY_ATTEMPTS, $e->getMessage()));

                // Don't sleep on the last attempt
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        // All retry attempts failed
        throw new RuntimeException(
            sprintf(
                'Failed to fetch OAuth access token after %d attempts. Last error: %s',
                self::MAX_RETRY_ATTEMPTS,
                $lastException ? $lastException->getMessage() : 'Unknown error'
            ),
            0,
            $lastException
        );
    }

    private function fetchInitialTokens()
    {
        $params = array(
            'grant_type'    => $this->grantType,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scope,
        );

        if ('password' === $this->grantType && $this->username && $this->password) {
            $params = array_merge($params, [
                'username' => $this->username,
                'password' => $this->password,
            ]);
        }

        $response = $this->httpClient->post($uri = $this->tokenUrl, $options = array(
            'headers' => $this->getHeaders(),
            'json'    => $params,
        ));

        $this->log('POST', $uri, $options, new GuzzleResponse($response->getStatusCode(), [], $response->getBody()));

        if ($response && ($statusCode = $response->getStatusCode()) === 401) {
            throw new Exception('Something went wrong while trying to fetch initial tokens.', $statusCode);
        }

        return $this->parseAndStoreTokens($response);
    }

    /**
     * Parses the OAuth token response and stores the tokens.
     *
     * @param \GuzzleHttp\Message\ResponseInterface $response The response containing OAuth tokens to be parsed and stored.
     *
     * @return string
     */
    private function parseAndStoreTokens($response)
    {
        $data = json_decode((string) $response->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['access_token'])) {
            return '';
        }

        $this->storeTokens($data);

        return $data['access_token'];
    }

    private function storeTokens(array $data)
    {
        if (!isset($data['access_token']) || !isset($data['expires_in'])) {
            return;
        }

        $this->cache->put(self::$accessTokenKey, $data['access_token'], max(1, (int) $data['expires_in'] - 30));
        isset($data['refresh_token']) && $this->cache->forever(self::$refreshTokenKey, $data['refresh_token']);
    }
}
