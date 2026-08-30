<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Symfony\Component\Process\Process;

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
    /** @var string|null Path of the file-backed DB from a contention test, if any. */
    private ?string $contendedDbFile = null;

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

        if ($this->contendedDbFile !== null) {
            R::selectDatabase('default');
            @unlink($this->contendedDbFile);
            $this->contendedDbFile = null;
        }
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

    // Concurrent-write safety — a contended lock must refuse the attempt (not
    // throw, and not let it through unreserved) when another connection
    // already holds the write lock

    public function testReserveLoginAttemptRefusesRatherThanThrowingWhenTheWriteLockIsHeld(): void
    {
        // A single shared connection can't simulate this: SQLite silently
        // accepts a nested BEGIN IMMEDIATE on the same connection, so the
        // contention has to come from a genuinely separate connection.
        $lock = $this->holdWriteLockOnAContendedConnection();

        try {
            $retry_after = reserve_login_attempt('203.0.113.7', 1_000);
        } finally {
            $lock->exec('COMMIT');
        }

        // Refused (not silently let through): a contended reservation must
        // fail *closed*, otherwise the very race this function exists to
        // close reopens under lock contention.
        $this->assertGreaterThan(0, $retry_after);
        $this->assertNull(R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]));
    }

    public function testReserveLoginAttemptStillCountsOnceTheLockIsFree(): void
    {
        $lock = $this->holdWriteLockOnAContendedConnection();
        reserve_login_attempt('203.0.113.7', 1_000); // Contended: refused, no row.
        $lock->exec('COMMIT'); // Release the lock.

        // The refused attempt above left no row; this one, with no contention,
        // must still start a fresh counter rather than staying silently broken.
        reserve_login_attempt('203.0.113.7', 1_000);

        $bean = R::findOne('option', ' name = ? ', [login_throttle_key('203.0.113.7')]);
        $this->assertNotNull($bean);
        $this->assertSame(['count' => 1, 'expires' => 1_000 + LOGIN_THROTTLE_WINDOW], decode_throttle_state($bean->value));
    }

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

    /**
     * Points the RedBean facade at a throwaway file-backed SQLite database and
     * takes its write lock from a second, independent PDO connection to the
     * same file — real cross-connection contention, which a single shared
     * connection cannot produce. `busy_timeout=0` on the RedBean side turns
     * the resulting "database is locked" error into an immediate SQL exception
     * instead of a multi-second stall.
     *
     * tearDown() restores the `default` connection and removes the file.
     *
     * @return PDO The lock-holding connection; the caller releases it with
     *              `$lock->exec('COMMIT')` once the contended call is made.
     */
    private function holdWriteLockOnAContendedConnection(): PDO
    {
        $this->contendedDbFile = tempnam(sys_get_temp_dir(), 'lamb_throttle_test_');

        // A fresh key per call: RedBean refuses to re-register an existing one,
        // and each test needs its own throwaway file anyway.
        $key = 'contended_' . uniqid();
        R::addDatabase($key, 'sqlite:' . $this->contendedDbFile);
        R::selectDatabase($key);
        R::freeze(false);
        R::exec('PRAGMA busy_timeout = 0');

        $lock = new PDO('sqlite:' . $this->contendedDbFile);
        $lock->exec('PRAGMA busy_timeout = 0');
        $lock->exec('BEGIN IMMEDIATE');

        return $lock;
    }

    /**
     * Reproduces the conditions reserve_login_attempt() actually runs under in
     * production, unlike holdWriteLockOnAContendedConnection() above: this
     * test never forces busy_timeout on the RedBean side, so the connection
     * keeps whatever a bare `new PDO('sqlite:...')` defaults to on this host —
     * the same thing a real deployment never overrides either. The write lock
     * is held by a genuinely separate OS process (not a second connection in
     * this same process) so a regression that blocks instead of refusing
     * fails this test in bounded time (the hold's length) instead of hanging
     * the suite for the host's full default busy_timeout.
     */
    public function testReserveLoginAttemptRefusesPromptlyUnderContentionWithoutTestForcedBusyTimeout(): void
    {
        $this->contendedDbFile = tempnam(sys_get_temp_dir(), 'lamb_throttle_test_');

        $key = 'contended_' . uniqid();
        R::addDatabase($key, 'sqlite:' . $this->contendedDbFile);
        R::selectDatabase($key);
        R::freeze(false);
        // Deliberately not forcing busy_timeout here — see docblock above.

        $holdSeconds = 2;
        $holder = new Process([
            'php',
            '-r',
            '$pdo = new PDO("sqlite:' . $this->contendedDbFile . '"); '
                . '$pdo->exec("BEGIN IMMEDIATE"); '
                . 'usleep(' . ($holdSeconds * 1_000_000) . '); '
                . '$pdo->exec("COMMIT");',
        ]);
        $holder->start();
        // Let the subprocess actually acquire BEGIN IMMEDIATE before racing it.
        usleep(300_000);

        $started = microtime(true);
        $retry_after = reserve_login_attempt('203.0.113.7', 1_000);
        $elapsed = microtime(true) - $started;

        $holder->wait();

        // Refused (not silently let through)...
        $this->assertGreaterThan(0, $retry_after);
        // ...and refused promptly. A regression that stops forcing
        // busy_timeout to 0 for this critical section would instead block
        // for the lock holder's full hold time before still admitting the
        // attempt — the exact bcrypt-pileup race this function exists to
        // close, just serialised across the stall instead of parallel.
        $this->assertLessThan(
            $holdSeconds,
            $elapsed,
            'reserve_login_attempt() blocked on lock contention instead of refusing promptly'
        );
    }

    // The refusal message tells the owner when to come back

    public function testThrottleMessageStatesTheWait(): void
    {
        $this->assertStringContainsString('1 minute', throttle_message(60));
        $this->assertStringContainsString('2 minutes', throttle_message(61));
        $this->assertStringContainsString('15 minutes', throttle_message(15 * 60));
    }
}
