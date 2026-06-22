<?php

use function Lamb\Theme\page_title;
use function Lamb\Theme\part;

echo page_title();
echo '<p>Trashed posts are automatically deleted after 30 days.</p>';

part('_items');
