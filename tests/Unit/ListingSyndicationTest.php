<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;

use function Lamb\Network\prepare_item;
use function Lamb\Post\populate_bean;
use function Lamb\Response\feed_item_content_html;

/**
 * What a listing carries across a feed, in both directions.
 *
 * Outbound: a subscriber must get the price from the feed itself, not only by
 * fetching every permalink and parsing its JSON-LD. Inbound: an ingested item
 * is a quoted citation of someone else's post, so it must not come back as a
 * listing of our own.
 */
class ListingSyndicationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        global $config;
        $config = ['site_title' => 'Test Blog', 'author_name' => 'Sander'];
    }

    private function listing(): \RedBeanPHP\OODBBean
    {
        return populate_bean(
            "---\ntitle: Blue Wool Jumper\npost-type: listing\n"
            . "price: '25.00'\ncurrency: EUR\ncondition: used\ncontact: me@example.com\n"
            . "---\n\nWarm, barely worn.\n"
        );
    }

    // --- outbound ---

    public function testAListingsFeedItemCarriesThePrice(): void
    {
        // Without this the entry for a 25 EUR jumper is "<p>Warm, barely
        // worn.</p>" — a subscriber to the listings feed gets prose and a title
        // and would have to fetch each permalink to learn anything is for sale.
        $html = feed_item_content_html($this->listing());

        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringContainsString('p-price', $html);
        $this->assertStringContainsString('used', $html);
    }

    public function testAnOrdinaryPostsFeedItemIsUnchanged(): void
    {
        $bean = populate_bean("---\ntitle: A page\n---\n\nJust prose.\n");

        $this->assertSame('<p>Just prose.</p>', trim(feed_item_content_html($bean)));
    }

    public function testTheJsonFeedCarriesTheListingFieldsAsAnExtension(): void
    {
        // JSON Feed reserves `_`-prefixed keys for extensions (the JSON feed
        // already ships `_microblog`). A hub reading this gets the terms without
        // having to parse them back out of the content HTML.
        $bean = $this->listing();
        $bean->id = 3;
        R::store($bean);

        global $config;
        $json = $this->renderJsonFeed([$bean], $config);

        $item = $json['items'][0];
        $this->assertSame(
            ['price' => '25.00', 'currency' => 'EUR', 'condition' => 'used', 'contact' => 'me@example.com'],
            $item['_listing']
        );
    }

    public function testAnOrdinaryPostGetsNoListingExtension(): void
    {
        $bean = populate_bean("---\ntitle: A page\n---\n\nJust prose.\n");
        R::store($bean);

        global $config;
        $json = $this->renderJsonFeed([$bean], $config);

        $this->assertArrayNotHasKey('_listing', $json['items'][0]);
    }

    /**
     * @param array<int, \RedBeanPHP\OODBBean> $posts
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function renderJsonFeed(array $posts, array $config): array
    {
        $data = [
            'posts'    => $posts,
            'title'    => 'Test Blog',
            'feed_url' => ROOT_URL . '/listings/feed.json',
            'updated'  => '2026-01-01 00:00:00',
        ];

        ob_start();
        \Lamb\Response\render_json_feed($data, $config);

        return json_decode((string) ob_get_clean(), true);
    }

    // --- inbound ---

    public function testAnIngestedListingDoesNotBecomeOneOfOurs(): void
    {
        // Ingestion stores a five-line quoted excerpt plus a link back — a
        // citation of someone else's post, not a copy of it. Carrying the post
        // type across would make this site's /listings page and its JSON-LD
        // offer goods it does not have, with its own author named as the
        // seller. The hub is a reader here, not a shop.
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_title')->willReturn('Blue Wool Jumper');
        $item->method('get_description')->willReturn(
            '<p>Warm, barely worn.</p><dl class="listing-details">'
            . '<dt>Price</dt><dd class="p-price">EUR 25.00</dd></dl>'
        );
        $item->method('get_permalink')->willReturn('https://seller.example.com/blue-wool-jumper');
        $item->method('get_id')->willReturn('jumper-1');
        $item->method('get_date')->willReturnCallback(
            fn(string $format = 'U') => $format === 'U' ? (string) time() : date($format)
        );
        $item->method('get_updated_date')->willReturnCallback(
            fn(string $format = 'U') => $format === 'U' ? (string) time() : date($format)
        );

        $bean = prepare_item($item, 'A Seller', R::dispense('post'));

        $this->assertSame('', $bean->post_type);
        $this->assertFalse(\Lamb\Listing\is_listing($bean));
    }
}
