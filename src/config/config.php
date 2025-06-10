<?php

return array(
    'TOKEN_PREFIXES'   => array(
        'ACCESS'  => 'oauth_access_token',
        'REFRESH' => 'oauth_refresh_token',
    ),
    'LOCK_KEY'         => 'oauth_token_refresh_lock',
    'OAUTH_CREDENTIAL' => array(
        'GRANT_TYPE'    => 'client_credentials',
        'TOKEN_URL'     => null,
        'REFRESH_URL'   => null,
        'CLIENT_ID'     => null,
        'CLIENT_SECRET' => null,
        'USERNAME'      => null,
        'PASSWORD'      => null,
        'SCOPE'         => '',
    ),
);
