<?php

global $data;

use function Lamb\Theme\csrf_token;
use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;

$feed_statuses = $data['feed_statuses'] ?? [];

?>
<h1>Settings</h1>

<style>
    /* CSS-only tabs: hidden radios drive panel visibility via :checked, so the
       page stays fully usable with no JavaScript. */
    .settings-tabs > input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .settings-tabs__nav {
        display: flex;
        margin-bottom: 1rem;
        /* Neutralise theme styling of bare <nav> (site chrome). */
        border-bottom: none;
        background: none;
        padding: 0;
    }
    /* The underline is drawn per-label (plus this filler for the remaining
       width) so the active tab can open a gap in it. */
    .settings-tabs__nav::after {
        content: "";
        flex: 1;
        border-bottom: 1px solid currentColor;
    }
    .settings-tabs__nav label {
        cursor: pointer;
        padding: .4rem .9rem;
        border: 1px solid transparent;
        border-bottom: 1px solid currentColor;
        border-radius: .3rem .3rem 0 0;
        opacity: .65;
    }
    .settings-tabs__nav label:hover {
        opacity: 1;
    }
    .settings-tabs__panel {
        display: none;
    }
    #settings-tab-config:checked ~ .settings-tabs__nav label[for="settings-tab-config"],
    #settings-tab-logs:checked ~ .settings-tabs__nav label[for="settings-tab-logs"] {
        opacity: 1;
        border-color: currentColor;
        border-bottom-color: transparent;
        font-weight: bold;
    }
    #settings-tab-config:checked ~ #settings-panel-config,
    #settings-tab-logs:checked ~ #settings-panel-logs {
        display: block;
    }
    .settings-logs table {
        width: 100%;
        border-collapse: collapse;
    }
    .settings-logs th,
    .settings-logs td {
        text-align: left;
        padding: .4rem .5rem;
        border-bottom: 1px solid currentColor;
        vertical-align: top;
    }
    .settings-logs .feed-error {
        color: #b00020;
    }
</style>

<div class="settings-tabs">
    <input type="radio" name="settings-tab" id="settings-tab-config" checked>
    <input type="radio" name="settings-tab" id="settings-tab-logs">

    <nav class="settings-tabs__nav">
        <label for="settings-tab-config">Configuration</label>
        <label for="settings-tab-logs">Logs</label>
    </nav>

    <section class="settings-tabs__panel" id="settings-panel-config">
        <p>
            Edit the application configuration in INI format. Changes are validated before saving.
            Refer to the <a href="https://svandragt.github.io/lamb/site-configuration" target="_blank">documentation</a> for available keys and examples.
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
    </section>

    <section class="settings-tabs__panel settings-logs" id="settings-panel-logs">
        <p>
            Read-only feed crawl status. Feeds are crawled by <code>/_cron</code>; each row
            shows when the feed last succeeded, the most recent error (if any) and how many
            items were ingested on the last successful run.
        </p>

        <?php if (empty($feed_statuses)) : ?>
            <p>No feeds configured. Add a <code>[feeds]</code> section in the Configuration tab.</p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Feed</th>
                        <th>Last success</th>
                        <th>Items</th>
                        <th>Last error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feed_statuses as $feed) : ?>
                        <tr>
                            <td>
                                <?= escape($feed['name']) ?><br>
                                <small><?= escape($feed['url']) ?></small>
                            </td>
                            <td>
                                <?= $feed['last_success'] > 0 ? escape(human_time($feed['last_success'])) : 'Never' ?>
                            </td>
                            <td><?= (int) $feed['item_count'] ?></td>
                            <td>
                                <?php if (!empty($feed['error_message'])) : ?>
                                    <span class="feed-error"><?= escape($feed['error_message']) ?></span>
                                    <?php if ($feed['last_error'] > 0) : ?>
                                        <br><small><?= escape(human_time($feed['last_error'])) ?></small>
                                    <?php endif; ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
