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

    // Derive the base list path from the current request, stripping any existing
    // /page/N segment and the legacy ?page= query so links stay clean paths.
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $base = rtrim((string)preg_replace('#/page/\d+/?$#', '', $path), '/');

    $build_url = static function (int $page) use ($base): string {
        if ($page <= 1) {
            return $base === '' ? '/' : $base;
        }
        return $base . '/page/' . $page;
    };
    ?>
    <nav class="pagination" aria-label="Pagination">
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