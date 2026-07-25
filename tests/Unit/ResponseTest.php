<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\build_exclude_slugs_clause;
use function Lamb\Response\build_pagination_meta;
use function Lamb\Response\get_cookie_options;
use function Lamb\Response\get_feed_updated_date;
use function Lamb\Response\paginate_posts;
use function Lamb\Response\pagination_window;
use function Lamb\Response\public_posts_clause;
use function Lamb\Response\upgrade_posts;

class ResponseTest extends TestCase
{
    // get_cookie_options

    public function testGetCookieOptionsReturnsArray()
    {
        $this->assertIsArray(get_cookie_options(time() + 3600));
    }

    public function testGetCookieOptionsHasRequiredKeys()
    {
        $opts = get_cookie_options(time() + 3600);
        foreach (['expires', 'path', 'secure', 'httponly', 'samesite'] as $key) {
            $this->assertArrayHasKey($key, $opts);
        }
    }

    public function testGetCookieOptionsUsesProvidedExpiry()
    {
        $expiry = time() + 1234;
        $opts = get_cookie_options($expiry);
        $this->assertSame($expiry, $opts['expires']);
    }

    public function testGetCookieOptionsPathIsRoot()
    {
        $this->assertSame('/', get_cookie_options(time())['path']);
    }

    public function testGetCookieOptionsSameSiteIsStrict()
    {
        $this->assertSame('Strict', get_cookie_options(time())['samesite']);
    }

    public function testGetCookieOptionsSecureIsTrue()
    {
        $this->assertTrue(get_cookie_options(time())['secure']);
    }

    public function testGetCookieOptionsHttponlyIsTrue()
    {
        $this->assertTrue(get_cookie_options(time())['httponly']);
    }

    // build_exclude_slugs_clause

    public function testBuildExcludeSlugClauseReturnsNullForEmptyArray()
    {
        $this->assertNull(build_exclude_slugs_clause([]));
    }

    public function testBuildExcludeSlugClauseReturnsSqlForSingleSlug()
    {
        $result = build_exclude_slugs_clause(['about']);
        $this->assertStringContainsString('slug NOT IN', $result['sql']);
        $this->assertStringContainsString('?', $result['sql']);
    }

    public function testBuildExcludeSlugClauseReturnsSqlForMultipleSlugs()
    {
        $result = build_exclude_slugs_clause(['about', 'contact', 'feed']);
        $this->assertSame(3, substr_count($result['sql'], '?'));
    }

    public function testBuildExcludeSlugClauseReturnsMatchingParams()
    {
        $slugs = ['about', 'contact'];
        $result = build_exclude_slugs_clause($slugs);
        $this->assertSame($slugs, $result['params']);
    }

    // public_posts_clause

    public function testPublicPostsClauseFiltersDraftsDeletedAndScheduled()
    {
        global $config;
        $config = ['menu_items' => []];

        $clause = public_posts_clause();

        $this->assertStringContainsString('draft', $clause['sql']);
        $this->assertStringContainsString('deleted', $clause['sql']);
        $this->assertStringContainsString('created', $clause['sql']);
        $this->assertStringNotContainsString('slug NOT IN', $clause['sql']);
    }

    public function testPublicPostsClauseExcludesMenuItemSlugs()
    {
        global $config;
        $config = ['menu_items' => ['About' => 'about', 'Contact' => '/contact']];

        $clause = public_posts_clause();

        $this->assertStringContainsString('slug NOT IN', $clause['sql']);
        // Params must line up with the placeholders: the visibility clause's
        // "created > ?" comes first, then the excluded slugs in order.
        $this->assertSame(substr_count($clause['sql'], '?'), count($clause['params']));
        $this->assertSame(['about', 'contact'], array_slice($clause['params'], 1));
    }

    // pagination_window

    public function testPaginationWindowComputesOffsetForRequestedPage()
    {
        $window = pagination_window(25, 3, 10);
        $this->assertSame(3, $window['page']);
        $this->assertSame(20, $window['offset']);
        $this->assertSame(3, $window['total_pages']);
    }

    public function testPaginationWindowClampsPageBeyondTheLastOne()
    {
        $window = pagination_window(25, 99, 10);
        $this->assertSame(3, $window['page']);
        $this->assertSame(20, $window['offset']);
    }

    public function testPaginationWindowIsSinglePageWhenThereAreNoPosts()
    {
        $window = pagination_window(0, 4, 10);
        $this->assertSame(1, $window['page']);
        $this->assertSame(0, $window['offset']);
        $this->assertSame(1, $window['total_pages']);
    }

    public function testPaginationWindowTreatsNonPositivePerPageAsOne()
    {
        // posts_per_page = 0 in the INI config would otherwise divide by zero.
        $window = pagination_window(3, 2, 0);
        $this->assertSame(2, $window['page']);
        $this->assertSame(1, $window['offset']);
        $this->assertSame(3, $window['total_pages']);
    }

    // build_pagination_meta

    public function testBuildPaginationMetaReturnsCorrectStructure()
    {
        $meta = build_pagination_meta(1, 10, 25, 0);
        $this->assertArrayHasKey('current', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('total_posts', $meta);
        $this->assertArrayHasKey('total_pages', $meta);
        $this->assertArrayHasKey('prev_page', $meta);
        $this->assertArrayHasKey('next_page', $meta);
        $this->assertArrayHasKey('offset', $meta);
    }

    public function testBuildPaginationMetaTotalPagesCalculatedCorrectly()
    {
        $meta = build_pagination_meta(1, 10, 25, 0);
        $this->assertSame(3, $meta['total_pages']);
    }

    public function testBuildPaginationMetaTotalPagesIsOneWhenNoPosts()
    {
        $meta = build_pagination_meta(1, 10, 0, 0);
        $this->assertSame(1, $meta['total_pages']);
    }

    public function testBuildPaginationMetaPrevPageNullOnFirstPage()
    {
        $meta = build_pagination_meta(1, 10, 25, 0);
        $this->assertNull($meta['prev_page']);
    }

    public function testBuildPaginationMetaNextPageNullOnLastPage()
    {
        $meta = build_pagination_meta(3, 10, 25, 20);
        $this->assertNull($meta['next_page']);
    }

    public function testBuildPaginationMetaHasPrevAndNextOnMiddlePage()
    {
        $meta = build_pagination_meta(2, 10, 25, 10);
        $this->assertSame(1, $meta['prev_page']);
        $this->assertSame(3, $meta['next_page']);
    }

    public function testBuildPaginationMetaPassthroughValues()
    {
        $meta = build_pagination_meta(2, 10, 25, 10);
        $this->assertSame(2, $meta['current']);
        $this->assertSame(10, $meta['per_page']);
        $this->assertSame(25, $meta['total_posts']);
        $this->assertSame(10, $meta['offset']);
    }

    // paginate_posts: explicit $per_page avoids global $config

    public function testPaginatePostsArraySourceWithExplicitPerPage()
    {
        $items = range(1, 15);
        $result = paginate_posts($items, 'created DESC', null, [], 5);
        $this->assertCount(5, $result['items']);
        $this->assertSame(3, $result['pagination']['total_pages']);
    }

    public function testPaginatePostsArraySourceReturnsFirstPageItems()
    {
        $items = array_map(fn($i) => "post$i", range(1, 12));
        $result = paginate_posts($items, 'created DESC', null, [], 5);
        $this->assertSame(['post1', 'post2', 'post3', 'post4', 'post5'], $result['items']);
    }

    public function testPaginatePostsArraySourceSinglePageWhenFewerItemsThanPerPage()
    {
        $items = range(1, 3);
        $result = paginate_posts($items, 'created DESC', null, [], 10);
        $this->assertCount(3, $result['items']);
        $this->assertSame(1, $result['pagination']['total_pages']);
        $this->assertNull($result['pagination']['next_page']);
    }

    public function testPaginatePostsEmptyArrayReturnsEmptyItemsOnePage()
    {
        $result = paginate_posts([], 'created DESC', null, [], 10);
        $this->assertSame([], $result['items']);
        $this->assertSame(1, $result['pagination']['total_pages']);
        $this->assertSame(0, $result['pagination']['total_posts']);
    }

    public function testPaginatePostsExplicitPageSelectsCorrectSlice()
    {
        $items = array_map(fn($i) => "post$i", range(1, 15));
        $result = paginate_posts($items, 'created DESC', null, [], 5, 2);
        $this->assertSame(['post6', 'post7', 'post8', 'post9', 'post10'], $result['items']);
    }

    public function testPaginatePostsExplicitPageReflectedInPaginationMeta()
    {
        $items = range(1, 15);
        $result = paginate_posts($items, 'created DESC', null, [], 5, 3);
        $this->assertSame(3, $result['pagination']['current']);
        $this->assertNull($result['pagination']['next_page']);
    }

    // get_feed_updated_date

    public function testGetFeedUpdatedDateReturnsCurrentDateWhenPostsEmpty()
    {
        $before = time();
        $result = get_feed_updated_date([]);
        $after = time();

        $ts = strtotime($result);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testGetFeedUpdatedDateReturnsUpdatedFromFirstPost()
    {
        $bean = new \stdClass();
        $bean->updated = '2024-06-15 12:00:00';

        $result = get_feed_updated_date([$bean]);

        $this->assertSame('2024-06-15 12:00:00', $result);
    }

    public function testGetFeedUpdatedDateUsesFirstPostNotLast()
    {
        $first = new \stdClass();
        $first->updated = '2024-06-15 12:00:00';
        $second = new \stdClass();
        $second->updated = '2024-01-01 00:00:00';

        $result = get_feed_updated_date([$first, $second]);

        $this->assertSame('2024-06-15 12:00:00', $result);
    }

    // upgrade_posts

    public function testUpgradePostsHandlesNullWithoutError(): void
    {
        // R::findOne returns null when no post is found; upgrade_posts must not crash
        $this->expectNotToPerformAssertions();
        upgrade_posts([null]);
    }
}
