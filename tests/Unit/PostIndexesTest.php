<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\migrate_post_table;

use const Lamb\Bootstrap\POST_INDEXES;

/**
 * Covers the index creation in migrate_post_table().
 *
 * RedBeanPHP's fluid mode creates columns but never indexes, so the lookups
 * every request runs — the slug the router resolves the path against, the
 * newest `updated` the conditional-GET validator reads, the two migration
 * probes — were full table scans of `post`.
 *
 * Two things matter beyond "the index exists": that a column the install does
 * not have yet is skipped (naming it in a CREATE INDEX is an error, not a
 * no-op), and that the steady state issues no DDL at all — a `CREATE INDEX IF
 * NOT EXISTS` on every request would take a write lock even though it changes
 * nothing, which is exactly what the migration probes were rewritten to avoid.
 */
class PostIndexesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    /**
     * @param list<string> $columns Extra column definitions beyond `id`.
     */
    private function createPostTable(array $columns): void
    {
        R::exec('CREATE TABLE post (id INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', $columns) . ')');
    }

    /**
     * @return list<string>
     */
    private function postIndexes(): array
    {
        $names = R::getCol("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='post'");
        sort($names);
        return array_values($names);
    }

    /**
     * The DDL a call to migrate_post_table() issues.
     *
     * @return list<string>
     */
    private function ddlDuringEnsureColumns(): array
    {
        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        try {
            migrate_post_table();
            $logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        } finally {
            R::debug(false);
        }

        return array_values(array_filter(
            $logs,
            fn ($line) => is_string($line) && preg_match('/^\s*(CREATE|ALTER)\b/i', $line) === 1
        ));
    }

    public function testEveryIndexedColumnGetsAnIndex(): void
    {
        $this->createPostTable([
            'slug TEXT', 'updated TEXT', 'version INTEGER', 'feed_name TEXT',
            'draft INTEGER', 'deleted INTEGER',
        ]);

        migrate_post_table();

        $expected = array_keys(POST_INDEXES);
        sort($expected);
        $this->assertSame($expected, $this->postIndexes());
    }

    public function testAColumnTheInstallDoesNotHaveIsSkipped(): void
    {
        // A post table carrying only two of the indexed columns. Under
        // bootstrap_db() this cannot happen — ensure_schema() runs first and
        // declares them all — but ensure_post_indexes() must still skip a column
        // that is absent, because naming it in a CREATE INDEX is an error rather
        // than a no-op.
        $this->createPostTable(['slug TEXT', 'updated TEXT']);

        migrate_post_table();

        $this->assertSame(['idx_post_slug', 'idx_post_updated'], $this->postIndexes());
    }

    public function testAColumnAddedLaterIsIndexedOnTheNextCall(): void
    {
        $this->createPostTable(['slug TEXT', 'updated TEXT']);
        migrate_post_table();
        $this->assertNotContains('idx_post_version', $this->postIndexes());

        R::exec('ALTER TABLE post ADD COLUMN version INTEGER');
        migrate_post_table();

        $this->assertContains('idx_post_version', $this->postIndexes());
    }

    public function testTheSteadyStateIssuesNoDdl(): void
    {
        $this->createPostTable([
            'slug TEXT', 'updated TEXT', 'version INTEGER', 'feed_name TEXT',
            'draft INTEGER', 'deleted INTEGER', 'import_uuid TEXT',
        ]);
        migrate_post_table();

        $this->assertSame([], $this->ddlDuringEnsureColumns());
    }

    public function testNothingIsCreatedWhenThePostTableDoesNotExist(): void
    {
        migrate_post_table();

        $this->assertSame([], $this->postIndexes());
    }
}
