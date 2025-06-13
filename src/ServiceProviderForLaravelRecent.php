<?php

namespace Sarahman\OauthTokensClient;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class ServiceProviderForLaravelRecent extends ServiceProvider
{
    public function boot()
    {
        // Publishes the configuration file
        $this->publishes([
            __DIR__ . '/config/config.php' => config_path('oauth-tokens-client.php'),
        ], 'config');
    }

    public function register()
    {
        // Merges default configuration
        $this->mergeConfigFrom(__DIR__ . '/config/config.php', 'oauth-tokens-client');

        $this->app->bind('oauth-tokens-client.service', function ($app) {
            $oauthTokenConfig = $app->make('config')->get('oauth-tokens-client');

            return new OAuthClient(
                new Client,
                $app['cache.store'],
                $oauthTokenConfig['OAUTH_CREDENTIAL'],
                $oauthTokenConfig['TOKEN_PREFIXES'],
                $oauthTokenConfig['LOCK_KEY']
            );
        });
    }
}
