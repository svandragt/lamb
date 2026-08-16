<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\compose_config;
use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\get_menu_slugs;
use function Lamb\Config\normalize_config;
use function Lamb\Config\shape_warnings;

/**
 * The settings INI is parsed with sections enabled, so the author decides each
 * key's PHP type by how they write it: a `[site_title]` header makes a scalar
 * setting an array, `feeds = <url>` makes a section a string, and `Home[] = /`
 * nests an array inside a section. Consumers assume the documented shape and
 * PHP 8 turns the mismatch into a fatal — on a page that is also how the author
 * would undo the mistake.
 *
 * These tests pin the normalisation that keeps a wrongly-shaped value from
 * reaching a consumer at all.
 */
class ConfigTypesTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function withConfig(string $ini): array
    {
        return compose_config($ini, get_default_ini_text());
    }

    // normalize_config() — scalar settings

    public function testScalarSettingWrittenAsSectionIsDropped(): void
    {
        // `[site_title]` rather than `site_title = ...`. An array here reached
        // escape()/wrap_title() and fatally TypeError'd every HTML page.
        $config = normalize_config(['site_title' => ['foo' => 'bar']], ['site_title' => 'Default']);

        $this->assertArrayNotHasKey('site_title', $config);
    }

    public function testDroppedScalarSettingFallsBackToTheSeededDefault(): void
    {
        $config = $this->withConfig("[site_title]\nfoo = bar\n");

        $this->assertSame('My Microblog', $config['site_title']);
    }

    public function testScalarSettingKeepsItsStringValue(): void
    {
        $config = normalize_config(['site_title' => 'Mine'], ['site_title' => 'Default']);

        $this->assertSame('Mine', $config['site_title']);
    }

    public function testUnknownScalarKeyIsKept(): void
    {
        $config = normalize_config(['custom' => 'value'], []);

        $this->assertSame('value', $config['custom']);
    }

    public function testUnknownArrayKeyIsDropped(): void
    {
        // Nothing reads it and it is not a documented section, so it cannot be
        // handed on as an array.
        $config = normalize_config(['custom' => ['a' => 'b']], []);

        $this->assertArrayNotHasKey('custom', $config);
    }

    /**
     * The seeded INI keeps the personal-identity and opt-in settings commented
     * out, so they are absent from the parsed defaults. Inferring "scalar
     * setting" from the defaults alone therefore missed exactly these keys, and
     * `[author_name]` still reached escape() in the Atom feed as an array.
     *
     * @dataProvider commentedOutScalarKeyProvider
     */
    public function testScalarSettingCommentedOutInTheDefaultsIsStillNormalised(string $key): void
    {
        $config = $this->withConfig("[{$key}]\nx = y\n");

        $this->assertFalse(is_array($config[$key] ?? null), "$key must not stay an array");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function commentedOutScalarKeyProvider(): array
    {
        return [
            'author_name'      => ['author_name'],
            'author_email'     => ['author_email'],
            'site_description' => ['site_description'],
            'site_url'         => ['site_url'],
            '404_fallback'     => ['404_fallback'],
            'websub_hubs'      => ['websub_hubs'],
            'micropub_debug'   => ['micropub_debug'],
        ];
    }

    public function testShapeWarningsNamesAScalarCommentedOutInTheDefaults(): void
    {
        $warnings = shape_warnings("[author_name]\nx = y\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('author_name', $warnings[0]);
    }

    // normalize_config() — sections

    public function testSectionWrittenAsScalarIsDropped(): void
    {
        // `feeds = https://example.com/rss` instead of a `[feeds]` section.
        $config = normalize_config(['feeds' => 'https://example.com/rss'], ['feeds' => []]);

        $this->assertSame([], $config['feeds']);
    }

    public function testSectionDropsNestedArrayEntries(): void
    {
        // `Home[] = /` inside `[menu_items]` nests an array under the label.
        $config = normalize_config(
            ['menu_items' => ['Home' => ['/'], 'About' => '/about']],
            ['menu_items' => []]
        );

        $this->assertSame(['About' => '/about'], $config['menu_items']);
    }

    public function testSectionValuesAreStrings(): void
    {
        $config = normalize_config(['menu_items' => ['Home' => 1]], ['menu_items' => []]);

        $this->assertSame(['Home' => '1'], $config['menu_items']);
    }

    // The consumers that used to fatal

    public function testMenuSlugsSurviveASectionWrittenAsAScalar(): void
    {
        global $config;
        $original = $config;
        $config = $this->withConfig("menu_items = /about\n");

        // foreach over a string warned and produced nothing.
        $this->assertIsArray(get_menu_slugs());

        $config = $original;
    }

    public function testMenuSlugsSurviveANestedMenuEntry(): void
    {
        global $config;
        $original = $config;
        $config = $this->withConfig("[menu_items]\nHome[] = /x\n");

        // str_starts_with() on an array is a TypeError, and this runs on every
        // listing page and feed.
        $this->assertSame([], get_menu_slugs());

        $config = $original;
    }

    public function testFeedsDraftFailsClosedWhenWronglyShaped(): void
    {
        // filter_var(array, FILTER_VALIDATE_BOOLEAN) is false, which silently
        // flipped the default from "hold feed items for review" to "publish
        // them immediately".
        $config = $this->withConfig("[feeds_draft]\nx = y\n");

        $this->assertTrue(filter_var($config['feeds_draft'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testPostsPerPageFallsBackToTheDefaultWhenWronglyShaped(): void
    {
        // (int) on a non-empty array is 1 — with no diagnostic — which
        // paginated the whole site one post at a time.
        $config = $this->withConfig("posts_per_page[] = 10\n");

        $this->assertSame(10, (int) $config['posts_per_page']);
    }

    public function testThemeIsAlwaysAStringOrAbsent(): void
    {
        $config = $this->withConfig("[theme]\nx = y\n");

        // index.php casts this to a string to build a filesystem path.
        $this->assertFalse(is_array($config['theme'] ?? null));
    }

    public function testRedirectionsWrittenAsAScalarCannotRedirectSlugZero(): void
    {
        // isset() on a string is false for a non-numeric offset but true for
        // "0", so requesting /0 read a single character out of the value.
        $config = $this->withConfig("redirections = https://evil.example\n");

        $this->assertSame([], $config['redirections']);
    }

    // shape_warnings() — the author has to be told what was ignored

    public function testShapeWarningsIsEmptyForTheSeededDefaults(): void
    {
        $this->assertSame([], shape_warnings(get_default_ini_text()));
    }

    public function testShapeWarningsNamesAScalarWrittenAsASection(): void
    {
        $warnings = shape_warnings("[site_title]\nfoo = bar\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('site_title', $warnings[0]);
        $this->assertStringContainsString('ignored', $warnings[0]);
    }

    public function testShapeWarningsNamesAPathInTheSiteUrl(): void
    {
        // Issue #580: Lamb serves from the domain root only. A path in site_url
        // is dropped on read, which leaves Micropub comparing the author's
        // identity against the wrong URL and refusing every token, with the
        // settings page reporting a clean save.
        $warnings = shape_warnings("site_url = https://example.com/blog\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('site_url', $warnings[0]);
        $this->assertStringContainsString('/blog', $warnings[0]);
        $this->assertStringContainsString('Micropub', $warnings[0]);
    }

    public function testShapeWarningsAcceptsARootSiteUrl(): void
    {
        $this->assertSame([], shape_warnings("site_url = https://example.com\n"));
        $this->assertSame([], shape_warnings("site_url = https://example.com/\n"));
        $this->assertSame([], shape_warnings("site_url = http://example.com:8747\n"));
    }

    public function testShapeWarningsIgnoresASiteUrlItCannotRead(): void
    {
        // canonical_site_url() already refuses these and Micropub already says so;
        // a second, differently-worded complaint here would just be noise.
        $this->assertSame([], shape_warnings("site_url = not-a-url\n"));
        $this->assertSame([], shape_warnings("site_url =\n"));
    }

    public function testShapeWarningsNamesASectionWrittenAsAScalar(): void
    {
        $warnings = shape_warnings("feeds = https://example.com/rss\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[feeds]', $warnings[0]);
    }

    public function testShapeWarningsNamesARepeatedSectionKey(): void
    {
        $warnings = shape_warnings("[menu_items]\nHome[] = /\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('menu_items', $warnings[0]);
        $this->assertStringContainsString('Home', $warnings[0]);
    }

    public function testShapeWarningsIsEmptyForUnparseableIni(): void
    {
        // validate_ini() already rejects this with a syntax error; there is
        // nothing useful to add about the shape of a file that did not parse.
        $this->assertSame([], shape_warnings("[unclosed\n=== not ini ==="));
    }

    public function testEveryDefaultSectionStaysAnArray(): void
    {
        $config = $this->withConfig(
            "menu_items = x\nfooter_items = x\nfeeds = x\npreconnect = x\nme = x\n"
            . "redirections = x\nsyndicate_to = x\n"
        );

        foreach (['menu_items', 'footer_items', 'feeds', 'preconnect', 'me', 'redirections', 'syndicate_to'] as $key) {
            $this->assertIsArray($config[$key], "$key must stay an array");
        }
    }
}
