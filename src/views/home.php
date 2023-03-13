<?php 
	if ( $_SESSION['loggedin']):
?>
<form method="post" action="/">
	<textarea placeholder="Bleat here..." name="contents"></textarea>
	<input type="submit" name="submit" value="<?php echo BUTTON_BLEAT; ?>">
</form>
<?php endif; ?>
<?= page_title(); ?>
<?= page_intro(); ?>

<?php require "views/_items.php"; ?>
	