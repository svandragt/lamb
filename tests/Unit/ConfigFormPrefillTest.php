<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\form_to_ini;
use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\parse_ini_safe;
use function Lamb\Config\settings_form_prefill;

/**
 * The /settings form shows what the author stored, not the effective config.
 * The effective config carries the placeholder identity (Joe Sheeple), and a
 * form prefilled with it would save the placeholder as the author's own.
 */
class ConfigFormPrefillTest extends TestCase
{
    public function testPrefillReadsTheStoredSingleValues(): void
    {
        $form = settings_form_prefill("site_title = My Blog\nsite_url = https://example.com\nauthor_name = Ada\n");

        $this->assertSame('My Blog', $form['fields']['site_title']);
        $this->assertSame('https://example.com', $form['fields']['site_url']);
        $this->assertSame('Ada', $form['fields']['author_name']);
    }

    public function testPrefillLeavesUnsetIdentityEmptyInsteadOfThePlaceholder(): void
    {
        $form = settings_form_prefill(get_default_ini_text());

        $this->assertSame('', $form['fields']['author_name']);
        $this->assertSame('', $form['fields']['author_email']);
        $this->assertSame('', $form['fields']['site_url']);
        $this->assertSame('My Microblog', $form['fields']['site_title']);
    }

    /**
     * @dataProvider timezoneProvider
     */
    public function testPrefillFallsBackToUtcWhenTheTimezoneIsMissingOrUnknown(string $ini, string $expected): void
    {
        $this->assertSame($expected, settings_form_prefill($ini)['fields']['timezone']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timezoneProvider(): array
    {
        return [
            'missing' => ["site_title = x\n", 'UTC'],
            'unknown' => ["timezone = Mars/Olympus\n", 'UTC'],
            'known' => ["timezone = Europe/London\n", 'Europe/London'],
        ];
    }

    /**
     * @dataProvider feedsDraftProvider
     * @param array<string, string> $posted
     */
    public function testPrefillReadsTheFeedsDraftCheckbox(string $ini, array $posted, bool $expected): void
    {
        $this->assertSame($expected, settings_form_prefill($ini, $posted)['feeds_draft']);
    }

    /**
     * @return array<string, array{string, array<string, string>, bool}>
     */
    public static function feedsDraftProvider(): array
    {
        return [
            'unset means the default, on' => ["site_title = x\n", [], true],
            'stored true' => ["feeds_draft = true\n", [], true],
            'stored false' => ["feeds_draft = false\n", [], false],
            'posted checked wins over stored false' => ["feeds_draft = false\n", ['site_title' => 'x', 'feeds_draft' => '1'], true],
            'a post without the box means unchecked' => ["feeds_draft = true\n", ['site_title' => 'x'], false],
        ];
    }

    public function testPrefillWritesSectionsBackAsLabelValueLines(): void
    {
        $form = settings_form_prefill("[menu_items]\nHome = /\nAbout = about\n\n[footer_items]\n\n[me]\nGithub = https://github.com/ada\n");

        $this->assertSame("Home = /\nAbout = about", $form['fields']['menu_items']);
        $this->assertSame('', $form['fields']['footer_items']);
        $this->assertSame('Github = https://github.com/ada', $form['fields']['me']);
        $this->assertSame('', $form['fields']['feeds']);
    }

    public function testPrefillSkipsARepeatedLabelThatReadersIgnore(): void
    {
        $form = settings_form_prefill("[menu_items]\nHome = /\nTwice[] = /a\nTwice[] = /b\n");

        $this->assertSame('Home = /', $form['fields']['menu_items']);
    }

    public function testPrefillDoesNotOfferDeprecatedKeys(): void
    {
        $form = settings_form_prefill("posts_per_page = 5\n404_fallback = https://old.example\n[preconnect]\nfonts = https://fonts.example\n");

        $this->assertArrayNotHasKey('posts_per_page', $form['fields']);
        $this->assertArrayNotHasKey('404_fallback', $form['fields']);
        $this->assertArrayNotHasKey('preconnect', $form['fields']);
    }

    public function testPrefillOfUnreadableIniIsEmptyRatherThanAnError(): void
    {
        $form = settings_form_prefill("[unclosed\n=== not ini ===\n");

        $this->assertSame('', $form['fields']['site_title']);
        $this->assertSame('UTC', $form['fields']['timezone']);
        $this->assertTrue($form['feeds_draft']);
    }

    public function testPostedFieldsWinOverStoredOnesEvenWhenTheyAreEmpty(): void
    {
        $form = settings_form_prefill(
            "site_title = Stored\nsite_description = Stored description\n[menu_items]\nHome = /\n",
            ['site_title' => 'Typed', 'site_description' => '', 'menu_items' => "Typed = /typed\n"]
        );

        $this->assertSame('Typed', $form['fields']['site_title']);
        $this->assertSame('', $form['fields']['site_description']);
        $this->assertSame("Typed = /typed\n", $form['fields']['menu_items']);
    }

    public function testStoredValuesFillTheFieldsAPostLeftOut(): void
    {
        $form = settings_form_prefill("site_title = Stored\nauthor_name = Ada\n", ['site_title' => 'Typed']);

        $this->assertSame('Ada', $form['fields']['author_name']);
    }

    public function testSavingAnUntouchedFormKeepsEveryStoredSetting(): void
    {
        $stored = "site_title = My Blog\nsite_url = https://example.com\nauthor_name = Ada\n"
            . "timezone = Europe/London\ntheme = 2024\nwebsub_hubs = https://hub.example/\nfeeds_draft = false\n\n"
            . "[menu_items]\nHome = /\nSearch = /search?q=a=b\n\n[syndicate_to]\nhttps://bsky.app/profile/ada = Bluesky\n";

        $form = settings_form_prefill($stored);
        $post = $form['fields'] + ($form['feeds_draft'] ? ['feeds_draft' => '1'] : []);
        $saved = parse_ini_safe(form_to_ini($post, $stored));

        $this->assertEquals(parse_ini_safe($stored), array_intersect_key($saved, parse_ini_safe($stored)));
    }
}
