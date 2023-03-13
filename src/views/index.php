<form method="post" action="/">
	<textarea placeholder="Bleat here..." name="contents"></textarea>
	<input type="submit" name="submit" value="<?php echo BUTTON_LABEL; ?>">
</form>
<?= page_title(); ?>
<?= page_intro(); ?>

<?php require "views/_items.php"; ?>
