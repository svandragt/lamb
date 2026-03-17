<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\is_menu_item;
use function Lamb\Config\validate_ini;

class ConfigValidateTest extends TestCase
{
    // validate_ini

    public function testValidateIniReturnsTrueForValidIni()
    {
        $ini = "[section]\nkey=value\n";
        $result = validate_ini($ini);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testValidateIniReturnsTrueForEmptyString()
    {
        $result = validate_ini('');
        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testValidateIniReturnsTrueForCommentOnlyIni()
    {
        $ini = "; This is a comment\n;; Another comment\n";
        $result = validate_ini($ini);
        $this->assertTrue($result['valid']);
    }

    public function testValidateIniReturnsTrueForDefaultConfig()
    {
        $ini = get_default_ini_text();
        $result = validate_ini($ini);
        $this->assertTrue($result['valid'], 'Default INI text should be valid');
    }

    public function testValidateIniReturnsFalseForInvalidIni()
    {
        // A value-less key (starts with =) is invalid INI syntax
        $ini = "= orphan_value\n";
        $result = validate_ini($ini);
        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    // get_default_ini_text

    public function testGetDefaultIniTextReturnsNonEmptyString()
    {
        $ini = get_default_ini_text();
        $this->assertIsString($ini);
        $this->assertNotEmpty($ini);
    }

    public function testGetDefaultIniTextContainsExpectedSections()
    {
        $ini = get_default_ini_text();
        $this->assertStringContainsString('[menu_items]', $ini);
        $this->assertStringContainsString('[feeds]', $ini);
    }

    // is_menu_item

    public function testIsMenuItemReturnsTrueForKnownSlug()
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['About' => 'about'];

        $this->assertTrue(is_menu_item('about'));

        $config = $original;
    }

    public function testIsMenuItemReturnsFalseForUnknownSlug()
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['About' => 'about'];

        $this->assertFalse(is_menu_item('contact'));

        $config = $original;
    }

    public function testIsMenuItemReturnsFalseForExternalUrl()
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['External' => 'https://example.com'];

        $this->assertFalse(is_menu_item('https://example.com'));

        $config = $original;
    }

    public function testIsMenuItemReturnsFalseWhenNoMenuItems()
    {
        global $config;
        $original = $config;
        $config['menu_items'] = [];

        $this->assertFalse(is_menu_item('about'));

        $config = $original;
    }
}
