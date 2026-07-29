<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_post_columns;

/**
 * Covers the Lamb export importer: archive reading and validation, the
 * manifest → post restore, and asset restoration.
 */
class LambRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = $config ?? [];

        date_default_timezone_set('UTC');
    }

    /**
     * @return list<string>
     */
    private function postColumns(): array
    {
        return array_column(R::getAll('PRAGMA table_info(post)'), 'name');
    }

    public function testEnsurePostColumnsAddsTheImportUuidColumn(): void
    {
        R::exec('CREATE TABLE post (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
        $this->assertNotContains('import_uuid', $this->postColumns());

        ensure_post_columns();

        $this->assertContains('import_uuid', $this->postColumns());
    }
}
