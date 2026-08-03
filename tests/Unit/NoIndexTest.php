<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\is_noindex;
use function Lamb\Response\mark_noindex;
use function Lamb\Response\should_noindex;
use function Lamb\Route\is_private_route;
use function Lamb\Route\register_app_routes;
use function Lamb\Theme\the_robots;

/**
 * Pages that are not meant for the public — the admin routes and the
 * ?preview=<token> link that opens an unpublished post without a login — must
 * tell crawlers not to index them. robots.txt is only a hint a polite crawler
 * reads first; the per-response meta/header is what covers a preview link that
 * was pasted somewhere a crawler already follows.
 */
class NoIndexTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }
        $GLOBALS['routes'] = [];
        $GLOBALS['private_routes'] = [];
        $GLOBALS['noindex'] = false;
        register_app_routes('home', null, null);
    }

    public function testPrivateRoutesAreRecognised(): void
    {
        foreach (['edit', 'drafts', 'trash', 'scheduled', 'settings', 'login', '_cron'] as $action) {
            $this->assertTrue(is_private_route($action), "'$action' should be private");
        }
    }

    public function testPublicRoutesAreNotPrivate(): void
    {
        foreach (['home', 'status', 'tag', 'search', 'feed', 'sitemap.xml'] as $action) {
            $this->assertFalse(is_private_route($action), "'$action' should be public");
        }
    }

    public function testPrivateRoutesAreNoindexed(): void
    {
        $this->assertTrue(should_noindex('drafts', []));
        $this->assertTrue(should_noindex('settings', []));
    }

    public function testPublicRoutesAreIndexable(): void
    {
        $this->assertFalse(should_noindex('home', []));
        $this->assertFalse(should_noindex('status', []));
    }

    public function testPreviewLinkIsNoindexed(): void
    {
        $this->assertTrue(should_noindex('status', ['preview' => 'deadbeef']));
        $this->assertTrue(should_noindex('some-page-slug', ['preview' => 'deadbeef']));
    }

    public function testEmptyPreviewParamStillCountsAsAPreviewUrl(): void
    {
        // ?preview= with no value never grants access, but it is still a
        // duplicate of the canonical permalink and must not be indexed.
        $this->assertTrue(should_noindex('status', ['preview' => '']));
    }

    public function testMarkNoindexFlagsTheResponse(): void
    {
        $this->assertFalse(is_noindex());
        mark_noindex();
        $this->assertTrue(is_noindex());
    }

    public function testMetaTagIsEmittedOnlyWhenFlagged(): void
    {
        ob_start();
        the_robots();
        $this->assertSame('', ob_get_clean());

        mark_noindex();
        ob_start();
        the_robots();
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            (string) ob_get_clean()
        );
    }
}
