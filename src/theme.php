<?php

/**
 * Theme support functions
 */

namespace Lamb\Theme;

use Generator;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RuntimeException;

use function Lamb\get_tags;
use function Lamb\Network\get_feeds;
use function Lamb\permalink;
use function Lamb\Post\get_tag_search_conditions;

/**
 * Returns a delete form for the given post bean, or '' if not logged in.
 *
 * @param OODBBean $bean The post bean to delete.
 * @return string HTML delete form, or '' when the user is not authenticated.
 */
function action_delete(OODBBean $bean): string
{
    if (!isset($bean->id, $_SESSION[SESSION_LOGIN])) {
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
    if (!isset($bean->id, $_SESSION[SESSION_LOGIN])) {
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
    if (!isset($bean->id, $_SESSION[SESSION_LOGIN])) {
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

    // Support plain text use
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

    // Support plain text use
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
 * @param string $body Post body Markdown text to extract hashtags from.
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
 * @param array $tags List of tag strings to search for.
 * @param int $exclude_id Post ID to exclude from results (e.g. the current post).
 * @param int $limit Maximum number of posts to return.
 * @return array Unique OODBBean post objects matching any of the tags.
 */
function get_posts_by_tags(array $tags, int $exclude_id = 0, int $limit = 10): array
{
    $related_posts = [];
    foreach ($tags as $tag) {
        $conditions = get_tag_search_conditions($tag);
        $sql = '(' . $conditions['sql'] . ') AND (draft IS NULL OR draft != 1)';
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
 * Emits OpenGraph and Twitter Card <meta> tags for the current status post.
 * Does nothing when the current template is not 'status'.
 *
 * @return void
 */
function the_opengraph(): void
{
    global $template;
    global $config;
    global $data;
    if ($template !== 'status') {
        return;
    }
    $bean = $data['posts'][0];
    $description = $bean->description;

    printf('<meta property="description" content="%s"/>' . PHP_EOL, og_escape($description));

    $og_tags = [
        'og:description' => $description,
        'og:image' => ROOT_URL . '/images/og-image-lamb.jpg',
        'og:image:height' => '630',
        'og:image:type' => 'image/jpeg',
        'og:image:width' => '1200',
        'og:locale' => 'en_GB',
        'og:modified_time' => $bean->created,
        'og:published_time' => $bean->updated,
        'og:publisher' => ROOT_URL,
        'og:site_name' => $config['site_title'],
        'og:type' => 'article',
        'og:url' => permalink($bean),
        'twitter:card' => 'summary',
        'twitter:description' => $description,
        'twitter:domain' => $_SERVER["HTTP_HOST"],
        'twitter:image' => ROOT_URL . '/images/og-image-lamb.jpg',
        'twitter:url' => permalink($bean),
    ];
    if (isset($bean->title)) {
        $og_tags['og:title'] = $bean->title;
        $og_tags['twitter:title'] = $bean->title;
    }
    foreach ($og_tags as $property => $content) {
        if (empty($content)) {
            continue;
        }
        printf('<meta property="%s" content="%s"/>' . PHP_EOL, og_escape($property), og_escape($content));
    }
}

/**
 * Emits <link rel="preconnect"> and <link rel="dns-prefetch"> tags for origins in $config['preconnect'].
 * Does nothing when no preconnect origins are configured.
 *
 * @return void
 */
function the_preconnect(): void
{
    global $config;
    if (empty($config['preconnect'])) {
        return;
    }
    foreach ($config['preconnect'] as $origin) {
        printf('<link rel="preconnect" href="%s">' . PHP_EOL, escape($origin));
        printf('<link rel="dns-prefetch" href="%s">' . PHP_EOL, escape($origin));
    }
}

/**
 * Emits a <link rel="stylesheet"> tag for the active theme's styles/styles.css with a cache-busting hash.
 *
 * @return void
 */
function the_styles(): void
{
    $styles = [
        '' => ['styles.css'],
    ];
    $assets = asset_loader($styles, THEME_URL . 'styles');
    foreach ($assets as $id => $href) {
        printf('<link rel="stylesheet" id="%1$s" href="%2$s?%1$s" />' . PHP_EOL, $id, $href);
    }
}

/**
 * Emits <script defer> tags for shorthand.js and, when logged in, the admin-only JS files.
 *
 * @return void
 */
function the_scripts(): void
{
    $scripts = [
        '' => ['shorthand.js', 'search-highlight.js'],
        'logged_in' => ['growing-input.js', 'confirm-delete.js', 'link-edit-buttons.js', 'upload-image.js'],
    ];
    $assets = asset_loader($scripts, 'scripts');
    foreach ($assets as $id => $href) {
        printf("<script id='%s' defer src='%s'></script>", $id, $href);
    }
}

/**
 * Loads and yields asset URLs for the application.
 *
 * @param array $assets An associative array where keys represent directory names
 *                       and values are arrays of filenames to be loaded.
 * @param string $asset_dir The base directory for the assets.
 * @return Generator A generator that yields a hash (md5) of the asset URL as the key
 *                   and the complete asset URL as the value.
 */
function asset_loader(array $assets, string $asset_dir): Generator
{
    foreach ($assets as $dir => $files) {
        foreach ($files as $file) {
            $is_admin_script = $dir === SESSION_LOGIN;
            if (empty($dir) || ($is_admin_script && isset($_SESSION[SESSION_LOGIN]))) {
                // Empty dir
                $href = ROOT_URL . str_replace('//', '/', "/$asset_dir/$dir/$file");
                yield md5($href) => $href;
            }
        }
    }
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
 * Returns a human-readable date string for a past timestamp (j > 2).
 *
 * @param int $j         Period index after the time-difference loop.
 * @param int $difference Rounded number of periods elapsed.
 * @param int $timestamp  Original Unix timestamp.
 * @return string
 */
function format_past_date(int $j, int $difference, int $timestamp): string
{
    switch (true) {
        case $j === 3 && $difference === 1:
            return "Yesterday at " . date("g:i a", $timestamp);
        case $j === 3:
            return date("l \a\\t g:i a", $timestamp);
        case $j < 6 && !($j === 5 && $difference === 12):
            return date("F j \a\\t g:i a", $timestamp);
        default:
            return date("F j, Y \a\\t g:i a", $timestamp);
    }
}

/**
 * Returns a human-readable relative time string for the given Unix timestamp.
 * Thanks to Rose Perrone.
 *
 * @param int $timestamp Unix timestamp to format.
 * @return string Relative string such as "3 hours ago", "Yesterday at 2:15 pm", or an absolute date.
 * @link https://stackoverflow.com/a/11813996
 */
function human_time($timestamp): string
{
    $difference = time() - $timestamp;
    $periods = ["second", "minute", "hour", "day", "week", "month", "years"];
    $lengths = ["60", "60", "24", "7", "4.35", "12"];

    if ($difference >= 0) {
        $ending = "ago";
    } else {
        $difference = -$difference;
        $ending = "to go";
    }

    $arr_len = count($lengths);
    for ($j = 0; $j < $arr_len && $difference >= $lengths[$j]; $j++) {
        $difference /= $lengths[$j];
    }

    $difference = (int)round($difference);

    if ($difference !== 1) {
        $periods[$j] .= "s";
    }

    if ($j <= 2) {
        return "$difference $periods[$j] $ending";
    }

    if ($ending === "to go") {
        if ($j === 3 && $difference === 1) {
            return "Tomorrow at " . date("g:i a", $timestamp);
        }
        return date("F j, Y \a\\t g:i a", $timestamp);
    }

    return format_past_date($j, $difference, $timestamp);
}

/**
 * Returns the sanitised value of the ?redirect_to= query parameter, or '' if absent.
 *
 * @return string Sanitised URL string from the query parameter.
 */
function redirect_to(): string
{
    return (string)filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL);
}

/**
 * Escapes a string for safe HTML5 output using htmlspecialchars.
 *
 * @param string $html The raw string to escape.
 * @return string HTML-safe escaped string.
 */
function escape(string $html): string
{
    return htmlspecialchars($html, ENT_HTML5 | ENT_QUOTES | ENT_SUBSTITUTE);
}

/**
 * Escapes a string for use in OpenGraph meta attribute values.
 * Decodes any existing HTML entities first to avoid double-encoding.
 *
 * @param string $html The raw or partially-encoded string to escape.
 * @return string Escaped string safe for use in HTML attribute values.
 */
function og_escape(string $html): string
{
    return htmlspecialchars(htmlspecialchars_decode($html), ENT_COMPAT | ENT_HTML5);
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
    // Remove any character that is not alphanumeric, a hyphen, or an underscore
    $filename = preg_replace('/[^a-zA-Z0-9-_]/', '_', $filename);

    return (string)$filename;
}

/**
 * Returns the HTML-escaped value of the ?text= query parameter, used to pre-fill the entry form textarea.
 *
 * @return string Escaped text string, or '' when the parameter is absent.
 */
function preload_text(): string
{
    return htmlspecialchars($_GET['text'] ?? '', ENT_QUOTES, 'UTF-8');
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
