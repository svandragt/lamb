<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\populate_bean;

class PostFinalizeStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = $config ?? [];
    }

    public function testStoresBeanAndAssignsId(): void
    {
        $bean = populate_bean('Just a status update.');
        finalize_and_store_post($bean);

        $this->assertNotEmpty($bean->id, 'The bean must be persisted with an id');
    }

    public function testDeduplicatesAndPersistsCollidingSlug(): void
    {
        $text = "---\nslug: shared-slug\n---\nContent.";

        $first = populate_bean($text);
        finalize_and_store_post($first);

        $second = populate_bean($text);
        finalize_and_store_post($second);

        $this->assertSame('shared-slug', $first->slug);
        $this->assertSame('shared-slug-' . $second->id, $second->slug);
        $this->assertStringContainsString('slug: shared-slug-' . $second->id, $second->body);

        // The re-store must have persisted the pinned slug, not just changed it in memory.
        $reloaded = R::load('post', (int) $second->id);
        $this->assertSame('shared-slug-' . $second->id, $reloaded->slug);
    }
}
