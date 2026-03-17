<?php

use function Lamb\Theme\part;
use function Lamb\Theme\site_title;
use function Lamb\Theme\the_entry_form;

the_entry_form();
?>
<?= site_title() ?>

<?php
part('_items');
