<?php

namespace Sarahman\OauthTokensClient;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

class OAuthClient
{
    private static $accessTokenKey;
    private static $refreshTokenKey;
    private static $lockKey;

    /** @var Client */
    private $httpClient;

    /** @var CacheInterface */
    private $cache;

    private $tokenUrl;
    private $refreshUrl;
    private $clientId;
    private $clientSecret;

    public function __construct(Client $httpClient, CacheInterface $cache, array $oauthConfig, array $tokenPrefixes, $lockKey)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->tokenUrl = $oauthConfig['TOKEN_URL'];
        $this->refreshUrl = $oauthConfig['REFRESH_URL'];
        $this->clientId = $oauthConfig['CLIENT_ID'];
        $this->clientSecret = $oauthConfig['CLIENT_SECRET'];

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

        $this->cache->set(self::$lockKey, true, 10);

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
                    ),
                ));

                $token = $this->parseAndStoreTokens($response);
            }
        } catch (Exception $e) {
            $this->cache->delete(self::$lockKey);
            throw $e;
        }

        $this->cache->delete(self::$lockKey);

        return $token;
    }

    private function fetchInitialTokens()
    {
        $response = $this->httpClient->post($this->tokenUrl, array(
            'form_params' => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ),
        ));

        return $this->parseAndStoreTokens($response);
    }

    private function parseAndStoreTokens(ResponseInterface $response)
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
        $this->cache->set(self::$accessTokenKey, $data['access_token'], $data['expires_in'] - 30);
        $this->cache->set(self::$refreshTokenKey, $data['refresh_token']);
    }
}
