<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Network\feed_status_bean;
use function Lamb\Network\ingest_item;
use function Lamb\Network\is_json_feed_url;
use function Lamb\Network\parse_json_feed;
use function Lamb\Network\record_json_feed_crawl;

class JsonFeedTest extends TestCase
{
    private function fixture(): string
    {
        return <<<JSON
        {
          "version": "https://jsonfeed.org/version/1.1",
          "title": "Example JSON Feed",
          "items": [
            {
              "id": "https://example.com/posts/1",
              "url": "https://example.com/posts/1",
              "title": "First JSON post",
              "content_text": "Hello from JSON Feed.\\nSecond line of text.",
              "date_published": "2026-06-10T12:00:00Z"
            }
          ]
        }
        JSON;
    }

    public function testIsJsonFeedUrlMatchesJsonSuffix(): void
    {
        $this->assertTrue(is_json_feed_url('https://example.com/feed.json'));
        $this->assertTrue(is_json_feed_url('https://example.com/feed.json?x=1'));
        $this->assertFalse(is_json_feed_url('https://example.com/feed.atom'));
        $this->assertFalse(is_json_feed_url('https://example.com/feed'));
    }

    public function testParseJsonFeedReturnsNullForNonJsonFeed(): void
    {
        $this->assertNull(parse_json_feed('not json at all'));
        $this->assertNull(parse_json_feed('{"title":"no version key"}'));
    }

    public function testParseJsonFeedMapsItemFields(): void
    {
        $feed = parse_json_feed($this->fixture());

        $this->assertNotNull($feed);
        $this->assertSame('Example JSON Feed', $feed['title']);
        $this->assertCount(1, $feed['items']);

        $item = $feed['items'][0];
        $this->assertSame('https://example.com/posts/1', $item->get_id());
        $this->assertSame('First JSON post', $item->get_title());
        $this->assertSame('https://example.com/posts/1', $item->get_permalink());
        $this->assertSame(strtotime('2026-06-10T12:00:00Z'), $item->get_date('U'));
    }

    public function testGetDescriptionPrefersContentTextOverHtml(): void
    {
        $json = '{"version":"https://jsonfeed.org/version/1.1","items":[{"id":"a",'
            . '"content_text":"plain text","content_html":"<p>html</p>"}]}';

        $item = parse_json_feed($json)['items'][0];

        $this->assertSame('plain text', $item->get_description());
    }

    public function testGetDescriptionFallsBackToContentHtml(): void
    {
        $json = '{"version":"https://jsonfeed.org/version/1.1","items":[{"id":"a",'
            . '"content_html":"<p>only html</p>"}]}';

        $item = parse_json_feed($json)['items'][0];

        // The raw HTML is returned; the ingest pipeline strips tags downstream.
        $this->assertSame('<p>only html</p>', $item->get_description());
    }

    public function testGetUpdatedDateFallsBackToPublishedWhenModifiedAbsent(): void
    {
        $item = parse_json_feed($this->fixture())['items'][0];

        $this->assertSame($item->get_date('U'), $item->get_updated_date('U'));
    }

    public function testIngestCreatesPostWithExpectedFieldsAndDedupes(): void
    {
        global $config;
        $original = $config;
        $config['feeds_draft'] = true;

        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::nuke();

        $item = parse_json_feed($this->fixture())['items'][0];

        $this->assertTrue(ingest_item($item, 'examplefeed', 0));
        $this->assertSame(1, R::count('post'));

        $bean = R::findOne('post');
        $this->assertSame('examplefeed', $bean->feed_name);
        $this->assertSame('https://example.com/posts/1', $bean->source_url);
        $this->assertSame(md5('examplefeed' . 'https://example.com/posts/1'), $bean->feeditem_uuid);
        $this->assertStringContainsString('Hello from JSON Feed.', $bean->body);
        $this->assertStringContainsString('Originally written on [examplefeed]', $bean->body);
        $this->assertSame(1, (int) $bean->draft, 'feed items are drafts by default');

        // Re-ingesting the same item must not create a duplicate.
        ingest_item($item, 'examplefeed', 0);
        $this->assertSame(1, R::count('post'));

        $config = $original;
    }

    public function testRecordJsonFeedCrawlBlocksLoopbackTargetAndRecordsFailure(): void
    {
        // A compromised/malicious feed host redirecting the cron's fetch to
        // an internal address is exactly the SSRF fetch_guarded() blocks;
        // record_json_feed_crawl() must degrade to a recorded failure, not an
        // exception or a silently "successful" empty crawl.
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::nuke();

        $result = record_json_feed_crawl('LoopbackFeed', 'http://127.0.0.1/feed.json');

        $this->assertFalse($result['ok']);
        $status = feed_status_bean('LoopbackFeed', 'http://127.0.0.1/feed.json');
        $this->assertNotEmpty($status->error_message);
        $this->assertEmpty($status->last_success);
    }
}
