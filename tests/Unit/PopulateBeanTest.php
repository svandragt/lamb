<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;

use function Lamb\Post\populate_bean;

class PopulateBeanTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        global $config;
        $config = $config ?? [];
    }

    public function testPopulateBeanReturnsBean(): void
    {
        $bean = populate_bean('Hello world');
        $this->assertNotNull($bean);
    }

    public function testPopulateBeanSetsBodyText(): void
    {
        $bean = populate_bean('Hello world');
        $this->assertSame('Hello world', $bean->body);
    }

    public function testPopulateBeanSetsCreatedDate(): void
    {
        $bean = populate_bean('Hello world');
        $this->assertNotEmpty($bean->created);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $bean->created);
    }

    public function testPopulateBeanSetsUpdatedDate(): void
    {
        $bean = populate_bean('Hello world');
        $this->assertNotEmpty($bean->updated);
    }

    public function testPopulateBeanExtractsSlugFromFrontMatter(): void
    {
        $text = "---\ntitle: My Post Title\n---\nContent here.";
        $bean = populate_bean($text);
        $this->assertSame('my-post-title', $bean->slug);
    }

    public function testPopulateBeanSetsEmptySlugForPlainText(): void
    {
        $bean = populate_bean('Just a plain status update.');
        $this->assertSame('', $bean->slug);
    }

    public function testPopulateBeanPopulatesTransformed(): void
    {
        $bean = populate_bean('Hello **world**');
        $this->assertNotEmpty($bean->transformed);
        $this->assertStringContainsString('world', $bean->transformed);
    }

    public function testPopulateBeanConvertsMarkdownInTransformed(): void
    {
        $bean = populate_bean('Hello **bold**');
        $this->assertStringContainsString('<strong>', $bean->transformed);
    }

    public function testPopulateBeanSetsDescriptionFromFirstLine(): void
    {
        $bean = populate_bean("First line content.\n\nSecond paragraph.");
        $this->assertNotEmpty($bean->description);
        $this->assertStringContainsString('First line', $bean->description);
    }

    public function testPopulateBeanReusesExistingBeanWhenProvided(): void
    {
        $existing = R::dispense('post');
        $existing->slug = 'preserved-slug';
        R::store($existing);

        $result = populate_bean('Updated content', null, null, $existing);
        $this->assertSame($existing->id, $result->id);
    }

    public function testPopulateBeanSetsDraftZeroForPublishedPost(): void
    {
        $bean = populate_bean("---\ntitle: Post\ndraft: false\n---\nContent.");
        $this->assertSame(0, $bean->draft);
    }

    public function testPopulateBeanSetsDraftOneForDraftPost(): void
    {
        $bean = populate_bean("---\ntitle: Draft\ndraft: true\n---\nContent.");
        $this->assertSame(1, $bean->draft);
    }

    public function testFeedItemIsDraftByDefaultWhenNoConfigSet(): void
    {
        global $config;
        $saved = $config;
        unset($config['feeds_draft']);

        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn('test-id-1');

        $bean = populate_bean("Hello feed", $item, 'test-feed');
        $config = $saved;

        $this->assertSame(1, $bean->draft);
    }

    public function testFeedItemIsPublishedWhenFeedsDraftIsFalse(): void
    {
        global $config;
        $saved = $config;
        $config['feeds_draft'] = 'false';

        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn('test-id-2');

        $bean = populate_bean("Hello feed", $item, 'test-feed');
        $config = $saved;

        $this->assertNotSame(1, $bean->draft);
    }

    private function makeStoredPublishedBean(): \RedBeanPHP\OODBBean
    {
        $bean = \RedBeanPHP\R::dispense('post');
        $bean->body = 'Existing post';
        $bean->draft = 0;
        \RedBeanPHP\R::store($bean);
        return $bean;
    }

    public function testExistingPublishedFeedItemStaysPublishedOnCronUpdate(): void
    {
        global $config;
        $saved = $config;
        unset($config['feeds_draft']);

        $bean = $this->makeStoredPublishedBean();

        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn('test-id-3');

        $bean = populate_bean("Hello feed", $item, 'test-feed', $bean);
        $config = $saved;

        $this->assertNotSame(1, $bean->draft);
    }

    public function testExistingPublishedFeedItemStaysPublishedWhenFeedsDraftTrue(): void
    {
        global $config;
        $saved = $config;
        $config['feeds_draft'] = 'true';

        $bean = $this->makeStoredPublishedBean();

        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn('test-id-4');

        $bean = populate_bean("Hello feed", $item, 'test-feed', $bean);
        $config = $saved;

        $this->assertNotSame(1, $bean->draft);
    }
}
