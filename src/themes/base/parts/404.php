<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;

global $data;

// Request-controlled, so escaped at both output sites below. Search ANDs
// space-separated words, so a path's slashes and hyphens become spaces.
$requested = (string) ($data['requested'] ?? '');
$terms     = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $requested));
?>
<?= page_title() ?>

<section>
    <?= page_intro() ?>
</section>

<?php if ($terms !== '') : ?>
<p>Why not try <a href="/search/<?= escape(rawurlencode($terms)) ?>">searching for <?= escape($terms) ?></a></p>
<?php endif; ?>
