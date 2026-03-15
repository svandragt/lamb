<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;
use function Lamb\Theme\og_escape;
use function Lamb\Theme\sanitize_filename;

class ThemeTest extends TestCase
{
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
}
