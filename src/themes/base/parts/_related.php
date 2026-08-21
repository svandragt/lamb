<?php

global $data;
global $template;

use function Lamb\Theme\date_created;
use function Lamb\Theme\escape;
use function Lamb\Theme\related_posts;

if ($template !== 'status') {
    return;
}
$current_id = (int) $data['posts'][0]->id;
$body = $data['posts'][0]->body;
$related_posts = related_posts($body, $current_id);

if (!empty($related_posts['posts'])) :
    ?>
        <article class="related-posts">
            <h6>Related</h6>
            <ul>
            <?php
            foreach ($related_posts['posts'] as $bean) :
                if (!isset($bean->title)) :
                    $bean->title = '';
                endif;
                if (empty($bean->is_menu_item)) :
                    ?>
                    <li>
                        <?php if (!empty($bean->title)) : ?>
                            <?php // mb_strimwidth(), as the 2026 theme's own _related.php uses:
                                  // substr() cuts on bytes, so any title in a script whose
                                  // characters are multi-byte was truncated mid-sequence and
                                  // rendered a U+FFFD, and only got half as many characters as
                                  // a Latin one. It also appends the ellipsis only when it
                                  // actually trims — the literal &hellip; was emitted even for
                                  // a title that fit.
                            ?>
                            <span><?= escape(mb_strimwidth(strip_tags($bean->title), 0, 42, '…')) ?></span>
                        <?php endif; ?>
                        <p><?= date_created($bean) ?>
                        <?php if (!empty($bean->transformed)) : ?>
                            <?= $bean->transformed ?>
                        <?php endif; ?>
                        </p>
                    </li>
                    <?php
                endif;
            endforeach;
            ?>
            </ul>
        </article>
    <?php
endif;
