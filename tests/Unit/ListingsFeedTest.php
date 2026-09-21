<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\populate_bean;
use function Lamb\Response\get_listings_feed_data;
use function Lamb\Response\respond_listings;
use function Lamb\Route\is_reserved_route;
use function Lamb\Route\register_app_routes;

/**
 * The /listings page and its feed: a view over the posts marked
 * `post-type: listing`, so an aggregator can subscribe to the listings alone.
 */
class ListingsFeedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog'];
    }

    /**
     * Stores a post, stamping the visibility columns the feed's WHERE clause
     * names. bootstrap_db() declares them from Bootstrap\SCHEMA in production;
     * the suite's bare in-memory connection only grows a column once something
     * writes it, and a clause naming a column that does not exist matches
     * nothing at all rather than erroring.
     */
    private function store(string $body): int
    {
        $bean = populate_bean($body);
        $bean->draft = (int) $bean->draft;
        $bean->deleted = 0;

        return (int) R::store($bean);
    }

    private function seed(): void
    {
        $this->store("---\ntitle: Blue Wool Jumper\npost-type: listing\nprice: '25'\n---\n\nFor sale.\n");
        $this->store("---\ntitle: An ordinary page\n---\n\nNot for sale.\n");
        $this->store("Just a status about selling things\n");
    }

    public function testListingsFeedHoldsOnlyListings(): void
    {
        $this->seed();

        $data = get_listings_feed_data();

        $this->assertCount(1, $data['posts']);
        $this->assertSame('Blue Wool Jumper', reset($data['posts'])->title);
    }

    public function testListingsFeedHasTheKeysTheRenderersNeed(): void
    {
        $this->seed();

        $data = get_listings_feed_data();

        foreach (['posts', 'title', 'feed_url', 'updated'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(ROOT_URL . '/listings/feed', $data['feed_url']);
    }

    public function testDraftAndTrashedListingsStayOutOfTheFeed(): void
    {
        $this->store("---\ntitle: Draft listing\npost-type: listing\ndraft: true\n---\n\nNot yet.\n");
        $id = $this->store("---\ntitle: Trashed listing\npost-type: listing\n---\n\nGone.\n");
        $bean = R::load('post', $id);
        $bean->deleted = 1;
        R::store($bean);

        $this->assertCount(0, get_listings_feed_data()['posts']);
    }

    public function testListingsPageListsListingsAndAdvertisesItsFeed(): void
    {
        $this->seed();

        $data = respond_listings();

        $this->assertCount(1, $data['posts']);
        $this->assertSame(ROOT_URL . '/listings/feed', $data['feed_url']);
        $this->assertArrayHasKey('pagination', $data);
    }

    public function testEveryThemeCanRenderTheListingsTemplate(): void
    {
        // $template is the route's action, so /listings renders parts/listings.php.
        // Without it in the base theme, part() throws and the page 500s — the
        // data function passing its own test is not enough to prove the route works.
        $this->assertFileExists(__DIR__ . '/../../src/themes/base/parts/listings.php');
    }

    public function testListingsIsAReservedRoute(): void
    {
        // Otherwise a post slugged "listings" would replace the route.
        register_app_routes('home');
        $this->assertTrue(is_reserved_route('listings'));
    }
}
