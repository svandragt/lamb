<?php

namespace Lamb\Highlight;

use Phiki\Phiki;
use Phiki\Theme\Theme;
use Throwable;

const CODE_BLOCK_PATTERN = '/<pre><code class="language-([\w+-]+)">(.*?)<\/code><\/pre>/s';
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
    $html = preg_replace_callback('/<pre><code[^>]*>.*?<\/code><\/pre>/s', function ($matches) use (&$blocks) {
        $blocks[] = $matches[0];

        return sprintf(PLACEHOLDER_FORMAT, count($blocks) - 1);
    }, $html);

    return [$html, $blocks];
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

/**
 * Syntax-highlights fenced code blocks in rendered post HTML via Phiki.
 *
 * Only blocks carrying a language class (```php → class="language-php") are
 * touched; unknown languages and plain blocks are left as-is, so output is
 * never worse than the unhighlighted original. Highlighting happens once at
 * parse time and is cached in the post's `transformed` column, so visitors
 * pay no runtime cost and no JavaScript is shipped.
 *
 * @param string $html The rendered post HTML.
 *
 * @return string
 */
function highlight_code_blocks(string $html): string
{
    if (!str_contains($html, '<pre><code class="language-')) {
        return $html;
    }

    return preg_replace_callback(CODE_BLOCK_PATTERN, function ($matches) {
        $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        try {
            return (new Phiki())->codeToHtml($code, strtolower($matches[1]), [
                'light' => Theme::GithubLight,
                'dark'  => Theme::GithubDark,
            ])->toString();
        } catch (Throwable) {
            return $matches[0];
        }
    }, $html);
}
