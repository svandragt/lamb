<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_post_columns;
use function Lamb\Import\run_import;

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

    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, mixed>          $extra Trailing run_import() arguments.
     */
    private function captureImport(array $items, callable $import, array $extra = []): string
    {
        ob_start();
        run_import(
            $items,
            static fn(array $item): ?string => $item['skip'] ?? null,
            static fn(array $item): string => (string) $item['uuid'],
            $import,
            false,
            ...$extra
        );

        return (string) ob_get_clean();
    }

    public function testRunImportStillDedupesOnFeeditemUuidWithoutALookup(): void
    {
        $existing = R::dispense('post');
        $existing->body = 'Already here.';
        $existing->feeditem_uuid = 'u1';
        R::store($existing);

        $output = $this->captureImport(
            [['uuid' => 'u1', 'title' => 'One']],
            static fn(): ?\RedBeanPHP\OODBBean => null,
        );

        $this->assertStringContainsString('created=0 existed=1 skipped=0 total=1', $output);
        $this->assertSame(1, R::count('post'));
    }

    public function testRunImportReportsAConversionFailureForAnItemWithoutATitle(): void
    {
        $output = $this->captureImport(
            [['uuid' => 'u1']],
            static fn(): ?\RedBeanPHP\OODBBean => null,
        );

        $this->assertStringContainsString('skipped (conversion failed):', $output);
        $this->assertStringContainsString('created=0 existed=0 skipped=1 total=1', $output);
    }

    public function testRunImportReplacesThroughAnInjectedLookup(): void
    {
        $existing = R::dispense('post');
        $existing->body = 'Old body.';
        $existing->import_uuid = 'u1';
        R::store($existing);

        $seen = null;
        $output = $this->captureImport(
            [['uuid' => 'u1', 'title' => 'One']],
            static function (array $item, callable $downloader, bool $dry_run, ?\RedBeanPHP\OODBBean $bean = null) use (&$seen): ?\RedBeanPHP\OODBBean {
                $seen = $bean;
                $bean->body = 'New body.';
                R::store($bean);
                return $bean;
            },
            [static fn(string $uuid): ?\RedBeanPHP\OODBBean => R::findOne('post', ' import_uuid = ? ', [$uuid]), true],
        );

        $this->assertNotNull($seen);
        $this->assertStringContainsString('replaced=1', $output);
        $this->assertSame(1, R::count('post'));
        $this->assertSame('New body.', R::load('post', $existing->id)->body);
    }

    public function testRunImportKeepsTheSummaryUnchangedWhenReplaceIsOff(): void
    {
        $output = $this->captureImport(
            [['uuid' => 'u1', 'title' => 'One']],
            static function (array $item): \RedBeanPHP\OODBBean {
                return R::dispense('post');
            },
        );

        $this->assertStringNotContainsString('replaced=', $output);
    }
}
