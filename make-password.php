<?php

if (empty($argv[1])) {
    die('Usage: php make-password.php <password>');
}
$hash = base64_encode(password_hash($argv[1], PASSWORD_DEFAULT));

$test_port = $_ENV['LAMB_TEST_PORT'] ?? '8747';
$site_url  = $_ENV['DDEV_PRIMARY_URL'] ?? "http://0.0.0.0:{$test_port}";
if (($_ENV['PWD'] ?? '') === '/srv/app') {
    $site_url = 'http://lamb-web';
}

$micropub_token = bin2hex(random_bytes(32));

$data  = "SITE_URL='" . $site_url . "'" . PHP_EOL;
$data .= "LAMB_TEST_PORT='" . $test_port . "'" . PHP_EOL;
$data .= "LAMB_LOGIN_PASSWORD='" . $hash . "'" . PHP_EOL;
$data .= "LAMB_TEST_PASSWORD='" . $argv[1] . "'" . PHP_EOL;
$data .= "LAMB_MICROPUB_TOKEN='" . $micropub_token . "'" . PHP_EOL;
$env_out = file_put_contents('.env', $data);
if (!$env_out) {
    user_error('Problem saving .env', E_USER_WARNING);
}

echo $hash;
