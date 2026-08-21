<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Post\build_matter;
use function Lamb\Post\normalize_frontmatter_fence;
use function Lamb\Post\parse_matter;
use function Lamb\Post\set_matter;

class FrontMatterTest extends TestCase
{
    // normalize_frontmatter_fence — iOS Smart Punctuation recovery

    private const EM = "\xE2\x80\x94"; // — em dash (U+2014)
    private const EN = "\xE2\x80\x93"; // – en dash (U+2013)

    public function testNormalizeRestoresEmDashHyphenFence(): void
    {
        // iOS Smart Punctuation turns a typed `---` into an em dash followed by
        // a hyphen (`—-`). Both the opening and closing fence must be restored
        // and the metadata extracted.
        $em = self::EM;
        $body = "$em-\nin-reply-to: https://example.com/post\n$em-\n";
        $normalized = normalize_frontmatter_fence($body);

        $this->assertSame(
            "---\nin-reply-to: https://example.com/post\n---\n",
            $normalized
        );
        $this->assertSame(
            'https://example.com/post',
            parse_matter($normalized)['in-reply-to']
        );
    }

    public function testNormalizeLeavesLoneEmDashUntouched(): void
    {
        // A single dash-like character is not a fence: a lone em dash is
        // ordinary punctuation (or a thematic break) far more often than a
        // mangled `---`, so it must be left alone rather than swallowing the
        // line between it and the next em dash.
        $em = self::EM;
        $body = "$em\nin-reply-to: https://example.com/post\n$em\n";
        $this->assertSame($body, normalize_frontmatter_fence($body));
    }

    public function testNormalizeLeavesLoneEnDashUntouched(): void
    {
        $en = self::EN;
        $body = "$en\ntitle: Hello\n$en\n\nBody.";
        $this->assertSame($body, normalize_frontmatter_fence($body));
    }

    public function testNormalizeLeavesLiteralTripleDashUnchanged(): void
    {
        $body = "---\ntitle: Hello\n---\nBody.";
        $this->assertSame($body, normalize_frontmatter_fence($body));
    }

    public function testNormalizeLeavesEmDashProseUntouched(): void
    {
        // A standalone em dash used as punctuation must not be mistaken for a
        // fence — there is no opening/closing pair at the start of the body.
        $em = self::EM;
        $body = "Hello $em this is an aside $em world.\n\nMore.";
        $this->assertSame($body, normalize_frontmatter_fence($body));
    }

    public function testNormalizeLeavesSingleHyphenLinesUntouched(): void
    {
        // A lone hyphen is never an iOS conversion of `---`; leave it alone so
        // it can't swallow body content.
        $body = "-\nnot front matter\n-\n";
        $this->assertSame($body, normalize_frontmatter_fence($body));
    }

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
        // Values are emitted as YAML, so a value carrying a `:` is quoted.
        $this->assertSame(
            "---\ntitle: Hello\nin-reply-to: 'https://example.com'\n---\nBody",
            build_matter(
                ['title' => 'Hello', 'in-reply-to' => 'https://example.com'],
                'Body'
            )
        );
    }

    public function testBuildMatterCannotInjectExtraKeysThroughAValue(): void
    {
        // A newline in a value used to open new front-matter lines, which
        // apply_frontmatter() then applied to the bean.
        $body = build_matter(['title' => "Harmless\nid: 1\ndeleted: 1"], 'Body');
        $matter = parse_matter($body);

        $this->assertSame("Harmless\nid: 1\ndeleted: 1", $matter['title']);
        $this->assertArrayNotHasKey('id', $matter);
        $this->assertArrayNotHasKey('deleted', $matter);
    }

    public function testBuildMatterKeepsATitleContainingAColon(): void
    {
        $body = build_matter(['title' => 'Rust: a first look'], 'Body');

        $this->assertSame('Rust: a first look', parse_matter($body)['title']);
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
            set_matter($body, 'created', '2024-06-05 12:00:00', append: false)
        );
    }

    public function testSetMatterQuotedDoesNotAppendWhenAbsent(): void
    {
        $body = "---\ntitle: Hi\n---\nContent.";
        $this->assertSame(
            $body,
            set_matter($body, 'created', '2024-06-05 12:00:00', append: false)
        );
    }

    public function testSetMatterQuotedIdempotentWhenValueUnchanged(): void
    {
        $body = "---\ncreated: '2024-06-05 12:00:00'\n---\nContent.";
        $this->assertSame(
            $body,
            set_matter($body, 'created', '2024-06-05 12:00:00', append: false)
        );
    }

    // set_matter — the value is YAML, not interpolated text

    public function testSetMatterQuotesASlugCarryingAColon(): void
    {
        // finalize_slug() appends an id suffix to an explicit slug that
        // collides or names a reserved route, then pins the result back here.
        // Interpolated, `slug: a: b-12` is not valid YAML, so parse_matter()
        // returned nothing and the whole block — title and draft included —
        // silently vanished on the next read.
        $body = "---\ntitle: My Post\ndraft: true\nslug: 'a: b'\n---\n\nBody.\n";

        $result = set_matter($body, 'slug', 'a: b-12');

        $this->assertSame(
            ['title' => 'My Post', 'draft' => true, 'slug' => 'a: b-12'],
            parse_matter($result)
        );
    }

    public function testSetMatterQuotesASlugCarryingAHash(): void
    {
        // Unquoted, everything from the `#` is a YAML comment, so the pinned
        // slug read back as `y` and never matched the stored one.
        $body = "---\ntitle: T\nslug: x\n---\n\nBody.\n";

        $this->assertSame('y #z', parse_matter(set_matter($body, 'slug', 'y #z'))['slug']);
    }

    public function testSetMatterQuotesAnAppendedValueToo(): void
    {
        $body = "---\ntitle: T\n---\n\nBody.\n";

        $this->assertSame('a: b', parse_matter(set_matter($body, 'slug', 'a: b'))['slug']);
    }

    // set_matter — CRLF bodies (what a browser submits from a <textarea>)

    public function testSetMatterDoesNotChurnACrlfBodyWhoseValueIsUnchanged(): void
    {
        $body = "---\r\nslug: my-slug\r\ntitle: Hi\r\n---\r\n\r\nBody text.\r\n";

        $this->assertSame($body, set_matter($body, 'slug', 'my-slug'));
    }

    public function testSetMatterKeepsCrlfWhenRewritingAValue(): void
    {
        $body = "---\r\nslug: my-slug\r\ntitle: Hi\r\n---\r\n\r\nBody text.\r\n";

        $this->assertSame(
            "---\r\nslug: other\r\ntitle: Hi\r\n---\r\n\r\nBody text.\r\n",
            set_matter($body, 'slug', 'other')
        );
    }

    public function testSetMatterKeepsCrlfWhenAppendingAKey(): void
    {
        $body = "---\r\ntitle: Hi\r\n---\r\n\r\nBody.\r\n";

        $result = set_matter($body, 'slug', 'new');

        $this->assertSame("---\r\ntitle: Hi\r\nslug: new\r\n---\r\n\r\nBody.\r\n", $result);
        $this->assertSame(['title' => 'Hi', 'slug' => 'new'], parse_matter($result));
    }
}
