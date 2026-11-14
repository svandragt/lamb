<?php

declare(strict_types=1);

namespace Tests\Support\Extension;

use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;

/**
 * Gives every Unit test the globals it started with.
 *
 * Tests set `$config`, `$data`, `$_SESSION`, `$_GET` and `$_POST` to build a
 * request, and the app reads them as globals. A test that does not undo that
 * leaks it into whichever test runs next, so a shuffled run fails depending on
 * the order. This snapshots them before each test and restores them after it,
 * also when the test fails: Codeception fires `test.after` for every outcome.
 *
 * A global that was not set before the test is unset again, not left as null.
 */
class RestoreGlobals extends Extension
{
    /** @var array<string, string> */
    public static array $events = [
        Events::TEST_BEFORE => 'snapshot',
        Events::TEST_AFTER  => 'restore',
    ];

    /** Globals the app reads by name. */
    private const GLOBALS = ['config', 'data', '_SESSION', '_GET', '_POST'];

    /** @var array<string, mixed> Values of the globals that were set. */
    private array $snapshot = [];

    /**
     * Records the globals before the test's own setUp runs.
     *
     * @param TestEvent $event The test about to run.
     * @return void
     */
    public function snapshot(TestEvent $event): void
    {
        $this->snapshot = [];
        foreach (self::GLOBALS as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $this->snapshot[$name] = $GLOBALS[$name];
            }
        }
    }

    /**
     * Puts the globals back after the test's own tearDown ran.
     *
     * @param TestEvent $event The test that just ran.
     * @return void
     */
    public function restore(TestEvent $event): void
    {
        foreach (self::GLOBALS as $name) {
            if (array_key_exists($name, $this->snapshot)) {
                $GLOBALS[$name] = $this->snapshot[$name];
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }
}
