<?php

global $data;
global $config;
global $template;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\action_preview;
use function Lamb\Theme\action_restore;
use function Lamb\Theme\author_card;
use function Lamb\Theme\date_created;
use function Lamb\Theme\anchor_headings;
use function Lamb\Theme\escape;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;
use function Lamb\Theme\the_reply_context;
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

        <article class="h-entry" data-post-id="<?= (int) $bean->id ?>" itemscope itemtype="https://schema.org/BlogPosting">
            <header>
                <?php if ($template !== 'status') : ?>
                    <?php $title = title_link($bean); ?>
                    <?php if (!empty(trim(strip_tags($title)))) : ?>
                        <h2><?= $title ?></h2>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="meta">
                    <span itemprop="author"><?= author_card() ?></span> @
                    <?= date_created($bean) ?>
                </div>
            </header>
            <?= the_reply_context($bean) ?>
            <?php // List view renders the post title at h2, so the body's top heading sits at h3; otherwise h2 under the site h1. ?>
            <div class="e-content"><?= anchor_headings($bean->transformed, ($template !== 'status' && !empty($bean->title)) ? 3 : 2) ?></div>

            <?php if (isset($_SESSION[SESSION_LOGIN])) : ?>
                <small><?= link_source($bean) ?> <?= action_preview($bean) ?> <?= action_edit($bean) ?> <?= $bean->deleted ? action_restore($bean) : action_delete($bean) ?></small>
            <?php endif; ?>
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
