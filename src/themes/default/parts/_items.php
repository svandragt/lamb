<?php
global $data;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\date_created;

if ( empty( $data['items'] ) ):?><p>Sorry no items found.</p>
<?php else:
	foreach ( $data['items'] as $item ):
		if ( empty ( $item['is_menu_item'] ) ):
			?>
            <article>
				<?= Lamb\parse_tags( $item['body'] ); ?>

                <small><?= date_created( $item ); ?> <?= action_edit( $item ); ?> <?= action_delete( $item ); ?></small>
            </article>
		<?php
		endif;
	endforeach;
endif;
