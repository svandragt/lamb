<?php

use function Lamb\Http\app_path;
use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;

global $data;

// Request-controlled, so escaped at both output sites below.
$requested = (string) ($data['requested'] ?? '');
?>
<?= page_title() ?>

<section>
    <?= page_intro() ?>
</section>

<?php if ($requested !== '') : ?>
<p>Why not try <a href="<?= escape(app_path('/search/' . rawurlencode($requested))) ?>">searching for <?= escape($requested) ?></a></p>
<?php endif; ?>
