<?php

use function Lamb\Theme\csrf_token;
use function Lamb\Theme\escape;
use function Lamb\Theme\redirect_to;

?>
<form method="post" action="/login">
    <p style="text-align: center">
        <label> Password:
            <input type="password" name="password" autofocus/>
        </label>
        <input type="submit" name="submit" value="<?php
        echo SUBMIT_LOGIN; ?>">
    </p>
    <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= csrf_token() ?>"/>
    <input type="hidden" name="redirect_to" value="<?= escape(redirect_to()) ?>"/>
</form>
