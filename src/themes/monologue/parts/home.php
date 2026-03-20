<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\part;
use function Lamb\Theme\the_entry_form;

global $config;
global $data;
?>

<?php if (isset($_SESSION[SESSION_LOGIN])) : ?>
<div class="entry-form-wrap card">
    <?php the_entry_form(); ?>
</div>
<?php endif; ?>

<section class="hero card">
    <div class="accent-line" aria-hidden="true"></div>
    <h2 class="hero-title"><?= escape($config['site_title']) ?></h2>
    <?php $intro = page_intro();
    if ($intro) : ?>
        <div class="hero-intro"><?= $intro ?></div>
    <?php endif; ?>
</section>

<div class="feed-header">
    <h2>Latest writing</h2>
</div>

<?php part('_items'); ?>
