<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;

/**
 * Acceptance suite helper.
 *
 * Acceptance tests run against a real PHP server backed by a SQLite file at
 * tests/Support/Data/lamb.db. That file persists between runs, so posts created
 * by one test (e.g. seeding a tagged post) can leak into a later test (e.g. a
 * search expecting "No results found.") and cause spurious failures on re-runs.
 *
 * Deleting the database before each test gives every test a clean slate. The
 * server recreates the schema on the next request (ensure_schema()), and the
 * deletion happens while no request is in flight, so it is safe.
 *
 * Never open the live file from here. The server may run as another user
 * (www-data in the release image and under php-fpm) and the database is in WAL
 * mode, so a connection from this process creates lamb.db-wal/-shm owned by the
 * test runner. The server can then no longer write them and every request
 * fails with "attempt to write a readonly database". Reads go through a copy.
 */
class Acceptance extends Module
{
    public function _before(TestInterface $test): void
    {
        $db = dirname(__DIR__) . '/Data/lamb.db';
        if (is_file($db)) {
            @unlink($db);
        }
    }

    /**
     * Reads a single column off a stored post, straight from the SQLite file the
     * test server writes to.
     *
     * Some post state is deliberately invisible in the rendered page — the
     * `version` column records which render format `transformed` was produced
     * with — so asserting on it needs a look at the row itself.
     */
    public function grabPostColumn(int $id, string $column): mixed
    {
        return $this->readFromDatabaseCopy(fn (\PDO $pdo) => $this->fetchPostColumn($pdo, $id, $column));
    }

    /**
     * Asserts the settings INI the server has stored contains the text, read from a copy of the database.
     *
     * A save through the settings form rewrites the INI, so a test that checks what
     * it kept (an unknown key) or dropped (a removed one) has to look at the stored text itself.
     */
    public function seeStoredSettingsContain(string $needle): void
    {
        $ini = (string) $this->readFromDatabaseCopy(
            fn (\PDO $pdo) => $pdo->query('SELECT value FROM "option" WHERE name = \'site_config_ini\'')->fetchColumn()
        );
        $this->assertStringContainsString($needle, $ini);
    }

    /**
     * Asserts the settings INI the server has stored does not contain the text.
     *
     * @param string $needle The text that must be absent.
     */
    public function dontSeeStoredSettingsContain(string $needle): void
    {
        $ini = (string) $this->readFromDatabaseCopy(
            fn (\PDO $pdo) => $pdo->query('SELECT value FROM "option" WHERE name = \'site_config_ini\'')->fetchColumn()
        );
        $this->assertStringNotContainsString($needle, $ini);
    }

    /**
     * Replaces the stored settings INI, straight in the SQLite file the test server reads.
     *
     * Some settings (a key the form does not know) have no field
     * in the /settings form, so a test that needs them seeds them here. Make a
     * request first, so the server has created the database. The connection is
     * closed before returning, which checkpoints the WAL and removes the sidecar
     * files, so the server (which may run as another user) can open the file next.
     *
     * A test that calls this must be in the `seeds-db` group: release-verify runs against an
     * external server whose database this process cannot write, and skips that group.
     *
     * @param string $ini The full settings INI text to store.
     *
     * @return void
     *
     * @throws \PDOException When the database cannot be opened or written.
     */
    public function haveStoredSettings(string $ini): void
    {
        $db = dirname(__DIR__) . '/Data/lamb.db';
        $this->assertFileExists($db, 'Expected the acceptance database to exist: make a request before seeding settings');

        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        try {
            $values = [$ini, date('Y-m-d H:i:s'), 'site_config_ini'];
            $update = $pdo->prepare('UPDATE "option" SET value = ?, updated = ? WHERE name = ?');
            $update->execute($values);
            if ($update->rowCount() === 0) {
                $pdo->prepare('INSERT INTO "option" (value, updated, name) VALUES (?, ?, ?)')->execute($values);
            }
        } finally {
            $pdo = null;
        }
    }

    /**
     * Runs $read against a throwaway copy of the acceptance database.
     *
     * @param callable(\PDO): mixed $read Receives a connection to the copy.
     */
    private function readFromDatabaseCopy(callable $read): mixed
    {
        $db = dirname(__DIR__) . '/Data/lamb.db';
        $this->assertFileExists($db, 'Expected the acceptance database to exist');

        // The server closes its connection at the end of each request, which
        // checkpoints the WAL into the main file, so the copy is complete.
        $copy = tempnam(sys_get_temp_dir(), 'lamb-acceptance-');
        $this->assertTrue(copy($db, $copy), 'Expected to copy the acceptance database');
        try {
            $pdo = new \PDO('sqlite:' . $copy, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return $read($pdo);
        } finally {
            $pdo = null;
            @unlink($copy);
        }
    }

    /**
     * Fetches one column of a post row over an open connection.
     *
     * @param \PDO   $pdo    Connection to a copy of the acceptance database.
     * @param int    $id     Post id.
     * @param string $column Column name, test-supplied.
     * @return mixed The column value, or false when the row is missing.
     */
    private function fetchPostColumn(\PDO $pdo, int $id, string $column): mixed
    {
        // The column name is test-supplied, never request data; quote it so a
        // reserved word still parses.
        $stmt = $pdo->prepare('SELECT "' . str_replace('"', '', $column) . '" FROM post WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn();
    }

    /**
     * Asserts a stored post's column holds the expected value (compared as text,
     * since SQLite's fluid columns may hand back either an int or a string).
     */
    public function seePostColumnEquals(int $id, string $column, mixed $expected): void
    {
        $this->assertSame((string) $expected, (string) $this->grabPostColumn($id, $column));
    }

    /**
     * Returns all values of a response header, joined by newlines.
     *
     * The installed PhpBrowser/InnerBrowser has no seeHttpHeader/grabHttpHeader,
     * so we reach into the BrowserKit client's last response directly.
     */
    public function grabResponseHeader(string $name): string
    {
        /** @var \Codeception\Module\PhpBrowser $browser */
        $browser = $this->getModule('PhpBrowser');
        $values = $browser->client->getInternalResponse()->getHeader($name, false);
        return implode("\n", (array) $values);
    }

    public function seeResponseHeaderContains(string $name, string $needle): void
    {
        $this->assertStringContainsString($needle, $this->grabResponseHeader($name));
    }

    public function dontSeeResponseHeaderContains(string $name, string $needle): void
    {
        $this->assertStringNotContainsString($needle, $this->grabResponseHeader($name));
    }

    public function seeResponseHeaderExists(string $name): void
    {
        $this->assertNotSame('', $this->grabResponseHeader($name), "Expected response header $name to be present");
    }

    public function dontSeeResponseHeader(string $name): void
    {
        $this->assertSame('', $this->grabResponseHeader($name), "Expected response header $name to be absent");
    }

    /**
     * Asserts on the raw response body. $I->see() needs a rendered page in the
     * browser's history, which an AJAX-style request (sendAjaxPostRequest, no
     * page load) never populates — this reads the InnerBrowser response directly.
     */
    public function seeResponseContains(string $needle): void
    {
        /** @var \Codeception\Module\PhpBrowser $browser */
        $browser = $this->getModule('PhpBrowser');
        $this->assertStringContainsString($needle, (string) $browser->client->getInternalResponse()->getContent());
    }

    /**
     * Issues a form-less POST with an empty body to the given path, following any
     * redirect. Lets a test hit a POST-only route (e.g. /delete) without a page
     * rendering its form first; assert the landing spot with $I->seeInCurrentUrl().
     *
     * @param string $url The path to POST to.
     * @return void
     */
    public function sendEmptyPost(string $url): void
    {
        $this->getModule('PhpBrowser')->_request('POST', $url);
    }
}
