<?php

namespace Lamb;

use Parsedown;

class LambDown extends Parsedown
{
    /**
     * Registers the GitHub-style task-list checkbox block.
     *
     * Internalised from leblanc-simon/parsedown-checkbox (which targets
     * ParsedownExtra) and adapted for plain Parsedown. Base Parsedown has no
     * constructor, so this does not call parent::__construct().
     */
    public function __construct()
    {
        array_unshift($this->BlockTypes['['], 'Checkbox');
    }

    /**
     * Renders Markdown to HTML, then numbers each task-list checkbox with a
     * zero-based `data-checkbox-index` in document order.
     *
     * The index is assigned in a final DOM-order pass rather than during block
     * parsing, so the Nth rendered checkbox always maps to the Nth `[ ]`/`[x]`
     * marker in the source — the mapping the toggle endpoint relies on.
     *
     * @param string $text The Markdown source.
     * @return string The rendered HTML with indexed checkboxes.
     */
    public function text($text)
    {
        $html = parent::text($text);

        $index = 0;
        return preg_replace_callback(
            '/<input type="checkbox"/',
            static function (array $m) use (&$index): string {
                return $m[0] . ' data-checkbox-index="' . $index++ . '"';
            },
            $html
        ) ?? $html;
    }

    /**
     * Detects a task-list marker (`[ ] ` or `[x] `) at the start of a line.
     *
     * @param array<string, mixed> $line The current line.
     * @return array<string, mixed>|null A checkbox block, or null when not a task line.
     */
    protected function blockCheckbox($line)
    {
        $text = trim($line['text']);
        $marker = substr($text, 0, 4);
        if ($marker === '[ ] ') {
            return ['handler' => 'checkboxUnchecked', 'text' => substr($text, 4)];
        }
        if ($marker === '[x] ' || $marker === '[X] ') {
            return ['handler' => 'checkboxChecked', 'text' => substr($text, 4)];
        }

        return null;
    }

    /**
     * Task checkboxes are single-line; no continuation.
     *
     * @param array<string, mixed> $block The current block.
     * @return null
     */
    protected function blockCheckboxContinue(array $block)
    {
        return null;
    }

    /**
     * Finalises a checkbox block into raw HTML.
     *
     * @param array<string, mixed> $block The checkbox block.
     * @return array<string, mixed> The completed block.
     */
    protected function blockCheckboxComplete(array $block)
    {
        $block['element'] = [
            'rawHtml'                => $this->{$block['handler']}($block['text']),
            'allowRawHtmlInSafeMode' => true,
        ];

        return $block;
    }

    /**
     * Adds a `task-list-item` class to list items that contain a task marker,
     * after running base Parsedown's loose-list completion.
     *
     * @param array<string, mixed> $block The completed list block.
     * @return array<string, mixed> The block with task-list classes applied.
     */
    protected function blockListComplete(array $block)
    {
        $block = parent::blockListComplete($block);

        foreach ($block['element']['elements'] as &$li) {
            foreach ($li['handler']['argument'] as $text) {
                $marker = substr(trim($text), 0, 4);
                if ($marker === '[ ] ' || $marker === '[x] ' || $marker === '[X] ') {
                    $li['attributes'] = ['class' => 'task-list-item'];
                    break;
                }
            }
        }
        unset($li);

        return $block;
    }

    /**
     * Renders an unchecked task checkbox followed by its inline-formatted label.
     *
     * @param string $text The label text.
     * @return string The checkbox HTML.
     */
    protected function checkboxUnchecked($text)
    {
        if ($this->markupEscaped || $this->safeMode) {
            $text = self::escape($text);
        }

        return '<input type="checkbox" disabled> ' . $this->formatLabel($text);
    }

    /**
     * Renders a checked task checkbox followed by its inline-formatted label.
     *
     * @param string $text The label text.
     * @return string The checkbox HTML.
     */
    protected function checkboxChecked($text)
    {
        if ($this->markupEscaped || $this->safeMode) {
            $text = self::escape($text);
        }

        return '<input type="checkbox" checked disabled> ' . $this->formatLabel($text);
    }

    /**
     * Inline-formats a checkbox label without double-escaping.
     *
     * The label has already been escaped under safe mode, so markup escaping and
     * safe mode are toggled off around the inline pass (then restored), exactly
     * as the upstream extension does.
     *
     * @param string $text The (already escaped) label text.
     * @return string The inline-formatted label.
     */
    protected function formatLabel($text)
    {
        $markupEscaped = $this->markupEscaped;
        $safeMode      = $this->safeMode;

        $this->setMarkupEscaped(false);
        $this->setSafeMode(false);

        $text = $this->line($text);

        $this->setMarkupEscaped($markupEscaped);
        $this->setSafeMode($safeMode);

        return $text;
    }

    /**
     * Determines if the given line is a valid header block in Markdown format.
     *
     * @param array<string, mixed> $Line The line to be checked.
     *
     * @return array<string, mixed>|null Returns the result of the parent's blockHeader method, or null if the line is not a valid header block.
     */
    protected function blockHeader($Line)
    {
        $level = strspn($Line['text'], '#');
        $tag = substr($Line['text'], $level - 1, 2);
        if ($tag !== '# ') {
            return null;
        }

        return parent::blockHeader($Line);
    }

    /**
     * Inject lazy-loading attributes on every inline image so post bodies
     * with embedded screenshots do not block first paint on the homepage.
     *
     * @param array<string, mixed> $Excerpt The inline excerpt to be parsed.
     *
     * @return array<string, mixed>|null The parsed image element, or null when not an image.
     */
    protected function inlineImage($Excerpt)
    {
        $image = parent::inlineImage($Excerpt);
        if (!is_array($image) || !isset($image['element']['attributes'])) {
            return $image;
        }
        $image['element']['attributes'] += [
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];
        return $image;
    }
}
