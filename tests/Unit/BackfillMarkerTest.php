<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\backfill_marker_done;
use function Lamb\Bootstrap\backfill_post_version;
use function Lamb\Bootstrap\mark_backfill_done;
use function Lamb\Bootstrap\migrate_post_table;

/**
 * Covers the marker that lets a completed backfill stop probing forever
 * (issue #811), and that migrate_post_table() — the part of every boot that
 * used to run backfill_post_version() and backfill_imported_post_identity()
 * — no longer does. Both now run only from `bin/lamb migrate`; see
 * tests/Unit/BinLambTest.php for their end-to-end coverage.
 */
class BackfillMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        R::exec(
            'CREATE TABLE post (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT,'
            . ' version INTEGER, feed_name TEXT, feeditem_uuid TEXT, source_url TEXT, import_uuid TEXT)'
        );
    }

    private function columns(): array
    {
        return array_column(R::getAll('PRAGMA table_info(post)'), 'name');
    }

    public function testBootNoLongerMigratesUnstampedPosts(): void
    {
        R::exec("INSERT INTO post (body, version, feed_name, feeditem_uuid) VALUES ('a', NULL, 'wordpress', 'uuid-1')");

        migrate_post_table();

        $this->assertNull(R::getCell('SELECT version FROM post LIMIT 1'));
        $this->assertSame('wordpress', R::getCell('SELECT feed_name FROM post LIMIT 1'));
    }

    public function testADatabaseStillNeedingTheBackfillGetsIt(): void
    {
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        backfill_post_version($this->columns());

        $this->assertSame(1, (int) R::getCell('SELECT version FROM post LIMIT 1'));
    }

    public function testASecondRunWithTheMarkerSetDoesNotReProbe(): void
    {
        // Nothing to migrate yet: this run's probe comes back empty and
        // records completion.
        backfill_post_version($this->columns());
        // A row the probe would find and fix, were it to run again.
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        backfill_post_version($this->columns());

        $this->assertNull(R::getCell('SELECT version FROM post WHERE body = ?', ['a']));
    }

    public function testAnInstallWithNoMarkerStillProbes(): void
    {
        // No `option` table at all — a fresh install, or an upgrade from
        // before this marker existed.
        $this->assertNull(R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='option'"));
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        backfill_post_version($this->columns());

        $this->assertSame(1, (int) R::getCell('SELECT version FROM post LIMIT 1'));
    }

    public function testAWriteFailureIsSwallowedRatherThanThrown(): void
    {
        // Simulates a read-only database: PDO/SQLite reject the write, and
        // that must not surface as a broken request — worst case, the next
        // run's probe just runs again.
        R::exec('PRAGMA query_only = ON');
        try {
            mark_backfill_done('probe_test_marker');
        } finally {
            R::exec('PRAGMA query_only = OFF');
        }

        $this->assertFalse(backfill_marker_done('probe_test_marker'));
    }
}
