<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Config\get_ini_text;
use function Lamb\Config\load;
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
        $custom = "[menu_items]\nAbout = about\n";
        save_ini_text($custom);

        $text = get_ini_text();
        $this->assertSame($custom, $text);
    }

    // save_ini_text

    public function testSaveIniTextPersistsText(): void
    {
        $ini = "site_title = Test Blog\n";
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
}
