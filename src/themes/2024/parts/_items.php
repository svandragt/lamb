<?php

global $data;
global $config;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;

if (empty($data['items'])) :
    ?><p>Sorry no items found.</p>
    <?php
else :
    foreach ($data['items'] as $item) :
        if (empty($item['is_menu_item'])) :
            ?>
            <article>
                <header><strong><?= $config['author_name'] ?></strong> @
                    <span><?= date_created($item); ?></span></header>
                <?= Lamb\parse_tags($item['body']); ?>

                <?php
                if (isset($_SESSION[SESSION_LOGIN])) : ?>
                    <footer><?= action_edit($item); ?> <?= action_delete($item); ?></footer>
                    <?php
                endif; ?>
            </article>
            <?php
        endif;
    endforeach;
endif;
