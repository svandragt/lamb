<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\part;

global $data;
?>

<div class="feed-header">
    <h2><?= !empty($data['title']) ? escape($data['title']) : 'Tag' ?></h2>
</div>

<?php $intro = page_intro();
if ($intro) : ?>
    <p class="page-intro"><?= $intro ?></p>
<?php endif; ?>

<?php part('_items'); ?>
