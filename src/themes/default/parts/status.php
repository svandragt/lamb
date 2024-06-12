<?php

use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;
use function Lamb\Theme\part;

?>
<?= page_title(); ?>
<?= page_intro(); ?>

<?php part( '_items' ); ?>
