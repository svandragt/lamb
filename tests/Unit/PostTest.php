<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\body_has_tag;
use function Lamb\Post\get_tag_search_conditions;
use function Lamb\Post\parse_matter;
use function Lamb\Post\posts_by_tag;
use function Lamb\Post\sanitize_explicit_slug;
use function Lamb\Post\slugify;
use function Lamb\render_body;

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

    // posts_by_tag

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

    public function testPostsByTagReturnsMatchingPost(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'Hello #php world';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = posts_by_tag('php');
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

        $result = posts_by_tag('php');
        $this->assertCount(0, $result);
    }

    public function testPostsByTagReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->setUpDb();

        $result = posts_by_tag('nonexistenttag999');
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

        $result = posts_by_tag('endtag');
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

        $result = posts_by_tag('til');
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

        $result = posts_by_tag('til');
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

        $result = posts_by_tag('multitag');
        $this->assertCount(3, $result);
    }
}
