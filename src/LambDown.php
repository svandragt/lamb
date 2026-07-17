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
     * Classifies the leading task-list marker of a line.
     *
     * The single source of truth for what counts as a task marker, shared by
     * block detection and list-item tagging so the two can never diverge.
     *
     * @param string $text The line text (leading/trailing whitespace ignored).
     * @return bool|null True when checked (`[x] `/`[X] `), false when unchecked
     *                   (`[ ] `), null when the line is not a task marker.
     */
    private function checkboxState(string $text): ?bool
    {
        return match (substr(trim($text), 0, 4)) {
            '[ ] '          => false,
            '[x] ', '[X] '  => true,
            default         => null,
        };
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
        $checked = $this->checkboxState($text);
        if ($checked === null) {
            return null;
        }

        return ['checked' => $checked, 'text' => substr($text, 4)];
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
            'rawHtml'                => $this->renderCheckbox($block['text'], $block['checked']),
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
                if ($this->checkboxState($text) !== null) {
                    $li['attributes'] = ['class' => 'task-list-item'];
                    break;
                }
            }
        }
        unset($li);

        return $block;
    }

    /**
     * Renders a disabled task checkbox followed by its inline-formatted label.
     *
     * @param string $text    The label text.
     * @param bool   $checked Whether the box is ticked.
     * @return string The checkbox HTML.
     */
    protected function renderCheckbox($text, $checked)
    {
        if ($this->markupEscaped || $this->safeMode) {
            $text = self::escape($text);
        }

        $checkedAttr = $checked ? ' checked' : '';

        return '<input type="checkbox"' . $checkedAttr . ' disabled> ' . $this->formatLabel($text);
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
     * Uploaded video files reuse the same `![alt](url)` Markdown syntax as
     * images (dropped/uploaded via the same endpoint), so a src ending in a
     * known video extension is rendered as an embedded `<video>` player
     * instead of a broken `<img>`.
     *
     * @param array<string, mixed> $Excerpt The inline excerpt to be parsed.
     *
     * @return array<string, mixed>|null The parsed image or video element, or null when not an image.
     */
    protected function inlineImage($Excerpt)
    {
        $image = parent::inlineImage($Excerpt);
        if (!is_array($image) || !isset($image['element']['attributes']['src'])) {
            return $image;
        }

        $src = $image['element']['attributes']['src'];
        $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?? $src, PATHINFO_EXTENSION));

        if (in_array($ext, VIDEO_UPLOAD_EXTENSIONS, true)) {
            $image['element'] = $this->videoElement($image['element']);
            return $image;
        }

        $image['element']['attributes'] += [
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];
        return $image;
    }

    /**
     * Builds a `<video>` element from a parsed `<img>` element, reusing its
     * attributes (dropping `alt`, which is meaningless on `<video>`).
     *
     * Parsedown's safe mode only auto-applies its src scheme allowlist to
     * elements named `a`/`img` (see Parsedown::sanitiseElement()); renaming
     * the element to `video` bypasses that check, so it is re-applied here
     * explicitly via the inherited (protected) filterUnsafeUrlInAttribute().
     * An explicit empty `text` forces a proper closing tag: `video` is not an
     * HTML5 void element, so the self-closing markup Parsedown would
     * otherwise emit for a childless element would leave the tag unclosed.
     *
     * @param array<string, mixed> $imgElement The `<img>` element from parent::inlineImage().
     * @return array<string, mixed> The `<video>` element.
     */
    private function videoElement(array $imgElement): array
    {
        $attributes = $imgElement['attributes'];
        unset($attributes['alt']);
        $attributes += [
            'controls'    => 'controls',
            'preload'     => 'metadata',
            'playsinline' => 'playsinline',
        ];

        $element = [
            'name'       => 'video',
            'attributes' => $attributes,
            'text'       => '',
        ];

        if ($this->safeMode) {
            $element = $this->filterUnsafeUrlInAttribute($element, 'src');
        }

        return $element;
    }
}
