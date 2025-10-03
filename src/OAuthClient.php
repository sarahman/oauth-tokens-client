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

    const LOCK_WAIT_MS = 50000; // 50ms
    const LOCK_TTL_SECONDS = 10;
    const TOKEN_EXPIRY_BUFFER_SECONDS = 30;

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

    private $accessTokenKey;
    private $refreshTokenKey;
    private $lockKey;
    private $maxRetries;
    private $retryDelay;

    public function __construct(Client $httpClient, CacheRepository $cache, array $credentials, array $tokenPrefixes, $lockKey, $maxRetries = 3, $retryDelay = 1)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->tokenUrl = $credentials['TOKEN_URL'];
        $this->refreshUrl = $credentials['REFRESH_URL'];
        $this->grantType = $credentials['GRANT_TYPE'];
        $this->clientId = $credentials['CLIENT_ID'];
        $this->clientSecret = $credentials['CLIENT_SECRET'];
        $this->username = isset($credentials['USERNAME']) ? $credentials['USERNAME'] : null;
        $this->password = isset($credentials['PASSWORD']) ? $credentials['PASSWORD'] : null;
        $this->scope = isset($credentials['SCOPE']) ? $credentials['SCOPE'] : '';

        $this->accessTokenKey = $tokenPrefixes['ACCESS'];
        $this->refreshTokenKey = $tokenPrefixes['REFRESH'];
        $this->lockKey = $lockKey;
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
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

            $this->log($method, $uri, $options, new GuzzleResponse($response->getStatusCode(), $response->getHeaders(), $response->getBody()));

            return $response;
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response && $response->getStatusCode() === 401 && $retryCount > 0) {
                $options['headers']['Authorization'] = "Bearer {$this->refreshAccessToken()}";

                return $this->request($method, $uri, $options, $retryCount - 1);
            }

            $this->log($method, $uri, $options, new GuzzleResponse($e->getCode(), $response->getHeaders(), $response->getBody()));

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
        $token = $this->cache->get($this->accessTokenKey);

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
        while ($this->cache->has($this->lockKey)) {
            usleep(self::LOCK_WAIT_MS);
        }
    }

    private function refreshAccessToken()
    {
        if ($this->cache->has($this->lockKey)) {
            $this->waitForLock();

            return $this->cache->get($this->accessTokenKey);
        }

        $this->cache->put($this->lockKey, true, self::LOCK_TTL_SECONDS);

        try {
            $token = '';
            $refreshToken = $this->cache->get($this->refreshTokenKey);

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
                    throw new RequestException('Unauthorized Access Token!', new GuzzleRequest('post', $this->refreshUrl, $options['headers'], isset($options['json']) ? json_encode($options['json']) : null), $response);
                }

                $this->log('POST', $this->refreshUrl, $options, new GuzzleResponse($response->getStatusCode(), $response->getHeaders(), $response->getBody()));

                $token = $this->parseAndStoreTokens($response);
            }
        } catch (RequestException $e) {
            $response = $e->getResponse();

            $this->log('POST', $this->refreshUrl, empty($options) ? [] : $options, new GuzzleResponse($e->getCode(), $response->getHeaders(), $response->getBody()));

            if ($response && $response->getStatusCode() !== 401) {
                $this->cache->forget($this->lockKey);
                throw $e;
            }

            $token = $this->fetchAccessTokenWithRetry();
        } catch (Exception $e) {
            $this->cache->forget($this->lockKey);
            throw $e;
        }

        $this->cache->forget($this->lockKey);

        return $token;
    }

    private function fetchAccessTokenWithRetry()
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                return $this->fetchInitialTokens();
            } catch (Exception $e) {
                $lastException = $e;

                // Log the retry attempt
                error_log(sprintf('OAuth token fetch attempt %d/%d failed: %s', $attempt, $this->maxRetries, $e->getMessage()));

                // Don't sleep on the last attempt
                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelay * 1000000);
                }
            }
        }

        // All retry attempts failed
        throw new RuntimeException(
            sprintf(
                'Failed to fetch OAuth access token after %d attempts. Last error: %s',
                $this->maxRetries,
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

        $this->log('POST', $uri, $options, new GuzzleResponse($response->getStatusCode(), $response->getHeaders(), $response->getBody()));

        if ($response && ($statusCode = $response->getStatusCode()) === 401) {
            throw new Exception('Something went wrong while trying to fetch initial tokens.', $statusCode);
        }

        return $this->parseAndStoreTokens($response);
    }

    /**
     * Parses the OAuth token response and stores the tokens.
     *
     * @param \GuzzleHttp\Message\ResponseInterface|\Psr\Http\Message\ResponseInterface $response The response containing OAuth tokens to be parsed and stored.
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

        $this->cache->put($this->accessTokenKey, $data['access_token'], max(1, (int) $data['expires_in'] - self::TOKEN_EXPIRY_BUFFER_SECONDS));
        isset($data['refresh_token']) && $this->cache->forever($this->refreshTokenKey, $data['refresh_token']);
    }
}
