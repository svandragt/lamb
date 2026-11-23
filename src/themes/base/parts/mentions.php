<?php

global $data;

use function Lamb\permalink_path;
use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;
use function Lamb\Theme\page_title;

echo page_title();

if (empty($data['mentions'])) :
    ?><p>Sorry no items found.</p>
    <?php
else :
    ?>
<ul class="mentions">
    <?php foreach ($data['mentions'] as $entry) : ?>
        <?php
        /** @var \RedBeanPHP\OODBBean $mention */
        $mention = $entry['mention'];
        $post = $entry['post'];
        ?>
        <li>
        <?php if (!empty($mention->author)) : ?>
            <span class="webmention-author"><?= escape($mention->author) ?></span>
        <?php endif; ?>
            <a href="<?= escape($mention->source) ?>" rel="nofollow ugc">
                <?= escape($mention->content ?: $mention->source) ?>
            </a>
            <time datetime="<?= escape($mention->verified_at) ?>"><?= escape(human_time(strtotime($mention->verified_at))) ?></time>
            <small><a href="<?= escape(permalink_path($post)) ?>">On this post</a></small>
        </li>
    <?php endforeach; ?>
</ul>
    <?php
endif;
