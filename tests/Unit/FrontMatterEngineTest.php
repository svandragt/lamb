<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Post\build_matter;
use function Lamb\Post\parse_matter;
use function Lamb\Post\persist_slug;
use function Lamb\Post\set_frontmatter_key;
use function Lamb\Post\set_matter;
use function Lamb\Post\set_reply_to;
use function Lamb\persist_resolved_created;

/**
 * Characterisation of the front-matter engine's contract at the shapes issue
 * #689 names as load-bearing. These pin the behaviour that must survive the two
 * engines converging onto set_frontmatter_key(): they pass against both the old
 * in-place set_matter() and the migrated one.
 */
class FrontMatterEngineTest extends TestCase
{
    // 1. CRLF bodies from the edit form — set_matter()'s no-churn contract.
    //    A `\r` must not make an unchanged save rewrite (and re-store) the line.

    public function testSetMatterNoChurnOnUnchangedCrlfBody(): void
    {
        $body = "---\r\nslug: my-slug\r\ntitle: Hi\r\n---\r\n\r\nBody text.\r\n";
        $this->assertSame($body, set_matter($body, 'slug', 'my-slug'));
    }

    public function testPersistSlugNoChurnOnUnchangedCrlfBody(): void
    {
        $body = "---\r\nslug: my-slug\r\ntitle: Hi\r\n---\r\n\r\nBody text.\r\n";
        $this->assertSame($body, persist_slug($body, 'my-slug'));
    }

    public function testPersistResolvedCreatedNoChurnOnUnchangedCrlfBody(): void
    {
        // Midnight is the common scheduled-post time; the stored `00:00:00` must
        // compare equal to the resolved value or every save re-stores the post.
        $body = "---\r\ncreated: '2024-06-05 00:00:00'\r\ntitle: Hi\r\n---\r\n\r\nBody.\r\n";
        $this->assertSame($body, persist_resolved_created($body, '2024-06-05 00:00:00'));
    }

    // 2. Bodies with no front matter at all — behaviour differs per call site.

    public function testSetMatterLeavesBodyWithoutFrontMatterUnchanged(): void
    {
        $body = "Just a status update.";
        $this->assertSame($body, set_matter($body, 'slug', 'anything'));
    }

    public function testPersistResolvedCreatedLeavesBodyWithoutFrontMatterUnchanged(): void
    {
        $body = "Just a status update.";
        $this->assertSame($body, persist_resolved_created($body, '2024-06-05 12:00:00'));
    }

    public function testSetFrontmatterKeyAddsBlockToBodyWithoutFrontMatter(): void
    {
        $body = "Just a status update.";
        $result = set_frontmatter_key($body, 'in-reply-to', 'https://example.com/post');
        $this->assertStringStartsWith("---\n", $result);
        $this->assertSame('https://example.com/post', parse_matter($result)['in-reply-to']);
        $this->assertStringContainsString('Just a status update.', $result);
    }

    public function testBuildMatterReturnsContentVerbatimWhenEmpty(): void
    {
        $this->assertSame('Just content', build_matter([], 'Just content'));
    }

    // 3. An unrecognised, hand-written key must not be dropped when another key
    //    on the same block is changed (the Micropub update path).

    public function testSetFrontmatterKeyPreservesUnrecognisedKeys(): void
    {
        $body = "---\ntitle: Old\nslug: pinned\ndraft: true\n---\nBody.";
        $result = set_frontmatter_key($body, 'title', 'New');
        $this->assertStringContainsString('slug: pinned', $result);
        $this->assertStringContainsString('draft: true', $result);
        $this->assertSame('New', parse_matter($result)['title']);
    }

    // 4. Both hyphen and underscore spellings collapse onto one key.

    public function testSetReplyToReplacesUnderscoreSpelledKeyLeavingOne(): void
    {
        $body = set_reply_to("---\nin_reply_to: https://old.example/x\n---\nHi", 'https://new.example/y');
        $this->assertStringNotContainsString('old.example', $body);
        $this->assertSame(1, substr_count($body, 'reply'));
        $this->assertSame('https://new.example/y', parse_matter($body)['in-reply-to']);
    }

    // 5. A block left with nothing but the key being removed loses its fence.

    public function testSetFrontmatterKeyRemovesLastKeyAndFence(): void
    {
        $body = "---\nin-reply-to: https://other.example/post\n---\nHi";
        $this->assertSame('Hi', set_frontmatter_key($body, 'in-reply-to', ''));
    }
}
