<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\author_name;
use function Lamb\Config\compose_config;
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
        $this->assertSame('https://indieauth.com/auth', $parsed['authorization_endpoint']);
        $this->assertSame('https://tokens.indieauth.com/token', $parsed['token_endpoint']);
        $this->assertArrayHasKey('feeds_draft', $parsed);
    }

    public function testDefaultIniKeepsRealDefaultsOutOfSections(): void
    {
        $parsed = parse_ini_string(get_default_ini_text(), true, INI_SCANNER_RAW);

        // Regression: these were previously commented inside [feeds],
        // so uncommenting them in place parsed as nested keys the code never read.
        $this->assertArrayNotHasKey('feeds_draft', $parsed['feeds'] ?? []);
    }

    public function testComposeConfigFillsRealDefaultsForMissingKeys(): void
    {
        $config = compose_config("site_title = Mine\n", get_default_ini_text());

        $this->assertSame('UTC', $config['timezone']);
        $this->assertSame('https://indieauth.com/auth', $config['authorization_endpoint']);
    }

    public function testComposeConfigLetsStoredValuesOverrideDefaults(): void
    {
        $config = compose_config("timezone = Europe/London\n", get_default_ini_text());

        $this->assertSame('Europe/London', $config['timezone']);
    }

    public function testComposeConfigLeavesAnUnsetAuthorOutInsteadOfSupplyingAPlaceholder(): void
    {
        $config = compose_config("site_title = Mine\n", get_default_ini_text());

        $this->assertArrayNotHasKey('author_name', $config);
        $this->assertArrayNotHasKey('author_email', $config);
    }

    public function testComposeConfigNeverInheritsThemeFromDefaults(): void
    {
        // A stored config without a theme must not be silently re-themed by the
        // seeded default (Theme\resolve_theme() falls back to the base theme
        // for a missing theme instead).
        $config = compose_config("site_title = Mine\n", "theme = 2026\ntimezone = UTC\n");

        $this->assertArrayNotHasKey('theme', $config);
        $this->assertSame('UTC', $config['timezone']);
    }

    public function testComposeConfigHonorsTopLevelFeedsDraftFalse(): void
    {
        $config = compose_config("feeds_draft = false\n", get_default_ini_text());

        $this->assertFalse(filter_var($config['feeds_draft'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testDefaultIniOmitsTheSettingsRemovedIn017(): void
    {
        $text = get_default_ini_text();

        foreach (['posts_per_page', '404_fallback', 'preconnect', 'micropub_debug', 'experimental_features'] as $key) {
            $this->assertStringNotContainsString($key, $text);
        }
    }

    /**
     * @dataProvider authorNameProvider
     */
    public function testAuthorNameReturnsTheTrimmedNameOrAnEmptyString(array $config, string $expected): void
    {
        $this->assertSame($expected, author_name($config));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function authorNameProvider(): array
    {
        return [
            'set' => [['author_name' => 'Ada Lovelace'], 'Ada Lovelace'],
            'padded' => [['author_name' => '  Ada  '], 'Ada'],
            'whitespace only counts as unset' => [['author_name' => " \t "], ''],
            'empty' => [['author_name' => ''], ''],
            'null' => [['author_name' => null], ''],
            'missing' => [[], ''],
        ];
    }
}
