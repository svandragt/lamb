<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Config\apply_timezone;
use function Lamb\Config\load;

class ConfigTimezoneTest extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec("DELETE FROM option WHERE name = 'site_config_ini'");
        $this->originalTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTz);
    }

    public function testLoadDefaultsTimezoneToUtc(): void
    {
        $config = load();
        $this->assertSame('UTC', $config['timezone']);
    }

    public function testApplyTimezoneSetsValidConfiguredZone(): void
    {
        $applied = apply_timezone(['timezone' => 'Asia/Tokyo']);
        $this->assertSame('Asia/Tokyo', $applied);
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function testApplyTimezoneFallsBackToUtcForInvalidZone(): void
    {
        $applied = apply_timezone(['timezone' => 'Mars/Olympus_Mons']);
        $this->assertSame('UTC', $applied);
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function testApplyTimezoneDefaultsToUtcWhenUnset(): void
    {
        $applied = apply_timezone([]);
        $this->assertSame('UTC', $applied);
    }

    public function testDefaultIniDocumentsTimezoneSetting(): void
    {
        $this->assertStringContainsString('timezone', \Lamb\Config\get_default_ini_text());
    }
}
