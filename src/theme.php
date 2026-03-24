<?php

/**
 * Theme support functions — core rendering, navigation, forms, and post helpers.
 *
 * Asset loading: theme/assets.php
 * Date/text formatting and escaping: theme/formatting.php
 * OpenGraph/preconnect meta: theme/meta.php
 */

namespace Lamb\Theme;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RuntimeException;

use function Lamb\get_tags;
use function Lamb\Network\get_feeds;
use function Lamb\permalink;
use function Lamb\Post\get_tag_search_conditions;

use const Lamb\SQL_NOT_DRAFT;

/**
 * Returns true when the user is authenticated and the bean has an ID.
 *
 * @param OODBBean $bean
 * @return bool
 */
function can_act_on(OODBBean $bean): bool
{
    return isset($bean->id, $_SESSION[SESSION_LOGIN]);
}

/**
 * Returns a delete form for the given post bean, or '' if not logged in.
 *
 * @param OODBBean $bean The post bean to delete.
 * @return string HTML delete form, or '' when the user is not authenticated.
 */
function action_delete(OODBBean $bean): string
{
    if (!can_act_on($bean)) {
        return '';
    }

    return sprintf('<form data-id="%s" class="form-delete" action="/delete/%s" method="post"><input type="submit" value="Delete…"/><input type="hidden" name="csrf" value="%s" />
</form>', $bean->id, $bean->id, csrf_token());
}

/**
 * Returns a restore form for the given post bean, or '' if not logged in.
 *
 * @param OODBBean $bean The post bean to restore.
 * @return string HTML restore form, or '' when the user is not authenticated.
 */
function action_restore(OODBBean $bean): string
{
    if (!can_act_on($bean)) {
        return '';
    }

    return sprintf(
        '<form class="form-restore" action="/restore/%s" method="post">'
        . '<input type="submit" value="Restore post"/>'
        . '<input type="hidden" name="csrf" value="%s"/>'
        . '</form>',
        $bean->id,
        csrf_token()
    );
}

/**
 * Returns an edit button for the given post bean, or '' if not logged in.
 *
 * @param OODBBean $bean The post bean to edit.
 * @return string HTML button element, or '' when the user is not authenticated.
 */
function action_edit(OODBBean $bean): string
{
    if (!can_act_on($bean)) {
        return '';
    }

    return sprintf('<button class="button-edit" data-id="%s" type="button">Edit</button>', $bean->id);
}

/**
 * Returns the current session CSRF token, creating one if it does not exist yet.
 *
 * @return string SHA-256 hex CSRF token stored in the session.
 */
function csrf_token(): string
{
    $_SESSION[HIDDEN_CSRF_NAME] = $_SESSION[HIDDEN_CSRF_NAME] ?? hash('sha256', uniqid(mt_rand(), true));

    return $_SESSION[HIDDEN_CSRF_NAME];
}

/**
 * Returns an anchor wrapping a <time> element linking to the post permalink, or '' if no created date.
 *
 * @param OODBBean $bean The post bean.
 * @return string HTML anchor/time element, or '' when the bean has no created date.
 */
function date_created(OODBBean $bean): string
{
    if (!isset($bean->created)) {
        return '';
    }

    $human_created = human_time(strtotime($bean->created));

    $slug = "/status/$bean->id";
    if (!empty($bean->slug)) {
        $slug = $bean->slug;
    }

    return sprintf(
        '<a href="/%1$s" title="Timestamp: %2$s"><time datetime="%2$s">%3$s</time></a>',
        ltrim($slug, '/'),
        $bean->created,
        $human_created
    );
}

/**
 * Returns the site title as an <h1> element, or plain text when $type !== 'html'.
 *
 * @param string $type Output format: 'html' (default) wraps in <h1>, anything else returns plain text.
 * @return string HTML or plain-text site title.
 */
function site_title($type = 'html'): string
{
    global $config;

    if ($type !== 'html') {
        return $config['site_title'];
    }
    return sprintf('<h1>%s</h1>', $config['site_title']);
}

/**
 * Returns the page title if set, otherwise falls back to the site title.
 *
 * @param string $type Output format: 'html' (default) wraps in <h1>, anything else returns plain text.
 * @return string HTML or plain-text page or site title.
 */
function site_or_page_title($type = 'html'): string
{
    $page_title = page_title($type);
    if (empty($page_title)) {
        return site_title($type);
    }
    return $page_title;
}

/**
 * Returns the current page title (from $data['title']) as an <h1>, or plain text when $type !== 'html'.
 * Falls back to the site title when no page title is set.
 *
 * @param string $type Output format: 'html' (default) wraps in <h1>, anything else returns plain text.
 * @return string HTML or plain-text page title.
 */
function page_title(string $type = 'html'): string
{
    global $config;
    global $data;

    $title = $config['site_title'];
    if (!empty($data['title'])) {
        $title = $data['title'];
    }

    if ($type !== 'html') {
        return $title;
    }

    return sprintf('<h1>%s</h1>', $title);
}

/**
 * Returns the current page intro (from $data['intro']) wrapped in a <p>, or '' if not set.
 *
 * @return string HTML paragraph containing the intro text, or '' when no intro is available.
 */
function page_intro(): string
{
    global $data;
    if (!isset($data['intro'])) {
        return '';
    }

    return sprintf('<p>%s</p>', $data['intro']);
}

/**
 * Returns posts that share hashtags with the given body text.
 *
 * @param string $body       Post body Markdown text to extract hashtags from.
 * @param int    $exclude_id Post ID to exclude from results.
 * @return array Associative array with a 'posts' key containing matching OODBBean objects.
 */
function related_posts(string $body, int $exclude_id = 0): array
{
    $tags = get_tags($body);

    return ['posts' => get_posts_by_tags($tags, $exclude_id)];
}

/**
 * Finds all posts that contain at least one of the given tags, ordered by created date descending.
 *
 * @param array $tags       List of tag strings to search for.
 * @param int   $exclude_id Post ID to exclude from results (e.g. the current post).
 * @param int   $limit      Maximum number of posts to return.
 * @return array Unique OODBBean post objects matching any of the tags.
 */
function get_posts_by_tags(array $tags, int $exclude_id = 0, int $limit = 10): array
{
    $related_posts = [];
    foreach ($tags as $tag) {
        $conditions = get_tag_search_conditions($tag);
        $sql = '(' . $conditions['sql'] . ') AND' . SQL_NOT_DRAFT;
        $params = $conditions['params'];
        if ($exclude_id > 0) {
            $sql .= ' AND id != ?';
            $params[] = $exclude_id;
        }
        $sql .= ' ORDER BY created DESC';
        $tag_posts = R::find('post', $sql, $params);
        foreach ($tag_posts as $tag_post) {
            $related_posts[$tag_post->id] = $tag_post;
        }
        if (count($related_posts) >= $limit) {
            break;
        }
    }

    return array_slice(array_values($related_posts), 0, $limit);
}

/**
 * Returns a linked title anchor for the given post bean, or '' if the bean has no title.
 *
 * @param OODBBean $bean The post bean.
 * @return string HTML anchor element wrapping the post title, or ''.
 */
function title_link(OODBBean $bean): string
{
    if (empty($bean->title)) {
        return '';
    }
    return sprintf('<a class="title-link" href="%s">%s</a>', permalink($bean), escape($bean->title));
}

/**
 * Returns a "Via <a>" attribution link for feed-ingested posts, or '' for regular posts.
 * Prefers $bean->source_url; falls back to the feed URL from config.
 *
 * @param OODBBean $bean The post bean.
 * @return string HTML attribution link, or '' when the bean has no feed_name.
 */
function link_source(OODBBean $bean): string
{
    if (!isset($bean->feed_name)) {
        return '';
    }
    $feeds = get_feeds();

    $url = $bean->source_url ?? $feeds[$bean->feed_name] ?? '';

    return sprintf('Via <a href="%s" title="View %s">%s</a>', escape($url), escape($bean->feed_name), escape($bean->feed_name));
}

/**
 * Includes a theme part file, falling back to the default theme when the active theme does not override it.
 *
 * @param string $name Part name without extension (e.g. 'home', '_items').
 * @param string $dir  Subdirectory within the theme folder. Defaults to 'parts'. Pass '' for top-level files.
 * @return void
 * @throws RuntimeException When the part file cannot be found in either the active or default theme.
 */
function part(string $name, string $dir = 'parts'): void
{
    $name = sanitize_filename($name);
    if (!empty($dir)) {
        $dir = sanitize_filename($dir) . '/';
    }
    $filename = THEME_DIR . "$dir$name.php";
    if (!is_readable($filename)) {
        // Fallback to default
        $filename = __DIR__ . "/themes/default/$dir$name.php";
    }
    if (!is_readable($filename)) {
        throw new RuntimeException('unreadable part: ' . $filename);
    }

    require $filename;
}

/**
 * Returns <li><a> HTML for each item in $config['menu_items'], or '' when none are configured.
 *
 * @return string Newline-separated HTML list item strings.
 */
function li_menu_items(): string
{
    global $config;
    $items = [];
    $format = '<li><a href="%s">%s</a></li>';
    if (empty($config['menu_items'])) {
        return '';
    }
    foreach ($config['menu_items'] as $label => $url) {
        if (str_starts_with($url, 'http') || str_starts_with($url, '/')) {
            $items[] = sprintf($format, escape($url), escape($label));
        } else {
            $items[] = sprintf($format, ROOT_URL . '/' . escape($url), escape($label));
        }
    }

    return implode(PHP_EOL, $items);
}

/**
 * Strips any character that is not alphanumeric, a hyphen, or an underscore from a filename string.
 *
 * @param string $filename The filename to sanitize.
 * @return string Sanitized filename safe for use in file paths.
 */
function sanitize_filename($filename): string
{
    $filename = preg_replace('/[^a-zA-Z0-9-_]/', '_', $filename);

    return (string)$filename;
}

/**
 * Renders the quick-post entry form. Does nothing when the user is not logged in.
 *
 * @return void
 */
function the_entry_form(): void
{
    if (isset($_SESSION[SESSION_LOGIN])) : ?>
        <section class="entry-form">
            <form id="entry" method="post" action="/" enctype="multipart/form-data">
                <label>
                    <textarea placeholder="What's happening?" name="contents" required><?= preload_text() ?></textarea>
                </label>
                <input type="submit" name="submit" value="<?= SUBMIT_CREATE ?>">
                <input type="hidden" name="<?= HIDDEN_CSRF_NAME ?>" value="<?= csrf_token() ?>"/>
            </form>
        </section>
        <?php
    endif;
}

/**
 * Returns the HTML for the admin toolbar shown to logged-in users.
 * Injected theme-agnostically after <body> via output buffering in index.php.
 *
 * @return string
 */
function admin_toolbar_html(): string
{
    $drafts = \Lamb\Response\count_drafts();
    $trash  = \Lamb\Response\count_trash();

    $draftsLabel = 'Drafts' . ($drafts > 0 ? " ($drafts)" : '');
    $trashLabel  = 'Trash'  . ($trash  > 0 ? " ($trash)"  : '');

    return '<div id="admin-toolbar">'
        . '<a href="/drafts">'   . escape($draftsLabel) . '</a>'
        . '<a href="/trash">'    . escape($trashLabel)  . '</a>'
        . '<a href="/settings">Settings</a>'
        . '<a href="/logout">Logout</a>'
        . '</div>'
        . '<style>'
        . '#admin-toolbar{'
        . 'position:sticky;top:0;z-index:9999;'
        . 'display:flex;gap:1rem;align-items:center;'
        . 'padding:.4rem 1rem;'
        . 'background:#1a1a1a;color:#fff;font-size:.85rem;'
        . '}'
        . '#admin-toolbar a{color:#ccc;text-decoration:none;}'
        . '#admin-toolbar a:hover{color:#fff;}'
        . '#admin-toolbar a:last-child{margin-left:auto;}'
        . '</style>';
}
