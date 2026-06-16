<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\posts_by_tag;

class PostDraftVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Production's bootstrap_db() guarantees the post table carries the
        // soft-delete and draft columns. Replicate that here so visible_clause()
        // — which filters on `deleted` — resolves against a real column instead
        // of silently matching nothing in an isolated in-memory schema.
        R::store(R::dispense('post'));
        \Lamb\Bootstrap\ensure_post_columns();
        R::exec('DELETE FROM post');
    }

    protected function tearDown(): void
    {
        R::exec('DELETE FROM post');
    }

    public function testPostsWithNullDraftAreReturnedByPostsByTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello world #php';
        $bean->draft = null;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $results = posts_by_tag('php');

        $this->assertNotEmpty($results, 'Posts with NULL draft should be visible in tag pages');
    }

    public function testPostsWithDraftZeroAreReturnedByPostsByTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello world #php';
        $bean->draft = 0;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $results = posts_by_tag('php');

        $this->assertNotEmpty($results, 'Posts with draft=0 should be visible in tag pages');
    }

    public function testDraftPostsAreNotReturnedByPostsByTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Draft post #php';
        $bean->draft = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $results = posts_by_tag('php');

        $this->assertEmpty($results, 'Posts with draft=1 should NOT be visible in tag pages');
    }
}
