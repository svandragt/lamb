<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

use function Lamb\Bootstrap\ensure_post_columns;
use function Lamb\Export\build_export_archive;
use function Lamb\Import\run_import;
use function Lamb\Restore\import_post;
use function Lamb\Restore\item_skip_reason;
use function Lamb\Restore\manifest_items;
use function Lamb\Restore\open_source;
use function Lamb\Restore\origin_id;
use function Lamb\Restore\parse_restore_args;
use function Lamb\Restore\read_entry;
use function Lamb\Restore\restore_assets;
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

    /**
     * The post shape build_export_archive() consumes, matching collect_posts().
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return $overrides + [
            'id'            => 1,
            'slug'          => 'hello-world',
            'body'          => "---\ntitle: Hello World\nslug: hello-world\n---\n\nBody text.\n",
            'created'       => '2026-07-14 09:30:00',
            'updated'       => '2026-07-15 11:00:00',
            'draft'         => false,
            'deleted'       => false,
            'deleted_at'    => null,
            'version'       => 3,
            'feed_name'     => null,
            'feeditem_uuid' => null,
            'source_url'    => null,
        ];
    }

    /**
     * The posts the round-trip exercises: every state the manifest records.
     *
     * @return list<array<string, mixed>>
     */
    private function samplePosts(): array
    {
        return [
            $this->post(),
            $this->post([
                'id'   => 2,
                'slug' => 'work-in-progress',
                'body' => "---\ntitle: Work in progress\nslug: work-in-progress\n---\n\nNot done.\n",
                'draft' => true,
            ]),
            $this->post([
                'id'   => 3,
                'slug' => 'regrets',
                'body' => "---\ntitle: Regrets\nslug: regrets\n---\n\nBinned.\n",
                'deleted' => true,
                'deleted_at' => '2026-07-20 08:00:00',
            ]),
            $this->post([
                'id'   => 4,
                'slug' => '',
                'body' => "Just a status.\n",
            ]),
            $this->post([
                'id'   => 5,
                'slug' => 'with-a-photo',
                'body' => "---\nslug: with-a-photo\n---\n\n![](/assets/2026/07/photo.png)\n",
            ]),
        ];
    }

    /**
     * An uploads tree holding the one asset samplePosts() references.
     */
    private function assetsRoot(): string
    {
        $root = "$this->tmp_dir/assets_src";
        if (!is_dir("$root/2026/07")) {
            mkdir("$root/2026/07", 0777, true);
        }
        $image = imagecreatetruecolor(4, 3);
        imagepng($image, "$root/2026/07/photo.png");
        imagedestroy($image);

        return $root;
    }

    /**
     * @param list<array<string, mixed>>|null $posts
     */
    private function buildArchive(?array $posts = null, string $url = 'https://example.test', string $name = 'export.zip'): string
    {
        $path = "$this->tmp_dir/$name";
        build_export_archive(
            $posts ?? $this->samplePosts(),
            $this->assetsRoot(),
            $path,
            '2026-07-29T10:00:00+00:00',
            ['title' => 'Notes', 'url' => $url],
        );

        return $path;
    }

    /**
     * Runs the importer over an archive the way import-lamb.php does.
     */
    private function importArchive(string $path, bool $replace = false, ?string $site_url = null): string
    {
        [, $reader] = open_source($path);
        $manifest = read_manifest($reader);
        $items = manifest_items($manifest, $reader, origin_id($manifest, $site_url));

        ob_start();
        run_import(
            $items,
            item_skip_reason(...),
            static fn(array $item): string => (string) $item['import_uuid'],
            import_post(...),
            false,
            static fn(string $uuid): ?\RedBeanPHP\OODBBean => R::findOne('post', ' import_uuid = ? ', [$uuid]),
            $replace,
        );

        return (string) ob_get_clean();
    }

    public function testRoundTripRestoresEveryPostState(): void
    {
        $this->importArchive($this->buildArchive());

        $this->assertSame(5, R::count('post'));

        $hello = R::findOne('post', ' slug = ? ', ['hello-world']);
        $this->assertNotNull($hello);
        $this->assertSame("---\ntitle: Hello World\nslug: hello-world\n---\n\nBody text.\n", $hello->body);
        $this->assertSame('2026-07-14 09:30:00', $hello->created);
        $this->assertSame('2026-07-15 11:00:00', $hello->updated);
        $this->assertSame(0, (int) $hello->draft);
        $this->assertSame(0, (int) $hello->deleted);

        $draft = R::findOne('post', ' slug = ? ', ['work-in-progress']);
        $this->assertSame(1, (int) $draft->draft);

        $trashed = R::findOne('post', ' slug = ? ', ['regrets']);
        $this->assertSame(1, (int) $trashed->deleted);
        $this->assertSame('2026-07-20 08:00:00', $trashed->deleted_at);

        $status = R::findOne('post', ' body LIKE ? ', ['%Just a status%']);
        $this->assertNotNull($status);
        $this->assertSame('', (string) $status->slug);

        $photo = R::findOne('post', ' slug = ? ', ['with-a-photo']);
        $this->assertStringContainsString('/assets/2026/07/photo.png', (string) $photo->body);
    }

    public function testReimportingTheSameArchiveChangesNothing(): void
    {
        $archive = $this->buildArchive();
        $this->importArchive($archive);
        $output = $this->importArchive($archive);

        $this->assertSame(5, R::count('post'));
        $this->assertStringContainsString('created=0 existed=5', $output);
    }

    public function testReplaceOverwritesLocalEditsAndRestoresTrashState(): void
    {
        $archive = $this->buildArchive();
        $this->importArchive($archive);

        $edited = R::findOne('post', ' slug = ? ', ['hello-world']);
        $edited->body = "---\ntitle: Hello World\nslug: hello-world\n---\n\nLocally edited.\n";
        R::store($edited);
        $untrashed = R::findOne('post', ' slug = ? ', ['regrets']);
        $untrashed->deleted = 0;
        $untrashed->deleted_at = null;
        R::store($untrashed);

        $output = $this->importArchive($archive, true);

        $this->assertStringContainsString('replaced=5', $output);
        $this->assertSame(5, R::count('post'));
        $this->assertSame(
            "---\ntitle: Hello World\nslug: hello-world\n---\n\nBody text.\n",
            R::load('post', $edited->id)->body
        );
        $this->assertSame(1, (int) R::load('post', $untrashed->id)->deleted);
        $this->assertSame('2026-07-20 08:00:00', R::load('post', $untrashed->id)->deleted_at);
    }

    public function testArchivesFromDifferentSitesDoNotCollide(): void
    {
        $posts = [$this->post()];
        $this->importArchive($this->buildArchive($posts, 'https://example.test', 'a.zip'));
        $this->importArchive($this->buildArchive($posts, 'https://other.test', 'b.zip'));

        $this->assertSame(2, R::count('post'));
    }

    public function testAnArchiveWithoutASiteUrlStillImportsAndDedupes(): void
    {
        $archive = $this->buildArchive([$this->post()], '', 'no-site.zip');
        $this->importArchive($archive);
        $this->importArchive($archive);

        $this->assertSame(1, R::count('post'));
    }

    public function testTheSiteUrlOverrideNamespacesTheImport(): void
    {
        $archive = $this->buildArchive([$this->post()], '', 'no-site.zip');
        $this->importArchive($archive);
        $this->importArchive($archive, false, 'https://restored.test');

        $this->assertSame(2, R::count('post'));
    }

    public function testAManifestPathWithNoArchiveEntryIsSkipped(): void
    {
        $manifest = $this->manifest([
            'posts' => [
                ['path' => 'posts/2026/07/gone.md', 'id' => 1, 'slug' => 'gone', 'created' => '2026-07-01 00:00:00'],
                ['path' => 'posts/../../evil.md', 'id' => 2],
                ['path' => 'posts/2026/07/blank.md', 'id' => 3],
            ],
        ]);
        $archive = $this->zip([
            'manifest.json'            => (string) json_encode($manifest),
            'posts/2026/07/blank.md'   => "   \n",
        ]);

        $output = $this->importArchive($archive);

        $this->assertSame(0, R::count('post'));
        $this->assertStringContainsString('1' . "\t" . 'missing file', $output);
        $this->assertStringContainsString('1' . "\t" . 'bad path', $output);
        $this->assertStringContainsString('1' . "\t" . 'empty body', $output);
    }

    public function testAnUnpackedDirectoryImportsLikeTheZip(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->buildArchive());
        $zip->extractTo("$this->tmp_dir/unpacked");
        $zip->close();

        $this->importArchive("$this->tmp_dir/unpacked");

        $this->assertSame(5, R::count('post'));
        $this->assertSame('2026-07-14 09:30:00', R::findOne('post', ' slug = ? ', ['hello-world'])->created);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string> $entries In-archive asset path => bytes.
     * @return array{0: array{restored:int,skipped:int,rejected:int}, 1: string}
     */
    private function restoreAssets(array $entries, bool $dry_run = false): array
    {
        $manifest = $this->manifest(['assets' => array_keys($entries)]);
        [, $reader] = open_source($this->zip(
            ['manifest.json' => (string) json_encode($manifest)] + $entries,
            'assets-' . bin2hex(random_bytes(4)) . '.zip'
        ));
        $root = "$this->tmp_dir/assets_dest";

        return [restore_assets($manifest, $root, $reader, $dry_run), $root];
    }

    public function testRestoreAssetsWritesFilesUnderTheirDatedDirectory(): void
    {
        $bytes = $this->pngBytes();
        [$tally, $root] = $this->restoreAssets(['assets/2026/07/photo.png' => $bytes]);

        $this->assertSame(['restored' => 1, 'skipped' => 0, 'rejected' => 0], $tally);
        $this->assertSame($bytes, file_get_contents("$root/2026/07/photo.png"));
    }

    public function testRestoreAssetsRefusesAnExecutableExtension(): void
    {
        [$tally, $root] = $this->restoreAssets(['assets/2026/07/shell.php' => '<?php echo 1;']);

        $this->assertSame(1, $tally['rejected']);
        $this->assertFalse(is_file("$root/2026/07/shell.php"));
    }

    public function testRestoreAssetsRefusesContentThatDoesNotMatchItsExtension(): void
    {
        [$tally, $root] = $this->restoreAssets([
            'assets/2026/07/evil.png' => '<html><body><script>alert(1)</script></body></html>',
        ]);

        $this->assertSame(1, $tally['rejected']);
        $this->assertFalse(is_file("$root/2026/07/evil.png"));
        $this->assertSame([], glob("$root/2026/07/*") ?: []);
    }

    public function testRestoreAssetsRefusesAPathOutsideTheAssetLayout(): void
    {
        $manifest = $this->manifest(['assets' => ['assets/2026/07/../../x.php']]);
        [, $reader] = open_source($this->zip([
            'manifest.json' => (string) json_encode($manifest),
        ], 'traversal.zip'));
        $root = "$this->tmp_dir/assets_dest";

        $tally = restore_assets($manifest, $root, $reader, false);

        $this->assertSame(1, $tally['rejected']);
        $this->assertFalse(is_file("$this->tmp_dir/x.php"));
    }

    public function testRestoreAssetsNeverOverwritesAnExistingFile(): void
    {
        $root = "$this->tmp_dir/assets_dest";
        mkdir("$root/2026/07", 0777, true);
        file_put_contents("$root/2026/07/photo.png", 'keep me');

        [$tally] = $this->restoreAssets(['assets/2026/07/photo.png' => $this->pngBytes()]);

        $this->assertSame(1, $tally['skipped']);
        $this->assertSame('keep me', file_get_contents("$root/2026/07/photo.png"));
    }

    public function testRestoreAssetsSkipsAnAssetMissingFromTheArchive(): void
    {
        $manifest = $this->manifest(['assets' => ['assets/2026/07/gone.png']]);
        [, $reader] = open_source($this->zip([
            'manifest.json' => (string) json_encode($manifest),
        ], 'no-assets.zip'));

        $tally = restore_assets($manifest, "$this->tmp_dir/assets_dest", $reader, false);

        $this->assertSame(['restored' => 0, 'skipped' => 1, 'rejected' => 0], $tally);
    }

    public function testRestoreAssetsWritesNothingOnADryRun(): void
    {
        [$tally, $root] = $this->restoreAssets(['assets/2026/07/photo.png' => $this->pngBytes()], true);

        $this->assertSame(1, $tally['restored']);
        $this->assertFalse(is_dir($root));
    }

    public function testTheCliScriptRunsAnArchiveEndToEnd(): void
    {
        $archive = $this->buildArchive();
        $data_dir = "$this->tmp_dir/data";

        $process = new Process(
            ['php', codecept_root_dir('import-lamb.php'), $archive, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('[dry-run] Done. created=5', $process->getOutput());
    }

    public function testTheCliScriptRefusesAnArchiveItCannotRead(): void
    {
        $process = new Process(
            ['php', codecept_root_dir('import-lamb.php'), "$this->tmp_dir/absent.zip"],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => "$this->tmp_dir/data"] + getenv(),
        );
        $process->run();

        $this->assertSame(1, $process->getExitCode());
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
