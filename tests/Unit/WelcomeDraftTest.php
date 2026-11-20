<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Lamb\Export\build_export_archive;

/**
 * A new install starts with one welcome draft (#1004). The first boot of a new
 * database records that the draft is pending, and the first web request that
 * has booted completely saves it. Each case runs a child process, because
 * bootstrap_db() sets up its own connection, which would replace the shared
 * in-memory one the rest of the suite uses.
 */
class WelcomeDraftTest extends TestCase
{
    /** What src/index.php runs for a logged-in request, once the database, subscribers and timezone are set. */
    private const WEB_REQUEST = '\Lamb\Post\register_default_subscribers(); \Lamb\Bootstrap\seed_welcome_draft();';

    /** Makes every insert into `post` fail, which a save cannot recover from. */
    private const REFUSE_POSTS = <<<'PHP'
    R::exec("CREATE TRIGGER refuse_posts BEFORE INSERT ON post BEGIN SELECT RAISE(ABORT, 'refused'); END");
    PHP;

    /** @var list<string> */
    private array $dirs = [];

    /** What the last child process wrote to its error stream. */
    private string $stderr = '';

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
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
     * The script a child runs: boot the data directory, run the statements,
     * and print what the database holds. The statements may add keys to $report.
     */
    private function script(string $statements): string
    {
        return str_replace('/* STATEMENTS */', $statements, <<<'PHP'
        use RedBeanPHP\R;

        require $argv[1];
        \Lamb\Bootstrap\bootstrap_db($argv[2]);
        $report = [];
        /* STATEMENTS */
        $report['posts'] = R::getAll('SELECT title, slug, body, draft, deleted, created, preview_token FROM post ORDER BY id');
        $report['pending'] = R::count('option', ' name = ? ', ['welcome_draft_pending']);
        echo json_encode($report);
        PHP);
    }

    /**
     * Boots the data directory in a new process, runs PHP statements afterwards,
     * and reports the post rows, the pending marker count and any $report keys.
     *
     * @param string $dir        The data directory to boot.
     * @param string $statements PHP to run once bootstrap_db() has returned.
     * @return array{posts: list<array<string, mixed>>, pending: int}&array<string, mixed>
     * @throws \JsonException When the child prints something that is not JSON.
     */
    private function boot(string $dir, string $statements = ''): array
    {
        $process = new Process(['php', '-r', $this->script($statements), dirname(__DIR__, 2) . '/vendor/autoload.php', $dir]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->stderr = $process->getErrorOutput();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs one web request against the data directory.
     *
     * @return list<array<string, mixed>> The post rows afterwards.
     */
    private function request(string $dir): array
    {
        return $this->boot($dir, self::WEB_REQUEST)['posts'];
    }

    /**
     * Runs bin/lamb with the data directory set, as an operator would.
     *
     * @param list<string> $arguments
     */
    private function binLamb(string $dir, array $arguments): Process
    {
        $process = new Process(
            ['php', dirname(__DIR__, 2) . '/bin/lamb', ...$arguments],
            dirname(__DIR__, 2),
            ['LAMB_DATA_DIR' => $dir] + getenv(),
        );
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process;
    }

    /**
     * Writes the database an earlier release left behind: the given tables,
     * with one post row when asked.
     *
     * @param list<string> $tables Names of the tables to create.
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

    public function testBootingANewDatabaseOnlyRecordsThatTheDraftIsPending(): void
    {
        $boot = $this->boot($this->dataDir());

        $this->assertSame([], $boot['posts']);
        $this->assertSame(1, $boot['pending']);
    }

    public function testTheFirstWebRequestSavesOneWelcomeDraft(): void
    {
        $dir = $this->dataDir();

        $boot = $this->boot($dir, self::WEB_REQUEST);

        $this->assertCount(1, $boot['posts']);
        $this->assertSame('Hello from Lamb', $boot['posts'][0]['title']);
        $this->assertSame('hello-from-lamb', $boot['posts'][0]['slug']);
        $this->assertSame(1, (int) $boot['posts'][0]['draft']);
        $this->assertNotSame(1, (int) $boot['posts'][0]['deleted']);
        $this->assertNotSame('', (string) $boot['posts'][0]['preview_token']);
        $this->assertSame(0, $boot['pending']);
    }

    public function testTheWelcomeDraftIsTrueOncePublishedAndLinksTheTargetPost(): void
    {
        $body = $this->request($this->dataDir())[0]['body'];

        $this->assertStringContainsString('draft: true', $body);
        // Publishing is removing the draft line, so the rest is what the public reads.
        $this->assertStringEndsWith(
            "---\n\nHello! This is the first post on my new site, which runs on [Lamb](" . DO_YOU_RUN_LAMB_URL . ').',
            trim($body)
        );
    }

    public function testTheDraftIsDatedInTheTimezoneTheRequestHasSet(): void
    {
        $dir = $this->dataDir();
        $zone = new \DateTimeZone('Pacific/Kiritimati');

        $boot = $this->boot($dir, "date_default_timezone_set('Pacific/Kiritimati');" . self::WEB_REQUEST);

        $created = new \DateTimeImmutable((string) $boot['posts'][0]['created'], $zone);
        $now = new \DateTimeImmutable('now', $zone);
        $this->assertLessThanOrEqual($now->getTimestamp(), $created->getTimestamp(), 'the draft must not count as scheduled');
        $this->assertGreaterThan($now->getTimestamp() - 300, $created->getTimestamp());
    }

    public function testTheDraftIsSavedAfterTheSchemaIsFrozen(): void
    {
        $statements = <<<'PHP'
        try {
            $probe = R::dispense('option');
            $probe->undeclared_column = 'x';
            R::store($probe);
            $report['frozen'] = false;
        } catch (\RedBeanPHP\RedException\SQL) {
            $report['frozen'] = true;
        }
        PHP;

        $boot = $this->boot($this->dataDir(), $statements . self::WEB_REQUEST);

        $this->assertTrue($boot['frozen']);
        $this->assertCount(1, $boot['posts']);
    }

    public function testIndexSeedsTheDraftAfterTheDatabaseTheSubscribersAndTheTimezoneAreSet(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/src/index.php');

        $seed = strpos($index, 'seed_welcome_draft()');
        $this->assertNotFalse($seed, 'src/index.php must seed the welcome draft');
        foreach (['bootstrap_db(', 'register_default_subscribers()', 'apply_timezone('] as $earlier) {
            $this->assertLessThan($seed, (int) strpos($index, $earlier), "$earlier must run before the seed");
        }
    }

    public function testIndexSeedsTheDraftOnlyForALoggedInRequest(): void
    {
        $index = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/src/index.php'));

        $this->assertStringContainsString(
            'if (isset($_SESSION[SESSION_LOGIN])) { Bootstrap\seed_welcome_draft(); }',
            $index,
            'an anonymous request, such as a health check, must not seed or read the pending row'
        );
    }

    public function testASecondRequestAddsNothing(): void
    {
        $dir = $this->dataDir();
        $this->request($dir);

        $this->assertCount(1, $this->request($dir));
    }

    public function testADraftTheOwnerDeletedIsNotSavedAgain(): void
    {
        $dir = $this->dataDir();
        $this->request($dir);
        $this->boot($dir, "R::exec('DELETE FROM post');");

        $this->assertCount(0, $this->request($dir));
    }

    public function testAFailedSaveIsLoggedOnceAndTheDraftIsDroppedSoNoLaterRequestTriesAgain(): void
    {
        $dir = $this->dataDir();

        $boot = $this->boot($dir, self::REFUSE_POSTS . self::WEB_REQUEST);

        $this->assertSame([], $boot['posts']);
        $this->assertSame(0, $boot['pending']);
        $this->assertSame(1, substr_count($this->stderr, 'Could not save the welcome draft'));

        $later = $this->boot($dir, 'R::exec("DROP TRIGGER refuse_posts");' . self::WEB_REQUEST);

        $this->assertSame([], $later['posts']);
        $this->assertStringNotContainsString('welcome draft', $this->stderr);
    }

    public function testAnExceptionThatIsNotAnSqlErrorDoesNotStopTheRequestOrRepeat(): void
    {
        $dir = $this->dataDir();
        // A transaction that is already open makes the save throw a LogicException.
        $statements = <<<'PHP'
        R::exec('BEGIN');
        \Lamb\Bootstrap\seed_welcome_draft();
        R::exec('COMMIT');
        PHP;

        $boot = $this->boot($dir, $statements . self::WEB_REQUEST);

        $this->assertSame([], $boot['posts']);
        $this->assertSame(0, $boot['pending']);
    }

    public function testAPostSavedBeforeTheFirstLoginCancelsTheDraft(): void
    {
        // What a Micropub create does while nobody is logged in.
        $statements = <<<'PHP'
        \Lamb\Post\save(\Lamb\Post\populate_bean('A note from a Micropub client.'), ['finalize_slug' => true, 'notify' => true]);
        PHP;

        $boot = $this->boot($this->dataDir(), $statements . self::WEB_REQUEST);

        $this->assertSame(['A note from a Micropub client.'], array_column($boot['posts'], 'body'));
        $this->assertSame(0, $boot['pending']);
    }

    public function testAFeedItemThatArrivedFirstCancelsTheDraft(): void
    {
        // A feed item shows on the home page or the drafts page like any post, so the site is not empty.
        $statements = <<<'PHP'
        $item = R::dispense('post');
        $item->body = 'An item from a feed.';
        $item->feed_name = 'Example';
        $item->draft = 1;
        R::store($item);
        PHP;

        $boot = $this->boot($this->dataDir(), $statements . self::WEB_REQUEST);

        $this->assertSame(['An item from a feed.'], array_column($boot['posts'], 'body'));
        $this->assertSame(0, $boot['pending']);
    }

    public function testATrashedPostAlsoMeansTheSiteIsNotNew(): void
    {
        $statements = <<<'PHP'
        $post = R::dispense('post');
        $post->body = 'Deleted before the first login.';
        $post->deleted = 1;
        R::store($post);
        PHP;

        $boot = $this->boot($this->dataDir(), $statements . self::WEB_REQUEST);

        $this->assertCount(1, $boot['posts']);
        $this->assertSame(0, $boot['pending']);
    }

    public function testADatabaseThatAlreadyHasPostsGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['post'], true);

        $posts = $this->request($dir);

        $this->assertCount(1, $posts);
        $this->assertSame('An older post.', $posts[0]['body']);
    }

    public function testADatabaseWhoseOwnerHasNoPostsYetGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['post', 'option'], false);

        $this->assertCount(0, $this->request($dir));
    }

    public function testADatabaseWithSettingsButNoPostTableGetsNoDraft(): void
    {
        $dir = $this->dataDir();
        $this->existingDatabase($dir, ['option'], false);

        $this->assertCount(0, $this->request($dir));
    }

    public function testImportingIntoANewDataDirectoryLeavesNoDraft(): void
    {
        $dir = $this->dataDir();
        $wxr = $dir . '/export.xml';
        file_put_contents($wxr, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel><item>
            <title>Hello World</title>
            <link>https://oldsite.example/hello-world/</link>
            <guid isPermaLink="false">https://oldsite.example/?p=42</guid>
            <content:encoded><![CDATA[<p>Hello world.</p>]]></content:encoded>
            <wp:post_date>2024-03-03 11:00:00</wp:post_date>
            <wp:post_id>42</wp:post_id>
            <wp:post_name>hello-world</wp:post_name>
            <wp:status>publish</wp:status>
            <wp:post_type>post</wp:post_type>
        </item></channel>
        </rss>
        XML);
        $this->binLamb($dir . '/data', ['import', 'wordpress', $wxr, '--experimental']);

        $boot = $this->boot($dir . '/data', self::WEB_REQUEST);

        $this->assertSame(['hello-world'], array_column($boot['posts'], 'slug'));
        $this->assertSame(0, $boot['pending'], 'an import leaves the row, and the first logged-in request drops it');
    }

    public function testRestoringAnArchiveIntoANewDataDirectoryLeavesNoDraft(): void
    {
        $dir = $this->dataDir();
        $archive = $dir . '/export.zip';
        build_export_archive(
            [[
                'id' => 1, 'slug' => 'hello-world', 'body' => "---\ntitle: Hello World\nslug: hello-world\n---\n\nBody text.\n",
                'created' => '2026-07-14 09:30:00', 'updated' => '2026-07-15 11:00:00', 'draft' => false,
                'deleted' => false, 'deleted_at' => null, 'feed_locked' => false, 'version' => 3,
                'feed_name' => null, 'feeditem_uuid' => null, 'source_url' => null,
            ]],
            $dir . '/no-assets',
            $archive,
            '2026-07-29T10:00:00+00:00',
            ['title' => 'Notes', 'url' => 'https://example.test'],
        );
        $this->binLamb($dir . '/data', ['import', 'lamb', $archive, '--experimental']);

        $posts = $this->request($dir . '/data');

        $this->assertSame(['hello-world'], array_column($posts, 'slug'));
    }

    public function testMigratingANewDataDirectoryKeepsTheDraftForTheFirstWebRequest(): void
    {
        $dir = $this->dataDir();
        $this->binLamb($dir . '/data', ['migrate']);

        $this->assertSame(['hello-from-lamb'], array_column($this->request($dir . '/data'), 'slug'));
    }

    /**
     * Runs logged-in requests against a booted data directory while this test
     * holds the database's write lock, and lets go of the lock only once every
     * request is about to ask for it.
     *
     * A child signals from a query logger when its BEGIN IMMEDIATE is about to
     * run. By then it has read the pending row, so it acts on the state from
     * before anything the test commits. The children then take the lock in turn.
     *
     * @param string $dir        The data directory, booted first so that the pending row exists.
     * @param int    $requests   How many requests run together.
     * @param string $under_lock SQL the test runs while it holds the lock, committed with it.
     * @return array{bodies: list<string>, pending: int} The posts and the pending rows afterwards.
     */
    private function seedWhileHoldingTheLock(string $dir, int $requests, string $under_lock = ''): array
    {
        $this->boot($dir);
        $pdo = new \PDO('sqlite:' . $dir . '/lamb.db', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('BEGIN IMMEDIATE');
        if ($under_lock !== '') {
            $pdo->exec($under_lock);
        }

        $script = <<<'PHP'
        use RedBeanPHP\R;

        require $argv[1];
        \Lamb\Bootstrap\bootstrap_db($argv[2]);
        \Lamb\Post\register_default_subscribers();
        R::getDatabaseAdapter()->getDatabase()->setLogger(new class ($argv[3]) implements \RedBeanPHP\Logger {
            public function __construct(private string $file)
            {
            }

            public function log()
            {
                if (str_starts_with((string) (func_get_args()[0] ?? ''), 'BEGIN IMMEDIATE')) {
                    touch($this->file);
                }
            }
        })->setEnableLogging(true);
        \Lamb\Bootstrap\seed_welcome_draft();
        PHP;
        $children = [];
        for ($n = 1; $n <= $requests; $n++) {
            $children[$n] = new Process(['php', '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $dir, "$dir/ready-$n"]);
            $children[$n]->start();
        }
        $this->waitUntil(
            static fn(): bool => count(glob("$dir/ready-*") ?: []) === $requests
                || count(array_filter($children, static fn(Process $child): bool => !$child->isRunning())) > 0
        );
        $pdo->exec('COMMIT');
        $pdo = null;

        foreach ($children as $child) {
            $child->wait();
            $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput() . $child->getOutput());
        }
        $this->assertCount($requests, glob("$dir/ready-*") ?: [], 'every request must have asked for the write lock');

        $after = $this->boot($dir);

        return ['bodies' => array_column($after['posts'], 'body'), 'pending' => $after['pending']];
    }

    /**
     * Polls until the condition holds, and fails the test when it does not within 30 seconds.
     *
     * @param callable(): bool $condition
     */
    private function waitUntil(callable $condition): void
    {
        $deadline = microtime(true) + 30;
        while (!$condition()) {
            $this->assertLessThan($deadline, microtime(true), 'timed out waiting for the child processes');
            usleep(5_000);
        }
    }

    public function testTwoRequestsThatReadThePendingRowBeforeEitherWritesSaveOneDraft(): void
    {
        $result = $this->seedWhileHoldingTheLock($this->dataDir(), 2);

        $this->assertCount(1, $result['bodies']);
        $this->assertStringContainsString('title: Hello from Lamb', $result['bodies'][0]);
        $this->assertSame(0, $result['pending']);
    }

    public function testAPostCommittedWhileTheRequestWaitsForTheLockCancelsTheDraft(): void
    {
        $result = $this->seedWhileHoldingTheLock(
            $this->dataDir(),
            1,
            "INSERT INTO post (body) VALUES ('Posted while the draft waited.')"
        );

        $this->assertSame(['Posted while the draft waited.'], $result['bodies']);
        $this->assertSame(0, $result['pending']);
    }

    public function testAPendingRowDroppedWhileTheRequestWaitsForTheLockCancelsTheDraft(): void
    {
        $result = $this->seedWhileHoldingTheLock(
            $this->dataDir(),
            1,
            "DELETE FROM \"option\" WHERE name = 'welcome_draft_pending'"
        );

        $this->assertSame([], $result['bodies']);
        $this->assertSame(0, $result['pending']);
    }
}
