<?php

if (empty($argv[1])) {
    die('Usage: php make-password.php <password>');
}
$hash = base64_encode(password_hash($argv[1], PASSWORD_DEFAULT));

// Lamb admin
$data = "LAMB_LOGIN_PASSWORD='" . $hash . "'" . PHP_EOL;
$out = file_put_contents('.ddev/.env', $data);
if ($out) {
    echo $hash;
} else {
    user_error('Problem saving .ddev/.env', E_USER_WARNING);
}

// Codeception
// DDEV detection with local fallback
$site_url = $_ENV['DDEV_PRIMARY_URL'] ?? 'http://0.0.0.0:8747';

if ($_ENV['PWD'] === '/srv/app') {
    // Docker override
    $site_url = 'http://lamb-web';
}

$data = "SITE_URL='" . $site_url . "'" . PHP_EOL;
file_put_contents('.env', $data);
if (!$out) {
    user_error('Problem saving .env', E_USER_WARNING);
}
