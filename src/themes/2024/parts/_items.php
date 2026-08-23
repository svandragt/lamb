<?php

use function Lamb\Theme\render_post_list;

// 2024 shows the per-post author inline (see the 2026 theme's screen-reader-
// only variant). The shared loop/markup live in Lamb\Theme\render_post_list().
render_post_list(false);
