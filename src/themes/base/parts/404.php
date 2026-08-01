<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;

global $data;

// The path the visitor asked for. It used to read $data['action'], which the
// router has always overwritten with the literal '404' by the time this runs —
// so the page offered to search for "404". It is request-controlled, so it is
// escaped for the href and the link text rather than echoed raw.
$requested = (string) ($data['requested'] ?? '');
?>
<?= page_title() ?>

<section>
    <?= page_intro() ?>
</section>

<?php if ($requested !== '') : ?>
<p>Why not try <a href="/search/<?= escape(rawurlencode($requested)) ?>">searching for <?= escape($requested) ?></a></p>
<?php endif; ?>
