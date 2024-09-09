<?php

global $data;
global $template;

use function Lamb\Theme\date_created;
use function Lamb\Theme\related_posts;
use function Lamb\transform;

if ($template !== 'status') {
    return;
}
$body = $data['items'][0]['body'];
$related_posts = related_posts($body);
$data = transform($related_posts);
$ids = [];

if (! empty($data['items'])) :
    ?>
    <main>
        <article>
            <h3>Related posts</h3>
            <?php
            foreach ($data['items'] as $item) :
                if (in_array($item['id'], $ids, true)) :
                    continue;
                endif;
                if (! isset($item['title'])) :
                    $item['title'] = $item['body'];
                endif;
                if (empty($item['is_menu_item'])) :
                    ?>
                    <li><?= date_created($item) ?> <?= substr(strip_tags($item['title']), 0, 42) . '&hellip;' ?>
                    </li>
                    <?php
                endif;
                $ids[] = $item['id'];
            endforeach;
            ?></article>
    </main>
    <?php
endif;
