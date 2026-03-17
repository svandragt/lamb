<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\get_feed_data;
use function Lamb\Response\get_tag_feed_data;

class ResponseFeedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec("DELETE FROM post");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog'];
    }

    // get_feed_data

    public function testGetFeedDataReturnsArray(): void
    {
        $result = get_feed_data();
        $this->assertIsArray($result);
    }

    public function testGetFeedDataHasRequiredKeys(): void
    {
        $result = get_feed_data();
        foreach (['posts', 'title', 'feed_url', 'updated'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testGetFeedDataTitleMatchesConfig(): void
    {
        $result = get_feed_data();
        $this->assertSame('Test Blog', $result['title']);
    }

    public function testGetFeedDataFeedUrlEndsWithFeed(): void
    {
        $result = get_feed_data();
        $this->assertStringEndsWith('/feed', $result['feed_url']);
    }

    public function testGetFeedDataPostsIsArray(): void
    {
        $result = get_feed_data();
        $this->assertIsArray($result['posts']);
    }

    public function testGetFeedDataUpdatedIsStringWhenNoPosts(): void
    {
        $result = get_feed_data();
        $this->assertIsString($result['updated']);
    }

    // get_tag_feed_data

    public function testGetTagFeedDataReturnsArray(): void
    {
        $result = get_tag_feed_data('lamb');
        $this->assertIsArray($result);
    }

    public function testGetTagFeedDataHasRequiredKeys(): void
    {
        $result = get_tag_feed_data('lamb');
        foreach (['posts', 'title', 'feed_url', 'updated'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testGetTagFeedDataTitleIncludesTag(): void
    {
        $result = get_tag_feed_data('lamb');
        $this->assertStringContainsString('lamb', $result['title']);
    }

    public function testGetTagFeedDataFeedUrlIncludesTag(): void
    {
        $result = get_tag_feed_data('lamb');
        $this->assertStringContainsString('lamb', $result['feed_url']);
    }

    public function testGetTagFeedDataPostsIsArray(): void
    {
        $result = get_tag_feed_data('lamb');
        $this->assertIsArray($result['posts']);
    }

    public function testGetTagFeedDataUpdatedIsStringWhenNoPosts(): void
    {
        $result = get_tag_feed_data('lamb');
        $this->assertIsString($result['updated']);
    }
}
