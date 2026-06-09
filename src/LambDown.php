<?php

namespace Lamb;

use Parsedown;

class LambDown extends Parsedown
{
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
