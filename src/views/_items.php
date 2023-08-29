<?php
global $data;
if ( empty( $data['posts'] ) ):?><p>Sorry no items found.</p>
<?php else:
	foreach ( $data['posts'] as $bean ):
		if ( is_menu_item( $bean->slug ?? $bean->id ) ):
			continue;
		endif;
		?>
        <article>
			<?= $bean->transformed; ?>

            <small><?= date_created( $bean ); ?><?= link_source( $bean ); ?> <?= action_edit( $bean ); ?> <?= action_delete( $bean ); ?></small>
        </article>
	<?php
	endforeach;
endif;
