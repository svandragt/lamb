<?php
global $data;
global $template;

use function Lamb\transform;

if ( $template !== 'status' ) {
	return;
}
$body = $data['items'][0]['body'];
$related_posts = related_posts( $body );
$data = transform( $related_posts );
$ids = [];
?>
<main>
    <article>
        <h3>Related posts</h3>
		<?php
		if ( empty( $data['items'] ) ):?><p>Sorry no items found.</p>
		<?php else:
			foreach ( $data['items'] as $item ):
				if ( in_array( $item['id'], $ids, true ) ):
					continue;
				endif;
				if ( empty ( $item['is_menu_item'] ) ):
					?>
                    <li><?= date_created( $item ) ?> <?= $item['title'] ?>
                    </li>
				<?php
				endif;
				$ids[] = $item['id'];
			endforeach;
		endif;
		?></article>
</main>
