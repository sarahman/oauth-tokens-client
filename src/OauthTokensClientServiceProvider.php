<?php

namespace Sarahman\OauthTokensClient;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class OauthTokensClientServiceProvider extends ServiceProvider
{
    protected $provider;

    public function __construct($app)
    {
        parent::__construct($app);

        $this->provider = $this->getProvider();
    }

    public function boot()
    {
        if (method_exists($this->provider, 'boot')) {
            return $this->provider->boot();
        }
    }

    public function register()
    {
        if (method_exists($this->provider, 'register')) {
            return $this->provider->register();
        }
    }

    private function getProvider()
    {
        if (version_compare(Application::VERSION, '5.0', '<')) {
            $provider = '\Sarahman\OauthTokensClient\ServiceProviderForLaravel4';
        } else {
            $provider = '\Sarahman\OauthTokensClient\ServiceProviderForLaravelRecent';
        }

        return new $provider($this->app);
    }
}
