<?php

namespace Sarahman\OauthTokensClient;

use Config;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class OauthTokensClientServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->package('sarahman/oauth-tokens-client', null, __DIR__ . '/../..');
        $this->package('sarahman/laravel-http-request-api-log', null, __DIR__ . '/../../../../laravel-http-request-api-log/src');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app['oauth-tokens-client.service'] = $this->app->share(function ($app) {
            return new OAuthClient(
                new Client,
                $app['cache.store'],
                Config::get('oauth-tokens-client::OAUTH_CREDENTIAL'),
                Config::get('oauth-tokens-client::TOKEN_PREFIXES'),
                Config::get('oauth-tokens-client::LOCK_KEY')
            );
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('oauth-tokens-client.service');
    }
}
