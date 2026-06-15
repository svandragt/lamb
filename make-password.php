<?php

if (empty($argv[1])) {
    die('Usage: php make-password.php <password>');
}
$password = $argv[1];

// Highlight a weak password rather than refusing it: communicate, don't block
// (a refusal would also break test fixtures that pass a short password). The
// warning goes to STDERR so STDOUT stays just the hash for callers to copy.
const MIN_PASSWORD_LENGTH = 12;
if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    fwrite(
        STDERR,
        'Warning: that password is weak (under ' . MIN_PASSWORD_LENGTH
        . ' characters). Consider a longer passphrase.' . PHP_EOL
    );
}

$hash = base64_encode(password_hash($password, PASSWORD_DEFAULT));

$test_port = $_ENV['LAMB_TEST_PORT'] ?? '8747';
$site_url  = $_ENV['DDEV_PRIMARY_URL'] ?? "http://0.0.0.0:{$test_port}";
if (($_ENV['PWD'] ?? '') === '/srv/app') {
    // Inside the Docker dev container FrankenPHP serves the site locally.
    $site_url = 'http://localhost';
}

$data  = "SITE_URL='" . $site_url . "'" . PHP_EOL;
$data .= "LAMB_TEST_PORT='" . $test_port . "'" . PHP_EOL;
$data .= "LAMB_LOGIN_PASSWORD='" . $hash . "'" . PHP_EOL;
// The plaintext password is only useful to the acceptance suite (it logs in
// with $_ENV['LAMB_TEST_PASSWORD']). Keep it out of .env by default so a
// self-hoster's setup file never carries the cleartext secret; the test harness
// opts in via LAMB_WRITE_TEST_PASSWORD. Use getenv() rather than $_ENV here: CI
// runs this script under a variables_order that does not populate $_ENV from the
// environment, but getenv() reads the process environment regardless.
if (getenv('LAMB_WRITE_TEST_PASSWORD')) {
    $data .= "LAMB_TEST_PASSWORD='" . $password . "'" . PHP_EOL;
}
$env_out = file_put_contents('.env', $data);
if (!$env_out) {
    user_error('Problem saving .env', E_USER_WARNING);
}

echo $hash;
