<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Covers the base theme's "hide menu pages from listings" rule.
 *
 * A post whose slug is pinned in [menu_items] is a page reachable from the nav,
 * so it is kept out of the chronological stream. The home listing and the feeds
 * exclude it in SQL (public_posts_clause), but tag and search listings do not —
 * the partial is the only thing filtering those. It used to test a non-existent
 * `is_menu_item` bean property and fall through to the numeric id, so it never
 * matched a slug and never hid anything.
 *
 * The rule must not apply on a permalink: there the menu page *is* the post that
 * was asked for.
 */
class ThemeMenuItemsTest extends TestCase
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
        global $data, $template, $config;
        $_SESSION = [];
        $data     = [];
        $template = null;
        $config   = [];
    }

    /**
     * @param array<string, string> $menu_items
     */
    private function render(string $slug, string $template_name, array $menu_items): string
    {
        $bean              = R::dispense('post');
        $bean->slug        = $slug;
        $bean->title       = 'About this site';
        $bean->transformed = '<p>menu page body</p>';
        $bean->created     = '2024-01-01 12:00:00';
        $bean->deleted     = false;
        R::store($bean);

        global $data, $template, $config;
        $config   = ['menu_items' => $menu_items];
        $data     = ['posts' => [$bean]];
        $template = $template_name;

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/_items.php';
        return (string) ob_get_clean();
    }

    public function testMenuPageIsHiddenFromAListing(): void
    {
        $html = $this->render('about', 'tag', ['About' => 'about']);

        $this->assertStringNotContainsString('menu page body', $html);
    }

    public function testMenuPageIsHiddenWhenTheMenuUrlIsRootRelative(): void
    {
        $html = $this->render('about', 'search', ['About' => '/about']);

        $this->assertStringNotContainsString('menu page body', $html);
    }

    public function testOrdinaryPostIsNotHiddenFromAListing(): void
    {
        $html = $this->render('hello-world', 'tag', ['About' => 'about']);

        $this->assertStringContainsString('menu page body', $html);
    }

    public function testMenuPageStillRendersOnItsOwnPermalink(): void
    {
        $html = $this->render('about', 'status', ['About' => 'about']);

        $this->assertStringContainsString('menu page body', $html);
    }
}
