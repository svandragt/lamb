<?php
global $data;
foreach ( $data['items'] as $item ): ?>
    <article>
		<?= parse_tags( $item['body'] ); ?>

        <small><?= date_created( $item ); ?> <?= action_edit( $item ); ?> <?= action_delete( $item ); ?></small>
    </article>
<?php endforeach;
