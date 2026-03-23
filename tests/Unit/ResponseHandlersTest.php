<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\respond_404;
use function Lamb\Response\respond_drafts;
use function Lamb\Response\respond_home;
use function Lamb\Response\respond_post;
use function Lamb\Response\respond_search;
use function Lamb\Response\respond_settings;
use function Lamb\Response\respond_status;
use function Lamb\Response\respond_tag;
use function Lamb\Response\soft_delete_post;

class ResponseHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Ensure post schema columns exist so WHERE filters work regardless of test order.
        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);

        R::exec("DELETE FROM post");
        R::exec("DELETE FROM option");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = [
            'site_title'    => 'Test Blog',
            'posts_per_page' => 10,
            'menu_items'    => [],
            'feeds'         => [],
        ];

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    // respond_404

    public function testRespond404ReturnsArray(): void
    {
        $result = respond_404();
        $this->assertIsArray($result);
    }

    public function testRespond404HasTitleKey(): void
    {
        $result = respond_404();
        $this->assertArrayHasKey('title', $result);
    }

    public function testRespond404HasIntroKey(): void
    {
        $result = respond_404();
        $this->assertArrayHasKey('intro', $result);
    }

    public function testRespond404HasActionKey(): void
    {
        $result = respond_404();
        $this->assertArrayHasKey('action', $result);
    }

    public function testRespond404ActionIs404(): void
    {
        $result = respond_404();
        $this->assertSame('404', $result['action']);
    }

    public function testRespond404IntroMentionsPageNotFound(): void
    {
        $result = respond_404();
        $this->assertStringContainsString('not found', strtolower($result['intro']));
    }

    // respond_home

    public function testRespondHomeReturnsArray(): void
    {
        $result = respond_home();
        $this->assertIsArray($result);
    }

    public function testRespondHomeHasPostsKey(): void
    {
        $result = respond_home();
        $this->assertArrayHasKey('posts', $result);
    }

    public function testRespondHomeHasPaginationKey(): void
    {
        $result = respond_home();
        $this->assertArrayHasKey('pagination', $result);
    }

    public function testRespondHomeTitleMatchesSiteTitle(): void
    {
        $result = respond_home();
        $this->assertSame('Test Blog', $result['title']);
    }

    public function testRespondHomePostsIsArray(): void
    {
        $result = respond_home();
        $this->assertIsArray($result['posts']);
    }

    public function testRespondHomeExcludesDrafts(): void
    {
        $draft = R::dispense('post');
        $draft->body = 'Draft post';
        $draft->draft = 1;
        $draft->version = 1;
        $draft->created = date('Y-m-d H:i:s');
        R::store($draft);

        $result = respond_home();
        $this->assertCount(0, $result['posts']);
    }

    public function testRespondHomeIncludesPublishedPosts(): void
    {
        $post = R::dispense('post');
        $post->body = 'Hello world';
        $post->draft = null;
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_home();
        $this->assertCount(1, $result['posts']);
    }

    // respond_status

    public function testRespondStatusReturnsArray(): void
    {
        $post = R::dispense('post');
        $post->body = 'Status post body';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_status([$post->id]);
        $this->assertIsArray($result);
    }

    public function testRespondStatusHasPostsKey(): void
    {
        $post = R::dispense('post');
        $post->body = 'Status post body';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_status([$post->id]);
        $this->assertArrayHasKey('posts', $result);
    }

    public function testRespondStatusContainsSinglePost(): void
    {
        $post = R::dispense('post');
        $post->body = 'Status post body';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_status([$post->id]);
        $this->assertCount(1, $result['posts']);
    }

    public function testRespondStatusTitleMatchesPostTitle(): void
    {
        $post = R::dispense('post');
        $post->body = 'Status post body';
        $post->title = 'My Post Title';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_status([$post->id]);
        $this->assertSame('My Post Title', $result['title']);
    }

    // respond_post

    public function testRespondPostReturnsArrayForKnownSlug(): void
    {
        $post = R::dispense('post');
        $post->body = 'Post content';
        $post->slug = 'my-test-slug';
        $post->version = 1;
        $post->draft = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_post(['my-test-slug']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts', $result);
    }

    public function testRespondPostContainsCorrectPost(): void
    {
        $post = R::dispense('post');
        $post->body = 'Post content';
        $post->slug = 'test-slug-unique';
        $post->version = 1;
        $post->draft = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_post(['test-slug-unique']);
        $this->assertCount(1, $result['posts']);
        $this->assertSame($post->id, $result['posts'][0]->id);
    }

    public function testRespondPostReturns404ForUnknownSlug(): void
    {
        $result = respond_post(['no-such-slug-xyz']);
        // respond_404 returns ['title', 'intro', 'action']
        $this->assertArrayHasKey('action', $result);
        $this->assertSame('404', $result['action']);
    }

    public function testRespondPostReturns404ForDraftPost(): void
    {
        $post = R::dispense('post');
        $post->body = 'Draft';
        $post->slug = 'draft-slug';
        $post->draft = 1;
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_post(['draft-slug']);
        $this->assertArrayHasKey('action', $result);
        $this->assertSame('404', $result['action']);
    }

    // respond_search

    public function testRespondSearchReturnsEmptyArrayWhenNoQuery(): void
    {
        $result = respond_search([]);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testRespondSearchReturnsArrayWithRequiredKeys(): void
    {
        $result = respond_search(['hello']);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('intro', $result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('pagination', $result);
    }

    public function testRespondSearchTitleContainsQuery(): void
    {
        $result = respond_search(['hello']);
        $this->assertStringContainsString('hello', $result['title']);
    }

    public function testRespondSearchFindsMatchingPost(): void
    {
        $post = R::dispense('post');
        $post->body = 'This post is about testing search functionality';
        $post->version = 1;
        $post->draft = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_search(['testing']);
        $this->assertGreaterThan(0, $result['pagination']['total_posts']);
    }

    public function testRespondSearchIntroSaysNoResultsWhenNoneFound(): void
    {
        $result = respond_search(['xyzzy_no_match_abc']);
        $this->assertStringContainsString('No results', $result['intro']);
    }

    public function testRespondSearchIntroIncludesCountWhenResultsFound(): void
    {
        $post = R::dispense('post');
        $post->body = 'uniquesearchterm123';
        $post->version = 1;
        $post->draft = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_search(['uniquesearchterm123']);
        $this->assertStringContainsString('1', $result['intro']);
    }

    public function testRespondSearchExcludesDrafts(): void
    {
        $post = R::dispense('post');
        $post->body = 'uniquedraftterm456';
        $post->draft = 1;
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_search(['uniquedraftterm456']);
        $this->assertSame(0, $result['pagination']['total_posts']);
    }

    // respond_tag

    public function testRespondTagReturnsArray(): void
    {
        $result = respond_tag(['lamb']);
        $this->assertIsArray($result);
    }

    public function testRespondTagHasRequiredKeys(): void
    {
        $result = respond_tag(['lamb']);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('feed_url', $result);
    }

    public function testRespondTagTitleContainsTagName(): void
    {
        $result = respond_tag(['lamb']);
        $this->assertStringContainsString('lamb', $result['title']);
    }

    public function testRespondTagFeedUrlContainsTag(): void
    {
        $result = respond_tag(['lamb']);
        $this->assertStringContainsString('lamb', $result['feed_url']);
    }

    public function testRespondTagReturnsNoResultsIntroWhenNoPostsTagged(): void
    {
        $result = respond_tag(['nonexistenttag999']);
        $this->assertStringContainsString('No results', $result['intro']);
    }

    public function testRespondTagFindsPostWithMatchingTag(): void
    {
        $post = R::dispense('post');
        $post->body = 'Hello #uniqtag end';
        $post->version = 1;
        $post->draft = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_tag(['uniqtag']);
        $this->assertGreaterThan(0, $result['pagination']['total_posts']);
    }

    // respond_settings

    public function testRespondSettingsReturnsArray(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_settings();
        $this->assertIsArray($result);
    }

    public function testRespondSettingsHasTitleKey(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_settings();
        $this->assertArrayHasKey('title', $result);
    }

    public function testRespondSettingsTitleIsSettings(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_settings();
        $this->assertSame('Settings', $result['title']);
    }

    public function testRespondSettingsHasIniTextKey(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_settings();
        $this->assertArrayHasKey('ini_text', $result);
    }

    public function testRespondSettingsIniTextIsString(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_settings();
        $this->assertIsString($result['ini_text']);
    }

    // respond_drafts

    public function testRespondDraftsReturnsArray(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_drafts();
        $this->assertIsArray($result);
    }

    public function testRespondDraftsHasRequiredKeys(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_drafts();
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('title', $result);
    }

    public function testRespondDraftsTitleIsDrafts(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $result = respond_drafts();
        $this->assertSame('Drafts', $result['title']);
    }

    public function testRespondDraftsOnlyReturnsDraftPosts(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        $published = R::dispense('post');
        $published->body = 'Published post';
        $published->draft = null;
        $published->version = 1;
        $published->created = date('Y-m-d H:i:s');
        R::store($published);

        $draft = R::dispense('post');
        $draft->body = 'Draft post';
        $draft->draft = 1;
        $draft->version = 1;
        $draft->created = date('Y-m-d H:i:s');
        R::store($draft);

        $result = respond_drafts();
        $this->assertCount(1, $result['posts']);
        $this->assertSame($draft->id, array_values($result['posts'])[0]->id);
    }

    public function testRespondDraftsReturnsEmptyWhenNoDrafts(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        $result = respond_drafts();
        $this->assertCount(0, $result['posts']);
    }

    // deleted post visibility

    public function testRespondHomeExcludesDeletedPosts(): void
    {
        $visible = R::dispense('post');
        $visible->body = 'Visible post';
        $visible->created = date('Y-m-d H:i:s');
        $visible->updated = date('Y-m-d H:i:s');
        R::store($visible);

        $deleted = R::dispense('post');
        $deleted->body = 'Deleted post';
        $deleted->deleted = 1;
        $deleted->created = date('Y-m-d H:i:s');
        $deleted->updated = date('Y-m-d H:i:s');
        R::store($deleted);

        $result = respond_home();
        $ids = array_map(fn($p) => $p->id, $result['posts']);
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    public function testRespondStatusReturns404ForDeletedPost(): void
    {
        $deleted = R::dispense('post');
        $deleted->body = 'Soft-deleted post';
        $deleted->deleted = 1;
        $deleted->created = date('Y-m-d H:i:s');
        $deleted->updated = date('Y-m-d H:i:s');
        R::store($deleted);

        $result = respond_status([(string) $deleted->id]);
        $this->assertSame('404', $result['action'] ?? null);
    }

    // soft_delete_post

    public function testSoftDeletePostSetsDeletedFlag(): void
    {
        $bean = R::dispense('post');
        $bean->body    = 'A post to soft-delete';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        soft_delete_post($bean);

        $loaded = R::load('post', $bean->id);
        $this->assertSame(1, (int) $loaded->deleted);
    }

    public function testSoftDeletePostSetsDeletedAt(): void
    {
        $bean = R::dispense('post');
        $bean->body    = 'A post to soft-delete';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        soft_delete_post($bean);

        $loaded = R::load('post', $bean->id);
        $this->assertNotEmpty($loaded->deleted_at);
        $this->assertStringStartsWith(date('Y-m-d'), $loaded->deleted_at);
    }

    public function testSoftDeletePostKeepsPostInDatabase(): void
    {
        $bean = R::dispense('post');
        $bean->body    = 'Keep in DB';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);
        $id = $bean->id;

        soft_delete_post($bean);

        $loaded = R::load('post', $id);
        $this->assertSame($id, $loaded->id);
    }

    public function testRespondSearchExcludesDeletedPosts(): void
    {
        $deleted = R::dispense('post');
        $deleted->body = 'Uniquefindableterm deleted post';
        $deleted->deleted = 1;
        $deleted->created = date('Y-m-d H:i:s');
        $deleted->updated = date('Y-m-d H:i:s');
        R::store($deleted);

        $result = respond_search(['Uniquefindableterm']);
        $ids = array_map(fn($p) => $p->id, $result['posts']);
        $this->assertNotContains($deleted->id, $ids);
    }
}
