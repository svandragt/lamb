<?php

global $data;
global $config;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;

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
            <header><strong><?= $config['author_name'] ?></strong> @
                <span><?= date_created($bean) ?></span></header>
            <?= $bean->transformed ?>

            <small><?= link_source($bean) ?> <?= action_edit($bean) ?> <?= action_delete($bean) ?></small>

        </article>
        <?php
    endforeach;
endif;
