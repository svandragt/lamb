<?php

global $config;
global $data;
global $template;

use function Lamb\Microblog\profile_url;
use function Lamb\Microblog\replies_for_post;
use function Lamb\Microblog\username;
use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;

// Replies fetched from Micro.blog by /_cron (docs/microblog-replies.md).
// Unlike received webmentions these are public: readers see them too.
if ($template !== 'status' || empty($data['posts'])) {
    return;
}

$username = username($config ?? []);
$post = $data['posts'][0];
if ($username === null || !\Lamb\is_publicly_visible($post)) {
    return;
}

$replies = replies_for_post((int) $post->id);
?>
<section class="microblog-replies"<?= empty($replies) ? '' : ' aria-labelledby="microblog-replies-title"' ?>>
    <?php if (!empty($replies)) : ?>
        <h2 id="microblog-replies-title">Replies</h2>
        <ol>
            <?php foreach ($replies as $reply) : ?>
                <li class="h-cite">
                    <p class="microblog-reply__meta">
                        <?php if (!empty($reply->author_url)) : ?>
                            <a class="p-author h-card" href="<?= escape($reply->author_url) ?>" rel="nofollow ugc"><?= escape($reply->author_name) ?></a>
                        <?php else : ?>
                            <span class="p-author h-card"><?= escape($reply->author_name) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($reply->published)) : ?>
                            <a class="u-url" href="<?= escape($reply->url) ?>" rel="nofollow ugc"><time class="dt-published" datetime="<?= escape(date('c', (int) strtotime($reply->published))) ?>"><?= escape(human_time((int) strtotime($reply->published))) ?></time></a>
                        <?php else : ?>
                            <a class="u-url" href="<?= escape($reply->url) ?>" rel="nofollow ugc">View reply</a>
                        <?php endif; ?>
                    </p>
                    <p class="p-content"><?= escape($reply->content) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <p class="microblog-replies__reply"><a href="<?= escape(profile_url($username)) ?>">Reply on Micro.blog</a></p>
</section>
