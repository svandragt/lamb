<?php

global $data;
global $template;

use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;
use function Lamb\Webmention\webmentions_for_post;

// Received webmentions are a private notification for the author, not public
// comments: they are shown to the logged-in author only, by design.
if ($template !== 'status' || !isset($_SESSION[SESSION_LOGIN])) {
    return;
}

$current_id = (int) $data['posts'][0]->id;
$mentions = webmentions_for_post($current_id);

if (empty($mentions)) {
    return;
}
?>
<article class="webmentions">
    <h6>Webmentions</h6>
    <ul>
        <?php foreach ($mentions as $mention) : ?>
            <li>
                <?php if (!empty($mention->author)) : ?>
                    <span class="webmention-author"><?= escape($mention->author) ?></span>
                <?php endif; ?>
                <a href="<?= escape($mention->source) ?>" rel="nofollow ugc">
                    <?= escape($mention->content ?: $mention->source) ?>
                </a>
                <?php if (!empty($mention->verified_at)) : ?>
                    <time datetime="<?= escape($mention->verified_at) ?>"><?= escape(human_time(strtotime($mention->verified_at))) ?></time>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</article>
