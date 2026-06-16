<?php

global $data;
global $template;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\action_preview;
use function Lamb\Theme\action_restore;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\anchor_headings;
use function Lamb\Theme\author_card;
use function Lamb\Theme\escape;
use function Lamb\Theme\link_source;
use function Lamb\Theme\the_reply_context;
use function Lamb\Theme\title_link;

if (empty($data['posts'])) :
    ?><p>Sorry no items found.</p>
    <?php
else :
    foreach ($data['posts'] as $bean) :
        /** @var \RedBeanPHP\OODBBean $bean */
        if (is_menu_item($bean->is_menu_item ?? $bean->id)) :
            continue;
        endif;
        ?>
        <article class="h-entry" data-post-id="<?= (int) $bean->id ?>">
            <header>
                <?php if (!empty($bean->title)) : ?>
                <h2><?= $template !== 'status' ? title_link($bean) : '<span class="p-name">' . escape($bean->title) . '</span>' ?></h2>
                <?php endif; ?>
                <small><span class="screen-reader-text"><?= author_card() ?></span><?= date_created($bean) ?><?= link_source($bean) ?></small>
            </header>
            <?= the_reply_context($bean) ?>
            <?php // Post title renders at h2, so the body's top heading sits at h3 (h2 under the site h1 when untitled). ?>
            <div class="e-content"><?= anchor_headings($bean->transformed, !empty($bean->title) ? 3 : 2) ?></div>
            <footer>
                <small><?= action_preview($bean) ?> <?= action_edit($bean) ?> <?= $bean->deleted ? action_restore($bean) : action_delete($bean) ?></small>
            </footer>
        </article>
        <?php
    endforeach;
endif;
