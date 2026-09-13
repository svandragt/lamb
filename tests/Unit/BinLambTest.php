<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Lamb\Bootstrap\db_owned_by_another_user;
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

    /**
    /**
     * #831: a CLI run against a database owned by someone else leaves WAL
     * sidecars that a read-only connection can never checkpoint away, so
     * every later web request dies in ensure_schema(). The predicate takes
     * the uid as a parameter, so these cases need no second real account
     * and no test-only override inside bin/lamb.
     *
     * @return void
     */
    public function testDbOwnedByAnotherUserDetectsAMismatchedOwner(): void
    {
        $db = "$this->tmp_dir/lamb.db";
        touch($db);
        $owner = fileowner($db);
        $this->assertIsInt($owner);

        $this->assertTrue(
            db_owned_by_another_user($db, $owner + 1),
            'a different uid must be refused',
        );
        $this->assertFalse(
            db_owned_by_another_user($db, $owner),
            'the owning uid must be allowed through',
        );
    }

    /**
     * A database that isn't there yet is the normal first run, and an
     * undeterminable uid (no posix extension) must not block a legitimate
     * run — an unnecessary refusal costs an import, a missed one costs a
     * 500 the operator can recover from by deleting two files.
     *
     * @return void
     */
    public function testDbOwnedByAnotherUserAllowsMissingFileAndUnknownUid(): void
    {
        $db = "$this->tmp_dir/absent.db";
        $this->assertFalse(db_owned_by_another_user($db, 12345));

        touch($db);
        $this->assertFalse(db_owned_by_another_user($db, null));
    }

    /**
     * Dispenses and stores a post bean directly in $data_dir's lamb.db, in a
     * subprocess: the version and body a bin/lamb upgrade-posts test needs to
     * seed are set before this test process's own bootstrap runs, so a
     * separate connection (this file-backed db) never crosses the in-memory
     * one other tests in this suite use.
     */
    private function seedPost(string $data_dir, int $version, string $body): int
    {
        $root = rtrim(codecept_root_dir(), '/');
        $process = new Process(
            [
                'php',
                '-r',
                "define('ROOT_DIR', '$root/src'); require '$root/vendor/autoload.php'; "
                . "\\Lamb\\Bootstrap\\bootstrap_db(getenv('LAMB_DATA_DIR')); "
                . '$bean = \RedBeanPHP\R::dispense("post"); '
                . '$bean->body = getenv("SEED_BODY"); '
                . '$bean->version = (int) getenv("SEED_VERSION"); '
                . 'echo \RedBeanPHP\R::store($bean);',
            ],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir, 'SEED_BODY' => $body, 'SEED_VERSION' => (string) $version] + getenv(),
        );
        $process->mustRun();

        return (int) trim($process->getOutput());
    }

    /**
     * Reads back a stored post's `version` column, in its own subprocess for
     * the same connection-isolation reason as seedPost().
     */
    private function storedPostVersion(string $data_dir, int $id): int
    {
        $root = rtrim(codecept_root_dir(), '/');
        $process = new Process(
            [
                'php',
                '-r',
                "define('ROOT_DIR', '$root/src'); require '$root/vendor/autoload.php'; "
                . "\\Lamb\\Bootstrap\\bootstrap_db(getenv('LAMB_DATA_DIR')); "
                . 'echo (int) \RedBeanPHP\R::load("post", (int) getenv("SEED_ID"))->version;',
            ],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir, 'SEED_ID' => (string) $id] + getenv(),
        );
        $process->mustRun();

        return (int) trim($process->getOutput());
    }

    public function testUpgradePostsCommandUpgradesEveryPostBelowPostVersion(): void
    {
        $data_dir = "$this->tmp_dir/data";
        $id = $this->seedPost($data_dir, 1, 'plain body');

        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'upgrade-posts'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('needs_upgrade=1 upgraded=1', $process->getOutput());
        $this->assertSame(POST_VERSION, $this->storedPostVersion($data_dir, $id));
    }

    public function testUpgradePostsDryRunChangesNothing(): void
    {
        $data_dir = "$this->tmp_dir/data";
        $id = $this->seedPost($data_dir, 1, 'plain body');

        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'upgrade-posts', '--dry-run'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('[dry-run] Done. needs_upgrade=1 upgraded=0', $process->getOutput());
        $this->assertSame(1, $this->storedPostVersion($data_dir, $id));
    }

    public function testUpgradePostsCommandLeavesAnAlreadyCurrentPostAlone(): void
    {
        $data_dir = "$this->tmp_dir/data";
        $id = $this->seedPost($data_dir, POST_VERSION, 'plain body');

        $process = new Process(
            ['php', codecept_root_dir('bin/lamb'), 'upgrade-posts'],
            codecept_root_dir(),
            ['LAMB_DATA_DIR' => $data_dir] + getenv(),
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('needs_upgrade=0 upgraded=0', $process->getOutput());
        $this->assertSame(POST_VERSION, $this->storedPostVersion($data_dir, $id));
    }
}
