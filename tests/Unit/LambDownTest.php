<?php

namespace Tests\Unit;

use Lamb\LambDown;
use PHPUnit\Framework\TestCase;

class LambDownTest extends TestCase
{
    private LambDown $parser;

    protected function setUp(): void
    {
        $this->parser = new LambDown();
        $this->parser->setSafeMode(true);
    }

    public function testH1WithSpaceRendersAsHeading(): void
    {
        $html = $this->parser->text('# Hello');
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function testH2WithSpaceRendersAsHeading(): void
    {
        $html = $this->parser->text('## Hello');
        $this->assertStringContainsString('<h2>', $html);
    }

    public function testHashWithoutSpaceDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('#nospace');
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function testDoubleHashWithoutSpaceDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('##nospace');
        $this->assertStringNotContainsString('<h2>', $html);
    }

    public function testHashtagInTextDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('#lamb microblog software');
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function testSafeModeEscapesScriptTags(): void
    {
        $html = $this->parser->text('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testSafeModeEscapesInlineHtml(): void
    {
        $html = $this->parser->text('Hello <b>world</b>');
        $this->assertStringNotContainsString('<b>', $html);
    }
}
