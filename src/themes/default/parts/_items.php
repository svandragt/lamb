<?php

global $data;
global $template;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;
use function Lamb\Theme\title_link;

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
            <header>
                <?php if (!empty($bean->title)) : ?>
                <h2><?= $template !== 'status' ? title_link($bean) : $bean->title ?></h2>
                <?php endif; ?>
                <small><?= date_created($bean) ?><?= link_source($bean) ?></small>
            </header>
            <?= $bean->transformed ?>
            <footer>
                <small><?= action_edit($bean) ?> <?= $bean->deleted ? '' : action_delete($bean) ?></small>
            </footer>
        </article>
        <?php
    endforeach;
endif;
