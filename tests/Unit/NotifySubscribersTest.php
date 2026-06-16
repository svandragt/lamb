<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\notify_post_subscribers;

class NotifySubscribersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        global $config;
        $config = [];
    }

    public function testQueuesOutboundWebmentionsForEligiblePost(): void
    {
        $post = R::dispense('post');
        $post->body = 'See [link](https://other.example/a)';
        $post->transformed = '<p>See <a href="https://other.example/a">link</a></p>';
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        R::store($post);

        notify_post_subscribers($post);

        $rows = R::find('webmentionoutbox', ' post_id = ? ', [$post->id]);
        $this->assertCount(1, $rows, 'An eligible post should enqueue its external link');
        $row = reset($rows);
        $this->assertSame('https://other.example/a', $row->target);
    }

    public function testSkipsDraftsAndDoesNotThrowWithoutHubs(): void
    {
        $draft = R::dispense('post');
        $draft->body = 'Draft [link](https://other.example/b)';
        $draft->transformed = '<p>Draft <a href="https://other.example/b">link</a></p>';
        $draft->created = '2026-01-01 00:00:00';
        $draft->updated = '2026-01-01 00:00:00';
        $draft->version = 1;
        $draft->draft = 1;
        R::store($draft);

        notify_post_subscribers($draft);

        $rows = R::find('webmentionoutbox', ' post_id = ? ', [$draft->id]);
        $this->assertCount(0, $rows, 'Drafts must not enqueue outbound webmentions');
    }
}
