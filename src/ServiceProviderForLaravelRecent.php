<?php

namespace Sarahman\OauthTokensClient;

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
    }
}
