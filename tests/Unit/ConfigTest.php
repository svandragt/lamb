<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\compose_config;
use function Lamb\Config\ensure_explicit_theme;
use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\get_menu_slugs;
use function Lamb\Config\is_menu_item;
use function Lamb\Config\resolve_theme;

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

    public function testIsMenuItemMatchesSlugWithOrWithoutLeadingSlash(): void
    {
        // The docs (docs/menu-items.md) document "/about-me" as a menu value
        // that hides the matching page from the timeline. The leading slash is
        // optional: both forms must resolve to the bare post slug "about-me".
        global $config;
        $original = $config;

        $config['menu_items'] = ['About me' => '/about-me'];
        $this->assertTrue(is_menu_item('about-me'), 'Leading-slash menu value should match the bare slug');

        $config['menu_items'] = ['About me' => 'about-me'];
        $this->assertTrue(is_menu_item('about-me'), 'Slugless menu value should match the bare slug');

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

    public function testEnsureExplicitThemeAddsBaseWhenThemeMissing(): void
    {
        $ini = "site_title = Example\n\n[menu_items]\nAbout = about\n";

        $migrated = ensure_explicit_theme($ini);
        $parsed = parse_ini_string($migrated, true, INI_SCANNER_RAW);

        $this->assertSame('base', $parsed['theme']);
        // Existing content is preserved.
        $this->assertSame('Example', $parsed['site_title']);
        $this->assertSame('about', $parsed['menu_items']['About']);
    }

    public function testEnsureExplicitThemeLeavesExistingThemeUntouched(): void
    {
        $ini = "theme = 2024\nsite_title = Example\n";

        $this->assertSame($ini, ensure_explicit_theme($ini));
    }

    public function testEnsureExplicitThemeIsIdempotent(): void
    {
        $ini = "site_title = Example\n";

        $once = ensure_explicit_theme($ini);
        $twice = ensure_explicit_theme($once);

        $this->assertSame($once, $twice);
    }

    public function testResolveThemeFallsBackToBaseWhenUnset(): void
    {
        $this->assertSame('base', resolve_theme(null));
    }

    public function testResolveThemeMapsLegacyDefaultToBase(): void
    {
        $this->assertSame('base', resolve_theme('default'));
    }

    public function testResolveThemePassesThroughOtherThemes(): void
    {
        $this->assertSame('2026', resolve_theme('2026'));
        $this->assertSame('my-custom', resolve_theme('my-custom'));
    }

    public function testDefaultIniSetsSiteTitleForNewInstalls(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        $this->assertSame('My Microblog', $parsed['site_title'] ?? null, 'site_title must be uncommented in the seeded INI');
    }

    public function testDefaultIniSetsHomeAndFeedMenuItemsForNewInstalls(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        $this->assertSame('/', $parsed['menu_items']['Home'] ?? null);
        $this->assertSame('/feed', $parsed['menu_items']['Feed'] ?? null);
    }

    public function testDefaultIniExposesRealDefaultsAtTopLevel(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        $this->assertSame('UTC', $parsed['timezone']);
        $this->assertSame('10', $parsed['posts_per_page']);
        $this->assertSame('https://indieauth.com/auth', $parsed['authorization_endpoint']);
        $this->assertSame('https://tokens.indieauth.com/token', $parsed['token_endpoint']);
        $this->assertArrayHasKey('feeds_draft', $parsed);
    }

    public function testDefaultIniKeepsRealDefaultsOutOfSections(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        // Regression: these were previously commented inside [feeds]/[preconnect],
        // so uncommenting them in place parsed as nested keys the code never read.
        $this->assertArrayNotHasKey('feeds_draft', $parsed['feeds'] ?? []);
        $this->assertArrayNotHasKey('authorization_endpoint', $parsed['preconnect'] ?? []);
        $this->assertArrayNotHasKey('token_endpoint', $parsed['preconnect'] ?? []);
    }

    public function testComposeConfigFillsRealDefaultsForMissingKeys(): void
    {
        $config = compose_config("site_title = Mine\n", get_default_ini_text());

        $this->assertSame('UTC', $config['timezone']);
        $this->assertSame('10', $config['posts_per_page']);
        $this->assertSame('https://indieauth.com/auth', $config['authorization_endpoint']);
    }

    public function testComposeConfigLetsStoredValuesOverrideDefaults(): void
    {
        $config = compose_config("timezone = Europe/London\n", get_default_ini_text());

        $this->assertSame('Europe/London', $config['timezone']);
    }

    public function testComposeConfigSuppliesIdentityPlaceholderFallback(): void
    {
        // author_name is kept commented in the seeded INI, so it must come from
        // the in-code fallback for consumers (feed.php) that have no inline default.
        $config = compose_config("site_title = Mine\n", get_default_ini_text());

        $this->assertSame('Joe Sheeple', $config['author_name']);
    }

    public function testComposeConfigNeverInheritsThemeFromDefaults(): void
    {
        // A stored config without a theme must not be silently re-themed by the
        // seeded default (theme is resolved/migrated per-install instead).
        $config = compose_config("site_title = Mine\n", "theme = 2026\nposts_per_page = 10\n");

        $this->assertArrayNotHasKey('theme', $config);
        $this->assertSame('10', $config['posts_per_page']);
    }

    public function testComposeConfigHonorsTopLevelFeedsDraftFalse(): void
    {
        $config = compose_config("feeds_draft = false\n", get_default_ini_text());

        $this->assertFalse(filter_var($config['feeds_draft'], FILTER_VALIDATE_BOOLEAN));
    }
}
