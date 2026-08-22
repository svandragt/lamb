<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\get_tags;
use function Lamb\Post\body_has_tag;
use function Lamb\Post\get_tag_search_conditions;
use function Lamb\Post\load_posts_in_order;
use function Lamb\Post\parse_matter;
use function Lamb\Post\post_ids_by_tag;
use function Lamb\Post\sanitize_explicit_slug;
use function Lamb\Post\slugify;
use function Lamb\render_body;

use const Lamb\Post\TAG_SCAN_PAGE;

class PostTest extends TestCase
{
    // get_tag_search_conditions

    public function testGetTagSearchConditionsReturnsSqlAndParamsKeys()
    {
        $result = get_tag_search_conditions('php');
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('params', $result);
    }

    public function testGetTagSearchConditionsSqlContainsBodyLike()
    {
        $result = get_tag_search_conditions('php');
        $this->assertStringContainsString('body LIKE', $result['sql']);
    }

    public function testGetTagSearchConditionsParamsAllContainTag()
    {
        $result = get_tag_search_conditions('php');
        foreach ($result['params'] as $param) {
            $this->assertStringContainsString('php', $param);
        }
    }

    public function testGetTagSearchConditionsPrefiltersOnHashTag()
    {
        $result = get_tag_search_conditions('php');
        $this->assertContains('%#php%', $result['params']);
    }

    // body_has_tag

    public function testBodyHasTagMatchesTagFollowedBySpace()
    {
        $this->assertTrue(body_has_tag('php', 'Hello #php world'));
    }

    public function testBodyHasTagMatchesTagAtEndOfBody()
    {
        $this->assertTrue(body_has_tag('php', 'Hello #php'));
    }

    public function testBodyHasTagMatchesTagFollowedByPunctuation()
    {
        $this->assertTrue(body_has_tag('til', "PO Box does #til."));
        $this->assertTrue(body_has_tag('php', 'Love #php, really'));
        $this->assertTrue(body_has_tag('php', 'Really? #php!'));
    }

    public function testBodyHasTagMatchesTagAtStartOfBody()
    {
        $this->assertTrue(body_has_tag('php', '#php is great'));
    }

    public function testBodyHasTagIsCaseInsensitive()
    {
        $this->assertTrue(body_has_tag('php', 'Hello #PHP world'));
    }

    public function testBodyHasTagDoesNotMatchLongerTag()
    {
        $this->assertFalse(body_has_tag('til', 'Today #tildes everywhere'));
    }

    public function testBodyHasTagDoesNotMatchMidWordHash()
    {
        $this->assertFalse(body_has_tag('php', 'colour#php inline'));
    }

    /**
     * body_has_tag() has to end a tag exactly where TAG_PATTERN does, because
     * TAG_PATTERN decides which /tag/ link is rendered and body_has_tag()
     * decides whether that page lists the post. It was missing `>`, `"`, `'`,
     * a backtick, `=` and both slashes, so `#php/8` linked to /tag/php and the
     * tag page then left the post out.
     *
     * @dataProvider terminatorProvider
     */
    public function testBodyHasTagAgreesWithTheRendererOnWhereATagEnds(string $body): void
    {
        $tags = get_tags($body);

        $this->assertSame(['php'], $tags, $body);
        $this->assertTrue(body_has_tag('php', $body), $body);
    }

    /**
     * One body per character TAG_PATTERN treats as ending a tag name.
     *
     * @return array<string, array{0: string}>
     */
    public static function terminatorProvider(): array
    {
        return [
            'slash'       => ['Learn #php/8 today'],
            'backslash'   => ['Path #php\\win'],
            'double quote' => ['Read #php"quoted"'],
            'single quote' => ["It is #php' s"],
            'backtick'    => ['Tag #php`code`'],
            'equals'      => ['Query #php=1 here'],
            'gt'          => ['Markup #php> arrow'],
            // Already agreed before, kept so a future narrowing is caught too.
            'space'       => ['Plain #php here'],
            'end'         => ['End of line #php'],
            'full stop'   => ['Punctuated #php.'],
            'ampersand'   => ['Amp #php&more'],
            'colon'       => ['Colon #php: yes'],
            'hash'        => ['Hash #php#two'],
        ];
    }

    // slugify

    public function testSlugifyLowercasesText()
    {
        $this->assertSame('hello-world', slugify('Hello World'));
    }

    public function testSlugifyReplacesSpacesWithHyphens()
    {
        $this->assertSame('foo-bar-baz', slugify('foo bar baz'));
    }

    public function testSlugifyReplacesSpecialCharacters()
    {
        $this->assertSame('hello-world-', slugify('Hello, World!'));
    }

    public function testSlugifyHandlesMultipleConsecutiveNonWordChars()
    {
        $this->assertSame('foo-bar', slugify('foo---bar'));
    }

    public function testSlugifyHandlesAlreadySluggedInput()
    {
        $this->assertSame('already-a-slug', slugify('already-a-slug'));
    }

    public function testSlugifyHandlesEmptyString()
    {
        $this->assertSame('', slugify(''));
    }

    // parse_matter

    public function testParseMatterReturnsEmptyArrayWhenNoFrontMatter()
    {
        $result = parse_matter('Just plain text with no front matter.');
        $this->assertSame([], $result);
    }

    public function testParseMatterExtractsTitleAndDerivesSlug()
    {
        $body = "---\ntitle: My Post Title\n---\n\nContent here.";
        $result = parse_matter($body);
        $this->assertSame('My Post Title', $result['title']);
        $this->assertSame('my-post-title', $result['slug']);
    }

    public function testParseMatterExtractsArbitraryKeys()
    {
        $body = "---\ntitle: Hello\ndescription: A short summary\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('A short summary', $result['description']);
    }

    public function testParseMatterReturnsEmptyArrayForInvalidYaml()
    {
        $body = "---\n: this is: invalid yaml\n---\nContent.";
        $result = parse_matter($body);
        $this->assertIsArray($result);
    }

    public function testParseMatterReturnsListWhenFrontMatterIsSequence()
    {
        // YAML sequences (lists) are returned as-is since they are arrays
        $body = "---\n- item1\n- item2\n---\nContent.";
        $result = parse_matter($body);
        $this->assertIsArray($result);
        $this->assertContains('item1', $result);
    }

    public function testParseMatterSlugifiesTitle()
    {
        $body = "---\ntitle: Hello World!\n---\n";
        $result = parse_matter($body);
        $this->assertSame('hello-world-', $result['slug']);
    }

    public function testParseMatterWithNoTitleHasNoSlug()
    {
        $body = "---\nauthor: Someone\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('slug', $result);
        $this->assertSame('Someone', $result['author']);
    }

    public function testParseMatterExtractsDraftTrue()
    {
        $body = "---\ntitle: My Draft\ndraft: true\n---\nContent.";
        $result = parse_matter($body);
        $this->assertTrue((bool)$result['draft']);
    }

    public function testParseMatterExtractsDraftFalse()
    {
        $body = "---\ntitle: My Post\ndraft: false\n---\nContent.";
        $result = parse_matter($body);
        $this->assertFalse((bool)$result['draft']);
    }

    public function testParseMatterHasNoDraftKeyWhenAbsent()
    {
        $body = "---\ntitle: My Post\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('draft', $result);
    }

    public function testParseMatterUsesExplicitSlugOverTitle()
    {
        $body = "---\ntitle: My Post Title\nslug: custom-slug\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('custom-slug', $result['slug']);
    }

    // parse_matter / sanitize_explicit_slug — an explicit slug must never be
    // able to turn a later automatic redirect's `to_url` (built as
    // '/' . $slug in redirect_edited()) into a protocol-relative
    // "//host/..." (or "/\host/...") open redirect.

    public function testParseMatterStripsLeadingSlashFromExplicitSlug()
    {
        $body = "---\nslug: /evil.example.com\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('evil.example.com', $result['slug']);
    }

    public function testParseMatterStripsLeadingSlashesFromExplicitSlug()
    {
        $body = "---\nslug: //evil.example.com\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('evil.example.com', $result['slug']);
    }

    public function testParseMatterStripsLeadingBackslashFromExplicitSlug()
    {
        $body = "---\nslug: \\evil.example.com\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('evil.example.com', $result['slug']);
    }

    public function testParseMatterLeavesOrdinarySlugUnchanged()
    {
        $body = "---\nslug: my-normal-slug\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('my-normal-slug', $result['slug']);
    }

    public function testSanitizeExplicitSlugStripsLeadingSlashesAndBackslashes()
    {
        $this->assertSame('evil.com', sanitize_explicit_slug('/evil.com'));
        $this->assertSame('evil.com', sanitize_explicit_slug('//evil.com'));
        $this->assertSame('evil.com', sanitize_explicit_slug('/\\evil.com'));
        $this->assertSame('evil.com', sanitize_explicit_slug('\\evil.com'));
        $this->assertSame('normal-slug', sanitize_explicit_slug('normal-slug'));
    }

    public function testSanitizeExplicitSlugFlattensInnerSeparators()
    {
        // A slug is one path segment: the router matches a post against the
        // request's first segment, so `archive/2024` named a URL that could
        // never route back to the post it was stored on.
        $this->assertSame('archive-2024', sanitize_explicit_slug('archive/2024'));
        $this->assertSame('a-b-c', sanitize_explicit_slug('a/b\\c'));
        $this->assertSame('evil.com-x', sanitize_explicit_slug('//evil.com/x'));
    }

    public function testParseMatterFlattensInnerSlashInExplicitSlug()
    {
        $body = "---\nslug: archive/2024\n---\nContent.";
        $this->assertSame('archive-2024', parse_matter($body)['slug']);
    }

    // parse_matter — front matter is a *leading* fence only

    public function testParseMatterIgnoresKeyValueLineAfterBodyContent()
    {
        // A `key: value` line after a `---` that is *not* the document's leading
        // fence is body, not front matter. It must not be parsed (and must not
        // become a bean column).
        $body = "Check this out\n---\nNote: this is important";
        $this->assertSame([], parse_matter($body));
    }

    public function testParseMatterIgnoresInlineTripleDash()
    {
        $this->assertSame([], parse_matter('Just a thought --- or three.'));
    }

    public function testParseMatterReadsLeadingFrontMatterDespiteBodyHorizontalRule()
    {
        $body = "---\ntitle: Hello\n---\n\nIntro\n\n---\n\nOutro";
        $result = parse_matter($body);
        $this->assertSame('Hello', $result['title']);
        $this->assertSame('hello', $result['slug']);
    }

    // render_body — body `---` (horizontal rules, diffs, code) is preserved

    public function testRenderBodyPreservesContentAroundHorizontalRule()
    {
        $html = render_body("First paragraph.\n\n---\n\nSecond paragraph.");
        $this->assertStringContainsString('First paragraph.', $html);
        $this->assertStringContainsString('Second paragraph.', $html);
        $this->assertStringContainsString('<hr', $html);
    }

    public function testRenderBodyPreservesBodyAfterLeadingFrontMatter()
    {
        $html = render_body("---\ntitle: Hello\n---\n\nIntro\n\n---\n\nOutro");
        $this->assertStringNotContainsString('title: Hello', $html);
        $this->assertStringContainsString('Intro', $html);
        $this->assertStringContainsString('Outro', $html);
    }

    public function testRenderBodyPreservesFencedCodeBlockContainingTripleDash()
    {
        $body = "Here's a diff:\n\n```diff\n--- a/file\n+++ b/file\n```\n\nAfter the code.";
        $html = render_body($body);
        // The lead-in line is dropped by the broken explode('---') split.
        $this->assertStringContainsString("Here's a diff:", $html);
        $this->assertStringContainsString('a/file', $html);
        $this->assertStringContainsString('b/file', $html);
        $this->assertStringContainsString('After the code.', $html);
        // The diff lines must stay inside a code block, not leak into a paragraph.
        $this->assertStringContainsString('<code', $html);
    }

    // parse_matter — key normalisation (case-insensitive, underscores ↔ dashes)

    public function testParseMatterLowercasesCapitalisedKeys()
    {
        // Mobile keyboards often auto-capitalise the first letter of a line.
        $body = "---\nTitle: My Post Title\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('Title', $result);
        $this->assertSame('My Post Title', $result['title']);
        // The derived slug still works off the normalised key.
        $this->assertSame('my-post-title', $result['slug']);
    }

    public function testParseMatterConvertsUnderscoreKeysToDashes()
    {
        $body = "---\nin_reply_to: https://example.com/post\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('in_reply_to', $result);
        $this->assertSame('https://example.com/post', $result['in-reply-to']);
    }

    public function testParseMatterNormalisesMixedCaseAndUnderscores()
    {
        $body = "---\nIn_Reply_To: https://example.com/post\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('https://example.com/post', $result['in-reply-to']);
    }

    public function testParseMatterNormalisesDraftKeyCasing()
    {
        $body = "---\nDraft: true\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('Draft', $result);
        $this->assertTrue((bool) $result['draft']);
    }

    // post_ids_by_tag

    protected function setUpDb(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);
        R::exec('DELETE FROM post');
    }

    /**
     * The scan reads a page at a time and keeps only ids, because holding a
     * bean per match killed both tag endpoints on a tag covering a large
     * archive: at 20,000 posts under one tag, "Allowed memory size of
     * 134217728 bytes exhausted" on /tag/<tag> and on /tag/<tag>/feed alike.
     * Paging is only safe if a real match sitting past a page boundary is
     * still found, so that is what this pins — with a page and a bit of
     * LIKE-only decoys in front of it, which is also why a plain SQL LIMIT
     * cannot replace the scan.
     */
    public function testPostIdsByTagFindsAMatchBeyondTheFirstScanPage(): void
    {
        $this->setUpDb();

        for ($i = 1; $i <= TAG_SCAN_PAGE + 20; $i++) {
            $this->tagPost("decoy $i #phplover", date('Y-m-d H:i:s'));
        }
        // Oldest, so it sorts last and the scan has to reach the second page.
        $this->tagPost('the real one #php', '2020-01-01 00:00:00');

        $this->assertCount(1, post_ids_by_tag('php'));
    }

    public function testPostIdsByTagStopsAtTheRequestedLimit(): void
    {
        $this->setUpDb();

        for ($i = 1; $i <= 5; $i++) {
            $this->tagPost("post $i #php", date('Y-m-d H:i:s', time() - $i * 60));
        }

        $this->assertCount(2, post_ids_by_tag('php', false, 2));
        $this->assertCount(5, post_ids_by_tag('php'));
        // 0 means "every match" and is handled before the limited scan runs;
        // anything below that asks for fewer than none, and gets none. Pinned
        // because the limited loop now stops on the count alone.
        $this->assertSame([], post_ids_by_tag('php', false, -1));
    }

    /**
     * The exhaustive scan walks the table in rowid order and sorts the
     * survivors in PHP, because asking SQL to order it made every page a fresh
     * scan of the whole table. The order callers see must not have changed:
     * newest first, whichever column is being ordered on.
     */
    public function testPostIdsByTagReturnsNewestFirst(): void
    {
        $this->setUpDb();

        $oldest = $this->tagPost('one #php', '2020-01-01 00:00:00');
        $newest = $this->tagPost('two #php', '2026-01-01 00:00:00');
        $middle = $this->tagPost('three #php', '2023-01-01 00:00:00');

        $this->assertSame([$newest, $middle, $oldest], post_ids_by_tag('php'));
    }

    /**
     * Posts written in the same second used to come back in whatever order
     * SQLite emitted them, which decided which page of /tag/<tag> a reader
     * found them on. The PHP sort is stable, so a tie now falls to ascending id.
     */
    public function testPostIdsByTagBreaksTimestampTiesByAscendingId(): void
    {
        $this->setUpDb();

        $first  = $this->tagPost('one #php', '2026-01-01 00:00:00');
        $second = $this->tagPost('two #php', '2026-01-01 00:00:00');
        $third  = $this->tagPost('three #php', '2026-01-01 00:00:00');

        $this->assertSame([$first, $second, $third], post_ids_by_tag('php'));
    }

    /**
     * visible_clause() treats a null `created` as visible, and SQLite sorted
     * those rows last under ORDER BY created DESC. The PHP sort has to agree.
     */
    public function testPostIdsByTagSortsUndatedPostsLast(): void
    {
        $this->setUpDb();

        $undated = $this->tagPost('undated #php', '2026-01-01 00:00:00');
        R::exec('UPDATE post SET created = NULL WHERE id = ?', [$undated]);
        $dated = $this->tagPost('dated #php', '2020-01-01 00:00:00');

        $this->assertSame([$dated, $undated], post_ids_by_tag('php'));
    }

    public function testLoadPostsInOrderKeepsTheGivenOrder(): void
    {
        $this->setUpDb();

        $ids = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $ids[] = $this->tagPost("post $letter #php", date('Y-m-d H:i:s'));
        }
        $reversed = array_reverse($ids);

        $loaded = load_posts_in_order($reversed);

        $this->assertSame($reversed, array_map(static fn($p): int => (int) $p->id, $loaded));
        $this->assertSame([], load_posts_in_order([]));
    }

    private function tagPost(string $body, string $created): int
    {
        $post = R::dispense('post');
        $post->body    = $body;
        $post->version = 1;
        $post->draft   = null;
        $post->created = $created;
        $post->updated = $created;

        return (int) R::store($post);
    }

    public function testPostsByTagReturnsMatchingPost(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'Hello #php world';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = post_ids_by_tag('php');
        $this->assertCount(1, $result);
    }

    public function testPostsByTagDoesNotReturnDraftPosts(): void
    {
        $this->setUpDb();

        $draft = R::dispense('post');
        $draft->body    = 'Hello #php world';
        $draft->version = 1;
        $draft->draft   = 1;
        $draft->created = date('Y-m-d H:i:s');
        R::store($draft);

        $result = post_ids_by_tag('php');
        $this->assertCount(0, $result);
    }

    public function testPostsByTagReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->setUpDb();

        $result = post_ids_by_tag('nonexistenttag999');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testPostsByTagMatchesTagAtEndOfBody(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'My post #endtag';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = post_ids_by_tag('endtag');
        $this->assertCount(1, $result);
    }

    public function testPostsByTagMatchesTagFollowedByPunctuation(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = "I guess that's a PO Box does #til.";
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = post_ids_by_tag('til');
        $this->assertCount(1, $result);
    }

    public function testPostsByTagDoesNotMatchLongerTagPrefix(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'Today I used #tildes everywhere.';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = post_ids_by_tag('til');
        $this->assertCount(0, $result);
    }

    public function testPostsByTagReturnsMultipleMatchingPosts(): void
    {
        $this->setUpDb();

        for ($i = 0; $i < 3; $i++) {
            $post = R::dispense('post');
            $post->body    = "Post $i #multitag";
            $post->version = 1;
            $post->draft   = null;
            $post->created = date('Y-m-d H:i:s', time() - $i);
            R::store($post);
        }

        $result = post_ids_by_tag('multitag');
        $this->assertCount(3, $result);
    }
}
