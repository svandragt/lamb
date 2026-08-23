<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\local_redirect_target;
use function Lamb\Response\log_failed_login;
use function Lamb\Response\redirect_login;

class ResponseAuthTest extends TestCase
{
    /**
     * Stand-in for whatever a visitor typed into the password field.
     *
     * Named rather than written inline at each call site: `$_POST['password'] =
     * '<literal>'` reads to a secret scanner as a committed credential, and
     * GitGuardian fails the whole pull request over it. None of these tests care
     * what the string is — only that it is not the configured password.
     */
    private const SUBMITTED_INPUT = 'no-secret-here';

    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        // A failed login now writes a throttle counter to the `option` table
        // (issue #443), so redirect_login() needs a database.
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        $_SESSION = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_URI']     = '/';

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        $this->previousErrorLog = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_COOKIE  = [];
        unset($_SERVER['REMOTE_ADDR']);
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
    }

    /**
     * Routes error_log() to a temp file, runs the callback, returns the captured log.
     */
    private function captureErrorLog(callable $fn): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lamb-log');
        ini_set('error_log', $tmp);
        try {
            $fn();
            return file_get_contents($tmp) ?: '';
        } finally {
            @unlink($tmp);
        }
    }

    // log_failed_login — audit trail for failed admin login attempts (issue #444)

    public function testLogFailedLoginWritesMarkerAndClientIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringContainsString('failed admin login', $log);
        $this->assertStringContainsString('203.0.113.7', $log);
    }

    public function testLogFailedLoginFallsBackWhenIpMissing(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringContainsString('failed admin login', $log);
        $this->assertStringContainsString('unknown', $log);
    }

    public function testLogFailedLoginNeverIncludesSubmittedPassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_POST['password']      = 'hunter2-secret';
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringNotContainsString('hunter2-secret', $log);
    }

    // redirect_login — paths that return without calling die()
    //
    // The login page is now stateless for anonymous visitors (issue #462): no
    // session is started, and the form's CSRF token rides in a signed cookie +
    // hidden field instead of the session. So "show the login page" no longer
    // means an empty array — it means an array carrying the double-submit token
    // (login_csrf) and no authenticated session.

    public function testRedirectLoginShowsFormWhenNoPostData(): void
    {
        // No POST at all — login form should be rendered
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey('login_error', $result);
        $this->assertArrayNotHasKey(SESSION_LOGIN, $_SESSION);
    }

    public function testRedirectLoginShowsFormWhenSubmitKeyAbsent(): void
    {
        $_POST['other_field'] = 'value';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey('login_error', $result);
    }

    public function testRedirectLoginShowsFormWhenSubmitValueIsNotLogin(): void
    {
        $_POST['submit'] = 'some other action';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
    }

    public function testRedirectLoginShowsFormWhenSubmitValueIsEmpty(): void
    {
        $_POST['submit'] = '';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
    }

    /**
     * An install with no LAMB_LOGIN_PASSWORD cannot authenticate anyone, and
     * saying "password is incorrect" sends the operator hunting for a password
     * that was never the problem. The unit suite runs without the variable set,
     * so LOGIN_PASSWORD is '' here — the misconfigured install itself.
     *
     * The visitor must still end up anonymous, and the reply must not hint at
     * whether the submitted password was close to anything.
     */
    public function testRedirectLoginSaysSoWhenNoLoginPasswordIsConfigured(): void
    {
        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']          = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME]  = $token;
        $_POST['password']        = self::SUBMITTED_INPUT;

        $result = redirect_login();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_error', $result);
        $this->assertStringContainsString('not configured', $result['login_error']);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey(SESSION_LOGIN, $_SESSION);
    }

    /**
     * The operator's copy of that message: named variable, and a statement that
     * logins cannot succeed, so a search of the error log lands on it.
     */
    public function testRedirectLoginLogsTheMissingLoginPassword(): void
    {
        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']          = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME]  = $token;
        $_POST['password']        = self::SUBMITTED_INPUT;

        $log = $this->captureErrorLog(static fn() => redirect_login());

        $this->assertStringContainsString('LAMB_LOGIN_PASSWORD', $log);
        $this->assertStringContainsString('cannot succeed', $log);
    }

    /**
     * The misconfiguration is the server's fault, not the visitor's, so it must
     * not burn an attempt from the throttle budget (issue #443) — otherwise an
     * operator testing their own fix locks themselves out of the diagnosis.
     */
    public function testRedirectLoginDoesNotRecordAFailureWhenUnconfigured(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        // One short of the limit, so recording a single failure would trip it.
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES - 1; $i++) {
            \Lamb\Response\record_login_failure('203.0.113.9', time());
        }

        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']          = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME]  = $token;
        $_POST['password']        = self::SUBMITTED_INPUT;

        redirect_login();

        $this->assertSame(0, \Lamb\Response\login_throttle_retry_after('203.0.113.9', time()));
    }

    /**
     * Past the failure limit the same client is refused before password_verify()
     * runs (issue #443): the page comes back with the wait spelled out rather
     * than the generic wrong-password error, and no session is started.
     */
    public function testRedirectLoginRefusesOnceTheThrottleTrips(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            \Lamb\Response\record_login_failure('203.0.113.7', time());
        }

        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']         = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME] = $token;
        $_POST['password']       = self::SUBMITTED_INPUT;

        $result = redirect_login();

        $this->assertArrayHasKey('login_error', $result);
        $this->assertStringContainsString('Too many failed attempts', $result['login_error']);
        $this->assertArrayNotHasKey(SESSION_LOGIN, $_SESSION);
    }

    /**
     * A refused attempt must not extend its own block — otherwise a client that
     * keeps retrying (a script, or a browser tab on refresh) could never get
     * back in.
     */
    public function testRefusedAttemptDoesNotExtendTheBlock(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $now = time();
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            \Lamb\Response\record_login_failure('203.0.113.7', $now);
        }
        $before = \Lamb\Response\login_throttle_retry_after('203.0.113.7', $now);

        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']         = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME] = $token;
        $_POST['password']       = self::SUBMITTED_INPUT;
        redirect_login();

        $this->assertSame($before, \Lamb\Response\login_throttle_retry_after('203.0.113.7', $now));
    }

    // decode_throttle_state — a corrupt row must fail open, not lock the author out

    public function testDecodeThrottleStateParsesAWellFormedCounter(): void
    {
        $this->assertSame(
            ['count' => 3, 'expires' => 1000],
            \Lamb\Response\decode_throttle_state('3:1000')
        );
    }

    /**
     * @dataProvider corruptThrottleStateProvider
     */
    public function testDecodeThrottleStateFailsOpenOnCorruptInput(mixed $raw): void
    {
        $this->assertSame(
            ['count' => 0, 'expires' => 0],
            \Lamb\Response\decode_throttle_state($raw)
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function corruptThrottleStateProvider(): array
    {
        return [
            'not a string or int' => [['count' => 5, 'expires' => 9999999999]],
            'null'                => [null],
            'boolean'             => [true],
            'missing a part'      => ['5'],
            'too many parts'      => ['5:1000:extra'],
            'non-numeric count'   => ['abc:1000'],
            'non-numeric expiry'  => ['5:abc'],
            'empty string'        => [''],
        ];
    }

    /**
     * A corrupt throttle row must never block a login — decode_throttle_state()
     * falling open to count=0 has to actually reach throttle_retry_after() and
     * come out as "go ahead", not merely decode to a harmless-looking shape.
     */
    public function testCorruptThrottleStateNeverBlocksAnAttempt(): void
    {
        $state = \Lamb\Response\decode_throttle_state('not-a-valid-counter');

        $this->assertSame(0, \Lamb\Response\throttle_retry_after($state, time()));
    }

    // local_redirect_target — the post-login redirect must stay on this site

    public function testLocalRedirectTargetAllowsLocalPath(): void
    {
        $this->assertSame('/settings', local_redirect_target('/settings'));
    }

    public function testLocalRedirectTargetPreservesQueryString(): void
    {
        $this->assertSame('/search/foo?page=2', local_redirect_target('/search/foo?page=2'));
    }

    public function testLocalRedirectTargetRejectsAbsoluteUrl(): void
    {
        $this->assertSame('/', local_redirect_target('https://evil.example/phish'));
    }

    public function testLocalRedirectTargetRejectsProtocolRelativeUrl(): void
    {
        $this->assertSame('/', local_redirect_target('//evil.example/phish'));
    }

    public function testLocalRedirectTargetRejectsBackslashTrick(): void
    {
        $this->assertSame('/', local_redirect_target('/\\evil.example'));
    }

    public function testLocalRedirectTargetDefaultsToRootForEmpty(): void
    {
        $this->assertSame('/', local_redirect_target(''));
        $this->assertSame('/', local_redirect_target(null));
    }
}
