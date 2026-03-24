<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\action_restore;
use function Lamb\Theme\escape;
use function Lamb\Theme\format_past_date;
use function Lamb\Theme\human_time;
use function Lamb\Theme\og_escape;
use function Lamb\Theme\sanitize_filename;
use function Lamb\Theme\the_preconnect;

class ThemeTest extends TestCase
{
    // the_preconnect

    public function testPreconnectOutputsNothingWhenNotConfigured()
    {
        global $config;
        $original = $config;
        $config['preconnect'] = [];

        ob_start();
        the_preconnect();
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $config = $original;
    }

    public function testPreconnectOutputsPreconnectAndDnsPrefetchLinks()
    {
        global $config;
        $original = $config;
        $config['preconnect'] = ['google-fonts' => 'https://fonts.googleapis.com'];

        ob_start();
        the_preconnect();
        $output = ob_get_clean();

        $this->assertStringContainsString('<link rel="preconnect" href="https://fonts.googleapis.com">', $output);
        $this->assertStringContainsString('<link rel="dns-prefetch" href="https://fonts.googleapis.com">', $output);
        $config = $original;
    }

    public function testPreconnectOutputsAllConfiguredOrigins()
    {
        global $config;
        $original = $config;
        $config['preconnect'] = [
            'fonts' => 'https://fonts.googleapis.com',
            'static' => 'https://fonts.gstatic.com',
        ];

        ob_start();
        the_preconnect();
        $output = ob_get_clean();

        $this->assertStringContainsString('fonts.googleapis.com', $output);
        $this->assertStringContainsString('fonts.gstatic.com', $output);
        $config = $original;
    }

    public function testPreconnectEscapesOrigins()
    {
        global $config;
        $original = $config;
        $config['preconnect'] = ['xss' => 'https://example.com"><script>alert(1)</script>'];

        ob_start();
        the_preconnect();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $output);
        $config = $original;
    }

    // escape

    public function testEscapeConvertsAngleBrackets()
    {
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', escape('<b>bold</b>'));
    }

    public function testEscapeConvertsDoubleQuotes()
    {
        $this->assertSame('say &quot;hello&quot;', escape('say "hello"'));
    }

    public function testEscapeConvertsSingleQuotes()
    {
        // ENT_HTML5 encodes single quotes as &apos;
        $this->assertSame('it&apos;s', escape("it's"));
    }

    public function testEscapePassesThroughSafeText()
    {
        $this->assertSame('Hello, world!', escape('Hello, world!'));
    }

    public function testEscapeHandlesEmptyString()
    {
        $this->assertSame('', escape(''));
    }

    public function testEscapeConvertsAmpersand()
    {
        $this->assertSame('foo &amp; bar', escape('foo & bar'));
    }

    // og_escape

    public function testOgEscapeConvertsAngleBrackets()
    {
        $result = og_escape('<b>bold</b>');
        $this->assertStringNotContainsString('<b>', $result);
    }

    public function testOgEscapeDoesNotDoubleEncodeEntities()
    {
        // og_escape decodes then re-encodes, so &amp; should stay as &amp;
        $result = og_escape('foo &amp; bar');
        $this->assertSame('foo &amp; bar', $result);
    }

    public function testOgEscapeHandlesPlainText()
    {
        $this->assertSame('Hello world', og_escape('Hello world'));
    }

    // sanitize_filename

    public function testSanitizeFilenameAllowsAlphanumeric()
    {
        $this->assertSame('hello123', sanitize_filename('hello123'));
    }

    public function testSanitizeFilenameAllowsHyphensAndUnderscores()
    {
        $this->assertSame('hello-world_test', sanitize_filename('hello-world_test'));
    }

    public function testSanitizeFilenameReplacesSpacesWithUnderscores()
    {
        $this->assertSame('hello_world', sanitize_filename('hello world'));
    }

    public function testSanitizeFilenameReplacesSlashesWithUnderscores()
    {
        $this->assertSame('path_to_file', sanitize_filename('path/to/file'));
    }

    public function testSanitizeFilenameReplacesDotsWithUnderscores()
    {
        $this->assertSame('file_php', sanitize_filename('file.php'));
    }

    public function testSanitizeFilenameHandlesEmptyString()
    {
        $this->assertSame('', sanitize_filename(''));
    }

    // format_past_date

    public function testFormatPastDateYesterdayWhenJIs3AndDiffIs1()
    {
        $ts = mktime(10, 0, 0, 1, 1, 2020);
        $result = format_past_date(3, 1, $ts);
        $this->assertStringContainsString('Yesterday', $result);
    }

    public function testFormatPastDateDayNameWhenJIs3AndDiffIsNot1()
    {
        $ts = mktime(10, 0, 0, 1, 1, 2020);
        $result = format_past_date(3, 3, $ts);
        // Should be a day-of-week name like "Wednesday at ..."
        $this->assertMatchesRegularExpression('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)/', $result);
    }

    public function testFormatPastDateMonthDayIncludesYearWhenJIs4AndYearDiffers()
    {
        $ts = mktime(10, 0, 0, 6, 15, 2020);
        $result = format_past_date(4, 3, $ts);
        $this->assertStringContainsString('June 15', $result);
        $this->assertStringContainsString('2020', $result);
    }

    public function testFormatPastDateMonthDayOmitsYearWhenJIs4AndYearMatches()
    {
        $year = (int) date('Y');
        $ts = mktime(10, 0, 0, 1, 15, $year);
        $result = format_past_date(4, 3, $ts);
        $this->assertStringContainsString('January 15', $result);
        $this->assertStringNotContainsString((string) $year, $result);
    }

    public function testFormatPastDateMonthDayIncludesYearWhenJIs5AndYearDiffers()
    {
        $ts = mktime(10, 0, 0, 3, 10, 2020);
        $result = format_past_date(5, 3, $ts);
        $this->assertStringContainsString('March 10', $result);
        $this->assertStringContainsString('2020', $result);
    }

    public function testFormatPastDateMonthDayOmitsYearWhenJIs5AndYearMatches()
    {
        $year = (int) date('Y');
        $ts = mktime(10, 0, 0, 2, 10, $year);
        $result = format_past_date(5, 3, $ts);
        $this->assertStringContainsString('February 10', $result);
        $this->assertStringNotContainsString((string) $year, $result);
    }

    public function testFormatPastDateFullDateWithYearWhenJIs6()
    {
        $ts = mktime(10, 0, 0, 6, 30, 2010);
        $result = format_past_date(6, 2, $ts);
        $this->assertStringContainsString('2010', $result);
    }

    public function testFormatPastDateFullDateWhenJIs5AndDiffIs12()
    {
        $ts = mktime(10, 0, 0, 6, 30, 2010);
        $result = format_past_date(5, 12, $ts);
        // 12 months = 1 year ago, so should show year
        $this->assertStringContainsString('2010', $result);
    }

    // human_time

    public function testHumanTimeReturnsSecondsAgo()
    {
        $timestamp = time() - 30;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('second', $result);
    }

    public function testHumanTimeReturnsMinutesAgo()
    {
        $timestamp = time() - 120;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('minute', $result);
    }

    public function testHumanTimeReturnsHoursAgo()
    {
        $timestamp = time() - 7200;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('hour', $result);
    }

    public function testHumanTimeReturnsYesterdayForOneDayAgo()
    {
        $timestamp = time() - 86400;
        $result = human_time($timestamp);
        $this->assertStringContainsString('Yesterday', $result);
    }

    public function testHumanTimeReturnsToGoForFutureTimestamp()
    {
        $timestamp = time() + 30;
        $result = human_time($timestamp);
        $this->assertStringContainsString('to go', $result);
    }

    public function testHumanTimeReturnsTomorrowForOneDayAhead()
    {
        $timestamp = time() + 86400;
        $result = human_time($timestamp);
        $this->assertStringContainsString('Tomorrow', $result);
    }

    // action_restore

    public function testActionRestoreReturnsEmptyWhenNotLoggedIn(): void
    {
        $_SESSION = [];
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $bean = R::dispense('post');
        $bean->deleted = 1;
        R::store($bean);

        $this->assertSame('', action_restore($bean));
    }

    public function testActionRestoreReturnsFormWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $bean = R::dispense('post');
        $bean->deleted = 1;
        R::store($bean);

        $html = action_restore($bean);
        $this->assertStringContainsString('/restore/' . $bean->id, $html);
        $this->assertStringContainsString('Restore post', $html);
    }
}
