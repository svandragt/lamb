<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A new install starts with one welcome draft (#1004). Each case boots a data
 * directory the way every request does, with bootstrap_db(), in a child process:
 * the function sets up its own connection, which would replace the shared
 * in-memory one the rest of the suite uses.
 */
class WelcomeDraftTest extends TestCase
{
    private const TUTORIAL_URL = 'https://svandragt.github.io/lamb/first-post';
    private const TARGET_URL = 'https://vandragt.com/do-you-run-lamb';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    private function dataDir(): string
    {
        $dir = sys_get_temp_dir() . '/lamb_welcome_draft_test_' . uniqid();
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * Boots the data directory in a new process, runs an optional statement
     * afterwards, and returns every post row.
     *
     * @param string $dir   The data directory to boot.
     * @param string $after PHP statements to run once the boot has finished.
     * @return list<array<string, mixed>>
     * @throws \JsonException When the child prints something that is not JSON.
     */
    private function boot(string $dir, string $after = ''): array
    {
        $script = <<<'PHP'
        use RedBeanPHP\R;

        require $argv[1];
        \Lamb\Bootstrap\bootstrap_db($argv[2]);
        /* AFTER */
        echo json_encode(R::getAll('SELECT title, slug, body, draft, deleted, preview_token FROM post ORDER BY id'));
        PHP;

        $process = new Process([
            'php', '-r', str_replace('/* AFTER */', $after, $script),
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $dir,
        ]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        /** @var list<array<string, mixed>> */
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Writes the database an earlier release left behind: the given tables,
     * with one post row when asked.
     *
     * @param string       $dir    The data directory.
     * @param list<string> $tables Names of the tables to create.
     * @param bool         $post   Whether the post table holds a published post.
     */
    private function existingDatabase(string $dir, array $tables, bool $post): void
    {
        $pdo = new \PDO('sqlite:' . $dir . '/lamb.db', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ($tables as $table) {
            $pdo->exec("CREATE TABLE \"$table\" (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)");
        }
        if ($post) {
            $pdo->exec("INSERT INTO post (body) VALUES ('An older post.')");
        }
        $pdo = null;
    }

    public function testAFreshDatabaseGetsOneWelcomeDraft(): void
    {
        $posts = $this->boot($this->dataDir());

        $this->assertCount(1, $posts);
        $this->assertSame('Hello from Lamb', $posts[0]['title']);
        $this->assertSame('hello-from-lamb', $posts[0]['slug']);
        $this->assertSame(1, (int) $posts[0]['draft']);
        $this->assertNotSame(1, (int) $posts[0]['deleted']);
        $this->assertNotSame('', (string) $posts[0]['preview_token']);
    }

    public function testTheWelcomeDraftLinksTheTutorialAndTheTargetPostAndEndsWithTheInvitation(): void
    {
        $body = $this->boot($this->dataDir())[0]['body'];

        $this->assertStringContainsString('draft: true', $body);
        $this->assertStringContainsString('](' . self::TUTORIAL_URL . ')', $body);
        $this->assertStringContainsString('](' . self::TARGET_URL . ')', $body);
        $this->assertStringEndsWith("\nPublish this to say hello, or delete it.", trim($body));
    }

    public function testBootingTheSameDatabaseAgainAddsNothing(): void
    {
        $dir = $this->dataDir();
        $this->boot($dir);

        $this->assertCount(1, $this->boot($dir));
    }

    public function testADraftTheOwnerDeletedIsNotSeededAgain(): void
    {
        $dir = $this->dataDir();
        $this->boot($dir);

        $posts = $this->boot($dir, "R::exec('DELETE FROM post');");

        $this->assertCount(0, $posts);
        $this->assertCount(0, $this->boot($dir));
    }

    public function testAnotherBootRacingTheFirstDoesNotAddASecondDraft(): void
    {
        $posts = $this->boot($this->dataDir(), '\Lamb\Bootstrap\seed_welcome_draft();');

        $this->assertCount(1, $posts);
    }

    public function testADatabaseThatAlreadyHasPostsGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['post'], true);

        $posts = $this->boot($dir);

        $this->assertCount(1, $posts);
        $this->assertSame('An older post.', $posts[0]['body']);
    }

    public function testADatabaseWhoseOwnerHasNoPostsYetGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['post', 'option'], false);

        $this->assertCount(0, $this->boot($dir));
    }

    public function testADatabaseWithSettingsButNoPostTableGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['option'], false);

        $this->assertCount(0, $this->boot($dir));
    }
}
