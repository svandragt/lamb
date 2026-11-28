<?php

namespace Lamb\Highlight;

const PLACEHOLDER_FORMAT = '<!--lamb-code-%d-->';

/**
 * Replaces fenced code blocks with placeholder comments so intermediate
 * transforms (e.g. hashtag linking) cannot mangle code content or the
 * inline style attributes added by the highlighter.
 *
 * @param string $html The Parsedown output.
 *
 * @return array{0: string, 1: string[]} HTML with placeholders, and the extracted blocks.
 */
function extract_code_blocks(string $html): array
{
    $blocks = [];
    $result = preg_replace_callback('/<pre><code[^>]*>.*?<\/code><\/pre>/s', function ($matches) use (&$blocks) {
        $blocks[] = $matches[0];

        return sprintf(PLACEHOLDER_FORMAT, count($blocks) - 1);
    }, $html);

    // PCRE failure (e.g. backtrack limit on a huge post) returns null — fall
    // back to the untouched input rather than wiping the post.
    if ($result === null) {
        return [$html, []];
    }

    return [$result, $blocks];
}

/**
 * Restores code blocks extracted by extract_code_blocks().
 *
 * @param string   $html   HTML containing placeholder comments.
 * @param string[] $blocks The blocks to substitute back in.
 *
 * @return string
 */
function restore_code_blocks(string $html, array $blocks): string
{
    foreach ($blocks as $index => $block) {
        $html = str_replace(sprintf(PLACEHOLDER_FORMAT, $index), $block, $html);
    }

    return $html;
}
