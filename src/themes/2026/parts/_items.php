<?php

use function Lamb\Theme\render_post_list;

// 2026 hides the per-post author visually, keeping it for crawlers via
// screen-reader-text (see the 2024 theme's inline variant). The shared
// loop/markup live in Lamb\Theme\render_post_list().
render_post_list(true);
