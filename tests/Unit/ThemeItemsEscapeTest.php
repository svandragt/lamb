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

    private function renderStatusItem(string $title): string
    {
        $bean = R::dispense('post');
        $bean->title = $title;
        $bean->transformed = '<p>body</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->deleted = false;
        R::store($bean);

        global $data, $template, $config;
        $config = ['menu_items' => []];
        $data = ['posts' => [$bean]];
        $template = 'status';

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/default/parts/_items.php';
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
}
