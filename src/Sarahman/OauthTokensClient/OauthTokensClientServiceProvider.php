<?php

namespace Sarahman\OauthTokensClient;

use Config;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class OauthTokensClientServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->package('sarahman/oauth-tokens-client');

        include __DIR__ . '/../../config/config.php';
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
