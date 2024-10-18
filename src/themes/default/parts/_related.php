<?php

global $data;
global $template;

use function Lamb\Theme\date_created;
use function Lamb\Theme\related_posts;
use function Lamb\transform;

if ($template !== 'status') {
    return;
}
$body = $data['posts'][0]['body'];
$related_posts = related_posts($body);
$ids = [];

if (!empty($related_posts['posts'])) :
    ?>
    <main>
        <article>
            <h3>Related posts</h3>
            <?php
            foreach ($related_posts['posts'] as $bean) :
                if (in_array($bean->id, $ids, true)) :
                    continue;
                endif;
                if (!isset($bean->title)) :
                    $bean->title = $bean->body;
                endif;
                if (empty($bean->is_menu_item)) :
                    ?>
                    <li><?= date_created($bean) ?> <?= substr(strip_tags($bean->title), 0, 42) . '&hellip;' ?>
                    </li>
                    <?php
                endif;
                $ids[] = $bean->id;
            endforeach;
            ?></article>
    </main>
    <?php
endif;
