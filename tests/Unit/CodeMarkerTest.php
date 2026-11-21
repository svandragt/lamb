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

    public function testTheMarkerMovesWithTheFilesWhenNoCommitIsAvailable(): void
    {
        // The fixture has no .git, so code_stamp() has no commit to follow, as in a Docker image.
        $before = code_marker($this->root);

        file_put_contents($this->root . '/src/lamb.php', "<?php // v2\n");

        $this->assertNotSame($before, code_marker($this->root));
        $this->assertMatchesRegularExpression('/^code:[0-9a-f]{12}$/', code_marker($this->root));
    }

    public function testTheMarkerMovesWithTheCommit(): void
    {
        $before = code_marker($this->root);

        mkdir($this->root . '/.git', 0777, true);
        file_put_contents($this->root . '/.git/HEAD', str_repeat('a', 40) . "\n");
        $first = code_marker($this->root);
        file_put_contents($this->root . '/.git/HEAD', str_repeat('b', 40) . "\n");
        $second = code_marker($this->root);

        $this->assertNotSame($first, $second, 'a git upgrade that changes no PHP file still moves it');
        $this->assertNotSame($before, $first);
    }

    public function testTheMarkerIsTheSameForTheSameCode(): void
    {
        $this->assertSame(code_marker(), code_marker());
        $this->assertSame(code_marker($this->root), code_marker($this->root));
    }
}
