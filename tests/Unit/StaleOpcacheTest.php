<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Tests\Support\Filesystem;

use function Lamb\Bootstrap\code_digest_files;
use function Lamb\Bootstrap\opcache_stale_files;
use function Lamb\Bootstrap\run_pending_data_migrations;
use function Lamb\get_option;

/**
 * Right after a git reset, OPcache can still hold the old opcodes of a file for a moment, so a
 * /_cron request would run the old migrations and record the marker of the new code. The
 * request compares what OPcache holds with the files on disk, and refuses to migrate while
 * they differ.
 */
class StaleOpcacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lamb-stale-opcache-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->root = realpath($this->root);
        foreach (['lamb.php', 'network.php'] as $name) {
            file_put_contents($this->root . '/' . $name, "<?php // $name\n");
            touch($this->root . '/' . $name, 2000);
        }
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->root);
    }

    /**
     * @param array<string, int> $timestamps File name => the file's modification time as OPcache recorded it.
     * @return array<string, mixed> The shape of opcache_get_status(true).
     */
    private function opcacheStatus(array $timestamps): array
    {
        $scripts = [];
        foreach ($timestamps as $name => $timestamp) {
            $path = $this->root . '/' . $name;
            $scripts[$path] = ['full_path' => $path, 'timestamp' => $timestamp];
        }

        return ['opcache_enabled' => true, 'scripts' => $scripts];
    }

    private function files(): array
    {
        return [$this->root . '/lamb.php', $this->root . '/network.php'];
    }

    public function testAFileEditedSinceOpcacheCompiledItIsStale(): void
    {
        $status = $this->opcacheStatus(['lamb.php' => 1000, 'network.php' => 2000]);

        $this->assertSame([$this->root . '/lamb.php'], opcache_stale_files($this->files(), $status));
    }

    /**
     * A tarball or `rsync -a` keeps the modification times of the sender, so the new file can
     * be older than the one OPcache compiled.
     */
    public function testAFileOlderThanTheOneOpcacheCompiledIsStaleToo(): void
    {
        $status = $this->opcacheStatus(['lamb.php' => 3000, 'network.php' => 2000]);

        $this->assertSame([$this->root . '/lamb.php'], opcache_stale_files($this->files(), $status));
    }

    public function testFilesCompiledFromTheirCurrentContentAreNotStale(): void
    {
        $status = $this->opcacheStatus(['lamb.php' => 2000, 'network.php' => 2000]);

        $this->assertSame([], opcache_stale_files($this->files(), $status));
    }

    public function testAFileOpcacheHoldsNothingForIsNotStale(): void
    {
        $status = $this->opcacheStatus(['network.php' => 2000]);

        $this->assertSame([], opcache_stale_files($this->files(), $status));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableStatuses(): array
    {
        return [
            'opcache is off or its API is restricted' => [false],
            'no status was passed' => [null],
            'a status without the script list' => [['opcache_enabled' => true]],
        ];
    }

    /**
     * @dataProvider unreadableStatuses
     */
    public function testAnUnreadableStatusIsUnknownNotFresh(mixed $status): void
    {
        $this->assertNull(opcache_stale_files($this->files(), $status));
    }

    public function testTheFilesToCompareAreTheOnesTheMarkerIsDerivedFrom(): void
    {
        $files = code_digest_files();

        $this->assertContains(dirname(__DIR__, 2) . '/src/bootstrap.php', $files);
        $this->assertContains(dirname(__DIR__, 2) . '/src/network/status.php', $files);
        $this->assertNotContains(dirname(__DIR__, 2) . '/src/themes/base/html.php', $files);
    }

    public function testAStaleCacheMakesTheRequestBusyInsteadOfMigrating(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        $stale = [];
        foreach (code_digest_files() as $file) {
            $stale[$file] = ['full_path' => $file, 'timestamp' => (int) filemtime($file) - 10];
        }

        $reply = run_pending_data_migrations(get_option('data_migrated_for', ''), time(), ['scripts' => $stale]);

        $this->assertSame('busy', $reply['result']);
        $this->assertSame(0, (int) R::count('option', ' name = ? ', ['data_migrated_for']), 'no marker is recorded for code that did not run');
    }
}
