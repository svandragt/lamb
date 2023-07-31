<?php
global $data;
if ( empty( $data['items'] ) ):?><p>Sorry no items found.</p>
<?php else:
	foreach ( $data['items'] as $item ):
		if ( empty ( $item['is_menu_item'] ) ):
			?>
            <article>
				<?= Svandragt\Lamb\parse_tags( $item['body'] ); ?>

                <small><?= date_created( $item ); ?><?= link_source( $item ); ?> <?= action_edit( $item ); ?> <?= action_delete( $item ); ?></small>
            </article>
		<?php
		endif;
	endforeach;
endif;
