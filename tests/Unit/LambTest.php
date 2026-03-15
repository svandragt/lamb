<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\get_tags;
use function Lamb\parse_tags;

class LambTest extends TestCase
{
    // get_tags

    public function testGetTagsExtractsSingleTag()
    {
        $tags = get_tags('<p>Hello #world</p>');
        $this->assertSame(['world'], $tags);
    }

    public function testGetTagsExtractsMultipleTags()
    {
        $tags = get_tags('<p>Hello #world and #php</p>');
        $this->assertContains('world', $tags);
        $this->assertContains('php', $tags);
    }

    public function testGetTagsIgnoresHashWithoutSpace()
    {
        // "#Hello" at start of word (no preceding space or >) should not match
        $tags = get_tags('#Hello, World! #til');
        $this->assertContains('til', $tags);
        $this->assertNotContains('Hello,', $tags);
    }

    public function testGetTagsReturnsEmptyArrayWhenNoTags()
    {
        $tags = get_tags('<p>No tags here.</p>');
        $this->assertSame([], $tags);
    }

    public function testGetTagsSupportsEmojiTags()
    {
        $tags = get_tags('<p>Hello #🐑</p>');
        $this->assertContains('🐑', $tags);
    }

    public function testGetTagsExtractsTagAfterHtmlTag()
    {
        // Tag preceded by > (end of HTML tag)
        $tags = get_tags('<p>#lamb</p>');
        $this->assertContains('lamb', $tags);
    }

    public function testGetTagsDoesNotIncludePunctuation()
    {
        $tags = get_tags('<p>Hello #world.</p>');
        $this->assertContains('world', $tags);
        // The dot should not be included in the tag
        $this->assertNotContains('world.', $tags);
    }

    // parse_tags

    public function testParseTagsConvertsHashtagToLink()
    {
        $result = parse_tags('<p>Hello #world</p>');
        $this->assertStringContainsString('<a href="/tag/world">#world</a>', $result);
    }

    public function testParseTagsLowercasesTagInHref()
    {
        $result = parse_tags('<p>Hello #World</p>');
        $this->assertStringContainsString('href="/tag/world"', $result);
    }

    public function testParseTagsPreservesOriginalCaseInLinkText()
    {
        $result = parse_tags('<p>Hello #World</p>');
        $this->assertStringContainsString('>#World</a>', $result);
    }

    public function testParseTagsConvertsMultipleTags()
    {
        $result = parse_tags('<p>Hello #foo and #bar</p>');
        $this->assertStringContainsString('<a href="/tag/foo">#foo</a>', $result);
        $this->assertStringContainsString('<a href="/tag/bar">#bar</a>', $result);
    }

    public function testParseTagsSupportsEmojiTags()
    {
        $result = parse_tags('<p>Hello #🐑</p>');
        $this->assertStringContainsString('<a href="/tag/🐑">#🐑</a>', $result);
    }

    public function testParseTagsDoesNotAlterTextWithNoTags()
    {
        $input = '<p>No tags here.</p>';
        $this->assertSame($input, parse_tags($input));
    }

    public function testParseTagsDoesNotConvertInlineHash()
    {
        // "#tag" embedded inside a word (no space/> before it) should not be converted
        $result = parse_tags('word#tag end');
        $this->assertStringNotContainsString('<a href', $result);
    }
}
