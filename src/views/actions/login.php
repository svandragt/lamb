<form method="post" action="/login">
    <input type="password" name="password" / >
    <input type="submit" name="submit" value="<?php echo BUTTON_SUBMIT_LOGIN; ?>">
    <input type="hidden" name="<?= INPUT_CSRF; ?>" value="<?= csrf_token(); ?>"/>
    <input type="hidden" name="redirect_to" value="<?= redirect_to() ?>"/>
</form>
