<?php
global $data;
$bleat = $data['bleat'];

if ( isset( $_SESSION[ SESSION_LOGIN ] ) && $bleat->id > 0 ): ?>
	<h2> Edit Status</h2>

	<form method="post" action="/edit" id="editform">
		<textarea placeholder="Bleat here..." name="contents" required><?= strip_tags( $bleat->body ); ?></textarea>
		<input type="hidden" name="id" value="<?= strip_tags( $bleat->id ); ?>"/>
		<input type="submit" form="editform" name="submit" value="<?= SUBMIT_EDIT; ?>">
		<input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
	</form>

	<small><?= action_delete( $bleat ); ?></small>
<?php else:
	$_SESSION['flash'][] = "Error: Status does not exist!";
	Svandragt\Lamb\Response\respond_404();
endif;
