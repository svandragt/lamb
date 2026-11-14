<?php

use function Lamb\Theme\render_post_list;

// 2026 hides the per-post author visually, keeping it for crawlers via
// screen-reader-text. The shared loop/markup live in Lamb\Theme\render_post_list().
render_post_list(true);
