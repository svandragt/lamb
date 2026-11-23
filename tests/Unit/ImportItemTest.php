<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\Import\import_item;
use function Lamb\Import\store_redirect;

/**
 * Covers Lamb\Import\import_item(), the pipeline body shared by every
 * importer ({@see \Lamb\Known\import_item}, {@see \Lamb\WordPress\import_item}).
 * Each importer's own test suite exercises it through its thin wrapper; this
 * suite exercises the shared body directly with a minimal fake $source, so
 * the composition (which hook runs on what, and in what order) is pinned
 * independently of any one CMS's field names.
 */
class ImportItemTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    /**
     * @param list<array{0: array<string, mixed>, 1: OODBBean}> $redirect_calls
     * @return array{
     *     uuid: callable(string): string,
     *     allowed_types: list<string>,
     *     title: callable(array<string, mixed>): string,
     *     slug: callable(array<string, mixed>): string,
     *     dom_pass: ?callable(\Dom\HTMLDocument): void,
     *     markdown: callable(string): string,
     *     tags: callable(array<string, mixed>, string): list<string>,
     *     finalize_markdown: callable(array<string, mixed>, string): string,
     *     store_redirects: callable(array<string, mixed>, OODBBean): void,
     * }
     */
    private function fakeSource(array &$redirect_calls = []): array
    {
        return [
            'uuid' => static fn(string $guid): string => 'uuid-' . $guid,
            'allowed_types' => ['post'],
            'title' => static fn(array $item): string => 'T:' . (string) $item['title'],
            'slug' => static fn(array $item): string => 'slug-' . (string) $item['title'],
            'dom_pass' => null,
            'markdown' => static fn(string $html): string => 'md:' . $html,
            'tags' => static fn(array $item, string $markdown): array => ['from-' . $markdown],
            'finalize_markdown' => static fn(array $item, string $markdown): string => $markdown . ':final',
            'store_redirects' => function (array $item, OODBBean $bean) use (&$redirect_calls): void {
                $redirect_calls[] = [$item, $bean];
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleItem(): array
    {
        return [
            'guid' => 'g1',
            'post_type' => 'post',
            'status' => 'publish',
            'content' => 'hi',
            'created' => '2024-01-01 00:00:00',
            'updated' => '2024-01-02 00:00:00',
            'title' => 'Hello',
        ];
    }

    public function testImportItemComposesEverySourceHook(): void
    {
        $redirect_calls = [];
        $source = $this->fakeSource($redirect_calls);

        $bean = import_item($this->sampleItem(), $source, fn(): ?string => null, false);

        $this->assertNotNull($bean);
        $this->assertSame('slug-Hello', (string) $bean->slug);
        $this->assertStringContainsString("title: 'T:Hello'", (string) $bean->body);
        // tags() ran on the markdown() output, and finalize_markdown() ran
        // after tags() (its ':final' suffix must not leak into the tag).
        $this->assertStringContainsString('#from-md:hi', (string) $bean->body);
        $this->assertSame('uuid-g1', (string) $bean->import_uuid);
        $this->assertCount(1, $redirect_calls);
        $this->assertSame('uuid-g1', (string) $redirect_calls[0][1]->import_uuid);
    }

    public function testImportItemSkipsTypesOutsideAllowedTypes(): void
    {
        $redirect_calls = [];
        $source = $this->fakeSource($redirect_calls);
        $item = $this->sampleItem();
        $item['post_type'] = 'page';

        $bean = import_item($item, $source, fn(): ?string => null, false);

        $this->assertNull($bean);
        $this->assertCount(0, R::findAll('post'));
    }

    public function testImportItemDryRunSkipsSaveAndRedirects(): void
    {
        $redirect_calls = [];
        $source = $this->fakeSource($redirect_calls);

        $bean = import_item($this->sampleItem(), $source, fn(): ?string => null, true);

        $this->assertNotNull($bean);
        $this->assertEmpty($bean->id);
        $this->assertCount(0, R::findAll('post'));
        $this->assertCount(0, $redirect_calls);
    }

    public function testImportItemReturnsExistingRowByUuidWithoutRunningHooks(): void
    {
        $redirect_calls = [];
        $source = $this->fakeSource($redirect_calls);

        $first = import_item($this->sampleItem(), $source, fn(): ?string => null, false);
        $this->assertNotNull($first);
        $id = (int) $first->id;

        $second_calls = [];
        $second_source = $this->fakeSource($second_calls);
        $second = import_item($this->sampleItem(), $second_source, fn(): ?string => null, false);

        $this->assertNotNull($second);
        $this->assertSame($id, (int) $second->id);
        $this->assertCount(1, R::findAll('post'));
        // The dedup short-circuit returns before any per-item hook runs.
        $this->assertCount(0, $second_calls);
    }

    public function testImportItemReplacesGivenBeanInPlace(): void
    {
        $redirect_calls = [];
        $source = $this->fakeSource($redirect_calls);

        $first = import_item($this->sampleItem(), $source, fn(): ?string => null, false);
        $this->assertNotNull($first);
        $id = (int) $first->id;

        $item = $this->sampleItem();
        $item['title'] = 'Updated';
        $replaced = import_item($item, $source, fn(): ?string => null, false, R::load('post', $id));

        $this->assertNotNull($replaced);
        $this->assertSame($id, (int) $replaced->id);
        $this->assertSame('slug-Updated', (string) $replaced->slug);
        $this->assertCount(1, R::findAll('post'));
    }

    public function testAnImportedPostIsNotMarkedForWebmentions(): void
    {
        // An import never notifies, so /_cron must not queue webmentions for it.
        $bean = import_item($this->sampleItem(), $this->fakeSource(), fn(): ?string => null, false);

        $this->assertNotNull($bean);
        $this->assertSame(0, (int) R::load('post', $bean->id)->webmentions_pending);
    }

    public function testAFailedRedirectWriteLeavesNoPostBehind(): void
    {
        // Creates the table so the trigger can name it.
        store_redirect('seed', '/elsewhere');
        R::exec("CREATE TRIGGER redirect_block_insert BEFORE INSERT ON redirect BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $source = $this->fakeSource();
        $source['store_redirects'] = static function (array $item, OODBBean $bean): void {
            store_redirect('old/path', '/' . $bean->slug);
        };

        try {
            import_item($this->sampleItem(), $source, fn(): ?string => null, false);
            $this->fail('The redirect write should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER redirect_block_insert');
        }

        // A re-run matches on import_uuid, so a post left behind would never get its redirects.
        $this->assertSame(0, R::count('post'));
        $this->assertSame(1, R::count('redirect'));
    }
}
