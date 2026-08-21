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
use function Lamb\Theme\syndication_links;
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
            # Backstop for the owner-only views that query everything (drafts,
            # trash, scheduled); public listings exclude menu pages in SQL.
            continue;
        endif;
        if (count($data['posts']) > 1) :
            echo '<li>';
        endif;

        ?>

        <article class="h-entry" data-post-id="<?= (int) $bean->id ?>" itemscope itemtype="https://schema.org/BlogPosting">
            <header>
                <?php // On a post page the h1 already shows the title, and the
                      // stylesheet hides this h2 — but the h-entry still needs a
                      // p-name, so the element is emitted and hidden rather than
                      // skipped. Same expression as the base theme.
                ?>
                <?php if (!empty($bean->title)) : ?>
                    <h2><?= $template !== 'status' ? title_link($bean) : '<span class="p-name">' . escape($bean->title) . '</span>' ?></h2>
                <?php endif; ?>
                <div class="meta">
                    <span itemprop="author" class="screen-reader-text"><?= author_card() ?></span>
                    <?= date_created($bean) ?>
                </div>
            </header>
            <?= the_reply_context($bean) ?>
            <?php // List view renders the post title at h2, so the body's top heading sits at h3; otherwise h2 under the site h1. ?>
            <div class="e-content"><?= anchor_headings($bean->transformed, ($template !== 'status' && !empty($bean->title)) ? 3 : 2) ?></div>
            <?= syndication_links($bean) ?>

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
