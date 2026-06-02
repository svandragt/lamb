<?php

use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;

global $data;
?>
<?= page_title() ?>

<section>
    <?= page_intro() ?>
</section>

<p>Why not try <a href="/search/<?= $data['action'] ?>">searching for <?= $data['action'] ?> </a></p>
