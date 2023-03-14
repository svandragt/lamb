<?php if ( $_SESSION[SESSION_LOGIN]): ?>
<form method="post" action="/">
	<textarea placeholder="Bleat here..." name="contents" required></textarea>
	<input type="submit" name="submit" value="<?= BUTTON_BLEAT; ?>">
	<input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>" />
</form>
<?php endif; ?>


<?= page_title(); ?>
<?= page_intro(); ?>

<?php require "views/_items.php"; ?>
	