<form method="post" action="/login">
    <input type="password" name="password" autofocus / >
    <input type="submit" name="submit" value="<?php echo SUBMIT_LOGIN; ?>">
    <input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
    <input type="hidden" name="redirect_to" value="<?= escape( redirect_to() ) ?>"/>
</form>
