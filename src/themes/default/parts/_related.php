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
        <article class="related-posts">
            <h6>Related</h6>
            <ul>
            <?php
            foreach ($related_posts['posts'] as $bean) :
                if (!isset($bean->title)) :
                    $bean->title = '';
                endif;
                if (empty($bean->is_menu_item)) :
                    ?>
                    <li>
                        <?php if (!empty($bean->title)) : ?>
                            <span><?= escape(substr(strip_tags($bean->title), 0, 42)) ?>&hellip;</span>
                        <?php endif; ?>
                        <p><?= date_created($bean) ?>
                        <?php if (!empty($bean->transformed)) : ?>
                            <?= $bean->transformed ?>
                        <?php endif; ?>
                        </p>
                    </li>
                    <?php
                endif;
            endforeach;
            ?>
            </ul>
        </article>
    <?php
endif;
