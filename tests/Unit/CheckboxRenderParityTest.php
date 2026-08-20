<?php

namespace Tests\Unit;

use Lamb\LambDown;
use PHPUnit\Framework\TestCase;

use function Lamb\Post\checkbox_marker_offsets;
use function Lamb\Post\split_frontmatter;
use function Lamb\Post\toggle_checkbox;

/**
 * The toggle endpoint addresses a checkbox by its *rendered* position
 * (`data-checkbox-index`), so the markers checkbox_marker_offsets() counts in
 * the source have to be exactly the checkboxes LambDown renders — same count,
 * same order, same states. Where the two disagreed, clicking one box silently
 * rewrote a different one.
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
     * The `checked` state of every marker the toggle scanner finds, in order.
     *
     * @return list<bool>
     */
    private function scannedStates(string $body): array
    {
        return array_map(
            static fn(int $offset): bool => strtolower($body[$offset]) === 'x',
            checkbox_marker_offsets($body)
        );
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
        ];
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testScannerMatchesRenderer(string $body): void
    {
        $this->assertSame($this->renderedStates($body), $this->scannedStates($body));
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testTogglingEveryIndexFlipsThatCheckbox(string $body): void
    {
        $states = $this->renderedStates($body);

        foreach ($states as $index => $state) {
            $expected = $states;
            $expected[$index] = !$state;

            $toggled = toggle_checkbox($body, $index, !$state);

            $this->assertSame($expected, $this->renderedStates($toggled), "index $index");
            $this->assertSame(strlen($body), strlen($toggled), 'only the state character changes');
        }
    }

    public function testBareMarkerIsCountedBeforeListMarkers(): void
    {
        $body = "[ ] alpha\n\n- [ ] beta\n";

        $this->assertSame("[x] alpha\n\n- [ ] beta\n", toggle_checkbox($body, 0, true));
        $this->assertSame("[ ] alpha\n\n- [x] beta\n", toggle_checkbox($body, 1, true));
    }

    public function testMarkerInFencedCodeIsNotCounted(): void
    {
        $body = "```\n- [ ] code\n```\n\n- [ ] real\n";

        $this->assertSame("```\n- [ ] code\n```\n\n- [x] real\n", toggle_checkbox($body, 0, true));
    }

    public function testLabellessMarkerIsNotCounted(): void
    {
        $body = "- [ ] \n- [ ] real\n";

        $this->assertSame("- [ ] \n- [x] real\n", toggle_checkbox($body, 0, true));
    }

    public function testFrontMatterMarkerIsNotCounted(): void
    {
        $body = "---\ntags:\n  - [ ] odd\n---\n\n- [ ] real\n";

        $this->assertSame("---\ntags:\n  - [ ] odd\n---\n\n- [x] real\n", toggle_checkbox($body, 0, true));
    }

    public function testOrderedAndBlockquotedMarkersAreCounted(): void
    {
        $body = "1. [ ] one\n\n> - [ ] two\n";

        $this->assertSame("1. [x] one\n\n> - [ ] two\n", toggle_checkbox($body, 0, true));
        $this->assertSame("1. [ ] one\n\n> - [x] two\n", toggle_checkbox($body, 1, true));
    }

    public function testNegativeIndexIsNoOp(): void
    {
        $body = "- [ ] one\n";

        $this->assertSame($body, toggle_checkbox($body, -1, true));
    }
}
