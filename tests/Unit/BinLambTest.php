<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Lamb\Import\get_source;
use function Lamb\Import\register_source;
use function Lamb\Import\source_names;

/**
 * Covers the importer registry (Import\register_source / get_source /
 * source_names) and bin/lamb, the single CLI driver that replaced the
 * hand-rolled bootstrap each import-*.php script used to repeat.
 */
class BinLambTest extends TestCase
{
    private string $tmp_dir;

    private const SAMPLE_WXR = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <item>
        <title>Hello World</title>
        <link>https://oldsite.example/blog/hello-world/</link>
        <guid isPermaLink="false">https://oldsite.example/?p=42</guid>
        <pubDate>Mon, 03 Mar 2024 10:00:00 +0000</pubDate>
        <content:encoded><![CDATA[<p>Hello world.</p>]]></content:encoded>
        <wp:post_date>2024-03-03 11:00:00</wp:post_date>
        <wp:post_id>42</wp:post_id>
        <wp:post_name>hello-world</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
    </item>
</channel>
</rss>
XML;

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/lamb_bin_lamb_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmp_dir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp_dir]))->run();
    }

    public function testGetSourceReturnsNullForAnUnregisteredName(): void
    {
        $this->assertNull(get_source('does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function testRegisterSourceMakesItFindableByName(): void
    {
        $name = 'test-source-' . bin2hex(random_bytes(4));
        register_source([
            'name'        => $name,
            'parse'       => static fn(string $path): string => $path,
            'extract'     => static fn(string $path): array => [],
            'skip_reason' => static fn(array $item): ?string => null,
            'uuid'        => static fn(array $item): string => 'u',
            'import'      => static fn(array $item): ?\RedBeanPHP\OODBBean => null,
        ]);

        $source = get_source($name);
        $this->assertNotNull($source);
        $this->assertSame($name, $source['name']);
        $this->assertContains($name, source_names());
    }

    /**
     * Seeds a fresh, file-backed data dir with `experimental_features = true`,
     * mirroring LambRestoreTest's helper of the same purpose: the subprocess
     * under test bootstraps its own SQLite file, a distinct connection from
     * this test's in-memory DB.
     */
    private function enableExperimentalFeaturesInDataDir(string $data_dir): void
    {
        mkdir($data_dir, 0777, true);
        $root = rtrim(codecept_root_dir(), '/');
        $setup = new Process(
            [
                'php',
                '-r',
                "define('ROOT_DIR', '$root/src'); require '$root/vendor/autoload.php'; "
                . "\\Lamb\\Bootstrap\\bootstrap_db(getenv('LAMB_DATA_DIR')); "
                . "\\Lamb\\Config\\load(); "
                . "\\Lamb\\Config\\save_ini_text(\"experimental_features = true\\n\");",
            ],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $setup->mustRun();
    }

    public function testTheDriverRunsAWordpressImportEndToEnd(): void
    {
        $wxr = "$this->tmp_dir/export.xml";
        file_put_contents($wxr, self::SAMPLE_WXR);
        $data_dir = "$this->tmp_dir/data";
        $this->enableExperimentalFeaturesInDataDir($data_dir);

        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'import', 'wordpress', $wxr, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('[dry-run] Done. created=1', $process->getOutput());
    }

    /**
     * run_import() is shared, but the wiring around it (bootstrap, arg
     * parsing, the registry lookup) is new: this pins the new driver's
     * stdout against the old script's, byte for byte, for the same input.
     */
    public function testWordpressDryRunOutputMatchesTheOldScriptByteForByte(): void
    {
        $wxr = "$this->tmp_dir/export.xml";
        file_put_contents($wxr, self::SAMPLE_WXR);

        $old_data_dir = "$this->tmp_dir/data-old";
        $this->enableExperimentalFeaturesInDataDir($old_data_dir);
        $old = new Process(
            ['php', codecept_root_dir('import-wordpress.php'), $wxr, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $old_data_dir] + getenv(),
        );
        $old->run();

        $new_data_dir = "$this->tmp_dir/data-new";
        $this->enableExperimentalFeaturesInDataDir($new_data_dir);
        $new = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'import', 'wordpress', $wxr, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $new_data_dir] + getenv(),
        );
        $new->run();

        $this->assertSame(0, $old->getExitCode(), $old->getErrorOutput());
        $this->assertSame(0, $new->getExitCode(), $new->getErrorOutput());
        $this->assertSame($old->getOutput(), $new->getOutput());
    }

    public function testUnknownSourceExitsNonZeroListingTheValidSources(): void
    {
        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'import', 'bogus', "$this->tmp_dir/whatever"],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => "$this->tmp_dir/data"] + getenv(),
        );
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('wordpress', $process->getErrorOutput());
        $this->assertStringContainsString('known', $process->getErrorOutput());
        $this->assertStringContainsString('lamb', $process->getErrorOutput());
    }

    public function testTheDriverRefusesWhenExperimentalFeaturesDisabled(): void
    {
        $wxr = "$this->tmp_dir/export.xml";
        file_put_contents($wxr, self::SAMPLE_WXR);

        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'import', 'wordpress', $wxr, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => "$this->tmp_dir/data"] + getenv(),
        );
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('experimental', $process->getErrorOutput());
    }

    public function testTheOldWordpressScriptDelegatesAndWarns(): void
    {
        $wxr = "$this->tmp_dir/export.xml";
        file_put_contents($wxr, self::SAMPLE_WXR);
        $data_dir = "$this->tmp_dir/data";
        $this->enableExperimentalFeaturesInDataDir($data_dir);

        $process = new Process(
            ['php', codecept_root_dir('import-wordpress.php'), $wxr, '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('deprecated', strtolower($process->getErrorOutput()));
        $this->assertStringContainsString('[dry-run] Done. created=1', $process->getOutput());
    }
}
