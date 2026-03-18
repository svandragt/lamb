<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\asset_loader;
use function Lamb\Theme\get_posts_by_tags;
use function Lamb\Theme\link_source;
use function Lamb\Theme\related_posts;

class ThemeExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec("DELETE FROM post");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config['feeds'] = [
            'ExampleBlog' => 'https://example.com/feed',
            'AnotherFeed' => 'https://another.com/rss',
        ];

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // link_source

    public function testLinkSourceReturnsEmptyWhenNoFeedName(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $result = link_source($bean);
        $this->assertSame('', $result);
    }

    public function testLinkSourceReturnsViaLinkWhenFeedNameSet(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('Via', $result);
        $this->assertStringContainsString('<a href=', $result);
    }

    public function testLinkSourceIncludesFeedUrl(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('https://example.com/feed', $result);
    }

    public function testLinkSourceIncludesFeedName(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('ExampleBlog', $result);
    }

    public function testLinkSourceWorksForDifferentFeeds(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'AnotherFeed';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('https://another.com/rss', $result);
        $this->assertStringContainsString('AnotherFeed', $result);
    }

    // asset_loader

    public function testAssetLoaderYieldsPublicAsset(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'themes/default/styles'));

        $this->assertCount(1, $results);
        $href = array_values($results)[0];
        $this->assertStringContainsString('styles.css', $href);
    }

    public function testAssetLoaderKeyIsMd5OfHref(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'themes/default/styles'));

        foreach ($results as $key => $href) {
            $this->assertSame(md5($href), $key);
        }
    }

    public function testAssetLoaderSkipsAdminScriptWhenNotLoggedIn(): void
    {
        unset($_SESSION[SESSION_LOGIN]);
        $assets = [SESSION_LOGIN => ['admin.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(0, $results);
    }

    public function testAssetLoaderIncludesAdminScriptWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $assets = [SESSION_LOGIN => ['admin.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(1, $results);
        $href = array_values($results)[0];
        $this->assertStringContainsString('admin.js', $href);
    }

    public function testAssetLoaderYieldsMultiplePublicAssets(): void
    {
        $assets = ['' => ['app.js', 'extra.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(2, $results);
    }

    public function testAssetLoaderHrefStartsWithRootUrl(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'styles'));

        $href = array_values($results)[0];
        $this->assertStringStartsWith(ROOT_URL, $href);
    }

    // get_posts_by_tags

    public function testGetPostsByTagsReturnsEmptyArrayWhenNoMatchingPosts(): void
    {
        $result = get_posts_by_tags(['nonexistenttag']);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsReturnsEmptyArrayForEmptyTagList(): void
    {
        $result = get_posts_by_tags([]);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsFindsPostWithMatchingTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #mytag end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['mytag']);
        $this->assertCount(1, $result);
    }

    public function testGetPostsByTagsDoesNotReturnPostWithDifferentTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #othertag end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['mytag']);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsReturnsUniquePostsWhenMatchedByMultipleTags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #alpha and #beta end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['alpha', 'beta']);
        // The same post should only appear once despite matching two tags
        $this->assertCount(1, $result);
    }

    // related_posts

    public function testRelatedPostsReturnsEmptyArrayWhenBodyHasNoTags(): void
    {
        $result = related_posts('<p>No hashtags here.</p>');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testRelatedPostsFindsPostsMatchingTagsInBody(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Related post about #lamb end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        // related_posts extracts tags from the body HTML and finds matching posts
        $result = related_posts('<p>Some post about #lamb</p>');
        $this->assertCount(1, $result);
    }

    public function testRelatedPostsReturnsArrayType(): void
    {
        $result = related_posts('<p>Hello #world</p>');
        $this->assertIsArray($result);
    }
}
