<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\should_start_session;
use function Lamb\Bootstrap\cache_headers;
use function Lamb\Bootstrap\configure_session;
use function Lamb\Bootstrap\configure_session_save_path;
use function Lamb\Bootstrap\sign_login_marker;
use function Lamb\Bootstrap\valid_login_marker;

/**
 * Sessions for previously logged-in users only (issue #116).
 *
 * Anonymous visitors must not get a session started: that forces a Set-Cookie
 * and PHP's no-cache headers, which makes every public page uncacheable.
 *
 * A session is only started when the request carries a lamb_logged_in marker
 * cookie whose HMAC validates against the server secret. Bare cookie presence
 * is not enough: forged markers (and a bare LAMBSESSID) must NOT start a session,
 * or an attacker could flood requests with junk cookies and make the server
 * write a new session file per request (resource-exhaustion DoS).
 */
class SessionBootstrapTest extends TestCase
{
    private const SECRET = 'test-bcrypt-hash-secret';

    /** @var string|false */
    private $previous_secret;

    protected function setUp(): void
    {
        $this->previous_secret = getenv('LAMB_LOGIN_PASSWORD');
        putenv('LAMB_LOGIN_PASSWORD=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        if ($this->previous_secret === false) {
            putenv('LAMB_LOGIN_PASSWORD');
        } else {
            putenv('LAMB_LOGIN_PASSWORD=' . $this->previous_secret);
        }
    }

    public function testAnonymousVisitorWithNoCookiesDoesNotStartSession(): void
    {
        $this->assertFalse(should_start_session([]));
    }

    public function testUnrelatedCookiesDoNotStartSession(): void
    {
        $this->assertFalse(should_start_session(['theme' => 'dark', 'consent' => '1']));
    }

    public function testValidlySignedMarkerStartsSession(): void
    {
        $marker = sign_login_marker('deadbeef', self::SECRET);
        $this->assertTrue(should_start_session(['lamb_logged_in' => $marker]));
    }

    public function testForgedUnsignedMarkerDoesNotStartSession(): void
    {
        $this->assertFalse(should_start_session(['lamb_logged_in' => 'abc123']));
    }

    public function testTamperedMarkerDoesNotStartSession(): void
    {
        $marker = sign_login_marker('deadbeef', self::SECRET) . 'x';
        $this->assertFalse(should_start_session(['lamb_logged_in' => $marker]));
    }

    public function testMarkerSignedWithWrongSecretDoesNotStartSession(): void
    {
        $marker = sign_login_marker('deadbeef', 'a-different-secret');
        $this->assertFalse(should_start_session(['lamb_logged_in' => $marker]));
    }

    public function testBareSessionCookieDoesNotStartSession(): void
    {
        // The whole point of the marker gate: a forged LAMBSESSID must not be
        // enough to trigger session_start() and a per-request disk write.
        $this->assertFalse(should_start_session(['LAMBSESSID' => 'deadbeef']));
    }

    public function testValidMarkerRejectedWhenNoServerSecretConfigured(): void
    {
        putenv('LAMB_LOGIN_PASSWORD');
        $this->assertFalse(valid_login_marker(sign_login_marker('deadbeef', ''), ''));
    }

    public function testAnonymousPagesAreCacheable(): void
    {
        $headers = cache_headers(false);
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('max-age=300', $joined);
        $this->assertStringNotContainsString('no-store', $joined);
    }

    public function testLoggedInPagesArePrivateAndNotCacheable(): void
    {
        $headers = cache_headers(true);
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('private', $joined);
        $this->assertStringContainsString('no-store', $joined);
    }

    public function testResponsesVaryByCookieSoSharedCachesSeparateAnonymousFromLoggedIn(): void
    {
        $this->assertStringContainsString('Vary: Cookie', implode("\n", cache_headers(false)));
        $this->assertStringContainsString('Vary: Cookie', implode("\n", cache_headers(true)));
    }

    /**
     * Remember-me: the session cookie persists for a week rather than dying on
     * browser close, so logins survive restarts (no checkbox — always on).
     */
    public function testSessionCookiePersistsForRememberLifetime(): void
    {
        // configure_session() runs at bootstrap before any session is active;
        // in the shared test process another test may have left one open.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        configure_session();
        $this->assertSame(REMEMBER_LIFETIME, session_get_cookie_params()['lifetime']);
    }

    /**
     * The server-side session must outlive the cookie too, or GC reaps the
     * session data before the persistent cookie expires.
     */
    public function testServerSessionLifetimeMatchesRememberLifetime(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        configure_session();
        $this->assertSame((string) REMEMBER_LIFETIME, ini_get('session.gc_maxlifetime'));
    }

    /**
     * Sessions live in a dedicated directory under the app's persistent data dir,
     * not the shared system default. This isolates Lamb's week-long GC from other
     * PHP apps on the host (no cross-app session-lifetime bleed in either
     * direction) and survives deploys/restarts that wipe ephemeral session stores.
     */
    public function testSessionSavePathIsDedicatedDirectoryUnderDataDir(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $data_dir = sys_get_temp_dir() . '/lamb-session-test-' . getmypid();
        @mkdir($data_dir, 0700, true);

        configure_session_save_path($data_dir);

        $expected = $data_dir . '/sessions';
        $this->assertSame($expected, ini_get('session.save_path'));
        $this->assertDirectoryExists($expected);

        @rmdir($expected);
        @rmdir($data_dir);
    }
}
