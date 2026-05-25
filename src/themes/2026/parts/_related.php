<?php

global $data;
global $template;

use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;
use function Lamb\Theme\related_posts;
use function Lamb\get_tags;

if ($template !== 'status') {
    return;
}

$current = $data['posts'][0] ?? null;
if ($current === null) {
    return;
}

$current_id = (int) $current->id;
$body = (string) $current->body;
$related = related_posts($body, $current_id)['posts'] ?? [];

// Hide menu items from the related list.
$related = array_values(array_filter($related, static function ($bean) {
    return empty($bean->is_menu_item);
}));

if (empty($related)) {
    return;
}

$cap = 5;
$overflow = count($related) > $cap;
$shown = array_slice($related, 0, $cap);

// Pick the first tag from the current post to use as the overflow link target.
$tags = get_tags($body);
$primary_tag = $tags[0] ?? null;

?>
<article class="related-posts">
    <h6>Related</h6>
    <ul class="related-list">
        <?php foreach ($shown as $bean) : ?>
            <?php
            $permalink = '/' . ltrim(!empty($bean->slug) ? $bean->slug : "status/{$bean->id}", '/');
            $title = trim(strip_tags($bean->title ?? ''));
            $excerpt = trim(strip_tags($bean->description ?? ''));
            // If the post has no title, promote a short excerpt to the title slot.
            if ($title === '' && $excerpt !== '') {
                $title = mb_strimwidth($excerpt, 0, 90, '…');
                $excerpt = '';
            }
            $human = isset($bean->created) ? human_time(strtotime((string) $bean->created)) : '';
            ?>
            <li class="related-item">
                <?php if ($human !== '') : ?>
                    <time class="related-time" datetime="<?= escape((string) $bean->created) ?>"><?= escape($human) ?></time>
                <?php endif; ?>
                <p class="related-title"><a href="<?= escape($permalink) ?>"><?= escape($title !== '' ? $title : 'Untitled note') ?></a></p>
                <?php if ($excerpt !== '') : ?>
                    <p class="related-excerpt"><?= escape(mb_strimwidth($excerpt, 0, 140, '…')) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($overflow && $primary_tag !== null) : ?>
        <p class="related-more"><a href="/tag/<?= escape(strtolower($primary_tag)) ?>">More in #<?= escape($primary_tag) ?> →</a></p>
    <?php endif; ?>
</article>
