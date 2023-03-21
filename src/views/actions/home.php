<?php if ( $_SESSION[ SESSION_LOGIN ] ): ?>
    <form method="post" action="/">
        <textarea placeholder="Bleat here..." name="contents" required></textarea>
        <input type="submit" name="submit" value="<?= BUTTON_SUBMIT_CREATE; ?>">
        <input type="hidden" name="<?= INPUT_CSRF; ?>" value="<?= csrf_token(); ?>"/>
    </form>
<?php endif; ?>


<?= site_title(); ?>
<?= page_intro(); ?>

<?php require ROOT_DIR . "/views/_items.php"; ?>
