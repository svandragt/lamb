<?php

use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;

global $data;
?>
<?= page_title() ?>
<?= page_intro() ?>

<p>Why not try <a href="/search/<?= $data['action'] ?>">searching for <?= $data['action'] ?> </a></p>
