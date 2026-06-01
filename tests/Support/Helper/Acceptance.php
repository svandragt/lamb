<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;

/**
 * Acceptance suite helper.
 *
 * Acceptance tests run against a real PHP server backed by a SQLite file at
 * tests/Support/Data/lamb.db. That file persists between runs, so posts created
 * by one test (e.g. seeding a tagged post) can leak into a later test (e.g. a
 * search expecting "No results found.") and cause spurious failures on re-runs.
 *
 * Deleting the database before each test gives every test a clean slate. The
 * server recreates the schema on the next request (RedBeanPHP fluid mode), and
 * the deletion happens while no request is in flight, so it is safe.
 */
class Acceptance extends Module
{
    public function _before(TestInterface $test): void
    {
        $db = dirname(__DIR__) . '/Data/lamb.db';
        if (is_file($db)) {
            @unlink($db);
        }
    }
}
