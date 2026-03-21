<?php

global $data;
global $template;

use function Lamb\Theme\date_created;
use function Lamb\Theme\escape;
use function Lamb\Theme\related_posts;

if ($template !== 'status') {
    return;
}
$current_id = (int) $data['posts'][0]->id;
$body = $data['posts'][0]->body;
$related_posts = related_posts($body, $current_id);

if (!empty($related_posts['posts'])) :
    ?>
    <main>
        <article>
            <h3>Related posts</h3>
            <ul>
            <?php
            foreach ($related_posts['posts'] as $bean) :
                if (!isset($bean->title)) :
                    $bean->title = $bean->body;
                endif;
                if (empty($bean->is_menu_item)) :
                    ?>
                    <li><?= date_created($bean) ?>
                        <span><?= escape(substr(strip_tags($bean->title), 0, 42)) ?>&hellip;</span>
                        <?php if (!empty($bean->description)) : ?>
                        <p><?= escape($bean->description) ?></p>
                        <?php endif; ?>
                    </li>
                    <?php
                endif;
            endforeach;
            ?>
            </ul>
        </article>
    </main>
    <?php
endif;
