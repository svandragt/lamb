<?php

$args     = array_slice($argv, 1);
$force    = in_array('--force', $args, true);
$operands = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));

if (empty($operands[0])) {
    die('Usage: php make-password.php <password> [--force]');
}
$password = $operands[0];

// The docs tell a self-hoster to run this on their own server, and it writes
// .env into whatever directory it is run from. A second run there — a test, a
// forgotten password — used to overwrite the live file in place: that is how a
// production checkout ended up holding a cleartext LAMB_TEST_PASSWORD and a
// login hash the running site never read (issues #597, #598). Refuse by
// default, so replacing a real .env has to be asked for.
if (!$force && file_exists('.env')) {
    fwrite(
        STDERR,
        '.env already exists in ' . getcwd() . PHP_EOL
        . 'Refusing to overwrite it. Move it aside, or re-run with --force.' . PHP_EOL
    );
    exit(1);
}

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

/**
 * One `.env` value, quoted so phpdotenv reads back exactly what was passed.
 *
 * Single quotes are this file's normal form and hold everything without
 * escaping — spaces, `"`, `\`, `$`, `#`, non-ASCII — because phpdotenv treats a
 * single-quoted value as literal. The two characters they cannot hold are a
 * single quote itself and a real newline: both end the value early, and
 * phpdotenv then refuses the *whole file* with InvalidFileException. Bootstrap\
 * load_dotenv() calls safeLoad(), which only swallows a missing file, so a
 * password containing an apostrophe left `composer serve` throwing on every
 * request — with LAMB_LOGIN_PASSWORD unreadable even though its own line was
 * well-formed.
 *
 * Those two fall back to double quotes, which phpdotenv does unescape. `$` is
 * escaped there as well: a double-quoted value is interpolated, so `${HOME}` in
 * a password would otherwise be substituted instead of stored.
 */
function env_value(string $value): string
{
    if (!str_contains($value, "'") && !str_contains($value, "\n") && !str_contains($value, "\r")) {
        return "'" . $value . "'";
    }

    $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    $escaped = str_replace(["\r", "\n"], ['\\r', '\\n'], $escaped);

    return '"' . $escaped . '"';
}

$data  = 'SITE_URL=' . env_value($site_url) . PHP_EOL;
$data .= 'LAMB_TEST_PORT=' . env_value($test_port) . PHP_EOL;
$data .= 'LAMB_LOGIN_PASSWORD=' . env_value($hash) . PHP_EOL;
// The plaintext password is only useful to the acceptance suite (it logs in
// with $_ENV['LAMB_TEST_PASSWORD']). Keep it out of .env by default so a
// self-hoster's setup file never carries the cleartext secret; the test harness
// opts in via LAMB_WRITE_TEST_PASSWORD. Use getenv() rather than $_ENV here: CI
// runs this script under a variables_order that does not populate $_ENV from the
// environment, but getenv() reads the process environment regardless.
if (getenv('LAMB_WRITE_TEST_PASSWORD')) {
    $data .= 'LAMB_TEST_PASSWORD=' . env_value($password) . PHP_EOL;
}
// 0600 before the secret goes in, not the umask default: this file holds the
// login hash, and file_put_contents() alone left it whatever the umask allowed —
// 644 normally, 664 under 0002, and 666 under 0000, which is not unusual in
// containers. World-readable hands a local user the bcrypt hash to crack
// offline; world-*writable* lets them replace it and own the login outright.
// bootstrap_db() already applies the same reasoning to the data directory
// ("under a permissive umask 0777 would leave the database world-readable and
// replaceable"). Creating the file first and tightening it before writing means
// there is no moment where the hash sits in a readable one.
if (!file_exists('.env')) {
    touch('.env');
}
if (!chmod('.env', 0600)) {
    user_error('Could not restrict permissions on .env; check it is not world-readable', E_USER_WARNING);
}
$env_out = file_put_contents('.env', $data);
if (!$env_out) {
    user_error('Problem saving .env', E_USER_WARNING);
}

echo $hash;
