<?php

namespace Tests\Unit;

use Lamb\LambDown;
use PHPUnit\Framework\TestCase;

use function Lamb\Post\candidate_marker_offsets;
use function Lamb\Post\split_frontmatter;
use function Lamb\Post\toggle_rendered_checkbox;

/**
 * The toggle endpoint addresses a checkbox by its *rendered* position
 * (`data-checkbox-index`), so toggling index N has to flip the Nth rendered
 * checkbox and leave every other one exactly as it was. Which source marker
 * that is depends on Parsedown's block structure, so the tests below check the
 * result against the rendered HTML rather than against a source scan.
 */
class CheckboxRenderParityTest extends TestCase
{
    /**
     * The `checked` state of every checkbox LambDown renders, in document order.
     *
     * @return list<bool>
     */
    private function renderedStates(string $body): array
    {
        $parser = new LambDown();
        $parser->setSafeMode(true);
        [, $content] = split_frontmatter($body);
        $html = $parser->text(trim($content));

        preg_match_all('/<input type="checkbox" data-checkbox-index="\d+"( checked)?/', $html, $matches);

        return array_map(static fn(string $checked): bool => $checked !== '', $matches[1]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodyProvider(): array
    {
        return [
            'bullet list'              => ["- [ ] one\n- [x] two\n"],
            'bare markers'             => ["[ ] alpha\n\n- [ ] beta\n- [x] gamma\n"],
            'ordered list'             => ["1. [ ] one\n2. [x] two\n"],
            'ordered list with parens' => ["1) [x] paren\n"],
            'blockquoted list'         => ["> - [ ] q1\n> - [x] q2\n"],
            'blockquoted bare'         => ["> [x] quoted\n"],
            'backtick fenced code'     => ["```\n- [x] code\n```\n\n- [ ] real\n"],
            'tilde fenced code'        => ["~~~\n- [x] code\n~~~\n\n- [ ] real\n"],
            'fenced code with info'    => ["```php\n- [x] code\n```\n\n- [ ] real\n"],
            'indented code block'      => ["para\n\n    - [x] indented\n\n- [ ] real\n"],
            'labelless marker'         => ["- [ ] \n- [x] labelled\n"],
            'nested list'              => ["- [ ] a\n  - [x] b\n"],
            'nested list four spaces'  => ["- [ ] a\n    - [x] b\n"],
            'front matter'             => ["---\ntitle: t\n---\n\n- [x] one\n"],
            'paragraph then bare'      => ["text\n[x] task\n"],
            'crlf line endings'        => ["- [ ] one\r\n- [x] two\r\n"],
            'escaped bracket'          => ["\\[ ] not a task\n\n- [x] real\n"],
            'tab after bullet'         => ["-\t[x] tabbed\n"],
            'paragraph closes list'    => ["- [x] a\n\ntext\n\n    [ ] indented\n"],
            'mixed shapes'             => ["1. [ ] one\n\n> [x] quoted\n\n```\n[ ] code\n```\n\n[x] bare\n"],
            // Indentation on the first line is stripped before rendering
            // (render_body() trims the body), so this is a plain list of two
            // checkboxes — not an indented code block followed by one.
            'leading indent'           => ["    - [ ] indented\n\n- [ ] real\n"],
            'leading tab'              => ["\t- [x] tabbed\n\n- [ ] real\n"],
            // A blank line then a deep indent inside a list item is code, even
            // though a shallower indent there would be a nested checkbox.
            'indented code in a list'  => ["- [ ] parent\n\n      - [ ] nested\n"],
            'indented code then task'  => ["- [ ] parent\n\n      - [ ] nested\n\n- [ ] sibling\n"],
        ];
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testTogglingEveryIndexFlipsThatCheckboxAndNoOther(string $body): void
    {
        $states = $this->renderedStates($body);
        $this->assertNotSame([], $states, 'the fixture should render at least one checkbox');

        foreach ($states as $index => $state) {
            $expected = $states;
            $expected[$index] = !$state;

            $toggled = toggle_rendered_checkbox($body, $index, !$state);

            $this->assertNotNull($toggled, "index $index should be toggleable");
            $this->assertSame($expected, $this->renderedStates($toggled), "index $index");
            $this->assertSame(strlen($body), strlen($toggled), 'only the state character changes');
        }
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testNoIndexBeyondTheRenderedBoxesIsAccepted(string $body): void
    {
        $this->assertNull(toggle_rendered_checkbox($body, count($this->renderedStates($body)), true));
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testCandidateOffsetsAreASupersetOfTheRenderedBoxes(string $body): void
    {
        // The search can only find a marker it was offered, so the permissive
        // scan has to cover every marker the renderer might turn into a box.
        $this->assertGreaterThanOrEqual(
            count($this->renderedStates($body)),
            count(candidate_marker_offsets($body))
        );
    }

    public function testBareMarkerIsCountedBeforeListMarkers(): void
    {
        $body = "[ ] alpha\n\n- [ ] beta\n";

        $this->assertSame("[x] alpha\n\n- [ ] beta\n", toggle_rendered_checkbox($body, 0, true));
        $this->assertSame("[ ] alpha\n\n- [x] beta\n", toggle_rendered_checkbox($body, 1, true));
    }

    public function testMarkerInFencedCodeIsNotCounted(): void
    {
        $body = "```\n- [ ] code\n```\n\n- [ ] real\n";

        $this->assertSame("```\n- [ ] code\n```\n\n- [x] real\n", toggle_rendered_checkbox($body, 0, true));
    }

    public function testLabellessMarkerIsNotCounted(): void
    {
        $body = "- [ ] \n- [ ] real\n";

        $this->assertSame("- [ ] \n- [x] real\n", toggle_rendered_checkbox($body, 0, true));
    }

    public function testFrontMatterMarkerIsNotCounted(): void
    {
        $body = "---\ntags:\n  - [ ] odd\n---\n\n- [ ] real\n";

        $this->assertSame(
            "---\ntags:\n  - [ ] odd\n---\n\n- [x] real\n",
            toggle_rendered_checkbox($body, 0, true)
        );
    }

    public function testOrderedAndBlockquotedMarkersAreCounted(): void
    {
        $body = "1. [ ] one\n\n> - [ ] two\n";

        $this->assertSame("1. [x] one\n\n> - [ ] two\n", toggle_rendered_checkbox($body, 0, true));
        $this->assertSame("1. [ ] one\n\n> - [x] two\n", toggle_rendered_checkbox($body, 1, true));
    }

    public function testNegativeIndexIsRefused(): void
    {
        $this->assertNull(toggle_rendered_checkbox("- [ ] one\n", -1, true));
    }

    public function testAMarkerHiddenInIndentedCodeIsNeverRewritten(): void
    {
        // The box is already checked, so there is no state change to search for
        // and every candidate — including the one inside the code block — would
        // satisfy a naive check. The body has to come back untouched.
        $body = "- [x] parent\n\n      - [ ] nested\n";

        $this->assertSame($body, toggle_rendered_checkbox($body, 0, true));
    }

    public function testLeadingIndentDoesNotHideTheWholeList(): void
    {
        // render_body() trims the body, so the four spaces are gone by the time
        // Parsedown sees the line: both markers are checkboxes.
        $body = "    - [ ] indented\n\n- [ ] real\n";

        $this->assertSame("    - [x] indented\n\n- [ ] real\n", toggle_rendered_checkbox($body, 0, true));
        $this->assertSame("    - [ ] indented\n\n- [x] real\n", toggle_rendered_checkbox($body, 1, true));
    }

    public function testDeepIndentInsideAListIsCodeNotANestedCheckbox(): void
    {
        $body = "- [ ] parent\n\n      - [ ] nested\n\n- [ ] sibling\n";

        $this->assertSame(
            "- [ ] parent\n\n      - [ ] nested\n\n- [x] sibling\n",
            toggle_rendered_checkbox($body, 1, true)
        );
        $this->assertNull(toggle_rendered_checkbox($body, 2, true));
    }
}
