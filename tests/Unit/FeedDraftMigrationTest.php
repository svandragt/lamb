<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Network\migrate_feed_drafts;
use function Lamb\Post\parse_matter;

/**
 * Covers the one-time migration behind `bin/lamb migrate` that adds `draft: true`
 * to the front matter of feed drafts stored before ingestion wrote it (#924).
 * tests/Unit/BinLambTest.php covers the command wiring.
 */
class FeedDraftMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec('DELETE FROM post');
    }

    private function seed(string $body, int $draft = 1, ?string $feed_name = 'TestBlog'): int
    {
        $post = R::dispense('post');
        $post->body = $body;
        $post->draft = $draft;
        $post->feed_name = $feed_name;

        return (int) R::store($post);
    }

    private function body(int $id): string
    {
        return (string) R::load('post', $id)->body;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacyFeedDraftBodies(): array
    {
        return [
            'title only' => ["---\ntitle: Hello feed\n---\n\nFeed content"],
            'no front matter' => ['Feed content'],
            'draft: false' => ["---\ndraft: false\n---\n\nFeed content"],
        ];
    }

    /**
     * @dataProvider legacyFeedDraftBodies
     */
    public function testAddsDraftTrueToAFeedDraftWhoseFrontMatterLacksIt(string $body): void
    {
        $id = $this->seed($body);

        $this->assertSame(1, migrate_feed_drafts());

        $matter = parse_matter($this->body($id));
        $this->assertTrue($matter['draft'] ?? null);
        $this->assertStringContainsString('Feed content', $this->body($id));
    }

    public function testKeepsTheTitleOfTheFeedDraft(): void
    {
        $id = $this->seed("---\ntitle: Hello feed\n---\n\nFeed content");

        migrate_feed_drafts();

        $this->assertSame('Hello feed', parse_matter($this->body($id))['title']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $id = $this->seed("---\ntitle: Hello feed\n---\n\nFeed content");
        migrate_feed_drafts();
        $migrated = $this->body($id);

        $this->assertSame(0, migrate_feed_drafts());
        $this->assertSame($migrated, $this->body($id));
        $this->assertSame(1, substr_count($migrated, 'draft:'));
    }

    public function testDryRunCountsTheDraftsButWritesNothing(): void
    {
        $body = "---\ntitle: Hello feed\n---\n\nFeed content";
        $id = $this->seed($body);

        $this->assertSame(1, migrate_feed_drafts(true));

        $this->assertSame($body, $this->body($id));
    }

    public function testLeavesAFeedPostTheOwnerPublishedAlone(): void
    {
        $body = "---\ntitle: Hello feed\n---\n\nFeed content";
        $id = $this->seed($body, draft: 0);

        $this->assertSame(0, migrate_feed_drafts());

        $this->assertSame($body, $this->body($id));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function notFeedNames(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * @dataProvider notFeedNames
     */
    public function testLeavesADraftThatIsNotAFeedPostAlone(?string $feed_name): void
    {
        $body = "---\ntitle: My draft\n---\n\nMy content";
        $id = $this->seed($body, feed_name: $feed_name);

        $this->assertSame(0, migrate_feed_drafts());

        $this->assertSame($body, $this->body($id));
    }
}
