<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;

use function Lamb\parse_bean;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\persist_slug;
use function Lamb\Post\populate_bean;

class SlugFinalizeTest extends TestCase
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

    private function makeFeedItem(string $id): SimplePieItem
    {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn($id);
        return $item;
    }

    // -------------------------------------------------------------------
    // persist_slug — pinning the actual slug into front matter
    // -------------------------------------------------------------------

    public function testPersistSlugUpdatesExistingSlugLine(): void
    {
        $body = "---\nslug: old-slug\n---\nContent.";
        $this->assertSame("---\nslug: new-slug\n---\nContent.", persist_slug($body, 'new-slug'));
    }

    public function testPersistSlugInsertsSlugLineWhenOnlyTitlePresent(): void
    {
        $body = "---\ntitle: Hello World\n---\nContent.";
        $this->assertSame(
            "---\ntitle: Hello World\nslug: myfeed-hello-world\n---\nContent.",
            persist_slug($body, 'myfeed-hello-world')
        );
    }

    public function testPersistSlugLeavesBodyWithoutFrontMatterUnchanged(): void
    {
        $body = "Just a status update.";
        $this->assertSame($body, persist_slug($body, 'anything'));
    }

    public function testPersistSlugNoChurnWhenAlreadyEqual(): void
    {
        $body = "---\ntitle: Hello\nslug: hello\n---\nContent.";
        $this->assertSame($body, persist_slug($body, 'hello'));
    }

    // -------------------------------------------------------------------
    // finalize_slug — uniqueness + front-matter sync after store
    // -------------------------------------------------------------------

    public function testSecondPostWithSameExplicitSlugIsDeduplicated(): void
    {
        $text = "---\nslug: shared-slug\n---\nContent.";
        $first = populate_bean($text);
        R::store($first);
        finalize_slug($first);
        R::store($first);

        $second = populate_bean($text);
        R::store($second);
        finalize_slug($second);
        R::store($second);

        $this->assertSame('shared-slug', $first->slug);
        $this->assertSame('shared-slug-' . $second->id, $second->slug);
        $this->assertStringContainsString('slug: shared-slug-' . $second->id, $second->body);
    }

    public function testFinalizeIsIdempotentForTheSlugOwner(): void
    {
        $text = "---\nslug: my-page\n---\nContent.";
        $bean = populate_bean($text);
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);

        $this->assertFalse(finalize_slug($bean));
        $this->assertSame('my-page', $bean->slug);
    }

    public function testFinalizeSuffixesReservedRouteSlug(): void
    {
        // Routes are registered by index.php at runtime; register one here so
        // is_reserved_route() sees it in the unit context.
        \Lamb\Route\register_route('search', 'strlen');

        $text = "---\nslug: search\n---\nContent.";
        $bean = populate_bean($text);
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);

        $this->assertSame('search-' . $bean->id, $bean->slug);
        $this->assertStringContainsString('slug: search-' . $bean->id, $bean->body);
    }

    public function testFinalizeLeavesDerivedSlugBodyUntouched(): void
    {
        // Title-derived slug already matches the actual slug: front matter is in
        // sync without an explicit slug line, so the body must not be rewritten.
        $text = "---\ntitle: Hello World\n---\nContent.";
        $bean = populate_bean($text);
        R::store($bean);

        $this->assertFalse(finalize_slug($bean));
        $this->assertSame($text, $bean->body);
    }

    public function testFinalizeIgnoresSluglessPosts(): void
    {
        $bean = populate_bean('Just a status update.');
        R::store($bean);

        $this->assertFalse(finalize_slug($bean));
        $this->assertSame('', $bean->slug);
    }

    // -------------------------------------------------------------------
    // Feed items — same-feed title collisions and explicit-slug stability
    // -------------------------------------------------------------------

    public function testSameTitledItemsFromOneFeedGetDistinctSlugs(): void
    {
        $text = "---\ntitle: Hello World\n---\nContent.";

        $first = populate_bean($text, $this->makeFeedItem('item-1'), 'myfeed');
        R::store($first);
        finalize_slug($first);
        R::store($first);

        $second = populate_bean($text, $this->makeFeedItem('item-2'), 'myfeed');
        R::store($second);
        finalize_slug($second);
        R::store($second);

        $this->assertSame('myfeed-hello-world', $first->slug);
        $this->assertSame('myfeed-hello-world-' . $second->id, $second->slug);
    }

    public function testFinalizedFeedItemSlugSurvivesReparse(): void
    {
        // After finalize the body carries the explicit prefixed slug, so a later
        // populate_bean (cron update) must not prefix it again.
        $text = "---\ntitle: Hello World\n---\nContent.";
        $item = $this->makeFeedItem('item-reparse');
        $bean = populate_bean($text, $item, 'myfeed');
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);

        $this->assertStringContainsString('slug: myfeed-hello-world', $bean->body);

        $bean = populate_bean($bean->body, $item, 'myfeed', $bean);

        $this->assertSame('myfeed-hello-world', $bean->slug);
    }

    // -------------------------------------------------------------------
    // Slug immutability after publish — edits to slugs are ignored and the
    // front-matter slug is reversed to the slug the post is served under.
    // -------------------------------------------------------------------

    private function makePublishedPost(string $body): \RedBeanPHP\OODBBean
    {
        $bean = populate_bean($body);
        $bean->draft = 0;
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);
        return $bean;
    }

    public function testPublishedPostKeepsSlugWhenFrontMatterSlugEdited(): void
    {
        $bean = $this->makePublishedPost("---\ntitle: My Page\n---\nContent.");

        $bean->body = "---\ntitle: My Page\nslug: something-else\n---\nContent.";
        parse_bean($bean);

        $this->assertSame('my-page', $bean->slug);
        $this->assertStringContainsString('slug: my-page', $bean->body);
        $this->assertStringNotContainsString('something-else', $bean->body);
    }

    public function testPublishedPostKeepsSlugWhenTitleEdited(): void
    {
        $bean = $this->makePublishedPost("---\ntitle: My Page\n---\nContent.");

        $bean->body = "---\ntitle: A Better Title\n---\nContent.";
        parse_bean($bean);

        $this->assertSame('my-page', $bean->slug);
        $this->assertStringContainsString('slug: my-page', $bean->body);
    }

    public function testDraftPostSlugCanStillBeEdited(): void
    {
        $bean = populate_bean("---\ntitle: My Draft\ndraft: true\n---\nContent.");
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);

        $bean->body = "---\ntitle: My Draft\ndraft: true\nslug: better-slug\n---\nContent.";
        parse_bean($bean);

        $this->assertSame('better-slug', $bean->slug);
    }

    public function testPublishedSluglessPostCanGainSlug(): void
    {
        $bean = populate_bean('Just a status update.');
        $bean->draft = 0;
        R::store($bean);

        $bean->body = "---\ntitle: Now A Page\n---\nContent.";
        parse_bean($bean);

        $this->assertSame('now-a-page', $bean->slug);
    }

    public function testPublishedFeedItemKeepsSlugWhenUpstreamTitleChanges(): void
    {
        $item = $this->makeFeedItem('item-locked');
        $bean = populate_bean("---\ntitle: Hello World\n---\nContent.", $item, 'myfeed');
        $bean->draft = 0;
        R::store($bean);
        finalize_slug($bean);
        R::store($bean);
        $this->assertSame('myfeed-hello-world', $bean->slug);

        // Upstream rewrites the item title; cron update regenerates the body.
        $bean = populate_bean("---\ntitle: Renamed Post\n---\nContent.", $item, 'myfeed', $bean);

        $this->assertSame('myfeed-hello-world', $bean->slug);
    }
}
