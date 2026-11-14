<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\find_home_post;
use function Lamb\find_post_by_path;
use function Lamb\permalink;
use function Lamb\post_path;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;
use function Lamb\Response\get_feed_data;
use function Lamb\Response\public_posts_clause;
use function Lamb\Response\respond_home;
use function Lamb\Response\respond_search;

/**
 * A published post slugged `home` is shown above the timeline at `/` (issue
 * #867). It is a page, not a timeline entry, so every public listing leaves it
 * out, and its permalink is the site root.
 */
class HomePostTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog', 'menu_items' => [], 'feeds' => []];

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makePost(array $fields = []): OODBBean
    {
        $bean = R::dispense('post');
        $bean->body        = $fields['body'] ?? 'A post';
        $bean->transformed = '<p>' . $bean->body . '</p>';
        $bean->slug        = $fields['slug'] ?? '';
        $bean->draft       = $fields['draft'] ?? null;
        $bean->deleted     = $fields['deleted'] ?? null;
        $bean->version     = POST_VERSION;
        $bean->created     = $fields['created'] ?? '2026-01-01 12:00:00';
        $bean->updated     = $fields['created'] ?? '2026-01-01 12:00:00';
        R::store($bean);

        return $bean;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function hiddenStateProvider(): array
    {
        return [
            'draft'     => [['draft' => 1]],
            'deleted'   => [['deleted' => 1]],
            'scheduled' => [['created' => '2999-01-01 12:00:00']],
        ];
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function menuItemsProvider(): array
    {
        return [
            'no menu items'   => [[]],
            'with menu items' => [['About' => '/about']],
        ];
    }

    // finalize_slug

    public function testFinalizeSlugKeepsHomeAsTheSlug(): void
    {
        \Lamb\Route\register_route('home', 'strlen');
        $bean = populate_bean("---\nslug: home\n---\nWelcome.");
        R::store($bean);

        finalize_slug($bean);

        $this->assertSame('home', $bean->slug);
        $this->assertStringContainsString('slug: home', $bean->body);
    }

    public function testFinalizeSlugStillSuffixesASecondHomePost(): void
    {
        \Lamb\Route\register_route('home', 'strlen');
        $first = populate_bean("---\nslug: home\n---\nWelcome.");
        R::store($first);
        finalize_slug($first);
        R::store($first);

        $second = populate_bean("---\nslug: home\n---\nAgain.");
        R::store($second);
        finalize_slug($second);

        $this->assertSame('home', $first->slug);
        $this->assertSame('home-' . $second->id, $second->slug);
    }

    // The timeline, search, feeds

    /**
     * @dataProvider menuItemsProvider
     * @param array<string, string> $menu_items
     */
    public function testPublicPostsClauseExcludesTheHomePost(array $menu_items): void
    {
        global $config;
        $config['menu_items'] = $menu_items;
        $status = $this->makePost();
        $page   = $this->makePost(['slug' => 'about-us']);
        $home   = $this->makePost(['slug' => 'home']);

        $clause = public_posts_clause();
        $ids    = array_map('intval', array_keys(R::find('post', $clause['sql'], $clause['params'])));

        $this->assertContains((int) $status->id, $ids);
        $this->assertContains((int) $page->id, $ids);
        $this->assertNotContains((int) $home->id, $ids);
    }

    public function testPublicPostsClauseKeepsPostsWithoutASlug(): void
    {
        $bean = R::dispense('post');
        $bean->body    = 'Legacy post with a NULL slug';
        $bean->draft   = null;
        $bean->deleted = null;
        $bean->created = '2026-01-01 12:00:00';
        $bean->slug    = null;
        R::store($bean);

        $clause = public_posts_clause();

        $this->assertCount(1, R::find('post', $clause['sql'], $clause['params']));
    }

    public function testHomePostIsNotInTheTimeline(): void
    {
        $this->makePost(['body' => 'Timeline entry']);
        $this->makePost(['body' => 'Welcome', 'slug' => 'home']);

        $posts = respond_home()['posts'];

        $this->assertCount(1, $posts);
        $this->assertSame('Timeline entry', reset($posts)->body);
    }

    public function testHomePostIsNotInSearchResults(): void
    {
        $this->makePost(['body' => 'homepagemarker', 'slug' => 'home']);

        $this->assertCount(0, respond_search(['homepagemarker'])['posts']);
    }

    public function testHomePostIsNotInTheFeed(): void
    {
        $this->makePost(['body' => 'Timeline entry']);
        $this->makePost(['body' => 'Welcome', 'slug' => 'home']);

        $this->assertCount(1, get_feed_data()['posts']);
    }

    // respond_home

    public function testRespondHomeCarriesThePublishedHomePost(): void
    {
        $home = $this->makePost(['body' => 'Welcome', 'slug' => 'home']);

        $this->assertSame($home->id, respond_home()['home_post']->id);
    }

    public function testRespondHomeHasNoHomePostWhenNoneExists(): void
    {
        $this->makePost(['slug' => 'about-us']);

        $this->assertArrayNotHasKey('home_post', respond_home());
    }

    /**
     * @dataProvider hiddenStateProvider
     * @param array<string, mixed> $state
     */
    public function testRespondHomeIgnoresAHomePostThatIsNotVisible(array $state): void
    {
        $this->makePost(['body' => 'Welcome', 'slug' => 'home'] + $state);

        $this->assertArrayNotHasKey('home_post', respond_home());
    }

    public function testRespondHomeShowsTheHomePostOnTheFirstPageOnly(): void
    {
        $this->makePost(['body' => 'Welcome', 'slug' => 'home']);

        $_GET['page'] = 2;

        $this->assertArrayNotHasKey('home_post', respond_home());
    }

    public function testRespondHomeShowsAnUnpublishedHomePostForItsPreviewToken(): void
    {
        $home = $this->makePost(['body' => 'Welcome', 'slug' => 'home', 'draft' => 1]);
        $home->preview_token         = 'preview-secret';
        $home->preview_token_expires = date('Y-m-d H:i:s', time() + 3600);
        R::store($home);

        $_GET['preview'] = 'preview-secret';

        $this->assertSame($home->id, respond_home()['home_post']->id);
    }

    public function testRespondHomeIgnoresAWrongPreviewToken(): void
    {
        $home = $this->makePost(['body' => 'Welcome', 'slug' => 'home', 'draft' => 1]);
        $home->preview_token         = 'preview-secret';
        $home->preview_token_expires = date('Y-m-d H:i:s', time() + 3600);
        R::store($home);

        $_GET['preview'] = 'wrong';

        $this->assertArrayNotHasKey('home_post', respond_home());
    }

    // find_post_by_path and permalink

    public function testFindPostByPathResolvesTheRootToTheHomePost(): void
    {
        $home = $this->makePost(['slug' => 'home']);

        $this->assertSame($home->id, find_post_by_path('/')->id);
        $this->assertSame($home->id, find_post_by_path('')->id);
    }

    public function testFindPostByPathRootIsNullWithoutAHomePost(): void
    {
        $this->makePost(['slug' => 'about-us']);

        $this->assertNull(find_post_by_path('/'));
    }

    public function testFindHomePostReturnsThePublishedPost(): void
    {
        $home = $this->makePost(['slug' => 'home']);

        $this->assertSame($home->id, find_home_post()?->id);
    }

    /**
     * @dataProvider hiddenStateProvider
     * @param array<string, mixed> $state
     */
    public function testFindHomePostIgnoresAPostThatIsNotVisible(array $state): void
    {
        $this->makePost(['slug' => 'home'] + $state);

        $this->assertNull(find_home_post());
    }

    public function testTheHomePostPermalinkIsTheSiteRoot(): void
    {
        $home = $this->makePost(['slug' => 'home']);

        $this->assertSame('/', post_path('home', (int) $home->id));
        $this->assertSame(ROOT_URL . '/', permalink($home));
    }
}
