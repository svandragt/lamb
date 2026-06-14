<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;

class ParseBeanDescriptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testDescriptionIsPlainTextWithoutHtmlEntities()
    {
        $bean = R::dispense('post');
        $bean->body = "It seems sometimes I&#039;m researching and want the previous tab to become active.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertStringNotContainsString('&#039;', $bean->description);
        $this->assertStringNotContainsString('&amp;', $bean->description);
        $this->assertStringContainsString("I'm researching", $bean->description);
    }

    public function testFrontMatterSummaryOverridesAutoDescription()
    {
        $bean = R::dispense('post');
        $bean->body = "---\nsummary: A hand-written summary.\n---\n\nThe body's first line should not become the description.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame('A hand-written summary.', $bean->description);
    }

    public function testFrontMatterDescriptionKeyAlsoOverridesAutoDescription()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ndescription: Aliased summary.\n---\n\nFirst body line.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame('Aliased summary.', $bean->description);
    }

    public function testWhitespaceOnlySummaryFallsBackToAutoDescription()
    {
        $bean = R::dispense('post');
        $bean->body = "---\nsummary: \"   \"\n---\n\nFirst body line.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame('First body line.', $bean->description);
    }

    public function testFallsBackToAutoDescriptionWhenNoSummary()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: A Title\n---\n\nFirst body line.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame('First body line.', $bean->description);
    }

    public function testSummaryDoesNotLeakIntoAStrayColumn()
    {
        $bean = R::dispense('post');
        $bean->body = "---\nsummary: A hand-written summary.\n---\n\nBody.";
        $bean->slug = '';

        parse_bean($bean);

        // The summary becomes the description; it must not also create a stray
        // `summary` property that RedBean would persist as an unused column.
        $this->assertEmpty($bean->summary);
    }
}
