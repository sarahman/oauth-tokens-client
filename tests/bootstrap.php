<?php

// Manually require Composer's autoloader if you're using Composer
$autoloadFile = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
} else {
    // Fallback: manually require your source files
    require_once __DIR__ . '/../src/OAuthClient.php';
    // require other files if needed
}
