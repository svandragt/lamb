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
    ?><p>No posts found.</p>
    <?php
else :
    foreach ($data['posts'] as $bean) :
        if ($template !== 'status' && is_menu_item($bean->slug ?? $bean->id)) :
            continue;
        endif;
        ?>

        <article>
            <header>
                <?php if ($template !== 'status' && !empty($bean->title)) : ?>
                    <h2><?= $bean->title ?></h2>
                <?php endif; ?>
                <div class="meta">
                    <?= date_created($bean) ?>
                </div>
            </header>
            <div class="entry-content">
                <?= $bean->transformed ?>
            </div>
            <?php if (link_source($bean) || action_edit($bean) || action_delete($bean)) : ?>
            <footer>
                <?= link_source($bean) ?>
                <?= action_edit($bean) ?>
                <?= action_delete($bean) ?>
            </footer>
            <?php endif; ?>
        </article>

        <?php
    endforeach;
endif;
