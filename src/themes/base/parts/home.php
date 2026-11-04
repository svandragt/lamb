<?php

use function Lamb\Theme\action_edit;
use function Lamb\Theme\anchor_headings;
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
// page_intro(), which wraps its text in a <p>.
if (!empty($data['home_post'])) :
    $home_post = $data['home_post'];
    $edit      = action_edit($home_post);
    ?>
<section class="home-post">
    <?php if (!empty($home_post->title)) : ?>
    <h2><?= escape($home_post->title) ?></h2>
    <?php endif; ?>
    <div class="e-content"><?= anchor_headings($home_post->transformed, !empty($home_post->title) ? 3 : 2) ?></div>
    <?php if ($edit !== '') : ?>
    <small><?= $edit ?></small>
    <?php endif; ?>
</section>
    <?php
endif;

part('_items');
