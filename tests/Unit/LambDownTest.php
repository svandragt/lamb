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

    public function testUncheckedTaskRendersDisabledCheckbox(): void
    {
        $html = $this->parser->text("- [ ] buy milk");
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertStringContainsString('buy milk', $html);
        $this->assertStringNotContainsString('[ ]', $html);
    }

    public function testCheckedTaskRendersCheckedCheckbox(): void
    {
        $html = $this->parser->text("- [x] walk dog");
        $this->assertStringContainsString('checked', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('walk dog', $html);
        $this->assertStringNotContainsString('[x]', $html);
    }

    public function testUppercaseXIsTreatedAsChecked(): void
    {
        $html = $this->parser->text("- [X] done");
        $this->assertStringContainsString('checked', $html);
    }

    public function testTaskItemsGetSequentialIndices(): void
    {
        $html = $this->parser->text("- [ ] a\n- [x] b\n- [ ] c");
        $this->assertStringContainsString('data-checkbox-index="0"', $html);
        $this->assertStringContainsString('data-checkbox-index="1"', $html);
        $this->assertStringContainsString('data-checkbox-index="2"', $html);
    }

    public function testPlainListItemIsNotACheckbox(): void
    {
        $html = $this->parser->text("- normal item");
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testTaskListItemGetsClass(): void
    {
        $html = $this->parser->text("- [ ] a");
        $this->assertStringContainsString('task-list-item', $html);
    }

    public function testLooseTaskListRendersCheckbox(): void
    {
        $html = $this->parser->text("- [ ] a\n\n- [x] b\n");
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('data-checkbox-index="0"', $html);
        $this->assertStringContainsString('data-checkbox-index="1"', $html);
    }

    public function testCheckboxLabelMarkdownIsFormatted(): void
    {
        $html = $this->parser->text("- [ ] read **the** docs");
        $this->assertStringContainsString('<strong>the</strong>', $html);
    }
}
