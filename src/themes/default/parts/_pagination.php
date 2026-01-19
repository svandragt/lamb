<?php

use function Lamb\Theme\escape;

// Render pagination if available in the page data

$pagination = $data['pagination'] ?? $GLOBALS['data']['pagination'] ?? null;
if (!empty($pagination)) :
    $current = (int)($pagination['current'] ?? 1);
    $totalPages = (int)($pagination['total_pages'] ?? 1);
    if ($totalPages === 1) {
        return;
    }

    // Parse current request URI and preserve other query params
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($uri);
    parse_str($parts['query'] ?? '', $qs);

    $build_url = static function (int $page) use ($parts, $qs): string {
        $qs['page'] = $page;
        $query = http_build_query($qs);
        $path = $parts['path'] ?? '/';
        return $path . ($query ? '?' . $query : '');
    };
    ?>
    <nav class="pagination" role="navigation" aria-label="Pagination">
        <?php if (!empty($pagination['prev_page'])) : ?>
            <a class="prev" href="<?= escape($build_url((int)$pagination['prev_page'])) ?>" rel="prev">« Newer</a>
        <?php endif; ?>

        <span class="pages" aria-hidden="false">
            <?php
                $ellips = false;
            for ($i = 1; $i <= $totalPages; $i++) :
                // Define which pages to show: first two, last two, and current
                $should_show = ($i <= 2 || $i > $totalPages - 2 || $i === $current);

                if ($should_show) :
                    $ellips = false; // Reset ellipsis for next gap
                    if ($i === $current) : ?>
                            <span class="current" aria-current="page"><?= escape((string)$i) ?></span>
                    <?php else : ?>
                            <a href="<?= escape($build_url($i)) ?>"><?= escape((string)$i) ?></a>
                    <?php endif;
                elseif (!$ellips) :
                        // Show ellipsis once for the gap
                        echo '<span class="gap">…</span>';
                        $ellips = true;
                endif;
            endfor;
            ?>
            </span>

            <?php if (!empty($pagination['next_page'])) : ?>
            <a class="next" href="<?= escape($build_url((int)$pagination['next_page'])) ?>" rel="next">Older »</a>
            <?php endif; ?>
    </nav>
    <?php
endif;