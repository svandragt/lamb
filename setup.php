<?php

if (empty($argv[1])) {
    die('Usage: php setup.php <password>');
}
$hash = base64_encode(password_hash($argv[1], PASSWORD_DEFAULT)) . PHP_EOL;

// Lamb admin
$data = "LAMB_LOGIN_PASSWORD='" . $hash . "'" . PHP_EOL;
$out = file_put_contents('.ddev/.env', $data);
if ($out) {
    echo $hash;
} else {
    user_error('Problem saving .ddev/.env', E_USER_WARNING);
}

// Codeception
$site_url = $_ENV['DDEV_PRIMARY_URL'] ?? 'http://0.0.0.0:8747';
$data = "SITE_URL='" . $site_url . "'" . PHP_EOL;
file_put_contents('.env', $data);
if (!$out) {
    user_error('Problem saving .env', E_USER_WARNING);
}
