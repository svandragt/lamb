<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\is_hidden_menu_item;

/**
 * Characterisation test for the menu-page listing backstop (issue #731).
 *
 * Before this, the same check — "skip this bean unless we're on its own
 * permalink" — was hand-copied into both the base theme's parts/_items.php
 * and Lamb\Theme\render_post_list() (used by the 2024/2026 themes). This
 * pins the one shared predicate both call sites now use; the rendering
 * behaviour itself stays covered by ThemeMenuItemsTest.
 */
class HiddenMenuItemTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        global $config;
        $config = ['menu_items' => ['About' => 'about']];
    }

    protected function tearDown(): void
    {
        global $config;
        $config = [];
    }

    private function makeBean(?string $slug): \RedBeanPHP\OODBBean
    {
        $bean       = R::dispense('post');
        $bean->slug = $slug;
        return $bean;
    }

    public function testMenuPageIsHiddenOutsideItsOwnPermalink(): void
    {
        $this->assertTrue(is_hidden_menu_item('tag', $this->makeBean('about')));
    }

    public function testMenuPageIsNotHiddenOnItsOwnPermalink(): void
    {
        $this->assertFalse(is_hidden_menu_item('status', $this->makeBean('about')));
    }

    public function testOrdinaryPostIsNeverHidden(): void
    {
        $this->assertFalse(is_hidden_menu_item('tag', $this->makeBean('hello-world')));
    }

    public function testUnsluggedPostIsNeverHidden(): void
    {
        $this->assertFalse(is_hidden_menu_item('tag', $this->makeBean(null)));
    }
}
