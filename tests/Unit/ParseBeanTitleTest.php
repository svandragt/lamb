<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;

class ParseBeanTitleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testParseBeanSetsTitleFromFrontmatter()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: My Title\n---\nContent.";

        parse_bean($bean);

        $this->assertSame('My Title', $bean->title);
    }

    public function testParseBeanClearsTitleWhenRemovedFromFrontmatter()
    {
        $bean = R::dispense('post');
        // Simulate a post that previously had a title (title set in DB).
        $bean->title = 'Old Title';
        // User edited to remove the front matter entirely.
        $bean->body = 'Just a plain status update with no front matter.';

        parse_bean($bean);

        $this->assertSame('', $bean->title);
    }

    public function testParseBeanLeavesTitleEmptyForPlainMarkdownPost()
    {
        $bean = R::dispense('post');
        $bean->body = 'Just a plain status update with no front matter.';

        parse_bean($bean);

        $this->assertSame('', $bean->title);
    }
}
