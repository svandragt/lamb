<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\backfill_imported_post_identity;
use function Lamb\Bootstrap\backfill_post_version;

/**
 * Covers backfill_imported_post_identity() and backfill_post_version(), the
 * one-time post-table migrations that used to run from migrate_post_table()
 * on every boot and now run only from `bin/lamb migrate` (#811).
 *
 * backfill_imported_post_identity() moves posts stamped by the old
 * WordPress/Known importers off the feed columns and onto import_uuid. It
 * keys on `source_url IS NULL` because a user may legitimately subscribe to
 * a feed literally named `wordpress` or `known`; feed ingestion always
 * records the item permalink in source_url, the importers never did.
 */
class ImportIdentityBackfillTest extends TestCase
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
            // import_uuid is the migration's destination: both its probe and its
            // UPDATE name it. ensure_schema() declares it in production.
            . ' feed_name TEXT, feeditem_uuid TEXT, source_url TEXT, import_uuid TEXT)'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = R::getRow('SELECT * FROM post WHERE id = ?', [$id]);
        return is_array($row) ? $row : [];
    }

    private function insert(string $feed_name, string $feeditem_uuid, ?string $source_url): int
    {
        R::exec(
            'INSERT INTO post (body, feed_name, feeditem_uuid, source_url) VALUES (?, ?, ?, ?)',
            ['body', $feed_name, $feeditem_uuid, $source_url]
        );
        return (int) R::getCell('SELECT last_insert_rowid()');
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return array_column(R::getAll('PRAGMA table_info(post)'), 'name');
    }

    /**
     * The write statements a callable issues.
     *
     * SQLite takes a write lock for an UPDATE even when no row matches it, and
     * writers serialise — so a migration that ran unconditionally on every
     * boot made every request a writer and every read wait behind any
     * concurrent write (up to PDO's 60-second busy timeout) instead of
     * sharing a read lock. What matters is therefore not just the resulting
     * rows but whether anything was written at all.
     *
     * @return list<string>
     */
    private function writesDuring(callable $fn): array
    {
        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        try {
            $fn();
            $logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        } finally {
            R::debug(false);
        }

        return array_values(array_filter(
            $logs,
            fn ($line) => is_string($line) && preg_match('/^\s*(UPDATE|INSERT|DELETE|ALTER)\b/i', $line) === 1
        ));
    }

    public function testNoWriteWhenThereIsNothingToMigrate(): void
    {
        // Steady state: the columns exist and no row needs the migration.
        $this->insert('', '', null);
        backfill_imported_post_identity($this->columns());

        $this->assertSame([], $this->writesDuring(fn () => backfill_imported_post_identity($this->columns())));
    }

    public function testTheIdentityBackfillWritesOnlyWhenARowMatches(): void
    {
        $this->insert('wordpress', md5('wordpress-https://old.example/?p=1'), null);
        backfill_imported_post_identity($this->columns());
        // Probe now finds nothing: this call records completion (one write,
        // the marker row) rather than truly nothing (issue #811).
        backfill_imported_post_identity($this->columns());

        // Marker is set: the probe itself is skipped on the next call.
        $this->assertSame([], $this->writesDuring(fn () => backfill_imported_post_identity($this->columns())));
    }

    public function testTheVersionStampWritesOnlyWhenAPostNeedsIt(): void
    {
        R::exec('ALTER TABLE post ADD COLUMN version INTEGER');
        R::exec("INSERT INTO post (body, version) VALUES ('body', NULL)");

        $writes = $this->writesDuring(fn () => backfill_post_version($this->columns()));
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('version', $writes[0]);
        $this->assertSame(1, (int) R::getCell('SELECT version FROM post LIMIT 1'));

        // Stamped: the next call's probe finds nothing and records that (one
        // write, the marker row) — see issue #811.
        $this->writesDuring(fn () => backfill_post_version($this->columns()));
        // Only the call after that is truly silent.
        $this->assertSame([], $this->writesDuring(fn () => backfill_post_version($this->columns())));
    }

    public function testTheVersionStampIsSkippedWhenTheColumnDoesNotExist(): void
    {
        // A post table predating the version column has nothing to stamp, and
        // naming a missing column in an UPDATE is an error rather than a no-op.
        $this->insert('', '', null);

        $this->assertSame([], $this->writesDuring(fn () => backfill_post_version($this->columns())));
    }

    public function testBackfillMovesLegacyImportedPostsOntoImportUuid(): void
    {
        $wp = $this->insert('wordpress', md5('wordpress-https://old.example/?p=1'), null);
        $known = $this->insert('known', md5('known-https://old.example/view/a'), null);

        backfill_imported_post_identity($this->columns());

        foreach ([$wp => 'wordpress-https://old.example/?p=1', $known => 'known-https://old.example/view/a'] as $id => $seed) {
            $row = $this->row($id);
            $this->assertSame(md5($seed), $row['import_uuid']);
            $this->assertNull($row['feeditem_uuid']);
            $this->assertNull($row['feed_name']);
        }
    }

    public function testBackfillLeavesGenuineFeedPostsWithTheSameFeedNameAlone(): void
    {
        $id = $this->insert('wordpress', md5('wordpressitem-1'), 'https://feed.example/item-1');

        backfill_imported_post_identity($this->columns());

        $row = $this->row($id);
        $this->assertSame('wordpress', $row['feed_name']);
        $this->assertSame(md5('wordpressitem-1'), $row['feeditem_uuid']);
        $this->assertNull($row['import_uuid']);
    }

    public function testBackfillIsIdempotent(): void
    {
        $id = $this->insert('wordpress', md5('wordpress-https://old.example/?p=1'), null);

        backfill_imported_post_identity($this->columns());
        $first = $this->row($id);
        backfill_imported_post_identity($this->columns());

        $this->assertSame($first, $this->row($id));
    }

    public function testBackfillSkipsRowsWhoseImportUuidIsAlreadyTaken(): void
    {
        $uuid = md5('wordpress-https://old.example/?p=1');
        R::exec('ALTER TABLE post ADD COLUMN import_uuid TEXT');
        R::exec(
            'INSERT INTO post (body, import_uuid) VALUES (?, ?)',
            ['restored', $uuid]
        );
        $legacy = $this->insert('wordpress', $uuid, null);

        backfill_imported_post_identity($this->columns());

        $row = $this->row($legacy);
        $this->assertNull($row['import_uuid']);
        $this->assertSame('wordpress', $row['feed_name']);
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM post WHERE import_uuid = ?', [$uuid]));
    }

    public function testBackfillNoOpsWithoutAPostTable(): void
    {
        R::exec('DROP TABLE post');

        backfill_imported_post_identity($this->columns());

        $this->assertNull(R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='post'"));
    }
}
