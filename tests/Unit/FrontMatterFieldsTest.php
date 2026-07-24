<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_tags;
use function Lamb\Post\populate_bean;

/**
 * Front matter is metadata the author writes, not a channel for setting arbitrary
 * columns. It reaches these functions from the web editor, from Micropub requests,
 * and — via the parse_tags() path — from ingested remote feeds.
 */
class FrontMatterFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
    }

    public function testFrontMatterCannotRetargetTheBeanId(): void
    {
        // Setting `id` made the store that follows an UPDATE of that row, so a
        // create could overwrite any existing post.
        $bean = populate_bean("---\nid: 41\ntitle: Hijacked\n---\nBody");

        $this->assertEmpty($bean->id);
        $this->assertSame('Hijacked', $bean->title);
    }

    public function testFrontMatterCannotSetDeleted(): void
    {
        $bean = populate_bean("---\nid: 41\ndeleted: 1\n---\nBody");

        $this->assertEmpty($bean->deleted);
    }

    public function testFrontMatterDoesNotCreateArbitraryColumns(): void
    {
        $bean = populate_bean("---\ntitle: Hi\nbackdoor: injected\n---\nBody");

        $this->assertNull($bean->backdoor);
    }

    public function testFrontMatterStillAppliesTheFieldsItOwns(): void
    {
        $bean = populate_bean("---\ntitle: Real Title\nslug: real-slug\ndraft: true\n---\nBody");

        $this->assertSame('Real Title', $bean->title);
        $this->assertSame('real-slug', $bean->slug);
        $this->assertSame(1, $bean->draft);
    }

    public function testHashtagCannotBreakOutOfAnAttribute(): void
    {
        // parse_tags() runs over rendered HTML, where a hashtag can land inside an
        // attribute Parsedown built from user text. Two unescaped quotes there
        // closed the attribute and added new ones to the enclosing element.
        $html = '<img src="x" alt="pic #y/onerror=alert`1`// end">';

        // The tag is left alone entirely: no `<a>` is injected into the attribute,
        // so nothing closes it and no new attribute can appear on the img.
        $this->assertSame($html, parse_tags($html));
    }

    public function testHashtagInTextIsEscaped(): void
    {
        $result = parse_tags('a #y&quot;z w');

        $this->assertStringNotContainsString('"z', $result);
        $this->assertStringContainsString('/tag/y', $result);
    }

    public function testOrdinaryHashtagsStillLink(): void
    {
        $this->assertStringContainsString(
            '<a href="/tag/php">#PHP</a>',
            parse_tags('learning #PHP today')
        );
    }
}
