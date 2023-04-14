<?php if ( isset( $_SESSION[ SESSION_LOGIN ] ) ): ?>
	<form method="post" action="/">
		<textarea placeholder="Bleat here..." name="contents" required></textarea>
		<input type="submit" name="submit" value="<?= SUBMIT_CREATE; ?>">
		<input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
	</form>
<?php endif; ?>

<?= site_title(); ?>
<?= page_intro(); ?>

<?php require ROOT_DIR . "/views/_items.php"; ?>
