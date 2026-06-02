<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\get_menu_slugs;
use function Lamb\Config\is_menu_item;

// Since we need to test a function that depends on global $config, we'll mock it or set it.
class ConfigTest extends TestCase
{
    public function testGetMenuSlugs()
    {
        global $config;
        $original_config = $config;

        $config['menu_items'] = [
            'Home' => '/',
            'About' => 'about',
            'About Us' => '/about-us',
            'Contact' => '/contact/',
            'External' => 'https://example.com',
            'Feed' => '/feed'
        ];

        $slugs = get_menu_slugs();

        $this->assertNotContains('/', $slugs);
        $this->assertContains('about', $slugs);
        $this->assertContains('about-us', $slugs);
        $this->assertContains('contact', $slugs);
        $this->assertContains('feed', $slugs);
        $this->assertNotContains('https://example.com', $slugs);
        $this->assertNotContains('', $slugs);

        $config = $original_config;
    }

    // is_menu_item

    public function testIsMenuItemReturnsTrueForConfiguredSlug(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['About' => 'about'];

        $this->assertTrue(is_menu_item('about'));
        $config = $original;
    }

    public function testIsMenuItemReturnsFalseForUnconfiguredSlug(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['About' => 'about'];

        $this->assertFalse(is_menu_item('contact'));
        $config = $original;
    }

    public function testIsMenuItemReturnsFalseWhenNoMenuItems(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = [];

        $this->assertFalse(is_menu_item('any-slug'));
        $config = $original;
    }

    public function testIsMenuItemMatchesSlugFromRootRelativeUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['Feed' => '/feed'];

        $this->assertTrue(is_menu_item('feed'));
        $config = $original;
    }

    public function testIsMenuItemReturnsFalseForExternalUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['External' => 'https://example.com'];

        $this->assertFalse(is_menu_item('https://example.com'));
        $config = $original;
    }

    public function testDefaultIniSelectsTheTwentyTwentySixThemeForNewInstalls(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('theme', $parsed, 'theme must be a top-level key in the seeded INI');
        $this->assertSame('2026', $parsed['theme']);
    }
}
