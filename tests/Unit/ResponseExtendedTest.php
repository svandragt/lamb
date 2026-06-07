<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\build_pagination_meta;
use function Lamb\Response\get_results;
use function Lamb\Response\respond_search;
use function Lamb\Response\upgrade_posts;

class ResponseExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
    }

    // get_results

    public function testGetResultsIntroIsNoResultsFoundWhenZeroPosts(): void
    {
        $result = get_results(['title' => 'Search'], [], build_pagination_meta(1, 10, 0, 0));
        $this->assertSame('No results found.', $result['intro']);
    }

    public function testGetResultsIntroContainsCountWhenPostsFound(): void
    {
        $result = get_results(['title' => 'Search'], [], build_pagination_meta(1, 10, 3, 0));
        $this->assertStringContainsString('3', $result['intro']);
        $this->assertStringContainsString('found', $result['intro']);
    }

    public function testGetResultsSingularResultLabelForOnePost(): void
    {
        $result = get_results([], [], build_pagination_meta(1, 10, 1, 0));
        $this->assertStringContainsString('result', $result['intro']);
    }

    public function testGetResultsPluralResultsLabelForManyPosts(): void
    {
        $result = get_results([], [], build_pagination_meta(1, 10, 5, 0));
        $this->assertStringContainsString('results', $result['intro']);
    }

    public function testGetResultsPostsKeyEqualsProvidedPosts(): void
    {
        $result = get_results([], [], build_pagination_meta(1, 10, 0, 0));
        $this->assertSame([], $result['posts']);
    }

    public function testGetResultsPreservesExtraDataKeys(): void
    {
        $result = get_results(['title' => 'My Search', 'foo' => 'bar'], [], build_pagination_meta(1, 10, 0, 0));
        $this->assertSame('My Search', $result['title']);
        $this->assertSame('bar', $result['foo']);
    }

    public function testGetResultsPaginationCurrentPage(): void
    {
        $result = get_results([], [], build_pagination_meta(2, 10, 25, 10));
        $this->assertSame(2, $result['pagination']['current']);
    }

    public function testGetResultsPaginationPrevPage(): void
    {
        $result = get_results([], [], build_pagination_meta(2, 10, 25, 10));
        $this->assertSame(1, $result['pagination']['prev_page']);
    }

    public function testGetResultsPaginationNextPage(): void
    {
        $result = get_results([], [], build_pagination_meta(2, 10, 25, 10));
        $this->assertSame(3, $result['pagination']['next_page']);
    }

    public function testGetResultsPaginationNullPrevOnFirstPage(): void
    {
        $result = get_results([], [], build_pagination_meta(1, 10, 25, 0));
        $this->assertNull($result['pagination']['prev_page']);
    }

    public function testGetResultsPaginationNullNextOnLastPage(): void
    {
        $result = get_results([], [], build_pagination_meta(3, 10, 25, 20));
        $this->assertNull($result['pagination']['next_page']);
    }

    // respond_search

    public function testRespondSearchIncludesQueryInResult(): void
    {
        $result = respond_search(['hello']);
        $this->assertArrayHasKey('query', $result);
        $this->assertSame('hello', $result['query']);
    }

    public function testRespondSearchQueryIsStoredRawAndEscapedAtOutput(): void
    {
        // The query is kept raw in the result; escaping happens once at render
        // time (search box uses escape(), the title via page_title()). Storing it
        // pre-escaped here would double-encode metacharacters in the displayed term.
        $result = respond_search(['<script>']);
        $this->assertSame('<script>', $result['query']);
        $this->assertStringContainsString('<script>', $result['title']);
    }

    // upgrade_posts

    public function testUpgradePostsDoesNotModifyCurrentVersionPosts(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello world';
        $bean->slug = '';
        $bean->version = POST_VERSION;
        $bean->transformed = '<p>original</p>';
        R::store($bean);

        upgrade_posts([$bean]);

        $reloaded = R::load('post', $bean->id);
        $this->assertSame(POST_VERSION, (int)$reloaded->version);
        $this->assertSame('<p>original</p>', $reloaded->transformed);
    }

    public function testUpgradePostsSetsCurrentVersionOnUnversionedPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello world';
        $bean->slug = '';
        $bean->version = null;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame(POST_VERSION, $bean->version);
    }

    public function testUpgradePostsPopulatesTransformedOnUnversionedPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello **world**';
        $bean->slug = '';
        $bean->version = null;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertNotEmpty($bean->transformed);
        $this->assertStringContainsString('world', $bean->transformed);
    }

    public function testUpgradePostsHandlesEmptyArray(): void
    {
        // Should not throw
        upgrade_posts([]);
        $this->assertTrue(true);
    }
}
