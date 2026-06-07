<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Config\config_modified_timestamp;
use function Lamb\Config\get_ini_text;
use function Lamb\Config\load;
use function Lamb\Config\parse_ini_safe;
use function Lamb\Config\save_ini_text;

class ConfigLoadTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        // Remove any cached config option so each test starts fresh
        R::exec("DELETE FROM option WHERE name = 'site_config_ini'");
    }

    // parse_ini_safe

    public function testParseIniSafeParsesValidIni(): void
    {
        $parsed = parse_ini_safe("theme = base\n[menu_items]\nHome = /\n");
        $this->assertSame('base', $parsed['theme']);
        $this->assertSame(['Home' => '/'], $parsed['menu_items']);
    }

    public function testParseIniSafeReturnsEmptyArrayForBrokenIni(): void
    {
        $this->assertSame([], parse_ini_safe("[unclosed\n=== not ini ==="));
    }

    public function testParseIniSafeReturnsEmptyArrayForEmptyText(): void
    {
        $this->assertSame([], parse_ini_safe(''));
    }

    // get_ini_text

    public function testGetIniTextReturnsStringWhenNoStoredConfig(): void
    {
        $text = get_ini_text();
        $this->assertIsString($text);
        $this->assertNotEmpty($text);
    }

    public function testGetIniTextBootstrapsFromDefaultWhenNoConfigFile(): void
    {
        $text = get_ini_text();
        // Default INI has [menu_items] section
        $this->assertStringContainsString('[menu_items]', $text);
    }

    public function testGetIniTextReturnsSavedTextOnSubsequentCall(): void
    {
        // Includes an explicit theme so the themeless-migration is a no-op and
        // this stays a pure round-trip assertion (migration is covered below).
        $custom = "theme = 2024\n[menu_items]\nAbout = about\n";
        save_ini_text($custom);

        $text = get_ini_text();
        $this->assertSame($custom, $text);
    }

    public function testGetIniTextMigratesThemelessStoredConfigToExplicitTheme(): void
    {
        save_ini_text("site_title = Legacy Blog\n");

        $text = get_ini_text();
        $parsed = parse_ini_string($text, true, INI_SCANNER_RAW);

        // Themeless stored config gains an explicit theme on read...
        $this->assertSame('base', $parsed['theme']);
        // ...and the migration is persisted, so a second read is stable.
        $this->assertSame($text, get_ini_text());
    }

    // save_ini_text

    public function testSaveIniTextPersistsText(): void
    {
        // Explicit theme keeps get_ini_text()'s migration a no-op for this round-trip.
        $ini = "theme = 2024\nsite_title = Test Blog\n";
        save_ini_text($ini);

        $text = get_ini_text();
        $this->assertSame($ini, $text);
    }

    public function testSaveIniTextOverwritesPreviousValue(): void
    {
        save_ini_text("site_title = First\n");
        save_ini_text("site_title = Second\n");

        $text = get_ini_text();
        $this->assertStringContainsString('Second', $text);
        $this->assertStringNotContainsString('First', $text);
    }

    // config_modified_timestamp

    public function testConfigModifiedTimestampIsZeroWhenNoConfigStored(): void
    {
        // setUp() removes the site_config_ini option, so nothing has been saved.
        $this->assertSame(0, config_modified_timestamp());
    }

    public function testSaveIniTextStampsModifiedTimestamp(): void
    {
        $before = time();
        save_ini_text("site_title = Test\n");

        $ts = config_modified_timestamp();
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual(time() + 1, $ts);
    }

    public function testConfigModifiedTimestampAdvancesOnReSave(): void
    {
        save_ini_text("site_title = One\n");
        // Backdate the stored timestamp to simulate an old edit.
        R::exec("UPDATE option SET updated = ? WHERE name = 'site_config_ini'", ['2000-01-01 00:00:00']);
        $old = config_modified_timestamp();
        $this->assertSame(strtotime('2000-01-01 00:00:00'), $old);

        save_ini_text("site_title = Two\n");
        $this->assertGreaterThan($old, config_modified_timestamp());
    }

    public function testReSaveWithinSameSecondStillAdvancesTimestamp(): void
    {
        // A second save landing in the same wall-clock second as the previous
        // edit must still move the timestamp forward; otherwise the conditional
        // GET ETag is unchanged and anonymous clients get a stale 304 (#279).
        save_ini_text("site_title = One\n");
        // Force the stored edit time to the current second to simulate a
        // re-save colliding with the previous edit's second.
        R::exec("UPDATE option SET updated = ? WHERE name = 'site_config_ini'", [date('Y-m-d H:i:s')]);
        $first = config_modified_timestamp();

        save_ini_text("site_title = Two\n");
        $this->assertGreaterThan($first, config_modified_timestamp());
    }

    // load

    public function testLoadReturnsArray(): void
    {
        $config = load();
        $this->assertIsArray($config);
    }

    public function testLoadIncludesDefaultKeys(): void
    {
        $config = load();
        $this->assertArrayHasKey('site_title', $config);
        $this->assertArrayHasKey('author_email', $config);
        $this->assertArrayHasKey('author_name', $config);
    }

    public function testLoadReturnsCustomSiteTitle(): void
    {
        save_ini_text("site_title = My Custom Blog\n");
        $config = load();
        $this->assertSame('My Custom Blog', $config['site_title']);
    }

    public function testLoadMergesDefaultsForMissingKeys(): void
    {
        save_ini_text("site_title = Minimal\n");
        $config = load();
        // author_email should come from hardcoded defaults since it's not in the saved INI
        $this->assertArrayHasKey('author_email', $config);
        $this->assertNotEmpty($config['author_email']);
    }

    public function testLoadIncludesAuthorizationEndpointDefault(): void
    {
        $config = load();
        $this->assertArrayHasKey('authorization_endpoint', $config);
        $this->assertSame('https://indieauth.com/auth', $config['authorization_endpoint']);
    }

    public function testLoadIncludesTokenEndpointDefault(): void
    {
        $config = load();
        $this->assertArrayHasKey('token_endpoint', $config);
        $this->assertSame('https://tokens.indieauth.com/token', $config['token_endpoint']);
    }

    public function testLoadAllowsOverridingAuthorizationEndpoint(): void
    {
        save_ini_text("authorization_endpoint = https://my.auth.example.com/auth\n");
        $config = load();
        $this->assertSame('https://my.auth.example.com/auth', $config['authorization_endpoint']);
    }

    public function testLoadAllowsOverridingTokenEndpoint(): void
    {
        save_ini_text("token_endpoint = https://my.token.example.com/token\n");
        $config = load();
        $this->assertSame('https://my.token.example.com/token', $config['token_endpoint']);
    }

    public function testLoadMeSectionParsesIntoArray(): void
    {
        save_ini_text("[me]\nGithub = https://github.com/aaronpk\nEmail = mailto:me@example.com\n");
        $config = load();
        $this->assertArrayHasKey('me', $config);
        $this->assertSame('https://github.com/aaronpk', $config['me']['Github']);
        $this->assertSame('mailto:me@example.com', $config['me']['Email']);
    }

    public function testLoadMeSectionAbsentWhenNotConfigured(): void
    {
        save_ini_text("site_title = No Me Section\n");
        $config = load();
        $this->assertEmpty($config['me'] ?? []);
    }
}
