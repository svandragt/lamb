<?php

global $data;
global $config;
global $template;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\action_restore;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;
use function Lamb\Theme\title_link;

if (empty($data['posts'])) :
    ?><p>Sorry no items found.</p>
    <?php
else :
    if (count($data['posts']) > 1) :
        echo '<ul>';
    endif;
    foreach ($data['posts'] as $bean) :
        if ($template !== 'status' && is_menu_item($bean->slug ?? $bean->id)) :
            # Hide from timeline
            continue;
        endif;
        if (count($data['posts']) > 1) :
            echo '<li>';
        endif;

        ?>

        <article>
            <header>
                <?php if ($template !== 'status' && !empty($bean->title)) : ?>
                    <h2><?= title_link($bean) ?></h2>
                <?php endif; ?>
                <div class="meta">
                    <strong itemprop="author"><?= $config['author_name'] ?></strong> @
                    <?= date_created($bean) ?>
                </div>
            </header>
            <?= $bean->transformed ?>

            <small><?= link_source($bean) ?> <?= action_edit($bean) ?> <?= $bean->deleted ? action_restore($bean) : action_delete($bean) ?></small>
        </article>
        <?php
        if (count($data['posts']) > 1) :
            echo '</li>';
        endif;
    endforeach;
    if (count($data['posts']) > 1) :
        echo '</ul>';
    endif;
endif;
