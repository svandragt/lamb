<?php

use function Lamb\Theme\page_intro;
use function Lamb\Theme\part;
use function Lamb\Theme\site_title;
use function Lamb\Theme\the_entry_form;

the_entry_form(); ?>
<?= site_title(); ?>
<?= page_intro(); ?>

<?php
part('_items');
