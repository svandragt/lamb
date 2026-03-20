<?php

global $config;
global $data;
global $template;

use function Lamb\Config\is_menu_item;
use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;
use function Lamb\Theme\escape;
use function Lamb\Theme\link_source;
use function Lamb\Theme\title_link;

if (empty($data['posts'])) :
    ?>
    <div class="card post">
        <p>Sorry, no items found.</p>
    </div>
<?php else :
    foreach ($data['posts'] as $bean) :
        if ($template !== 'status' && is_menu_item($bean->slug ?? $bean->id)) :
            continue;
        endif;

        $source = link_source($bean);
        $edit = action_edit($bean);
        $delete = action_delete($bean);
        ?>
        <article class="post card">
            <div class="post-head">
                <div class="post-author">
                    <div class="mini-avatar" aria-hidden="true"></div>
                    <div>
                        <span class="post-author-name"><?= escape($config['author_name'] ?? 'Author') ?></span>
                        <?php if (!empty($bean->feed_name)) : ?>
                            <span class="post-author-handle"><?= escape($bean->feed_name) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="post-timestamp"><?= date_created($bean) ?></div>
            </div>

            <?php if (!empty($bean->title)) : ?>
                <h2><?= $template !== 'status' ? title_link($bean) : escape($bean->title) ?></h2>
            <?php endif; ?>

            <div class="post-body">
                <?= $bean->transformed ?>
            </div>

            <?php if ($source) : ?>
                <div class="post-via"><?= $source ?></div>
            <?php endif; ?>

            <?php if ($edit || $delete) : ?>
                <div class="post-actions">
                    <div class="admin-actions">
                        <?= $edit ?>
                        <?= $delete ?>
                    </div>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach;
endif;
