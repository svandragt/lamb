<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

use function Lamb\Export\asset_source_path;
use function Lamb\Export\build_export_archive;
use function Lamb\Export\build_manifest;
use function Lamb\Export\export_basename;
use function Lamb\Export\export_filename_stem;
use function Lamb\Export\manifest_post_entry;
use function Lamb\Export\post_export_path;
use function Lamb\Export\referenced_assets;
use function Lamb\Post\parse_matter;
use function Lamb\Post\split_frontmatter;

use const Lamb\Export\EXPORT_FORMAT;

/**
 * Covers the export archive's layout, manifest contents and the round-trip
 * guarantee the format rests on: a post body goes into the archive exactly as
 * stored, so parse_matter() reads the exported file back to the same front
 * matter it was saved with.
 *
 * Every function under test takes plain arrays rather than beans, so none of
 * this needs a database.
 */
class ExportTest extends TestCase
{
    private string $tmp_dir;

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/lamb_export_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmp_dir, 0777, true);
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return $overrides + [
            'id'            => 1,
            'slug'          => 'hello-world',
            'body'          => "---\ntitle: Hello World\n---\n\nBody text.\n",
            'created'       => '2026-07-14 09:30:00',
            'updated'       => '2026-07-14 09:30:00',
            'draft'         => false,
            'deleted'       => false,
            'deleted_at'    => null,
            'version'       => 3,
            'feed_name'     => null,
            'feeditem_uuid' => null,
            'source_url'    => null,
        ];
    }

    public function testExportBasenameCarriesTheDate(): void
    {
        $this->assertSame('lamb-export-2026-07-26', export_basename('2026-07-26'));
    }

    public function testPostPathIsFoldersByCreationMonth(): void
    {
        $taken = [];
        $this->assertSame(
            'posts/2026/07/hello-world.md',
            post_export_path($this->post(), $taken)
        );
    }

    public function testPostPathFallsBackToTheIdWhenTheSlugIsEmpty(): void
    {
        $taken = [];
        $this->assertSame(
            'posts/2026/07/post-42.md',
            post_export_path($this->post(['id' => 42, 'slug' => '']), $taken)
        );
    }

    public function testCollidingSlugsGetDistinctPaths(): void
    {
        $taken = [];
        $first = post_export_path($this->post(['id' => 1]), $taken);
        $second = post_export_path($this->post(['id' => 2]), $taken);
        $third = post_export_path($this->post(['id' => 3, 'slug' => 'Hello-World']), $taken);

        $this->assertSame('posts/2026/07/hello-world.md', $first);
        $this->assertSame('posts/2026/07/hello-world-2.md', $second);
        // Differs from the first only by case, which is a collision once the
        // archive is unpacked on a case-insensitive filesystem.
        $this->assertSame('posts/2026/07/Hello-World-3.md', $third);
    }

    public function testFilenameStemStripsPathAndHiddenFileTricks(): void
    {
        $this->assertSame('a-b', export_filename_stem('a/b'));
        $this->assertSame('', export_filename_stem('..'));
        $this->assertSame('', export_filename_stem('../../'));
        $this->assertSame('hidden', export_filename_stem('.hidden'));
        $this->assertSame(80, strlen(export_filename_stem(str_repeat('a', 200))));
    }

    public function testTraversalSlugCannotEscapeThePostsDirectory(): void
    {
        $taken = [];
        $path = post_export_path($this->post(['id' => 7, 'slug' => '../../etc/passwd']), $taken);

        $this->assertSame('posts/2026/07/etc-passwd.md', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    public function testReferencedAssetsFindsRootRelativeAndAbsoluteUrls(): void
    {
        $body = "![one](/assets/2026/07/a.webp)\n"
            . "<img src=\"https://example.test/assets/2025/12/b.png\">\n"
            . "![dup](/assets/2026/07/a.webp)\n"
            . "[not an asset](/2026/07/c.webp)\n";

        $this->assertSame(
            ['2026/07/a.webp', '2025/12/b.png'],
            referenced_assets($body)
        );
    }

    public function testReferencedAssetsIgnoresTraversal(): void
    {
        $this->assertSame([], referenced_assets('/assets/2026/07/../../../etc/passwd'));
    }

    public function testAssetSourcePathRejectsEscapesFromTheRoot(): void
    {
        mkdir($this->tmp_dir . '/assets/2026/07', 0777, true);
        file_put_contents($this->tmp_dir . '/assets/2026/07/a.webp', 'IMG');
        file_put_contents($this->tmp_dir . '/outside.txt', 'SECRET');

        $root = $this->tmp_dir . '/assets';
        $this->assertNotNull(asset_source_path($root, '2026/07/a.webp'));
        $this->assertNull(asset_source_path($root, '../outside.txt'));
        $this->assertNull(asset_source_path($root, '2026/07/missing.webp'));
    }

    public function testManifestEntryFlagsDraftAndTrashState(): void
    {
        $entry = manifest_post_entry(
            $this->post(['draft' => true, 'deleted' => true, 'deleted_at' => '2026-07-20 10:00:00']),
            'posts/2026/07/hello-world.md'
        );

        $this->assertTrue($entry['draft']);
        $this->assertTrue($entry['deleted']);
        $this->assertSame('2026-07-20 10:00:00', $entry['deleted_at']);
    }

    public function testManifestEntryRecordsFeedProvenance(): void
    {
        $entry = manifest_post_entry(
            $this->post([
                'feed_name'     => 'example',
                'feeditem_uuid' => 'abc123',
                'source_url'    => 'https://example.test/post',
            ]),
            'posts/2026/07/hello-world.md'
        );

        $this->assertSame('example', $entry['feed_name']);
        $this->assertSame('abc123', $entry['feeditem_uuid']);
        $this->assertSame('https://example.test/post', $entry['source_url']);
    }

    public function testManifestEntryNeverCarriesPreviewTokensOrBody(): void
    {
        $entry = manifest_post_entry(
            $this->post([
                'preview_token'         => 'super-secret',
                'preview_token_expires' => '2026-08-01 00:00:00',
            ]),
            'posts/2026/07/hello-world.md'
        );

        $this->assertArrayNotHasKey('preview_token', $entry);
        $this->assertArrayNotHasKey('preview_token_expires', $entry);
        $this->assertArrayNotHasKey('body', $entry);
        $this->assertStringNotContainsString('super-secret', json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function testManifestHeaderNamesTheFormatAndCounts(): void
    {
        $manifest = build_manifest(
            [manifest_post_entry($this->post(), 'posts/2026/07/hello-world.md')],
            ['assets/2026/07/a.webp'],
            '2026-07-26T14:00:00+00:00',
            ['title' => 'My Microblog', 'url' => 'https://example.test']
        );

        $this->assertSame(EXPORT_FORMAT, $manifest['format']);
        $this->assertSame('lamb-export/1', $manifest['format']);
        $this->assertSame('2026-07-26T14:00:00+00:00', $manifest['exported_at']);
        $this->assertSame(['posts' => 1, 'assets' => 1], $manifest['counts']);
        $this->assertSame('My Microblog', $manifest['site']['title']);
    }

    public function testArchiveContainsPostsManifestAndReferencedAssets(): void
    {
        mkdir($this->tmp_dir . '/assets/2026/07', 0777, true);
        file_put_contents($this->tmp_dir . '/assets/2026/07/a.webp', 'IMGBYTES');
        // An upload that exists but is referenced by nobody stays out.
        file_put_contents($this->tmp_dir . '/assets/2026/07/orphan.webp', 'ORPHAN');

        $zip_path = $this->tmp_dir . '/export.zip';
        $posts = [
            $this->post([
                'id'   => 1,
                'body' => "---\ntitle: With Image\n---\n\n![a](/assets/2026/07/a.webp)\n",
            ]),
            $this->post([
                'id'      => 2,
                'slug'    => 'a-draft',
                'body'    => "Just a draft.\n",
                'created' => '2025-12-01 08:00:00',
                'draft'   => true,
            ]),
        ];

        $manifest = build_export_archive(
            $posts,
            $this->tmp_dir . '/assets',
            $zip_path,
            '2026-07-26T14:00:00+00:00'
        );

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('posts/2026/07/hello-world.md'));
        $this->assertNotFalse($zip->locateName('posts/2025/12/a-draft.md'));
        $this->assertNotFalse($zip->locateName('assets/2026/07/a.webp'));
        $this->assertFalse($zip->locateName('assets/2026/07/orphan.webp'));

        $this->assertSame('IMGBYTES', $zip->getFromName('assets/2026/07/a.webp'));
        $this->assertSame(['posts' => 2, 'assets' => 1], $manifest['counts']);
        $this->assertSame(['assets/2026/07/a.webp'], $manifest['assets']);

        $written = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($manifest['format'], $written['format']);
        $this->assertSame('posts/2025/12/a-draft.md', $written['posts'][1]['path']);
        $this->assertTrue($written['posts'][1]['draft']);
        $zip->close();
    }

    public function testAMissingAssetDoesNotAbortTheExport(): void
    {
        mkdir($this->tmp_dir . '/assets', 0777, true);
        $zip_path = $this->tmp_dir . '/export.zip';

        $manifest = build_export_archive(
            [$this->post(['body' => "![gone](/assets/2026/07/gone.webp)\n"])],
            $this->tmp_dir . '/assets',
            $zip_path,
            '2026-07-26T14:00:00+00:00'
        );

        $this->assertSame(['posts' => 1, 'assets' => 0], $manifest['counts']);
        $this->assertFileExists($zip_path);
    }

    public function testExportedPostRoundTripsBackThroughParseMatter(): void
    {
        $body = "---\ntitle: Round Trip\nslug: round-trip\n---\n\nBody with a #tag.\n";
        $zip_path = $this->tmp_dir . '/export.zip';

        build_export_archive(
            [$this->post(['slug' => 'round-trip', 'body' => $body])],
            $this->tmp_dir . '/assets',
            $zip_path,
            '2026-07-26T14:00:00+00:00'
        );

        $zip = new ZipArchive();
        $zip->open($zip_path);
        $exported = (string) $zip->getFromName('posts/2026/07/round-trip.md');
        $zip->close();

        // Byte-identical: the export writes the stored body, it does not
        // re-serialise front matter that could drift from what was saved.
        $this->assertSame($body, $exported);

        $matter = parse_matter($exported);
        $this->assertSame('Round Trip', $matter['title']);
        $this->assertSame('round-trip', $matter['slug']);
        [, $content] = split_frontmatter($exported);
        $this->assertSame("\nBody with a #tag.\n", $content);
    }

    public function testEmptySiteExportsAValidEmptyArchive(): void
    {
        $zip_path = $this->tmp_dir . '/export.zip';
        $manifest = build_export_archive([], $this->tmp_dir . '/assets', $zip_path, '2026-07-26T14:00:00+00:00');

        $this->assertSame(['posts' => 0, 'assets' => 0], $manifest['counts']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $zip->close();
    }
    public function testExportSurvivesAnInvalidUtf8ByteInTheManifest(): void
    {
        // One stray byte in a slug or the site title used to abort the whole
        // export with "Malformed UTF-8 characters" — the author could not back
        // the site up at all.
        $dir = sys_get_temp_dir() . '/lamb_export_utf8_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $zip_path = "$dir/export.zip";

        try {
            $manifest = build_export_archive(
                [[
                    'id'      => 1,
                    'slug'    => 'caf' . chr(0xE9) . '-notes',
                    'body'    => "Body text.\n",
                    'created' => '2026-01-02 03:04:05',
                    'updated' => '2026-01-02 03:04:05',
                ]],
                $dir,
                $zip_path,
                '2026-01-02T03:04:05+00:00',
                ['title' => 'Caf' . chr(0xE9) . ' Blog', 'url' => 'https://example.com'],
            );

            $this->assertSame(1, $manifest['counts']['posts']);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path));
            $json = $zip->getFromName('manifest.json');
            $zip->close();
            $this->assertIsString($json);
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            // The byte degrades to U+FFFD; the post file itself is stored raw.
            $this->assertStringContainsString("\u{FFFD}", $decoded['site']['title']);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }
}
