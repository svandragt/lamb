<?php

global $data;

use function Lamb\Theme\csrf_token;
use function Lamb\Theme\escape;

?>
<h1>Settings</h1>
<p>
    Edit the application configuration in INI format. Changes are validated before saving.
    Refer to the <a href="https://github.com/svandragt/lamb/wiki" target="_blank">wiki</a> for available keys and examples.
</p>

<form method="post" action="/settings" id="settingsform">
    <label for="ini_text">Configuration (INI format)</label>
    <textarea name="ini_text" id="ini_text" rows="20" style="width: 100%; font-family: monospace;"
    ><?= escape($data['ini_text']) ?></textarea>
    <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= csrf_token() ?>"/>
    <div style="margin-top: 1rem;">
        <button type="submit" name="action" value="save">Save settings</button>
        <button type="submit" name="action" value="reset" onclick="return confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')">Reset to defaults</button>
    </div>
</form>
