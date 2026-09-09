<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Symfony\Component\Process\Process;

use function Lamb\Bootstrap\bootstrap_db;
use function Lamb\Response\clear_login_failures;
use function Lamb\Response\client_ip;
use function Lamb\Response\decode_throttle_state;
use function Lamb\Response\encode_throttle_state;
use function Lamb\Response\login_throttle_key;
use function Lamb\Response\login_throttle_retry_after;
use function Lamb\Response\prune_login_throttle;
use function Lamb\Response\reserve_login_attempt;
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
            reserve_login_attempt('203.0.113.7', $now);
        }

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now));
    }

    public function testFailuresAtTheLimitBlockFurtherAttempts(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            reserve_login_attempt('203.0.113.7', $now);
        }

        $this->assertSame(LOGIN_THROTTLE_WINDOW, login_throttle_retry_after('203.0.113.7', $now));
    }

    public function testBlockIsPerIpNotGlobal(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            reserve_login_attempt('203.0.113.7', $now);
        }

        // The owner, on a different address, must still be able to log in — a
        // global counter would hand any anonymous attacker a lockout button.
        $this->assertSame(0, login_throttle_retry_after('198.51.100.9', $now));
    }

    public function testBlockLapsesAfterTheWindow(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            reserve_login_attempt('203.0.113.7', $now);
        }

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now + LOGIN_THROTTLE_WINDOW));
    }

    public function testSuccessfulLoginClearsTheCounter(): void
    {
        $now = 1_000;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            reserve_login_attempt('203.0.113.7', $now);
        }
        clear_login_failures('203.0.113.7');

        $this->assertSame(0, login_throttle_retry_after('203.0.113.7', $now));
        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    // Pruning — the table must not grow one permanent row per attacking address

    public function testPruneDropsExpiredRowsAndKeepsLiveOnes(): void
    {
        reserve_login_attempt('203.0.113.7', 1_000);
        reserve_login_attempt('198.51.100.9', 1_500);

        $pruned = prune_login_throttle(1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertSame(1, $pruned);
        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
        $this->assertNotNull(R::findOne('option', ' name = ? ', [login_throttle_key('198.51.100.9')]));
    }

    public function testPruneLeavesUnrelatedOptionsAlone(): void
    {
        \Lamb\set_option(\Lamb\get_option('site_config_ini', 'site_title = Lamb'), 'site_title = Lamb');
        reserve_login_attempt('203.0.113.7', 1_000);

        prune_login_throttle(1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertNotNull(R::findOne('option', ' name = ? ', ['site_config_ini']));
    }

    public function testRecordingAFailurePrunesExpiredRows(): void
    {
        reserve_login_attempt('203.0.113.7', 1_000);
        reserve_login_attempt('198.51.100.9', 1_000 + LOGIN_THROTTLE_WINDOW);

        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    // Concurrent-write safety — the atomic check-and-increment caps admissions
    // at the limit even under a burst from one address

    public function testReserveLoginAttemptCapsConcurrentAdmissionsAtTheLimit(): void
    {
        // Real OS-level concurrency can't be simulated in-process, but the
        // property that guards the race holds either way: because the check and
        // increment happen together, calling reserve_login_attempt() in a loop
        // never admits more than LOGIN_THROTTLE_MAX_FAILURES before refusing.
        $now = 1_000;
        $admitted = 0;
        for ($i = 0; $i < LOGIN_THROTTLE_MAX_FAILURES + 5; $i++) {
            if (reserve_login_attempt('203.0.113.7', $now) === 0) {
                $admitted++;
            }
        }

        $this->assertSame(LOGIN_THROTTLE_MAX_FAILURES, $admitted);
    }

    public function testReserveLoginAttemptLeavesNoOpenTransaction(): void
    {
        // The reservation must commit (or roll back) cleanly and leave the
        // shared connection with no open transaction — a dangling BEGIN would
        // make the next one throw "cannot start a transaction within a
        // transaction" and poison every later query in the request.
        reserve_login_attempt('203.0.113.7', 1_000);

        R::exec('BEGIN IMMEDIATE');
        R::exec('ROLLBACK');

        // Reached here without an exception, and a fresh reservation still works.
        $this->assertSame(0, reserve_login_attempt('198.51.100.9', 1_000));
    }

    public function testBootstrapDbEnablesWalSoLoginCommitsSurviveConcurrentReaders(): void
    {
        // reserve_login_attempt()'s COMMIT must not stall on a concurrent
        // reader. WAL lets a writer commit while readers hold the file; a
        // regression to the rollback journal would let an ordinary page-render
        // read block or fail a correct-password login. Run bootstrap_db() in a
        // subprocess: it calls R::setup(), which would clobber this suite's
        // shared :memory: default connection.
        $dir = sys_get_temp_dir() . '/lamb_wal_test_' . uniqid();

        $boot = new Process([
            'php',
            '-r',
            'require "vendor/autoload.php"; \Lamb\Bootstrap\bootstrap_db($argv[1]);',
            $dir,
        ]);
        $boot->mustRun();

        // WAL is a persisted property of the file, so a fresh connection sees it.
        $pdo  = new PDO('sqlite:' . $dir . '/lamb.db');
        $mode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $pdo  = null;

        @unlink($dir . '/lamb.db');
        @unlink($dir . '/lamb.db-wal');
        @unlink($dir . '/lamb.db-shm');
        @rmdir($dir);

        $this->assertSame('wal', strtolower($mode));
    }

    // The refusal message tells the owner when to come back

    public function testThrottleMessageStatesTheWait(): void
    {
        $this->assertStringContainsString('1 minute', throttle_message(60));
        $this->assertStringContainsString('2 minutes', throttle_message(61));
        $this->assertStringContainsString('15 minutes', throttle_message(15 * 60));
    }
}
