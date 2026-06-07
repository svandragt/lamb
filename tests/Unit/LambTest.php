<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\add_body_tags;
use function Lamb\get_tags;
use function Lamb\parse_tags;
use function Lamb\remove_body_tags;
use function Lamb\strip_trailing_body_tags;

class LambTest extends TestCase
{
    // add_body_tags

    public function testAddBodyTagsAppendsMissingTags()
    {
        $this->assertSame('Hello #foo #bar', add_body_tags('Hello', ['foo', 'bar']));
    }

    public function testAddBodyTagsSkipsTagsAlreadyPresent()
    {
        $this->assertSame('Hello #foo #bar', add_body_tags('Hello #foo', ['foo', 'bar']));
    }

    public function testAddBodyTagsReturnsBodyUnchangedWhenAllPresent()
    {
        $body = "Hello #foo\n";
        $this->assertSame($body, add_body_tags($body, ['foo']));
    }

    public function testAddBodyTagsTrimsTrailingWhitespaceBeforeAppending()
    {
        $this->assertSame('Hello #new', add_body_tags("Hello \n", ['new']));
    }

    // strip_trailing_body_tags

    public function testStripTrailingBodyTagsRemovesTrailingRun()
    {
        $this->assertSame('Some text', strip_trailing_body_tags('Some text #foo #bar'));
    }

    public function testStripTrailingBodyTagsLeavesInlineTags()
    {
        $this->assertSame('Text #foo more text', strip_trailing_body_tags('Text #foo more text'));
    }

    public function testStripTrailingBodyTagsHandlesBodyWithoutTags()
    {
        $this->assertSame('No tags here.', strip_trailing_body_tags('No tags here.'));
    }

    // remove_body_tags

    public function testRemoveBodyTagsRemovesNamedTagsOnly()
    {
        $this->assertSame('Hello #bar', remove_body_tags('Hello #foo #bar', ['foo']));
    }

    public function testRemoveBodyTagsRemovesMultipleTags()
    {
        $this->assertSame('Hello', remove_body_tags('Hello #foo #bar', ['foo', 'bar']));
    }

    public function testRemoveBodyTagsIgnoresAbsentTags()
    {
        $this->assertSame('Hello #foo', remove_body_tags('Hello #foo', ['bar']));
    }

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

    public function testParseTagsDoesNotCreatePartialEntityReferenceInHref(): void
    {
        // Parsedown HTML-escapes & to &amp;, so "#foo&bar" in source becomes
        // "#foo&amp;bar" in HTML. The old regex matched through "&amp" (stopping
        // at ";") producing a broken href="/tag/foo&amp" — an incomplete entity.
        $result = parse_tags('<p>#foo&amp;bar</p>');
        $this->assertStringNotContainsString('href="/tag/foo&amp"', $result);
        $this->assertStringContainsString('href="/tag/foo"', $result);
    }
}
