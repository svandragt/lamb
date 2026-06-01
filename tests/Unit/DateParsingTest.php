<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\is_scheduled;
use function Lamb\Post\populate_bean;

class DateParsingTest extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        $this->originalTz = date_default_timezone_get();
        global $config;
        $config = [];
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTz);
    }

    private function created(string $frontMatterValue): string
    {
        $bean = populate_bean("---\ncreated: $frontMatterValue\n---\n\nBody #tag");
        return (string) $bean->created;
    }

    private const DATETIME_RE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function testNaiveDateKeepsTypedWallClockRegardlessOfServerTimezone(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $this->assertSame('2099-01-01 09:00:00', $this->created('2099-01-01 09:00:00'));

        date_default_timezone_set('America/New_York');
        $this->assertSame('2099-01-01 09:00:00', $this->created('2099-01-01 09:00:00'));
    }

    public function testDateOnlyDefaultsToMidnight(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $this->assertSame('2099-01-01 00:00:00', $this->created('2099-01-01'));
    }

    public function testIsoDateWithOffsetPreservesTypedWallClock(): void
    {
        date_default_timezone_set('UTC');
        $this->assertSame('2099-01-01 09:00:00', $this->created('2099-01-01T09:00:00+05:00'));
    }

    public function testRelativeDateIsParsedToAFutureDatetime(): void
    {
        date_default_timezone_set('UTC');
        $created = $this->created('+1 week');
        $this->assertMatchesRegularExpression(self::DATETIME_RE, $created);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $created);
    }

    public function testHumanRelativeDateNextFriday(): void
    {
        date_default_timezone_set('UTC');
        $created = $this->created('next friday 3pm');
        $this->assertMatchesRegularExpression(self::DATETIME_RE, $created);
        $this->assertStringEndsWith('15:00:00', $created);
    }

    public function testUnparseableDateFallsBackToAValidDatetimeAndPublishes(): void
    {
        date_default_timezone_set('UTC');
        $bean = populate_bean("---\ncreated: sometime soon\n---\n\nBody #tag");

        $this->assertMatchesRegularExpression(
            self::DATETIME_RE,
            (string) $bean->created,
            'An unparseable date must not be stored verbatim'
        );
        $this->assertFalse(
            is_scheduled($bean),
            'An unparseable date must not leave the post scheduled forever'
        );
    }
}
