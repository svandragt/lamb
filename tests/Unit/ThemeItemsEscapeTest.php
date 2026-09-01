<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Ensures the default theme's post-list partial escapes the post title.
 *
 * On a single-post ("status") page the title is rendered as plain text (not a
 * link). Feed-ingested posts carry titles from untrusted remote sources, so the
 * value must be HTML-escaped to prevent stored XSS.
 */
class ThemeItemsEscapeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function renderStatusItem(string $title, int|bool|null $deleted = false): string
    {
        $bean = R::dispense('post');
        $bean->title = $title;
        $bean->transformed = '<p>body</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->deleted = $deleted;
        R::store($bean);

        global $data, $template, $config;
        $config = ['menu_items' => []];
        $data = ['posts' => [$bean]];
        $template = 'status';
        $_SESSION[SESSION_LOGIN] = true;

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/_items.php';
        return (string) ob_get_clean();
    }

    public function testStatusTitleIsHtmlEscaped(): void
    {
        $html = $this->renderStatusItem('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testStatusTitlePlainTextRenders(): void
    {
        $html = $this->renderStatusItem('Hello World');
        $this->assertStringContainsString('Hello World', $html);
    }

    /**
     * The base theme's _items.php picked Restore-vs-Delete with the raw
     * `$bean->deleted` truthy check instead of the canonical is_deleted()
     * predicate ((bean->deleted ?? null) == 1). A non-canonical truthy value
     * (deleted = 2) would previously still show Restore even though
     * is_deleted() says the post is not deleted -- pin the delegation.
     */
    public function testNonCanonicalDeletedValueShowsDeleteNotRestore(): void
    {
        $html = $this->renderStatusItem('Hello World', 2);
        $this->assertStringContainsString('form-delete', $html);
        $this->assertStringNotContainsString('form-restore', $html);
    }

    public function testCanonicalDeletedValueShowsRestoreNotDelete(): void
    {
        $html = $this->renderStatusItem('Hello World', 1);
        $this->assertStringContainsString('form-restore', $html);
        $this->assertStringNotContainsString('form-delete', $html);
    }
}
