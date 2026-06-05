<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\is_viewable;
use function Lamb\Response\respond_post;
use function Lamb\Response\respond_status;

class PostVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Seed schema columns so WHERE/visibility filters work regardless of test order.
        $schema = R::dispense('post');
        $schema->draft   = 0;
        $schema->deleted = 0;
        $schema->created = date('Y-m-d H:i:s');
        $schema->slug    = '';
        R::store($schema);
        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog', 'posts_per_page' => 10];

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        R::exec('DELETE FROM post');
        $_SESSION = [];
    }

    private function makePost(array $fields): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body        = $fields['body'] ?? 'Body';
        $bean->title       = $fields['title'] ?? 'Title';
        $bean->transformed = '<p>Body</p>';
        $bean->version     = 1;
        $bean->draft       = $fields['draft'] ?? 0;
        $bean->deleted     = $fields['deleted'] ?? 0;
        $bean->slug        = $fields['slug'] ?? '';
        $bean->created     = $fields['created'] ?? date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    // ---- is_viewable() predicate -------------------------------------------

    public function testPublishedPostIsVisibleToAnonymous(): void
    {
        $bean = $this->makePost([]);
        $this->assertTrue(is_viewable($bean));
    }

    public function testDeletedPostIsNeverVisible(): void
    {
        $bean = $this->makePost(['deleted' => 1]);
        $this->assertFalse(is_viewable($bean));
        $_SESSION[SESSION_LOGIN] = true;
        $this->assertFalse(is_viewable($bean), 'Deleted posts stay hidden even when logged in');
    }

    public function testDraftHiddenFromAnonymousButVisibleToAuthor(): void
    {
        $bean = $this->makePost(['draft' => 1]);
        $this->assertFalse(is_viewable($bean), 'Draft hidden from anonymous visitors');

        $_SESSION[SESSION_LOGIN] = true;
        $this->assertTrue(is_viewable($bean), 'Logged-in author can preview a draft');
    }

    public function testScheduledHiddenFromAnonymousButVisibleToAuthor(): void
    {
        $bean = $this->makePost(['created' => date('Y-m-d H:i:s', time() + 86400)]);
        $this->assertFalse(is_viewable($bean), 'Scheduled post hidden from anonymous visitors');

        $_SESSION[SESSION_LOGIN] = true;
        $this->assertTrue(is_viewable($bean), 'Logged-in author can preview a scheduled post');
    }

    public function testMissingPostIsNotVisible(): void
    {
        $bean = R::load('post', 999999);
        $this->assertFalse(is_viewable($bean));
    }

    // ---- respond_status (/status/<id>) ------------------------------------

    public function testRespondStatusHidesDraftFromAnonymous(): void
    {
        $bean = $this->makePost(['draft' => 1]);
        $data = respond_status([$bean->id]);
        $this->assertSame('404', $data['action'] ?? null);
    }

    public function testRespondStatusShowsDraftToAuthor(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $bean = $this->makePost(['draft' => 1]);
        $data = respond_status([$bean->id]);
        $this->assertArrayHasKey('posts', $data);
        $this->assertCount(1, $data['posts']);
    }

    // ---- respond_post (slug) ----------------------------------------------

    public function testRespondPostShowsDraftToAuthorBySlug(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $this->makePost(['draft' => 1, 'slug' => 'my-draft']);
        $data = respond_post(['my-draft']);
        $this->assertArrayHasKey('posts', $data);
        $this->assertCount(1, $data['posts']);
    }

    public function testRespondPostHidesDraftFromAnonymousBySlug(): void
    {
        $this->makePost(['draft' => 1, 'slug' => 'my-draft']);
        $data = respond_post(['my-draft']);
        $this->assertSame('404', $data['action'] ?? null);
    }
}
