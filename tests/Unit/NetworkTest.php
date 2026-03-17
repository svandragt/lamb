<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\Item as SimplePieItem;

use function Lamb\Network\attributed_content;
use function Lamb\Network\get_structured_content;

class NetworkTest extends TestCase
{
    private function makeItem(string $title = '', string $description = '', string $permalink = ''): SimplePieItem
    {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_title')->willReturn($title);
        $item->method('get_description')->willReturn($description);
        $item->method('get_permalink')->willReturn($permalink);
        return $item;
    }

    // attributed_content

    public function testAttributedContentIncludesFeedName(): void
    {
        $item = $this->makeItem('', 'Hello world', 'https://example.com/post');
        $result = attributed_content($item, 'ExampleBlog');
        $this->assertStringContainsString('ExampleBlog', $result);
    }

    public function testAttributedContentIncludesPermalink(): void
    {
        $item = $this->makeItem('', 'Content', 'https://example.com/post');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('https://example.com/post', $result);
    }

    public function testAttributedContentStripsHtmlTags(): void
    {
        $item = $this->makeItem('', '<p>Hello <b>world</b></p>', 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringNotContainsString('<b>', $result);
    }

    public function testAttributedContentQuotesEachLine(): void
    {
        $item = $this->makeItem('', "Line one\nLine two", 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('> Line one', $result);
        $this->assertStringContainsString('> Line two', $result);
    }

    public function testAttributedContentLimitsToFiveLines(): void
    {
        $description = implode("\n", range(1, 10));
        $item = $this->makeItem('', $description, 'https://example.com');
        $result = attributed_content($item, 'Blog');
        // Lines 6-10 should not appear as quoted lines
        $this->assertStringNotContainsString('> 6', $result);
        $this->assertStringNotContainsString('> 10', $result);
    }

    public function testAttributedContentEmptyDescriptionReturnsAttribution(): void
    {
        $item = $this->makeItem('', '', 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('Originally written on', $result);
    }

    // get_structured_content

    public function testGetStructuredContentWithTitleAddsFrontMatter(): void
    {
        $item = $this->makeItem('My Post Title', 'Some content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString('---', $result);
        $this->assertStringContainsString('title: My Post Title', $result);
    }

    public function testGetStructuredContentWithoutTitleHasNoFrontMatter(): void
    {
        $item = $this->makeItem('', 'Some content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringNotContainsString('---', $result);
        $this->assertStringNotContainsString('title:', $result);
    }

    public function testGetStructuredContentIncludesAttributedBody(): void
    {
        $item = $this->makeItem('', 'Hello world', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString('Originally written on', $result);
    }

    public function testGetStructuredContentEscapesTitleSlashes(): void
    {
        $item = $this->makeItem("It's a test", 'Content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString("title: It\\'s a test", $result);
    }

    public function testGetStructuredContentReturnsString(): void
    {
        $item = $this->makeItem('Title', 'Body', 'https://example.com');
        $this->assertIsString(get_structured_content($item, 'Blog'));
    }
}
