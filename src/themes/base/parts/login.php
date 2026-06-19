<?php

use function Lamb\Theme\escape;
use function Lamb\Theme\redirect_to;

global $data;

// /login is sessionless (issue #462): the CSRF token and any error are passed
// in via $data rather than the session, and "Please login" is inferred from the
// presence of a redirect_to (set when require_login() bounced a gated request).
$login_csrf  = $data['login_csrf'] ?? '';
$login_error = $data['login_error'] ?? '';
$redirect    = redirect_to();

if ($login_error !== '') : ?>
    <div class="flash">⚠️ <?= escape($login_error) ?></div>
<?php elseif ($redirect !== '') : ?>
    <div class="flash">⚠️ Please login</div>
<?php endif; ?>
<form method="post" action="/login">
    <p style="text-align: center">
        <label> Password:
            <input type="password" name="password" autofocus/>
        </label>
        <input type="submit" name="submit" value="<?php
        echo SUBMIT_LOGIN; ?>">
    </p>
    <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= escape($login_csrf) ?>"/>
    <input type="hidden" name="redirect_to" value="<?= escape($redirect) ?>"/>
</form>
