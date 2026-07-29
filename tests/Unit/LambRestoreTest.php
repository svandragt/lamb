<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use RuntimeException;
use ZipArchive;

use function Lamb\Bootstrap\ensure_post_columns;
use function Lamb\Import\run_import;
use function Lamb\Restore\open_source;
use function Lamb\Restore\origin_id;
use function Lamb\Restore\parse_restore_args;
use function Lamb\Restore\read_entry;
use function Lamb\Restore\read_manifest;
use function Lamb\Restore\restore_uuid;
use function Lamb\Restore\safe_entry_path;

use const Lamb\Restore\MAX_ENTRIES;

/**
 * Covers the Lamb export importer: archive reading and validation, the
 * manifest → post restore, and asset restoration.
 */
class LambRestoreTest extends TestCase
{
    private string $tmp_dir;

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/lamb_restore_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmp_dir, 0777, true);

        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = $config ?? [];

        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp_dir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Writes a zip of `entry name => contents` and returns its path.
     *
     * @param array<string, string> $entries
     */
    private function zip(array $entries, string $name = 'archive.zip'): string
    {
        $path = "$this->tmp_dir/$name";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * Writes the same entries as an unpacked directory and returns its path.
     *
     * @param array<string, string> $entries
     */
    private function tree(array $entries, string $name = 'unpacked'): string
    {
        $root = "$this->tmp_dir/$name";
        foreach ($entries as $entry => $contents) {
            $dir = dirname("$root/$entry");
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents("$root/$entry", $contents);
        }

        return $root;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return $overrides + [
            'format'      => 'lamb-export/1',
            'generator'   => 'lamb',
            'exported_at' => '2026-07-29T10:00:00+00:00',
            'site'        => ['title' => 'Notes', 'url' => 'https://example.test'],
            'posts'       => [],
            'assets'      => [],
        ];
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

    public function testSafeEntryPathAcceptsTheExportLayout(): void
    {
        $this->assertSame('manifest.json', safe_entry_path('manifest.json'));
        $this->assertSame('posts/2026/07/hello-world.md', safe_entry_path('posts/2026/07/hello-world.md'));
        $this->assertSame('assets/2026/07/photo.webp', safe_entry_path('assets/2026/07/photo.webp'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeEntryPaths(): array
    {
        return [
            ['posts/../../evil.md'],
            ['assets/2026/07/../../x.php'],
            ['assets/2026/07/..'],
            ['/etc/passwd'],
            ['posts/2026/07/.hidden.md'],
            ['posts/2026/7/short-month.md'],
            ['posts/2026/07/nested/deep.md'],
            ['posts/2026/07/notes.txt'],
            ['README.md'],
            ['assets/2026/07/'],
        ];
    }

    /**
     * @dataProvider unsafeEntryPaths
     */
    public function testSafeEntryPathRefusesAnythingElse(string $name): void
    {
        $this->assertNull(safe_entry_path($name));
    }

    public function testOpenSourceListsAndReadsZipEntries(): void
    {
        [$names, $reader] = open_source($this->zip([
            'manifest.json'              => '{}',
            'posts/2026/07/hello.md'     => 'Hello.',
            'assets/2026/07/photo.webp'  => 'bytes',
        ]));

        sort($names);
        $this->assertSame(
            ['assets/2026/07/photo.webp', 'manifest.json', 'posts/2026/07/hello.md'],
            $names
        );
        $this->assertSame('Hello.', read_entry($reader, 'posts/2026/07/hello.md'));
        $this->assertNull(read_entry($reader, 'posts/2026/07/absent.md'));
    }

    public function testOpenSourceReadsAnUnpackedDirectory(): void
    {
        [$names, $reader] = open_source($this->tree([
            'manifest.json'          => '{}',
            'posts/2026/07/hello.md' => 'Hello.',
        ]));

        sort($names);
        $this->assertSame(['manifest.json', 'posts/2026/07/hello.md'], $names);
        $this->assertSame('Hello.', read_entry($reader, 'posts/2026/07/hello.md'));
    }

    public function testOpenSourceRejectsAnUnreadableSource(): void
    {
        $this->expectException(RuntimeException::class);
        open_source("$this->tmp_dir/nope.zip");
    }

    public function testOpenSourceRejectsAnArchiveWithTooManyEntries(): void
    {
        $entries = [];
        for ($i = 0; $i <= MAX_ENTRIES; $i++) {
            $entries["posts/2026/07/post-$i.md"] = '';
        }

        $this->expectExceptionMessageMatches('/more than ' . MAX_ENTRIES . ' entries/');
        open_source($this->zip($entries, 'huge.zip'));
    }

    public function testReadManifestReturnsTheDecodedDocument(): void
    {
        [, $reader] = open_source($this->zip([
            'manifest.json' => (string) json_encode($this->manifest()),
        ]));

        $this->assertSame('lamb-export/1', read_manifest($reader)['format']);
    }

    public function testReadManifestRejectsAnUnsupportedFormat(): void
    {
        [, $reader] = open_source($this->zip([
            'manifest.json' => (string) json_encode($this->manifest(['format' => 'lamb-export/2'])),
        ]));

        $this->expectException(RuntimeException::class);
        read_manifest($reader);
    }

    public function testReadManifestRejectsInvalidJson(): void
    {
        [, $reader] = open_source($this->zip(['manifest.json' => 'not json']));

        $this->expectException(RuntimeException::class);
        read_manifest($reader);
    }

    public function testReadManifestRejectsAnArchiveWithoutOne(): void
    {
        [, $reader] = open_source($this->zip(['posts/2026/07/hello.md' => 'Hello.']));

        $this->expectException(RuntimeException::class);
        read_manifest($reader);
    }

    public function testOriginIdPrefersTheOverrideAndNormalises(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('https://example.test', origin_id($manifest, null));
        $this->assertSame('https://other.test', origin_id($manifest, 'HTTPS://Other.test/'));
        $this->assertSame('', origin_id($this->manifest(['site' => []]), null));
    }

    public function testRestoreUuidIsStablePerOriginAndId(): void
    {
        $this->assertSame(md5('lamb-https://example.test#7'), restore_uuid('https://example.test', 7));
        $this->assertNotSame(
            restore_uuid('https://example.test', 7),
            restore_uuid('https://other.test', 7)
        );
    }

    public function testParseRestoreArgsReadsTheFlags(): void
    {
        $this->assertSame(
            ['backup.zip', true, true, 'https://example.test'],
            parse_restore_args([
                'import-lamb.php',
                'backup.zip',
                '--dry-run',
                '--replace',
                '--site-url=https://example.test',
            ])
        );
        $this->assertSame(['backup.zip', false, false, null], parse_restore_args(['x', 'backup.zip']));
        $this->assertSame([null, false, false, null], parse_restore_args(['x', '--help']));
    }
}
