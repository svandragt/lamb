<?php

use function Lamb\Theme\page_title;
use function Lamb\Theme\part;

echo page_title();

part('_items');
part('_pagination');
