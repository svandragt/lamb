<?php


global $data;
global $config;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;
use function Lamb\Post\get_paginated_posts;

if (empty($data['posts'])) :
    ?><p>Sorry no items found.</p>
    <?php
else :
    foreach ($data['posts'] as $bean) :
        if (is_menu_item($bean->is_menu_item ?? $bean->id)) :
            continue;
        endif;
        ?>
        <article>
            <?= $bean->transformed ?>

            <small><?= date_created($bean) ?><?= link_source($bean) ?> <?= action_edit($bean) ?> <?= action_delete($bean) ?></small>
        </article>
        <?php
    endforeach;
endif;

// Pagination controls

$page = $_GET['page'] ?? 1;
$page = max(1, (int)$page);


$per_page = $config['posts_per_page'] ?? 25;
[$posts, $total_pages] = get_paginated_posts($page, $per_page);

if ($total_pages > 1) {
    echo '<nav class="pagination">';
    if ($page > 1) {
        echo '<a href="?page=' . ($page - 1) . '">Previous</a> ';
    }
    echo 'Page ' . $page . ' of ' . $total_pages;
    if ($page < $total_pages) {
        echo ' <a href="?page=' . ($page + 1) . '">Next</a>';
    }
    echo '</nav>';
}
