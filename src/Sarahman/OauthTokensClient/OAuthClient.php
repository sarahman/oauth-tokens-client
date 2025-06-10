<?php

namespace Sarahman\OauthTokensClient;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Cache\Repository as CacheRepository;

class OAuthClient
{
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
        $options['headers']['Authorization'] = "Bearer {$this->getAccessToken()}";

        try {
            return $this->httpClient->request($method, $uri, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response && $response->getStatusCode() === 401 && $retryCount > 0) {
                $options['headers']['Authorization'] = "Bearer {$this->refreshAccessToken()}";

                return $this->request($method, $uri, $options, $retryCount - 1);
            }

            throw $e;
        }
    }

    private function getAccessToken()
    {
        $token = $this->cache->get(self::$accessTokenKey);

        if ($token) {
            return $token;
        }

        while ($this->cache->has(self::$lockKey)) {
            usleep(50000); // wait 50ms
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken()
    {
        if ($this->cache->has(self::$lockKey)) {
            while ($this->cache->has(self::$lockKey)) {
                usleep(50000);
            }

            return $this->cache->get(self::$accessTokenKey);
        }

        $this->cache->put(self::$lockKey, true, 10);

        try {
            $token = '';
            $refreshToken = $this->cache->get(self::$refreshTokenKey);

            if (!$refreshToken) {
                $token = $this->fetchInitialTokens();
            } else {
                $response = $this->httpClient->post($this->refreshUrl, array(
                    'form_params' => array(
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'client_id'     => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope'         => $this->scope,
                    ),
                ));

                $token = $this->parseAndStoreTokens($response);
            }
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response && $response->getStatusCode() === 401) {
                return $this->fetchInitialTokens();
            }

            throw $e;
        } catch (Exception $e) {
            $this->cache->forget(self::$lockKey);
            throw $e;
        }

        $this->cache->forget(self::$lockKey);

        return $token;
    }

    private function fetchInitialTokens()
    {
        $params = array(
            'grant_type' => $this->grantType,
            'client_id'  => $this->clientId,
            'client_secret' => $this->clientSecret,
        );

        if ('password' === $this->grantType && $this->username && $this->password) {
            $params['username'] = $this->username;
            $params['password'] = $this->password;
        }

        $response = $this->httpClient->post($this->tokenUrl, array(
            'form_params' => $params,
        ));

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

        if (json_last_error()) {
            return '';
        }

        $this->storeTokens($data);

        return $data['access_token'];
    }

    private function storeTokens(array $data)
    {
        $this->cache->put(self::$accessTokenKey, $data['access_token'], $data['expires_in'] - 30);
        $this->cache->forever(self::$refreshTokenKey, $data['refresh_token']);
    }
}
