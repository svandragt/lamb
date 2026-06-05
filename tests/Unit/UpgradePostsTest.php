<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\parse_matter;
use function Lamb\Post\populate_bean;
use function Lamb\Response\upgrade_posts;

class UpgradePostsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        global $config;
        $config = $config ?? [];
    }

    public function testVersion1PostWithCodeIsRehighlightedOnUpgrade(): void
    {
        $bean = R::dispense('post');
        $bean->body = "```php\necho 1;\n```";
        $bean->transformed = '<pre><code class="language-php">echo 1;</code></pre>';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertStringContainsString('phiki', $bean->transformed);
        $this->assertSame(POST_VERSION, (int)$bean->version);
    }

    public function testCurrentVersionPostIsNotRestored(): void
    {
        $bean = R::dispense('post');
        $bean->body = "plain post";
        $bean->transformed = '<p>stale marker that re-parsing would replace</p>';
        $bean->version = POST_VERSION;

        upgrade_posts([$bean]);

        $this->assertSame('<p>stale marker that re-parsing would replace</p>', $bean->transformed);
    }

    public function testPopulateBeanStampsCurrentVersion(): void
    {
        $bean = populate_bean('hello world');

        $this->assertSame(POST_VERSION, (int)$bean->version);
    }

    // Legacy posts (e.g. old feed items) store their title only on the title
    // column. parse_bean() clears titles absent from front matter (edit
    // semantics), so the upgrade must migrate the column title into the body's
    // front matter before re-parsing.

    public function testUpgradeMigratesColumnTitleIntoFrontMatter(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Status post body';
        $bean->title = 'My Post Title';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame('My Post Title', $bean->title);
        $this->assertStringStartsWith("---\n", $bean->body);
        $this->assertSame('My Post Title', parse_matter($bean->body)['title'] ?? null);
    }

    public function testUpgradeInsertsTitleIntoExistingFrontMatter(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\ncreated: 2024-01-01 10:00:00\n---\n\nBody text";
        $bean->title = 'Legacy Title';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame('Legacy Title', $bean->title);
        $matter = parse_matter($bean->body);
        $this->assertSame('Legacy Title', $matter['title'] ?? null);
        $this->assertArrayHasKey('created', $matter);
    }

    public function testUpgradeMigratesTitleNeedingYamlEscaping(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Release notes body';
        $bean->title = "Release: what's new";
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame("Release: what's new", $bean->title);
        $this->assertSame("Release: what's new", parse_matter($bean->body)['title'] ?? null);
    }

    // Slugs are reserved/adjusted at publish time (reserved-route suffixes,
    // redirects on edit). An upgrade is not an edit: it must never change a
    // stored slug or mint one for a slug-less post — good URLs don't change.

    public function testUpgradePreservesStoredSlug(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: Search\n---\n\nBody";
        $bean->title = 'Search';
        $bean->slug = 'search-1';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame('search-1', $bean->slug);
    }

    public function testUpgradeDoesNotMintSlugForSluglessPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Status post body';
        $bean->title = 'My Post Title';
        $bean->slug = '';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame('', (string)$bean->slug);
    }

    // Feed items drafted via feeds_draft are column-only drafts (no
    // front-matter draft key); re-parsing must not publish them.

    public function testUpgradePreservesColumnOnlyDraft(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Unreviewed feed item body';
        $bean->draft = 1;
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertSame(1, (int)$bean->draft);
    }
}
