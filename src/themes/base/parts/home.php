<?php

use function Lamb\Theme\action_edit;
use function Lamb\Theme\anchor_headings;
use function Lamb\Theme\author_card;
use function Lamb\Theme\date_created;
use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\part;
use function Lamb\Theme\site_title;
use function Lamb\Theme\the_entry_form;

global $data;

the_entry_form(); ?>
<?= site_title() ?>
<?= page_intro() ?>

<?php
// The post slugged `home`, shown above the timeline (first page only; see
// Lamb\Response\respond_home()). It is a block of HTML, so it cannot go through
// page_intro(), which wraps its text in a <p>. data-post-id is what task-list
// toggles read, and the h-entry markup matches the post's permalink at `/`.
if (!empty($data['home_post'])) :
    $home_post = $data['home_post'];
    $edit      = action_edit($home_post);
    ?>
<section class="home-post h-entry" data-post-id="<?= (int) $home_post->id ?>">
    <?php if (!empty($home_post->title)) : ?>
    <h2 class="p-name"><?= escape($home_post->title) ?></h2>
    <?php endif; ?>
    <div class="e-content"><?= anchor_headings($home_post->transformed, !empty($home_post->title) ? 3 : 2) ?></div>
    <div class="screen-reader-text"><?= author_card() ?> <?= date_created($home_post) ?></div>
    <?php if ($edit !== '') : ?>
    <small><?= $edit ?></small>
    <?php endif; ?>
</section>
    <?php
endif;

part('_items');
