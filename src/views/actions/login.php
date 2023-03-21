<form method="post" action="/login">
    <input type="password" name="password" / >
    <input type="submit" name="submit" value="<?php echo BUTTON_LOGIN; ?>">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>"/>
    <input type="hidden" name="redirect_to" value="<?= redirect_to() ?>"/>
</form>
