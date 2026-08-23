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
 * server recreates the schema on the next request (RedBeanPHP fluid mode), and
 * the deletion happens while no request is in flight, so it is safe.
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
        $db = dirname(__DIR__) . '/Data/lamb.db';
        $this->assertFileExists($db, 'Expected the acceptance database to exist');

        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
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
