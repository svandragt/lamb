<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\Filesystem;

use function Lamb\Bootstrap\code_digest;
use function Lamb\Bootstrap\code_marker;

/**
 * The marker records which code a database was last migrated for. It must move on every
 * release, including a Docker or tarball install that has no commit for code_stamp() to read,
 * and it must not need a number someone bumps by hand.
 */
class CodeMarkerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lamb-code-marker-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/response', 0777, true);
        mkdir($this->root . '/src/themes/custom', 0777, true);
        mkdir($this->root . '/src/assets/2026', 0777, true);
        mkdir($this->root . '/bin', 0777, true);
        file_put_contents($this->root . '/src/lamb.php', "<?php // v1\n");
        file_put_contents($this->root . '/src/response/feeds.php', "<?php // v1\n");
        file_put_contents($this->root . '/bin/lamb', "#!/usr/bin/env php\n<?php // v1\n");
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->root);
    }

    public function testTheDigestIsStableForUnchangedCode(): void
    {
        $this->assertSame(code_digest($this->root), code_digest($this->root));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', code_digest($this->root));
    }

    public function testTheDigestChangesWhenAFileOfTheCodeChanges(): void
    {
        $before = code_digest($this->root);

        file_put_contents($this->root . '/src/response/feeds.php', "<?php // v2\n");

        $this->assertNotSame($before, code_digest($this->root));
    }

    public function testTheDigestChangesWhenTheCommandLineEntryPointChanges(): void
    {
        $before = code_digest($this->root);

        file_put_contents($this->root . '/bin/lamb', "#!/usr/bin/env php\n<?php // v2\n");

        $this->assertNotSame($before, code_digest($this->root));
    }

    /**
     * A scheduled /_cron request can come while `composer install` is still replacing vendor/,
     * and would then migrate with the new src/ and the old libraries. Composer writes
     * installed.json last, so the marker moves again once the install has finished.
     */
    public function testTheDigestChangesWhenTheInstalledPackagesChange(): void
    {
        $before = code_digest($this->root);
        mkdir($this->root . '/vendor/composer', 0777, true);

        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages":[]}');
        $installed = code_digest($this->root);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages":[{"name":"a/b"}]}');

        $this->assertNotSame($before, $installed);
        $this->assertNotSame($installed, code_digest($this->root));
    }

    public function testTheDigestWorksWithoutAnInstalledJson(): void
    {
        $this->assertFileDoesNotExist($this->root . '/vendor/composer/installed.json');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', code_digest($this->root));
    }

    /**
     * The CLI and the web server run as different users. A file only one of them can read must
     * not make their digests differ, and md5_file() would log a warning on every request.
     */
    public function testTheDigestSkipsAFileTheUserCannotRead(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads every file.');
        }
        $before = code_digest($this->root);
        file_put_contents($this->root . '/src/network.php', "<?php\n");
        $withReadableFile = code_digest($this->root);
        chmod($this->root . '/src/network.php', 0000);

        $warnings = [];
        set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $withUnreadableFile = code_digest($this->root);
        } finally {
            restore_error_handler();
        }

        $this->assertNotSame($before, $withReadableFile);
        $this->assertSame($before, $withUnreadableFile, 'the unreadable file does not count');
        $this->assertSame([], $warnings);
    }

    public function testTheDigestChangesWhenAFileIsAdded(): void
    {
        $before = code_digest($this->root);

        file_put_contents($this->root . '/src/network.php', "<?php\n");

        $this->assertNotSame($before, code_digest($this->root));
    }

    public function testTheDigestIgnoresUploadsAndCustomThemes(): void
    {
        $before = code_digest($this->root);

        file_put_contents($this->root . '/src/assets/2026/upload.php', "<?php // uploaded\n");
        file_put_contents($this->root . '/src/themes/custom/html.php', "<?php // custom theme\n");
        file_put_contents($this->root . '/src/themes/custom.php', "<?php // custom theme\n");

        $this->assertSame($before, code_digest($this->root));
    }

    public function testTheDigestDefaultsToTheRunningInstall(): void
    {
        $this->assertNotSame(code_digest($this->root), code_digest());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', code_digest());
    }

    public function testTheMarkerMovesWithTheFiles(): void
    {
        $before = code_marker($this->root);

        file_put_contents($this->root . '/src/lamb.php', "<?php // v2\n");

        $this->assertNotSame($before, code_marker($this->root));
        $this->assertMatchesRegularExpression('/^code:[0-9a-f]{12}$/', code_marker($this->root));
    }

    /**
     * Migrations live in PHP files. A commit that changes none needs none, and the commit is
     * readable by the user that owns the checkout but not always by the web server user, so
     * counting it would make `bin/lamb migrate` and /_cron compute different markers.
     */
    public function testTheMarkerDoesNotMoveWithTheCommit(): void
    {
        mkdir($this->root . '/.git', 0777, true);
        file_put_contents($this->root . '/.git/HEAD', str_repeat('a', 40) . "\n");
        $first = code_marker($this->root);

        file_put_contents($this->root . '/.git/HEAD', str_repeat('b', 40) . "\n");

        $this->assertSame($first, code_marker($this->root));
    }

    public function testTheMarkerIsTheSameForTheSameCode(): void
    {
        $this->assertSame(code_marker(), code_marker());
        $this->assertSame(code_marker($this->root), code_marker($this->root));
    }
}
