<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_post_columns;

/**
 * Covers the one-time migration in ensure_post_columns() that moves posts
 * stamped by the old WordPress/Known importers off the feed columns and onto
 * import_uuid.
 *
 * The migration keys on `source_url IS NULL` because a user may legitimately
 * subscribe to a feed literally named `wordpress` or `known`; feed ingestion
 * always records the item permalink in source_url, the importers never did.
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
            . ' feed_name TEXT, feeditem_uuid TEXT, source_url TEXT)'
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

    public function testBackfillMovesLegacyImportedPostsOntoImportUuid(): void
    {
        $wp = $this->insert('wordpress', md5('wordpress-https://old.example/?p=1'), null);
        $known = $this->insert('known', md5('known-https://old.example/view/a'), null);

        ensure_post_columns();

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

        ensure_post_columns();

        $row = $this->row($id);
        $this->assertSame('wordpress', $row['feed_name']);
        $this->assertSame(md5('wordpressitem-1'), $row['feeditem_uuid']);
        $this->assertNull($row['import_uuid']);
    }

    public function testBackfillIsIdempotent(): void
    {
        $id = $this->insert('wordpress', md5('wordpress-https://old.example/?p=1'), null);

        ensure_post_columns();
        $first = $this->row($id);
        ensure_post_columns();

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

        ensure_post_columns();

        $row = $this->row($legacy);
        $this->assertNull($row['import_uuid']);
        $this->assertSame('wordpress', $row['feed_name']);
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM post WHERE import_uuid = ?', [$uuid]));
    }

    public function testBackfillNoOpsWithoutAPostTable(): void
    {
        R::exec('DROP TABLE post');

        ensure_post_columns();

        $this->assertNull(R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='post'"));
    }
}
