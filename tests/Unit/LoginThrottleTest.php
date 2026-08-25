<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\clear_login_failures;
use function Lamb\Response\client_ip;
use function Lamb\Response\decode_throttle_state;
use function Lamb\Response\encode_throttle_state;
use function Lamb\Response\login_throttle_key;
use function Lamb\Response\login_throttle_retry_after;
use function Lamb\Response\prune_login_throttle;
use function Lamb\Response\record_login_failure;
use function Lamb\Response\throttle_message;
use function Lamb\Response\throttle_retry_after;

/**
 * Per-IP brute-force throttle for the admin login (issue #443).
 *
 * /login is sessionless for anonymous visitors (#462), so the counter cannot
 * live in the session: it is a row in the `option` table keyed by a hash of the
 * client IP. Failures inside a window accumulate; past the limit further
 * attempts are refused before bcrypt runs, and a successful login clears the
 * counter.
 */
class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        unset($_SERVER['REMOTE_ADDR']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
    }

    // client_ip — the single source of the client address for logging + throttling

    public function testClientIpReadsRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->assertSame('203.0.113.7', client_ip());
    }

    public function testClientIpFallsBackWhenMissing(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('unknown', client_ip());
    }

    // login_throttle_key — fixed-width, per-install, and not a log of who probed

    public function testThrottleKeyIsStableForTheSameIp(): void
    {
        $this->assertSame(login_throttle_key('203.0.113.7'), login_throttle_key('203.0.113.7'));
    }

    public function testThrottleKeyDiffersPerIp(): void
    {
        $this->assertNotSame(login_throttle_key('203.0.113.7'), login_throttle_key('198.51.100.9'));
    }

    public function testThrottleKeyDoesNotLeakTheRawIp(): void
    {
        $this->assertStringNotContainsString('203.0.113.7', login_throttle_key('203.0.113.7'));
    }

    // throttle_retry_after — the pure decision, independent of storage

    public function testAllowsAttemptsBelowTheLimit(): void
    {
        $state = ['count' => LOGIN_THROTTLE_MAX_FAILURES - 1, 'expires' => 1_000 + LOGIN_THROTTLE_WINDOW];
        $this->assertSame(0, throttle_retry_after($state, 1_000));
    }

    public function testRefusesAtTheLimitForTheRemainderOfTheWindow(): void
    {
        $state = ['count' => LOGIN_THROTTLE_MAX_FAILURES, 'expires' => 1_000 + 120];
        $this->assertSame(120, throttle_retry_after($state, 1_000));
    }

    public function testAllowsAgainOnceTheWindowHasElapsed(): void
    {
        $state = ['count' => LOGIN_THROTTLE_MAX_FAILURES + 3, 'expires' => 1_000];
        $this->assertSame(0, throttle_retry_after($state, 1_000));
        $this->assertSame(0, throttle_retry_after($state, 1_001));
    }

    // encode/decode — the option row holds "<count>:<expires>"

    public function testStateRoundTrips(): void
    {
        $this->assertSame(['count' => 3, 'expires' => 1_700], decode_throttle_state(encode_throttle_state(3, 1_700)));
    }

    public function testDecodeTreatsGarbageAsNoFailures(): void
    {
        foreach ([null, '', 'nonsense', '1', ':', 'a:b'] as $raw) {
            $this->assertSame(['count' => 0, 'expires' => 0], decode_throttle_state($raw));
        }
    }

    // Storage-backed behaviour

    public function testFailuresBelowTheLimitDoNotBlock(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES - 1; $i++) {
            record_login_failure('203.0.113.7', $now);
        }

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now));
    }

    public function testFailuresAtTheLimitBlockFurtherAttempts(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            record_login_failure('203.0.113.7', $now);
        }

        $this->assertSame(LOGIN_THROTTLE_WINDOW, login_throttle_retry_after('203.0.113.7', $now));
    }

    public function testBlockIsPerIpNotGlobal(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            record_login_failure('203.0.113.7', $now);
        }

        // The owner, on a different address, must still be able to log in — a
        // global counter would hand any anonymous attacker a lockout button.
        $this->assertSame(0, login_throttle_retry_after('198.51.100.9', $now));
    }

    public function testBlockLapsesAfterTheWindow(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            record_login_failure('203.0.113.7', $now);
        }

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now + LOGIN_THROTTLE_WINDOW));
    }

    public function testSuccessfulLoginClearsTheCounter(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            record_login_failure('203.0.113.7', $now);
        }
        clear_login_failures('203.0.113.7');

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now));
        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    // Pruning — the table must not grow one permanent row per attacking address

    public function testPruneDropsExpiredRowsAndKeepsLiveOnes(): void
    {
        record_login_failure('203.0.113.7', 1_000);
        record_login_failure('198.51.100.9', 1_500);

        $pruned = prune_login_throttle(1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertSame(1, $pruned);
        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
        $this->assertNotNull(R::findOne('option', ' name = ? ', [login_throttle_key('198.51.100.9')]));
    }

    public function testPruneLeavesUnrelatedOptionsAlone(): void
    {
        \Lamb\set_option(\Lamb\get_option('site_config_ini', 'site_title = Lamb'), 'site_title = Lamb');
        record_login_failure('203.0.113.7', 1_000);

        prune_login_throttle(1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertNotNull(R::findOne('option', ' name = ? ', ['site_config_ini']));
    }

    public function testRecordingAFailurePrunesExpiredRows(): void
    {
        record_login_failure('203.0.113.7', 1_000);
        record_login_failure('198.51.100.9', 1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    // Concurrent-write safety — the counter must not lose an increment (or
    // crash the request) when another connection already holds the write lock

    public function testRecordLoginFailureSkipsCountingRatherThanThrowingWhenTheWriteLockIsHeld(): void
    {
        // Simulates another request already mid-write: SQLite refuses a second
        // BEGIN IMMEDIATE on the same connection, which is the same SQLSTATE
        // shape record_login_failure() must survive when a different
        // connection holds the lock instead.
        R::exec('BEGIN IMMEDIATE');
        try {
            record_login_failure('203.0.113.7', 1_000);
        } finally {
            R::exec('ROLLBACK');
        }

        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    public function testRecordLoginFailureStillCountsOnceTheLockIsFree(): void
    {
        R::exec('BEGIN IMMEDIATE');
        record_login_failure('203.0.113.7', 1_000);
        R::exec('ROLLBACK');

        // The skipped attempt above left no row; this one, with no contention,
        // must still start a fresh counter rather than staying silently broken.
        record_login_failure('203.0.113.7', 1_000);

        $bean = R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]);
        $this->assertNotNull($bean);
        $this->assertSame(['count' => 1, 'expires' => 1_000 + LOGIN_THROTTLE_WINDOW], decode_throttle_state($bean->value));
    }

    // The refusal message tells the owner when to come back

    public function testThrottleMessageStatesTheWait(): void
    {
        $this->assertStringContainsString('1 minute', throttle_message(60));
        $this->assertStringContainsString('2 minutes', throttle_message(61));
        $this->assertStringContainsString('15 minutes', throttle_message(15 * 60));
    }
}
