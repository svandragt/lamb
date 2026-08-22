<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\li_footer_items;
use function Lamb\Theme\li_menu_items;

class ThemeNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
    }

    public function testLiMenuItemsReturnsEmptyWhenNoItems(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = [];

        $this->assertSame('', li_menu_items());

        $config = $original;
    }

    public function testLiMenuItemsRendersAbsoluteUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['Home' => '/'];

        $html = li_menu_items();
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringContainsString('Home', $html);

        $config = $original;
    }

    public function testLiMenuItemsEscapesLabel(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['<script>' => '/'];

        $html = li_menu_items();
        $this->assertStringNotContainsString('<script>', $html);

        $config = $original;
    }

    public function testLiFooterItemsReturnsEmptyWhenNoItems(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = [];

        $this->assertSame('', li_footer_items());

        $config = $original;
    }

    public function testLiFooterItemsReturnsEmptyWhenKeyMissing(): void
    {
        global $config;
        $original = $config;
        unset($config['footer_items']);

        $this->assertSame('', li_footer_items());

        $config = $original;
    }

    public function testLiFooterItemsRendersAbsoluteUrl(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['Privacy' => '/privacy'];

        $html = li_footer_items();
        $this->assertStringContainsString('href="/privacy"', $html);
        $this->assertStringContainsString('Privacy', $html);

        $config = $original;
    }

    public function testLiFooterItemsRendersExternalUrl(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['Source' => 'https://github.com/example'];

        $html = li_footer_items();
        $this->assertStringContainsString('href="https://github.com/example"', $html);
        $this->assertStringContainsString('Source', $html);

        $config = $original;
    }

    public function testLiFooterItemsEscapesLabel(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['<script>' => '/'];

        $html = li_footer_items();
        $this->assertStringNotContainsString('<script>', $html);

        $config = $original;
    }

    public function testLiFooterItemsRendersBareSlugWithRootUrl(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['About' => 'about'];

        $html = li_footer_items();
        $this->assertStringContainsString('href="http://localhost/about"', $html);

        $config = $original;
    }

    /**
     * A mailto: link has no "http" prefix and doesn't start with "/", so it used
     * to fall into the bare-slug branch and get ROOT_URL prefixed onto it,
     * producing a broken "http://localhost/mailto:..." link instead of a
     * working mail link. footer_items documents contact/social links as a use
     * case, and config.php's own [me] example uses this exact mailto: value.
     */
    public function testLiFooterItemsRendersMailtoLinkUnmodified(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['Email' => 'mailto:you@example.com'];

        $html = li_footer_items();
        $this->assertStringContainsString('href="mailto:you@example.com"', $html);
        $this->assertStringNotContainsString('http://localhost/mailto:', $html);

        $config = $original;
    }

    public function testLiFooterItemsRendersTelLinkUnmodified(): void
    {
        global $config;
        $original = $config;
        $config['footer_items'] = ['Call' => 'tel:+1234567890'];

        $html = li_footer_items();
        $this->assertStringContainsString('href="tel:+1234567890"', $html);

        $config = $original;
    }
}
