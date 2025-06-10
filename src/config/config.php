<?php

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
