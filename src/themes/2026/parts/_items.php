<?php

global $data;
global $template;

use function Lamb\is_deleted;
use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\action_preview;
use function Lamb\Theme\action_restore;
use function Lamb\Theme\anchor_headings;
use function Lamb\Theme\author_card;
use function Lamb\Theme\date_created;
use function Lamb\Theme\escape;
use function Lamb\Theme\is_hidden_menu_item;
use function Lamb\Theme\link_source;
use function Lamb\Theme\syndication_links;
use function Lamb\Theme\the_reply_context;
use function Lamb\Theme\title_link;

if (empty($data['posts'])) :
    // Search and tag pages set $data['intro'] ("No results found.") which
    // already states the empty case; skip our own message then so the two
    // don't double up.
    if (empty($data['intro'])) :
        ?><p>Sorry no items found.</p>
    <?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- leading whitespace here is literal output
    endif;
else :
    // Wrap the list in <ul>/<li> only when there is more than one post, so a
    // single post renders as a bare <article>. Computed once; the menu-item
    // skip below uses `continue`, so skipped menu pages still count.
    $is_list = count($data['posts']) > 1;
    if ($is_list) :
        echo '<ul>';
    endif;
    foreach ($data['posts'] as $bean) :
        /** @var \RedBeanPHP\OODBBean $bean */
        if (is_hidden_menu_item($template, $bean)) :
            continue;
        endif;
        if ($is_list) :
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
                <small><?= link_source($bean) ?> <?= action_preview($bean) ?> <?= action_edit($bean) ?> <?= is_deleted($bean) ? action_restore($bean) : action_delete($bean) ?></small>
            <?php endif; ?>
        </article>
        <?php
        if ($is_list) :
            echo '</li>';
        endif;
    endforeach;
    if ($is_list) :
        echo '</ul>';
    endif;
endif;
