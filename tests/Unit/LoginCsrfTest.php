<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\sign_login_marker;
use function Lamb\Bootstrap\valid_login_marker;
use function Lamb\Bootstrap\should_start_session;
use function Lamb\Response\issue_login_csrf;
use function Lamb\Response\login_csrf_secret;
use function Lamb\Response\valid_login_csrf;

/**
 * Stateless double-submit CSRF for the anonymous /login form (issue #462).
 *
 * /login must not start a server-side session for anonymous visitors (no
 * per-request session file → no disk-exhaustion DoS), so its CSRF token is a
 * signed value carried in a cookie + matching hidden field rather than in the
 * session. The signature reuses the login-marker signing helpers, but under a
 * derived key so a CSRF token can never double as a lamb_logged_in marker.
 */
class LoginCsrfTest extends TestCase
{
    /** @var string|false */
    private $previousSecret;

    protected function setUp(): void
    {
        $_POST   = [];
        $_COOKIE = [];
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $this->previousSecret = getenv('LAMB_LOGIN_PASSWORD');
    }

    protected function tearDown(): void
    {
        $_POST   = [];
        $_COOKIE = [];
        if ($this->previousSecret === false) {
            putenv('LAMB_LOGIN_PASSWORD');
        } else {
            putenv('LAMB_LOGIN_PASSWORD=' . $this->previousSecret);
        }
    }

    public function testValidWhenFieldEqualsCookieAndSignatureValid(): void
    {
        $token = issue_login_csrf();
        $_POST[HIDDEN_CSRF_NAME] = $token;
        // issue_login_csrf reflects the value into $_COOKIE for same-request reads.
        $this->assertTrue(valid_login_csrf());
    }

    public function testInvalidWhenFieldMissing(): void
    {
        issue_login_csrf();
        // No matching hidden field submitted.
        $this->assertFalse(valid_login_csrf());
    }

    public function testInvalidWhenCookieMissing(): void
    {
        $_POST[HIDDEN_CSRF_NAME] = sign_login_marker('abc', login_csrf_secret('hash'));
        $_COOKIE = [];
        $this->assertFalse(valid_login_csrf());
    }

    public function testInvalidWhenFieldAndCookieDiffer(): void
    {
        $token = issue_login_csrf();
        // A different (but on its own validly signed) value in the field must
        // still fail: double-submit requires the two to be identical.
        $_POST[HIDDEN_CSRF_NAME] = sign_login_marker('other', login_csrf_secret((string) getenv('LAMB_LOGIN_PASSWORD')));
        $this->assertNotSame($token, $_POST[HIDDEN_CSRF_NAME]);
        $this->assertFalse(valid_login_csrf());
    }

    public function testInvalidWhenSignatureTampered(): void
    {
        $token = issue_login_csrf();
        $tampered = $token . 'x';
        $_POST[HIDDEN_CSRF_NAME]   = $tampered;
        $_COOKIE[LOGIN_CSRF_COOKIE] = $tampered;
        $this->assertFalse(valid_login_csrf());
    }

    /**
     * The crux of the design: a CSRF token is signed with a key derived from —
     * but distinct from — the login hash, so feeding it back as a lamb_logged_in
     * marker must NOT start a session. Otherwise an attacker could harvest a CSRF
     * token from GET /login and replay it as a marker to reopen the DoS.
     */
    public function testCsrfTokenIsNotAcceptedAsLoginMarker(): void
    {
        $secret = 'a-realistic-bcrypt-login-hash';
        putenv('LAMB_LOGIN_PASSWORD=' . $secret);

        $token = sign_login_marker('deadbeef', login_csrf_secret($secret));

        // Valid as a CSRF token under the derived key …
        $this->assertTrue(valid_login_marker($token, login_csrf_secret($secret)));
        // … but never valid as a login marker under the raw key …
        $this->assertFalse(valid_login_marker($token, $secret));
        // … so it can never trigger a session start.
        $this->assertFalse(should_start_session(['lamb_logged_in' => $token]));
    }

    public function testDerivedSecretDiffersFromRawLoginHash(): void
    {
        $this->assertNotSame('a-hash', login_csrf_secret('a-hash'));
        $this->assertNotSame('', login_csrf_secret(''));
    }

    public function testReusesExistingValidCookieAcrossIssues(): void
    {
        $first  = issue_login_csrf();
        $second = issue_login_csrf();
        // A valid cookie is reused rather than rotated, so two tabs that both
        // GET /login don't invalidate each other's hidden field.
        $this->assertSame($first, $second);
    }
}
