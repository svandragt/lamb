<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Webmention\discover_endpoint;
use function Lamb\Webmention\extract_meta;
use function Lamb\Webmention\is_external_http_url;
use function Lamb\Webmention\source_mentions_target;
use function Lamb\Webmention\target_post_id;
use function Lamb\Webmention\verify_and_store;
use function Lamb\Webmention\webmentions_for_post;

class WebmentionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        R::exec('DELETE FROM post WHERE 1');
        R::exec('DELETE FROM webmention WHERE 1');
    }

    private function makePost(?string $slug = null): int
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello world';
        $bean->transformed = '<p>Hello world</p>';
        $bean->created = '2026-01-01 00:00:00';
        $bean->updated = '2026-01-01 00:00:00';
        $bean->version = 1;
        if ($slug !== null) {
            $bean->slug = $slug;
        }
        return (int) R::store($bean);
    }

    // target_post_id --------------------------------------------------------

    public function testTargetPostIdResolvesStatusUrl(): void
    {
        $id = $this->makePost();
        $this->assertSame($id, target_post_id(ROOT_URL . '/status/' . $id));
    }

    public function testTargetPostIdResolvesSlugUrl(): void
    {
        $id = $this->makePost('my-page');
        $this->assertSame($id, target_post_id(ROOT_URL . '/my-page'));
    }

    public function testTargetPostIdRejectsForeignHost(): void
    {
        $id = $this->makePost();
        $this->assertNull(target_post_id('https://evil.example/status/' . $id));
    }

    public function testTargetPostIdRejectsUnknownPost(): void
    {
        $this->assertNull(target_post_id(ROOT_URL . '/status/999999'));
    }

    // source_mentions_target ------------------------------------------------

    public function testSourceMentionsTargetMatchesHref(): void
    {
        $target = ROOT_URL . '/status/1';
        $html = '<p>Great post <a href="' . $target . '">here</a></p>';
        $this->assertTrue(source_mentions_target($html, $target));
    }

    public function testSourceMentionsTargetFalseWhenAbsent(): void
    {
        $html = '<p>Nothing relevant here</p>';
        $this->assertFalse(source_mentions_target($html, ROOT_URL . '/status/1'));
    }

    // verify_and_store ------------------------------------------------------

    public function testVerifyRejectsMissingParams(): void
    {
        $this->assertSame(400, verify_and_store('', '', fn () => '')['status']);
    }

    public function testVerifyRejectsForeignTarget(): void
    {
        $res = verify_and_store('https://other.example/a', 'https://evil.example/status/1', fn () => '');
        $this->assertSame(400, $res['status']);
    }

    public function testVerifyRejectsSourceEqualToTarget(): void
    {
        $id = $this->makePost();
        $url = ROOT_URL . '/status/' . $id;
        $this->assertSame(400, verify_and_store($url, $url, fn () => '')['status']);
    }

    public function testVerifyRejectsWhenSourceDoesNotLinkTarget(): void
    {
        $id = $this->makePost();
        $target = ROOT_URL . '/status/' . $id;
        $res = verify_and_store('https://other.example/a', $target, fn () => '<p>unrelated</p>');
        $this->assertSame(400, $res['status']);
        $this->assertSame(0, R::count('webmention'));
    }

    public function testVerifyStoresVerifiedMention(): void
    {
        $id = $this->makePost();
        $target = ROOT_URL . '/status/' . $id;
        $source = 'https://other.example/reply';
        $html = '<a href="' . $target . '">re</a>';

        $res = verify_and_store($source, $target, fn () => $html);
        $this->assertContains($res['status'], [201, 202]);
        $this->assertSame(1, R::count('webmention'));

        $wm = R::findOne('webmention');
        $this->assertSame($source, $wm->source);
        $this->assertSame($target, $wm->target);
        $this->assertSame($id, (int) $wm->post_id);
        $this->assertNotEmpty($wm->verified_at);
    }

    public function testVerifyDeduplicatesOnRepeat(): void
    {
        $id = $this->makePost();
        $target = ROOT_URL . '/status/' . $id;
        $source = 'https://other.example/reply';
        $html = '<a href="' . $target . '">re</a>';

        verify_and_store($source, $target, fn () => $html);
        verify_and_store($source, $target, fn () => $html);
        $this->assertSame(1, R::count('webmention'));
    }

    public function testVerifyDeletesStaleMentionWhenLinkRemoved(): void
    {
        $id = $this->makePost();
        $target = ROOT_URL . '/status/' . $id;
        $source = 'https://other.example/reply';

        verify_and_store($source, $target, fn () => '<a href="' . $target . '">re</a>');
        $this->assertSame(1, R::count('webmention'));

        // Source page no longer links the target → mention is removed.
        $res = verify_and_store($source, $target, fn () => '<p>removed</p>');
        $this->assertSame(200, $res['status']);
        $this->assertSame(0, R::count('webmention'));
    }

    // webmentions_for_post --------------------------------------------------

    public function testWebmentionsForPostReturnsStored(): void
    {
        $id = $this->makePost();
        $target = ROOT_URL . '/status/' . $id;
        verify_and_store('https://other.example/reply', $target, fn () => '<a href="' . $target . '">re</a>');

        $mentions = webmentions_for_post($id);
        $this->assertCount(1, $mentions);
        $this->assertSame('https://other.example/reply', $mentions[0]->source);
    }

    // is_external_http_url --------------------------------------------------

    public function testIsExternalHttpUrlTrueForForeignHost(): void
    {
        // Some other unit test may already have defined ROOT_URL; build the
        // foreign URL from a host that cannot match it.
        $this->assertTrue(is_external_http_url('https://surely-not-our-host.example/post'));
    }

    public function testIsExternalHttpUrlFalseForOwnHost(): void
    {
        $own_host = (string) parse_url(ROOT_URL, PHP_URL_HOST);
        $this->assertFalse(is_external_http_url('https://' . $own_host . '/status/1'));
        // Host comparison is case-insensitive.
        $this->assertFalse(is_external_http_url('https://' . strtoupper($own_host) . '/status/1'));
    }

    public function testIsExternalHttpUrlFalseForInvalidUrl(): void
    {
        $this->assertFalse(is_external_http_url('mailto:a@example.com'));
        $this->assertFalse(is_external_http_url('/relative'));
    }

    // extract_meta ----------------------------------------------------------

    public function testExtractMetaReadsAuthorMetaTag(): void
    {
        $html = '<html><head><meta name="author" content="Jane Doe"><title>Hi</title></head></html>';
        $meta = extract_meta($html);
        $this->assertSame('Jane Doe', $meta['author']);
        $this->assertSame('Hi', $meta['content']);
    }

    public function testExtractMetaFallsBackToRelAuthor(): void
    {
        $html = '<a rel="author" href="/me">Jane</a><title>Title Here</title>';
        $meta = extract_meta($html);
        $this->assertSame('Jane', $meta['author']);
        $this->assertSame('Title Here', $meta['content']);
    }

    public function testExtractMetaReturnsNullsWhenAbsent(): void
    {
        $meta = extract_meta('<p>no metadata here</p>');
        $this->assertNull($meta['author']);
        $this->assertNull($meta['content']);
    }

    public function testExtractMetaDecodesTitleEntities(): void
    {
        $meta = extract_meta('<title>Tom &amp; Jerry</title>');
        $this->assertSame('Tom & Jerry', $meta['content']);
    }

    // discover_endpoint -----------------------------------------------------

    public function testDiscoverEndpointPrefersLinkHeader(): void
    {
        $endpoint = discover_endpoint(
            '<link rel="webmention" href="https://example.com/from-html">',
            ['<https://example.com/from-header>; rel="webmention"'],
            'https://example.com/post'
        );
        $this->assertSame('https://example.com/from-header', $endpoint);
    }

    public function testDiscoverEndpointFromHtmlLinkTag(): void
    {
        $endpoint = discover_endpoint(
            '<link rel="webmention" href="https://example.com/wm">',
            [],
            'https://example.com/post'
        );
        $this->assertSame('https://example.com/wm', $endpoint);
    }

    public function testDiscoverEndpointFromAnchorTag(): void
    {
        $endpoint = discover_endpoint(
            '<a rel="webmention" href="/wm-endpoint">webmention</a>',
            [],
            'https://example.com/blog/post'
        );
        $this->assertSame('https://example.com/wm-endpoint', $endpoint);
    }

    public function testDiscoverEndpointResolvesRelativeHeaderAgainstTarget(): void
    {
        $endpoint = discover_endpoint(
            '',
            ['</wm>; rel="webmention"'],
            'https://example.com/blog/post'
        );
        $this->assertSame('https://example.com/wm', $endpoint);
    }

    public function testDiscoverEndpointMatchesWebmentionWithinRelTokenList(): void
    {
        $endpoint = discover_endpoint(
            '<link rel="pingback webmention" href="https://example.com/wm">',
            [],
            'https://example.com/post'
        );
        $this->assertSame('https://example.com/wm', $endpoint);
    }

    public function testDiscoverEndpointReturnsNullWhenNoneFound(): void
    {
        $endpoint = discover_endpoint(
            '<link rel="stylesheet" href="/style.css">',
            ['<https://example.com/x>; rel="canonical"'],
            'https://example.com/post'
        );
        $this->assertNull($endpoint);
    }
}
