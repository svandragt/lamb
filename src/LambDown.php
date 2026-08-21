<?php

namespace Lamb;

use Parsedown;

class LambDown extends Parsedown
{
    /**
     * Resolves an image `src` to its pixel dimensions, or null when unknown.
     *
     * @var (callable(string): (array{0:int,1:int}|null))|null
     */
    private $imageSizeResolver = null;

    /**
     * The checked state of each checkbox the last text() call rendered, in
     * document order.
     *
     * @var list<bool>
     */
    private array $checkboxStates = [];

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

        $this->checkboxStates = [];
        $index = 0;
        return preg_replace_callback(
            '/<input type="checkbox"( checked)?/',
            function (array $m) use (&$index): string {
                $checked = $m[1] ?? '';
                $this->checkboxStates[] = $checked !== '';
                return '<input type="checkbox" data-checkbox-index="' . $index++ . '"' . $checked;
            },
            $html
        ) ?? $html;
    }

    /**
     * The checked state of every checkbox the last text() call rendered, in the
     * same order as their `data-checkbox-index` values.
     *
     * Recorded by the renderer itself so callers that need to map a rendered
     * checkbox back to the source (the task-list toggle endpoint) can check
     * their own reading of the Markdown against what was actually rendered,
     * rather than re-deriving it from the HTML.
     *
     * @return list<bool>
     */
    public function renderedCheckboxStates(): array
    {
        return $this->checkboxStates;
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
     * The label goes through line() with safe mode left ON, and is not escaped
     * beforehand. The upstream extension this was internalised from pre-escaped
     * the text and then switched safe mode off around the inline pass — which
     * turned off more than the escaping it was compensating for. Safe mode is
     * also what applies Parsedown's URL-scheme allowlist (sanitiseElement()),
     * so with it off a link or image *inside a label* kept whatever scheme it
     * was given:
     *
     *     [ ] [click](javascript:alert(1))
     *     → <input type="checkbox" disabled> <a href="javascript:alert(1)">click</a>
     *
     * while the same link outside a label correctly became `javascript%3A`.
     * That is not only the author's own body: a subscribed feed's item
     * description reaches the renderer (attributed_content() strips HTML tags
     * but leaves Markdown, and prefixes each line with `> `, which still parses
     * as a task item inside the blockquote), as does a Micropub client holding
     * only `create` scope — so it was remotely triggerable stored XSS, served
     * to every visitor from the post's cached `transformed` column.
     *
     * Dropping the pre-escape also fixes what it broke on the way past: `&` in
     * a label's link URL was escaped once by hand and once more as an attribute
     * value, so `?b=1&c=2` was emitted as `?b=1&amp;amp;c=2` and the link
     * pointed at a URL with a literal `&amp;` in it. line() escapes the label's
     * own text by itself, exactly once, the way every other inline text does.
     *
     * @param string $text    The label text.
     * @param bool   $checked Whether the box is ticked.
     * @return string The checkbox HTML.
     */
    protected function renderCheckbox($text, $checked)
    {
        $checkedAttr = $checked ? ' checked' : '';

        return '<input type="checkbox"' . $checkedAttr . ' disabled> ' . $this->line($text);
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
     * Supplies the lookup used to stamp intrinsic `width`/`height` on images.
     *
     * Injected rather than looked up here so the parser stays a pure
     * string→string transform: it needs an image's pixel size but has no
     * business knowing that uploads live under ROOT_DIR/assets, and unit tests
     * can pass a stub instead of writing real files. Callers wire in
     * Response\asset_dimensions(); with no resolver set, no dimensions are
     * emitted and the markup is unchanged.
     *
     * @param (callable(string): (array{0:int,1:int}|null))|null $resolver
     *        Receives an image `src`, returns [width, height] or null.
     * @return void
     */
    public function setImageSizeResolver(?callable $resolver): void
    {
        $this->imageSizeResolver = $resolver;
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

        $image['element']['attributes'] += $this->intrinsicDimensions($src) + [
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];
        return $image;
    }

    /**
     * The `width`/`height` attributes for an image, or none when its size is
     * unknown.
     *
     * These are what let the browser reserve the right box before the image
     * arrives — without them, `loading="lazy"` above guarantees every image in
     * a post shifts the text below it as it decodes. They describe the
     * intrinsic pixel size, and the themes' `img { max-width: 100%; height:
     * auto; }` keeps CSS in charge of the rendered size.
     *
     * @param string $src The image's `src` attribute.
     * @return array<string, string> `width`/`height`, or an empty array.
     */
    private function intrinsicDimensions(string $src): array
    {
        if ($this->imageSizeResolver === null) {
            return [];
        }

        $size = ($this->imageSizeResolver)($src);
        if ($size === null) {
            return [];
        }

        return [
            'width'  => (string) (int) $size[0],
            'height' => (string) (int) $size[1],
        ];
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
