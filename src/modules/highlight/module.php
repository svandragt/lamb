<?php

/**
 * Syntax highlighting: highlights fenced code blocks with Phiki when a post is
 * rendered. Without this module, code blocks render as plain text.
 */

namespace Lamb\Highlight;

use Lamb\Hooks;

require_once __DIR__ . '/highlight.php';

return static function (): void {
    Hooks\add_filter('render.code_block', __NAMESPACE__ . '\\highlight_code_blocks');
};
