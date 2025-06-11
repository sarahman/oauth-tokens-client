# Oauth Tokens Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sarahman/oauth-tokens-client.svg?style=flat-square)](https://packagist.org/packages/sarahman/oauth-tokens-client)
[![Build Status](https://img.shields.io/travis/com/sarahman/oauth-tokens-client/master.svg?style=flat-square)](https://travis-ci.org/sarahman/oauth-tokens-client)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/sarahman/oauth-tokens-client/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/sarahman/oauth-tokens-client/?branch=master)
[![StyleCI](https://styleci.io/repos/999174630/shield)](https://styleci.io/repos/999174630)
[![Total Downloads](https://img.shields.io/packagist/dt/sarahman/oauth-tokens-client.svg?style=flat-square)](https://packagist.org/packages/sarahman/oauth-tokens-client)
[![License](http://poser.pugx.org/sarahman/oauth-tokens-client/license)](https://packagist.org/packages/sarahman/oauth-tokens-client)
[![PHP Version Require](http://poser.pugx.org/sarahman/oauth-tokens-client/require/php)](https://packagist.org/packages/sarahman/oauth-tokens-client)

PHP library built by PSR-16 simple cache interface can be used in any php project or it has laravel support to install through its service provider.

## Installation

- Step 1: You can install the package via composer:

``` bash
composer require sarahman/oauth-tokens-client
```

- Step 2.a: Next, for the laravel projects, we can load its service provider:

```php
// app/config/app.php
'providers' => [
    ...
    'Sarahman\OauthTokensClient\OauthTokensClientServiceProvider',
];
```

You can publish the config file with:

```bash
php artisan config:publish sarahman/oauth-tokens-client
```

This is the contents of the published config file:

```php

return array(
    'TOKEN_PREFIXES'   => array(
        'ACCESS'  => 'oauth_access_token',
        'REFRESH' => 'oauth_refresh_token',
    ),
    'LOCK_KEY'         => 'oauth_token_refresh_lock',
    'OAUTH_CREDENTIAL' => array(
        'tokenUrl'     => null,
        'refreshUrl'   => null,
        'clientId'     => null,
        'clientSecret' => null,
    ),
);
```

- Step 2.b: for the regular php projects, we might directly add these following codes:

```php

require "vendor/autoload.php";

$clientConfig = array(
    'TOKEN_PREFIXES'   => array(
        'ACCESS'  => 'oauth_access_token',
        'REFRESH' => 'oauth_refresh_token',
    ),
    'LOCK_KEY'         => 'oauth_token_refresh_lock',
    'OAUTH_CREDENTIAL' => array(
        'tokenUrl'     => 'http://localhost/grant-token',
        'refreshUrl'   => 'http://localhost/refresh-token',
        'clientId'     => 1,
        'clientSecret' => '**********',
    ),
);

$client = new OAuthClient(
    new Client,
    <CACHE_STORE>,
    $clientConfig['OAUTH_CREDENTIAL'],
    $clientConfig['TOKEN_PREFIXES'],
    $clientConfig['LOCK_KEY']
);

// Set Cache key.
$data = array(
    'sample' => 'data',
    'another' => 'data',
);

$response = $client->request('POST', 'http://localhost/get-user', $data);

var_dump($response);
```

## Testing

You might go to the project directory and run the following command to run test code.

``` bash
composer test
```

## Contribution

Feel free to contribute in this library. Please make your changes and send us [pull requests](https://github.com/sarahman/oauth-tokens-client/pulls).

## Security Issues

If you discover any security related issues, please feel free to create an issue in the [issue tracker](https://github.com/oauth-tokens-client/issues) or write us at [aabid048@gmail.com](mailto:aabid048@gmail.com).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
