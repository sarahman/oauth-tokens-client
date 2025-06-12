<?php

namespace Tests\Sarahman\OauthTokensClient;

use GuzzleHttp\Psr7\Response;
use PHPUnit_Framework_TestCase;
use ReflectionClass;
use Sarahman\OauthTokensClient\OAuthClient;

class OAuthClientTest extends PHPUnit_Framework_TestCase
{
    private $httpClient;
    private $cache;
    private $oauthClient;

    private $oauthConfig;
    private $tokenPrefixes;
    private $lockKey;

    protected function setUp()
    {
        $this->httpClient = $this->getMock('GuzzleHttp\Client');
        $this->cache = $this->getMock('Psr\SimpleCache\CacheInterface');

        $this->oauthConfig = array(
            'token_url'     => 'https://example.com/token',
            'refresh_url'   => 'https://example.com/refresh',
            'client_id'     => 'client_id',
            'client_secret' => 'client_secret',
        );

        $this->tokenPrefixes = array(
            'ACCESS'  => 'access_token_key',
            'REFRESH' => 'refresh_token_key',
        );

        $this->lockKey = 'oauth_lock';

        $this->oauthClient = new OAuthClient(
            $this->httpClient,
            $this->cache,
            $this->oauthConfig,
            $this->tokenPrefixes,
            $this->lockKey
        );
    }

    public function testRequestWithCachedAccessToken()
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->with('access_token_key')
            ->will($this->returnValue('cached_token'));

        $this->cache->method('has')
            ->with($this->lockKey)
            ->will($this->returnValue(false));

        $response = new Response(200, array(), 'ok');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/resource',
                $this->callback(array($this, 'assertHasAuthHeader'))
            )
            ->will($this->returnValue($response));

        $result = $this->oauthClient->request('GET', '/resource');
        $this->assertSame($response, $result);
    }

    public function assertHasAuthHeader($options)
    {
        return isset($options['headers']['Authorization']) &&
            $options['headers']['Authorization'] === 'Bearer cached_token';
    }

    public function testParseAndStoreTokensWithInvalidJson()
    {
        $response = new Response(200, array(), 'invalid-json');

        $reflection = new ReflectionClass($this->oauthClient);
        $method = $reflection->getMethod('parseAndStoreTokens');
        $method->setAccessible(true);

        $result = $method->invoke($this->oauthClient, $response);

        $this->assertSame('', $result);
    }

    public function testRefreshAccessTokenWithLock()
    {
        $this->cache->expects($this->exactly(2))
            ->method('has')
            ->with($this->lockKey)
            ->will($this->onConsecutiveCalls(true, false));

        $this->cache->expects($this->once())
            ->method('get')
            ->with('access_token_key')
            ->will($this->returnValue('token_after_wait'));

        $reflection = new ReflectionClass($this->oauthClient);
        $method = $reflection->getMethod('refreshAccessToken');
        $method->setAccessible(true);

        $token = $method->invoke($this->oauthClient);
        $this->assertEquals('token_after_wait', $token);
    }
}
