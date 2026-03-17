<?php

global $data;
global $config;
global $template;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;
use function Lamb\Config\is_menu_item;
use function Lamb\Theme\link_source;

if (empty($data['posts'])) :
    ?><p>Sorry, no items found.</p>
    <?php
else :
    // On the home page the first post is shown as a hero; render the rest as cards
    $posts = $data['posts'];
    $is_home = ($template === 'home');

    if ($is_home && count($posts) > 0) {
        // Hero: first non-menu-item post
        $hero = null;
        $hero_index = null;
        foreach ($posts as $i => $bean) {
            if (!is_menu_item($bean->slug ?? $bean->id)) {
                $hero = $bean;
                $hero_index = $i;
                break;
            }
        }

        if ($hero !== null) :
            $slug = !empty($hero->slug) ? $hero->slug : "status/$hero->id";
            ?>
            <article class="news-hero">
                <div class="hero-text">
                    <?php if (!empty($hero->title)) : ?>
                        <h2><a href="/<?= ltrim($slug, '/') ?>"><?= $hero->title ?></a></h2>
                    <?php endif; ?>
                    <div class="article-body">
                        <?= $hero->transformed ?>
                    </div>
                    <div class="article-meta">
                        <?= date_created($hero) ?>
                        <?php if (!empty($hero->feed_name)) : ?>
                            <span class="via-source"><?= link_source($hero) ?></span>
                        <?php endif; ?>
                        <?php
                        $edit   = action_edit($hero);
                        $delete = action_delete($hero);
                        if ($edit || $delete) : ?>
                            <span class="article-actions"><?= $edit ?> <?= $delete ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php
            // Remove hero from remaining posts
            unset($posts[$hero_index]);
        endif;
    }

    // Render remaining posts as a card grid
    $remaining = array_values($posts);
    if (!empty($remaining)) :
        ?><div class="news-grid"><?php
foreach ($remaining as $bean) :
    if ($template !== 'status' && is_menu_item($bean->slug ?? $bean->id)) :
        continue;
    endif;

    $slug = !empty($bean->slug) ? $bean->slug : "status/$bean->id";
    ?>
            <article>
                <header>
            <?php if (!empty($bean->title)) : ?>
                        <h2><a href="/<?= ltrim($slug, '/') ?>"><?= $bean->title ?></a></h2>
            <?php endif; ?>
                </header>
                <div class="article-body">
            <?= $bean->transformed ?>
                </div>
                <div class="article-meta">
            <?= date_created($bean) ?>
            <?php if (!empty($bean->feed_name)) : ?>
                        <span class="via-source"><?= link_source($bean) ?></span>
            <?php endif; ?>
            <?php
            $edit   = action_edit($bean);
            $delete = action_delete($bean);
            if ($edit || $delete) : ?>
                        <span class="article-actions"><?= $edit ?> <?= $delete ?></span>
            <?php endif; ?>
                </div>
            </article>
            <?php
endforeach;
?></div><?php
    endif;
endif;
