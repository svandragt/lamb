<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Post\build_matter;
use function Lamb\Post\set_matter;

class FrontMatterTest extends TestCase
{
    // build_matter

    public function testBuildMatterReturnsContentOnlyWhenMatterEmpty(): void
    {
        $this->assertSame('Just content', build_matter([], 'Just content'));
    }

    public function testBuildMatterRendersSingleKey(): void
    {
        $this->assertSame(
            "---\ntitle: Hello\n---\nContent",
            build_matter(['title' => 'Hello'], 'Content')
        );
    }

    public function testBuildMatterRendersMultipleKeysInOrder(): void
    {
        $this->assertSame(
            "---\ntitle: Hello\nin-reply-to: https://example.com\n---\nBody",
            build_matter(
                ['title' => 'Hello', 'in-reply-to' => 'https://example.com'],
                'Body'
            )
        );
    }

    // set_matter — insert into existing fence

    public function testSetMatterReplacesExistingKeyInPlace(): void
    {
        $body = "---\ntitle: Hi\nslug: old\n---\nContent.";
        $this->assertSame(
            "---\ntitle: Hi\nslug: new\n---\nContent.",
            set_matter($body, 'slug', 'new')
        );
    }

    public function testSetMatterAppendsKeyWhenAbsent(): void
    {
        $body = "---\ntitle: Hi\n---\nContent.";
        $this->assertSame(
            "---\ntitle: Hi\nslug: new\n---\nContent.",
            set_matter($body, 'slug', 'new')
        );
    }

    public function testSetMatterPreservesUnrelatedKeys(): void
    {
        $body = "---\ntitle: Hi\ndescription: keep me\nslug: old\n---\nBody.";
        $this->assertSame(
            "---\ntitle: Hi\ndescription: keep me\nslug: new\n---\nBody.",
            set_matter($body, 'slug', 'new')
        );
    }

    public function testSetMatterIsIdempotentWhenValueUnchanged(): void
    {
        $body = "---\ntitle: Hi\nslug: same\n---\nContent.";
        $this->assertSame($body, set_matter($body, 'slug', 'same'));
    }

    public function testSetMatterReturnsBodyUnchangedWhenNoFence(): void
    {
        $body = "Just plain text, no front matter.";
        $this->assertSame($body, set_matter($body, 'slug', 'new'));
    }

    public function testSetMatterPreservesKeyWhitespacePrefixOnReplace(): void
    {
        $body = "---\n  slug:   old\n---\nContent.";
        // The matched key prefix is preserved; value rewritten with single space.
        $this->assertSame(
            "---\n  slug: new\n---\nContent.",
            set_matter($body, 'slug', 'new')
        );
    }

    // set_matter — quoted variant (created date)

    public function testSetMatterQuotedReplacesExistingKey(): void
    {
        $body = "---\ncreated: 2020-01-01 00:00:00\n---\nContent.";
        $this->assertSame(
            "---\ncreated: '2024-06-05 12:00:00'\n---\nContent.",
            set_matter($body, 'created', '2024-06-05 12:00:00', quote: true, append: false)
        );
    }

    public function testSetMatterQuotedDoesNotAppendWhenAbsent(): void
    {
        $body = "---\ntitle: Hi\n---\nContent.";
        $this->assertSame(
            $body,
            set_matter($body, 'created', '2024-06-05 12:00:00', quote: true, append: false)
        );
    }

    public function testSetMatterQuotedIdempotentWhenValueUnchanged(): void
    {
        $body = "---\ncreated: '2024-06-05 12:00:00'\n---\nContent.";
        $this->assertSame(
            $body,
            set_matter($body, 'created', '2024-06-05 12:00:00', quote: true, append: false)
        );
    }
}
