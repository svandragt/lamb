<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\post_has_slug;
use function Lamb\Post\populate_bean;
use function Lamb\Post\posts_by_tag;
use function Lamb\Response\count_scheduled;
use function Lamb\Response\respond_home;
use function Lamb\Response\respond_post;
use function Lamb\Response\respond_scheduled;
use function Lamb\Response\respond_search;

class ScheduledPostTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Ensure schema columns exist regardless of test order.
        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);

        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = [
            'site_title'     => 'Test Blog',
            'posts_per_page' => 10,
            'menu_items'     => [],
            'feeds'          => [],
        ];

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    protected function tearDown(): void
    {
        R::exec('DELETE FROM post');
    }

    private function makePost(string $body, string $created, ?string $slug = null): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body    = $body;
        $bean->draft   = null;
        $bean->deleted = null;
        $bean->version = 1;
        $bean->created = $created;
        $bean->updated = $created;
        if ($slug !== null) {
            $bean->slug = $slug;
        }
        R::store($bean);
        return $bean;
    }

    private function future(): string
    {
        return date('Y-m-d H:i:s', strtotime('+1 day'));
    }

    private function past(): string
    {
        return date('Y-m-d H:i:s', strtotime('-1 day'));
    }

    public function testFutureCreatedPostIsHiddenFromHome(): void
    {
        $this->makePost('Scheduled for later', $this->future());

        $result = respond_home();
        $this->assertCount(0, $result['posts'], 'Future-dated posts must not appear on the homepage');
    }

    public function testPastCreatedPostIsVisibleOnHome(): void
    {
        $this->makePost('Already published', $this->past());

        $result = respond_home();
        $this->assertCount(1, $result['posts'], 'Past-dated posts must appear on the homepage');
    }

    public function testFutureCreatedPostIsHiddenFromTagPages(): void
    {
        $this->makePost('A scheduled post #php', $this->future());

        $this->assertEmpty(posts_by_tag('php'), 'Future-dated posts must not appear on tag pages');
    }

    public function testFutureCreatedPostIsHiddenFromSearch(): void
    {
        $this->makePost('uniquescheduledword in body', $this->future());

        $result = respond_search(['uniquescheduledword']);
        $this->assertCount(0, $result['posts'], 'Future-dated posts must not appear in search results');
    }

    public function testRespondScheduledReturnsFuturePosts(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $this->makePost('Scheduled item', $this->future());
        $this->makePost('Published item', $this->past());

        $result = respond_scheduled();
        $this->assertCount(1, $result['posts'], 'Scheduled view should list only future-dated posts');
    }

    public function testCountScheduledCountsOnlyFuturePublishedPosts(): void
    {
        $this->makePost('Scheduled item', $this->future());
        $this->makePost('Published item', $this->past());

        // A future-dated draft is a draft, not a scheduled post.
        $draft = $this->makePost('Future draft', $this->future());
        $draft->draft = 1;
        R::store($draft);

        $this->assertSame(1, count_scheduled());
    }

    public function testPostHasSlugHidesFutureSluggedPost(): void
    {
        $this->makePost('A scheduled page', $this->future(), 'scheduled-page');

        $this->assertSame('', post_has_slug('scheduled-page'), 'Future-dated slugged posts must not resolve publicly');
    }

    public function testRespondPostReturns404ForFutureSluggedPost(): void
    {
        $this->makePost('A scheduled page', $this->future(), 'scheduled-page');

        $result = respond_post(['scheduled-page']);
        $this->assertSame('404', $result['action'] ?? null, 'Future-dated slugged posts must 404 publicly');
    }

    public function testFrontMatterCreatedDateIsNormalisedToDatetimeString(): void
    {
        global $config;
        $config = [];
        $body = "---\ntitle: Future Post\ncreated: 2099-01-01 09:00:00\n---\n\nHello from the future #news";

        $bean = populate_bean($body);

        $this->assertSame('2099-01-01 09:00:00', $bean->created, 'Front-matter created must be stored as a Y-m-d H:i:s string');
        $this->assertTrue(\Lamb\is_scheduled($bean), 'A future front-matter created date schedules the post');
    }
}
