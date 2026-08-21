<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;
use function Lamb\Post\consume_leading_heading;
use function Lamb\Post\parse_matter;
use function Lamb\Theme\anchor_headings;

class ConsumeLeadingHeadingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testConsumesLeadingHeadingIntoFrontMatterTitle()
    {
        $body = "# My Post\n\nSome content.";

        $result = consume_leading_heading($body);

        $this->assertStringContainsString('title: ', $result);
        $this->assertStringContainsString('My Post', $result);
        // The heading line is removed from the content.
        $this->assertStringNotContainsString('# My Post', $result);
        $this->assertStringContainsString('Some content.', $result);
    }

    public function testLeavesBodyUnchangedWhenFrontMatterTitlePresent()
    {
        $body = "---\ntitle: Real Title\n---\n\n# Not The Title\n\nBody.";

        $this->assertSame($body, consume_leading_heading($body));
    }

    public function testLeavesBodyUnchangedWhenNoLeadingHeading()
    {
        $body = "Just a plain status update.";

        $this->assertSame($body, consume_leading_heading($body));
    }

    public function testDoesNotConsumeHeadingThatIsNotFirst()
    {
        $body = "An intro paragraph.\n\n# A Section\n\nMore.";

        $this->assertSame($body, consume_leading_heading($body));
    }

    public function testConsumesAnyLeadingHeadingLevel()
    {
        // The level the author types for the title is immaterial.
        $result = consume_leading_heading("### My Post\n\nBody.");

        $this->assertStringContainsString('title: ', $result);
        $this->assertStringContainsString('My Post', $result);
        $this->assertStringNotContainsString('### My Post', $result);
        $this->assertStringContainsString('Body.', $result);
    }

    public function testPreservesExistingFrontMatterWithoutTitle()
    {
        $body = "---\ncreated: 2020-01-01\n---\n\n# My Post\n\nBody.";

        $result = consume_leading_heading($body);

        $this->assertStringContainsString('title: ', $result);
        $this->assertStringContainsString('My Post', $result);
        $this->assertStringContainsString('created: 2020-01-01', $result);
        $this->assertStringContainsString('Body.', $result);
    }

    public function testPreservesCrlfFrontMatterWhenPromotingHeading()
    {
        // A browser normalises a <textarea> to CRLF on submit, so this is what
        // every body saved from the edit form looks like. The existing block
        // must gain the title, not be pushed below a second one — otherwise
        // `draft: true` stops being front matter and the post publishes.
        $body = "---\r\ndraft: true\r\n---\r\n\r\n# My Post\r\n\r\nBody.\r\n";

        $result = consume_leading_heading($body);

        $this->assertSame(2, substr_count($result, '---'));
        $matter = parse_matter($result);
        $this->assertSame('My Post', $matter['title'] ?? null);
        $this->assertTrue($matter['draft'] ?? false);
        $this->assertStringNotContainsString('# My Post', $result);
        $this->assertSame($result, consume_leading_heading($result));
    }

    public function testPreservesFrontMatterFenceWithTrailingWhitespace()
    {
        // split_frontmatter() accepts trailing spaces on a fence line, so the
        // title injection has to recognise the same block it reads.
        $body = "---  \ncreated: 2020-01-01\n---  \n\n# My Post\n\nBody.";

        $result = consume_leading_heading($body);

        $this->assertSame(2, substr_count($result, '---'));
        $this->assertStringContainsString('created: 2020-01-01', $result);
        $this->assertSame('My Post', parse_matter($result)['title'] ?? null);
    }

    public function testFillsAnEmptyFrontMatterBlockRatherThanAddingAnother()
    {
        $body = "---\n---\n\n# My Post\n\nBody.";

        $result = consume_leading_heading($body);

        $this->assertSame(2, substr_count($result, '---'));
        $this->assertSame('My Post', parse_matter($result)['title'] ?? null);
    }

    public function testIsIdempotent()
    {
        $body = "# My Post\n\nSome content.";

        $once = consume_leading_heading($body);
        $twice = consume_leading_heading($once);

        $this->assertSame($once, $twice);
    }

    public function testParseBeanSetsTitleAndSlugFromLeadingHeading()
    {
        $bean = R::dispense('post');
        $bean->body = "# My Post\n\nSome content.";

        parse_bean($bean);

        $this->assertSame('My Post', $bean->title);
        $this->assertSame('my-post', $bean->slug);
        // The body no longer renders the title as a heading.
        $this->assertStringNotContainsString('<h1>My Post</h1>', (string) $bean->transformed);
    }

    public function testTitleHeadingConsumedAndSubheadingsAnchorAtHThree()
    {
        // The full pipeline a titled post goes through: a leading `# Title`
        // becomes the post title (rendered at h2 by the theme), and the body's
        // first subheading lands at h3 with no skipped level.
        $bean = R::dispense('post');
        $bean->body = "# My Post\n\n## Section\n\n### Sub\n\nText.";

        parse_bean($bean);
        // Stored transformed keeps the author's literal levels (theme-neutral).
        $this->assertStringContainsString('<h2>Section</h2>', (string) $bean->transformed);

        // The theme (title at h2) anchors the body's top heading at h3.
        $rendered = anchor_headings((string) $bean->transformed, 3);
        $this->assertStringContainsString('<h3>Section</h3>', $rendered);
        $this->assertStringContainsString('<h4>Sub</h4>', $rendered);
        $this->assertStringNotContainsString('<h2>', $rendered);
    }

    public function testPastedSubheadingShallowerThanTitleStillAnchorsToHThree()
    {
        // The author typed a deep title (#### ) then pasted a shallower heading
        // (## ). The absolute levels are immaterial: the title renders at h2 and
        // the body's highest heading re-bases to h3 — no inverted/ skipped outline.
        $bean = R::dispense('post');
        $bean->body = "#### My post\n\nThis is my post.\n\n## Pasted content\n\nOh no.";

        parse_bean($bean);
        $this->assertSame('My post', $bean->title);
        $this->assertStringContainsString('<h2>Pasted content</h2>', (string) $bean->transformed);

        $rendered = anchor_headings((string) $bean->transformed, 3);
        $this->assertStringContainsString('<h3>Pasted content</h3>', $rendered);
        $this->assertStringNotContainsString('<h2>', $rendered);
    }

    public function testParseBeanLeavesPlainStatusUntitled()
    {
        $bean = R::dispense('post');
        $bean->body = "Just a plain status update.";

        parse_bean($bean);

        $this->assertSame('', $bean->title);
    }
}
