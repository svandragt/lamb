<?php

global $data;

use function Lamb\Config\settings_form_prefill;
use function Lamb\Theme\csrf_token;
use function Lamb\Theme\escape;
use function Lamb\Theme\human_time;
use function Lamb\Theme\resolve_theme;

$feed_statuses = $data['feed_statuses'] ?? [];
$redirects     = $data['redirects'] ?? [];

// Shows the stored settings, or what was typed when a save was refused. An
// unset theme shows the one in use, so saving does not switch it.
$form      = settings_form_prefill($data['ini_text'], $data['form_values'] ?? []);
$fields    = $form['fields'];
$theme     = resolve_theme($fields['theme']);
$themes    = array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []);
$timezones = DateTimeZone::listIdentifiers();

$text_field = static function (
    string $name,
    string $label,
    string $help = '',
    string $inputmode = '',
    bool $required = false,
    string $placeholder = ''
) use ($fields): void {
    ?>
    <div class="settings-form__field">
        <label for="<?= $name ?>"><?= escape($label) ?><?= $required ? ' (required)' : '' ?></label>
        <input type="text" name="<?= $name ?>" id="<?= $name ?>" value="<?= escape($fields[$name]) ?>"
            <?= $inputmode !== '' ? 'inputmode="' . $inputmode . '"' : '' ?>
            <?= $required ? 'required' : '' ?>
            <?= $placeholder !== '' ? 'placeholder="' . escape($placeholder) . '"' : '' ?>
            <?= $help !== '' ? 'aria-describedby="' . $name . '-help"' : '' ?>>
        <?php if ($help !== '') : ?>
            <span class="settings-form__help" id="<?= $name ?>-help"><?= $help ?></span>
        <?php endif; ?>
    </div>
    <?php
};

$list_field = static function (string $name, string $label, string $help, int $rows = 3) use ($fields): void {
    ?>
    <div class="settings-form__field">
        <label for="<?= $name ?>"><?= escape($label) ?></label>
        <textarea name="<?= $name ?>" id="<?= $name ?>" rows="<?= $rows ?>" spellcheck="false"
            aria-describedby="<?= $name ?>-help"><?= escape($fields[$name]) ?></textarea>
        <span class="settings-form__help" id="<?= $name ?>-help"><?= $help ?></span>
    </div>
    <?php
};

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
    #settings-tab-redirects:checked ~ .settings-tabs__nav label[for="settings-tab-redirects"],
    #settings-tab-export:checked ~ .settings-tabs__nav label[for="settings-tab-export"],
    #settings-tab-logs:checked ~ .settings-tabs__nav label[for="settings-tab-logs"] {
        opacity: 1;
        border-color: currentColor;
        border-bottom-color: transparent;
        font-weight: bold;
    }
    #settings-tab-config:checked ~ #settings-panel-config,
    #settings-tab-redirects:checked ~ #settings-panel-redirects,
    #settings-tab-export:checked ~ #settings-panel-export,
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
    .settings-form fieldset {
        min-width: 0;
        margin: 0 0 1.5rem;
        padding: .5rem 1rem 1rem;
        border: 1px solid currentColor;
        border-radius: .3rem;
    }
    .settings-form legend {
        padding: 0 .4rem;
        font-weight: bold;
    }
    .settings-form fieldset > p {
        margin-top: 0;
    }
    .settings-form__field {
        margin-bottom: 1rem;
    }
    .settings-form label {
        display: block;
        margin-bottom: .25rem;
        font-weight: bold;
    }
    .settings-form input:not([type="checkbox"]),
    .settings-form select,
    .settings-form textarea {
        display: block;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin: 0;
    }
    .settings-form textarea {
        min-height: 0;
    }
    .settings-form .settings-form__check {
        display: inline;
        font-weight: normal;
    }
    /* A span, not <small>: themes style <small> as the post meta line. */
    .settings-form__help {
        display: block;
        margin-top: .25rem;
        opacity: .75;
    }
</style>

<div class="settings-tabs">
    <input type="radio" name="settings-tab" id="settings-tab-config" checked>
    <input type="radio" name="settings-tab" id="settings-tab-redirects">
    <input type="radio" name="settings-tab" id="settings-tab-export">
    <input type="radio" name="settings-tab" id="settings-tab-logs">

    <nav class="settings-tabs__nav">
        <label for="settings-tab-config">Configuration</label>
        <label for="settings-tab-redirects">Redirections</label>
        <label for="settings-tab-export">Export</label>
        <label for="settings-tab-logs">Logs</label>
    </nav>

    <section class="settings-tabs__panel" id="settings-panel-config">
        <p>
            Edit your site's settings. Fields marked required must be filled in.
            Refer to the <a href="https://svandragt.github.io/lamb/site-configuration" target="_blank">documentation</a> for details and examples.
        </p>

        <form method="post" action="/settings" id="settingsform" class="settings-form">
            <fieldset>
                <legend>Site</legend>
                <?php
                $text_field('site_title', 'Site title', 'Shown in the page header and in feeds.', '', true);
                $text_field(
                    'site_url',
                    'Site URL',
                    'The address of your site, such as https://example.com. Micropub needs it, and feeds use it for absolute links.',
                    'url',
                    true,
                    'https://example.com'
                );
                $text_field('site_description', 'Site description', 'A short summary, used as the description of list pages.');
                ?>
                <div class="settings-form__field">
                    <label for="timezone">Timezone</label>
                    <select name="timezone" id="timezone" aria-describedby="timezone-help">
                        <?php foreach ($timezones as $timezone) : ?>
                            <option<?= $timezone === $fields['timezone'] ? ' selected' : '' ?>><?= escape($timezone) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="settings-form__help" id="timezone-help">Used for post dates and scheduled posts.</span>
                </div>
                <div class="settings-form__field">
                    <label for="theme">Theme</label>
                    <select name="theme" id="theme">
                        <?php foreach ($themes as $name) : ?>
                            <option<?= $name === $theme ? ' selected' : '' ?>><?= escape($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

            <fieldset>
                <legend>Author</legend>
                <?php
                $text_field('author_name', 'Author name', 'Shown in feeds and in the page metadata.', '', true);
                $text_field('author_email', 'Author email', 'Optional. Used for the contact card at /.well-known/contact.vcf, when it is a valid address.', 'email');
                ?>
            </fieldset>

            <fieldset>
                <legend>Navigation</legend>
                <p>One link per line, written as <code>label = URL</code>. The URL is the slug of a post, a path on this site or a full web address.</p>
                <?php
                $list_field('menu_items', 'Menu links', 'For example: <code>Home = /</code>, <code>About = about</code> or <code>Source = https://example.com/source</code>.', 4);
                $list_field('footer_items', 'Footer links', 'For example: <code>Privacy = /privacy</code>.');
                ?>
            </fieldset>

            <fieldset>
                <legend>Identity links</legend>
                <?php
                $list_field(
                    'me',
                    'Profile links',
                    'One per line, written as <code>label = URL</code>, for example <code>Mastodon = https://example.social/@you</code>. They are added as <code>rel="me"</code> links for IndieAuth.'
                );
                $text_field(
                    'following_url',
                    'Following list',
                    'Optional. A public OPML list of the feeds you follow. Lamb serves a copy at /.well-known/following.opml.',
                    'url'
                );
                ?>
            </fieldset>

            <fieldset>
                <legend>Feeds</legend>
                <?php
                $list_field(
                    'feeds',
                    'Feeds to publish',
                    'One per line, written as <code>name = feed URL</code>, for example <code>releases = https://example.com/releases.atom</code>.'
                );
                ?>
                <div class="settings-form__field">
                    <input type="checkbox" name="feeds_draft" id="feeds_draft" value="1"<?= $form['feeds_draft'] ? ' checked' : '' ?>>
                    <label for="feeds_draft" class="settings-form__check">Save posts from these feeds as drafts for review</label>
                </div>
                <?php
                $text_field('websub_hubs', 'WebSub hubs', 'Optional. Hubs that push new posts to feed subscribers. Separate several with commas.');
                ?>
            </fieldset>

            <fieldset>
                <legend>IndieWeb</legend>
                <?php
                $text_field(
                    'authorization_endpoint',
                    'Authorization endpoint',
                    'The IndieAuth server Micropub clients sign in with. Leave empty for the default.',
                    'url',
                    false,
                    'https://indieauth.com/auth'
                );
                $text_field(
                    'token_endpoint',
                    'Token endpoint',
                    'The IndieAuth server that verifies Micropub tokens. Leave empty for the default.',
                    'url',
                    false,
                    'https://tokens.indieauth.com/token'
                );
                $list_field(
                    'syndicate_to',
                    'Syndication targets',
                    'Shown to Micropub clients. One per line, written as <code>profile URL = name</code>, for example <code>https://mastodon.social/@you = Mastodon</code>.'
                );
                ?>
            </fieldset>

            <fieldset>
                <legend>Redirections</legend>
                <?php
                $list_field(
                    'redirections',
                    'Redirects from old addresses',
                    'One per line, written as <code>old-slug = destination</code>. The destination is a path, a slug or a full web address, for example <code>old-post = /new-post</code>.',
                    4
                );
                ?>
            </fieldset>

            <input type="hidden" name="settings_form" value="1"/>
            <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= csrf_token() ?>"/>
            <div style="margin-top: 1rem;">
                <button type="submit" name="action" value="save">Save settings</button>
                <button type="submit" name="action" value="reset" formnovalidate onclick="return confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')">Reset to defaults</button>
            </div>
        </form>
    </section>

    <section class="settings-tabs__panel" id="settings-panel-redirects">
        <p>
            Automatic 301 redirects created when a post slug changes. These are stored in the
            database and resolve before any page is served.
        </p>

        <?php if (empty($redirects)) : ?>
            <p>No automatic redirects stored yet.</p>
        <?php else : ?>
            <p id="redirects-filter" hidden>
                <label for="redirects-filter-input">Filter redirects</label>
                <input type="search" id="redirects-filter-input" autocomplete="off">
            </p>
            <p id="redirects-filter-status" aria-live="polite"></p>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:.4rem .5rem;border-bottom:1px solid currentColor;">From</th>
                        <th style="text-align:left;padding:.4rem .5rem;border-bottom:1px solid currentColor;">To</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $redirect) : ?>
                        <tr>
                            <td style="padding:.4rem .5rem;border-bottom:1px solid currentColor;font-family:monospace;">/<?= escape($redirect['from_slug']) ?></td>
                            <td style="padding:.4rem .5rem;border-bottom:1px solid currentColor;font-family:monospace;"><?= escape($redirect['to_url']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="settings-tabs__panel" id="settings-panel-export">
        <p>
            Download every post as a zip archive: one Markdown file per post with its
            front matter intact, the images and videos those posts reference, and a
            <code>manifest.json</code> describing each post's dates, draft/trash state and
            origin. Drafts and trashed posts are included and flagged, so this is a
            complete backup of your writing rather than a copy of the public site.
        </p>
        <p>
            The Markdown files are the same shape Lamb stores internally, so they can be
            read by any editor and re-imported later. See the
            <a href="https://svandragt.github.io/lamb/export" target="_blank">export documentation</a>
            for the format.
        </p>
        <p>
            <a href="/export" class="button">Download export</a>
        </p>
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
