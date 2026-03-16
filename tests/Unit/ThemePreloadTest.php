<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\preload_text;

class ThemePreloadTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['text']);
    }

    public function testPreloadTextReturnsEmptyStringWhenNotSet()
    {
        unset($_GET['text']);
        $this->assertSame('', preload_text());
    }

    public function testPreloadTextReturnsValueFromQueryString()
    {
        $_GET['text'] = 'Hello world';
        $this->assertSame('Hello world', preload_text());
    }

    public function testPreloadTextEscapesHtml()
    {
        $_GET['text'] = '<script>alert("xss")</script>';
        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', preload_text());
    }

    public function testPreloadTextEscapesSingleQuotes()
    {
        $_GET['text'] = "it's a test";
        $this->assertSame('it&#039;s a test', preload_text());
    }
}
