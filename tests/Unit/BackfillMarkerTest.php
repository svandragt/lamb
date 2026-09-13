<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\backfill_marker_done;
use function Lamb\Bootstrap\mark_backfill_done;
use function Lamb\Bootstrap\migrate_post_table;

/**
 * Covers the marker that lets a completed backfill stop probing on every
 * boot forever (issue #811). backfill_post_version() and
 * backfill_imported_post_identity() each still run their own SELECT probe
 * and UPDATE unchanged — this only covers the shared skip/record mechanism
 * both of them call through.
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

    public function testADatabaseStillNeedingTheBackfillGetsIt(): void
    {
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        migrate_post_table();

        $this->assertSame(1, (int) R::getCell('SELECT version FROM post LIMIT 1'));
    }

    public function testASecondBootWithTheMarkerSetDoesNotReProbe(): void
    {
        // Nothing to migrate yet: this boot's probe comes back empty and
        // records completion.
        migrate_post_table();
        // A row that the probe would find and fix, were it to run again.
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        migrate_post_table();

        $this->assertNull(R::getCell('SELECT version FROM post WHERE body = ?', ['a']));
    }

    public function testAnInstallWithNoMarkerStillProbes(): void
    {
        // No `option` table at all — a fresh install, or an upgrade from
        // before this marker existed.
        $this->assertNull(R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='option'"));
        R::exec("INSERT INTO post (body, version) VALUES ('a', NULL)");

        migrate_post_table();

        $this->assertSame(1, (int) R::getCell('SELECT version FROM post LIMIT 1'));
    }

    public function testAWriteFailureIsSwallowedRatherThanThrown(): void
    {
        // Simulates a read-only database: PDO/SQLite reject the write, and
        // that must not surface as a broken request — worst case, the next
        // boot's probe just runs again.
        R::exec('PRAGMA query_only = ON');
        try {
            mark_backfill_done('probe_test_marker');
        } finally {
            R::exec('PRAGMA query_only = OFF');
        }

        $this->assertFalse(backfill_marker_done('probe_test_marker'));
    }
}
