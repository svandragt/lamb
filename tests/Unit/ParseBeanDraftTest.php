<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;

class ParseBeanDraftTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testParseBeanSetsDraftOneWhenFrontmatterHasDraftTrue()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: Draft Post\ndraft: true\n---\nContent.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame(1, $bean->draft);
    }

    public function testParseBeanSetsDraftZeroWhenFrontmatterHasDraftFalse()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: Published Post\ndraft: false\n---\nContent.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame(0, $bean->draft);
    }

    public function testParseBeanSetsDraftZeroWhenDraftAbsentFromFrontmatter()
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: Normal Post\n---\nContent.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame(0, $bean->draft);
    }

    public function testParseBeanResetsDraftToZeroWhenRemovedFromFrontmatter()
    {
        $bean = R::dispense('post');
        // Simulate a post that was previously a draft (draft=1 in DB)
        $bean->draft = 1;
        // User edited to remove draft: true from frontmatter
        $bean->body = "---\ntitle: Now Published\n---\nContent.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame(0, $bean->draft);
    }

    public function testParseBeanSetsDraftZeroForPlainMarkdownPost()
    {
        $bean = R::dispense('post');
        $bean->body = 'Just a plain status update with no front matter.';
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame(0, $bean->draft);
    }
}
