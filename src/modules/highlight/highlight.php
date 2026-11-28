<?php

namespace Lamb\Highlight;

use Phiki\Phiki;
use Phiki\Theme\Theme;
use Throwable;

const CODE_BLOCK_PATTERN = '/<pre><code class="language-([\w+-]+)">(.*?)<\/code><\/pre>/s';

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

    $result = preg_replace_callback(CODE_BLOCK_PATTERN, function ($matches) {
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

    // PCRE failure (e.g. backtrack limit on a huge post) returns null — output
    // is never worse than the unhighlighted original.
    return $result ?? $html;
}
