<?php

/**
 * Theme support functions
 */

namespace Lamb\Theme;

use Generator;
use RedBeanPHP\R;

use function Lamb\get_tags;
use function Lamb\permalink;

function action_delete($post): string
{
    if (! isset($post['id'], $_SESSION[SESSION_LOGIN])) {
        return '';
    }

    return sprintf(
        '<form data-id="%s" class="form-delete" action="/delete/%s" method="post"><input type="submit" value="Delete…"/><input type="hidden" name="csrf" value="%s" />
</form>',
        $post['id'],
        $post['id'],
        csrf_token()
    );
}

function action_edit($post): string
{
    if (! isset($post['id'], $_SESSION[SESSION_LOGIN])) {
        return '';
    }

    return sprintf('<button class="button-edit" data-id="%s" type="button">Edit</button>', $post['id']);
}

function csrf_token(): string
{
    $_SESSION[HIDDEN_CSRF_NAME] = $_SESSION[HIDDEN_CSRF_NAME] ?? hash('sha256', uniqid(mt_rand(), true));

    return $_SESSION[HIDDEN_CSRF_NAME];
}

function date_created($post): string
{
    if (! isset($post['created'])) {
        return '';
    }

    $human_created = human_time(strtotime($post['created']));

    $slug = "/status/{$post['id']}";
    if (! empty($post['slug'])) {
        $slug = $post['slug'];
    }

    return sprintf('<a href="/%s" title="%s">%s</a>', ltrim($slug, '/'), $post['created'], $human_created);
}

function site_title(): string
{
    global $config;

    return sprintf('<h1 class="screen-reader-text">%s</h1>', $config['site_title']);
}

function page_title(): string
{
    global $data;
    if (! isset($data['title'])) {
        return '';
    }

    return sprintf('<h1>%s</h1>', $data['title']);
}

function page_intro(): string
{
    global $data;
    if (! isset($data['intro'])) {
        return '';
    }

    return sprintf('<p>%s</p>', $data['intro']);
}

function related_posts($body): array
{
    $tags = get_tags($body);

    return get_posts_by_tags($tags);
}

function get_posts_by_tags($tags): array
{
    $related_posts = [];
    foreach ($tags as $tag) {
        $sql_query = 'body LIKE ? OR body LIKE ? ORDER BY created DESC';
        $params = ["% #$tag%", "%\n#$tag%"];
        $tag_posts = R::find('post', $sql_query, $params);
        foreach ($tag_posts as $tag_post) {
            $related_posts[] = $tag_post;
        }
    }

    return array_unique($related_posts);
}

function the_opengraph(): void
{
    global $template;
    global $config;
    global $data;
    if ($template !== 'status') {
        return;
    }
    $item = $data['items'][0];
    $description = $item['description'];
    $og_tags = [
        'og:description' => $description,
        'og:image' => ROOT_URL . '/images/og-image-lamb.jpg',
        'og:image:height' => '630',
        'og:image:type' => 'image/jpeg',
        'og:image:width' => '1200',
        'og:locale' => 'en_GB',
        'og:modified_time' => $item['created'],
        'og:published_time' => $item['updated'],
        'og:publisher' => ROOT_URL,
        'og:site_name' => $config['site_title'],
        'og:type' => 'article',
        'og:url' => permalink($item),
        'twitter:card' => 'summary',
        'twitter:description' => $description,
        'twitter:domain' => $_SERVER["HTTP_HOST"],
        'twitter:image' => ROOT_URL . '/images/og-image-lamb.jpg',
        'twitter:url' => permalink($item),
    ];
    if (isset($item['title'])) {
        $og_tags['og:title'] = $item['title'];
        $og_tags['twitter:title'] = $item['title'];
    }
    foreach ($og_tags as $property => $content) {
        if (empty($content)) {
            continue;
        }
        printf('<meta property="%s" content="%s"/>' . PHP_EOL, og_escape($property), og_escape($content));
    }
}

function the_styles(): void
{
    $styles = [
        '' => ['styles.css'],
    ];
    $assets = asset_loader($styles, THEME_URL . 'styles');
    foreach ($assets as $id => $href) {
        printf("<link rel='stylesheet' id='%s' href='%s'>", $id, $href);
    }
}

function the_scripts(): void
{
    $scripts = [
        '' => ['shorthand.js'],
        'logged_in' => ['growing-input.js', 'confirm-delete.js', 'link-edit-buttons.js', 'upload-image.js'],
    ];
    $assets = asset_loader($scripts, 'scripts');
    foreach ($assets as $id => $href) {
        printf("<script id='%s' src='%s'></script>", $id, $href);
    }
}

function asset_loader($assets, $asset_dir): Generator
{
    foreach ($assets as $dir => $files) {
        foreach ($files as $file) {
            $is_admin_script = $dir === SESSION_LOGIN;
            if (empty($dir) || ($is_admin_script && isset($_SESSION[SESSION_LOGIN]))) {
                $href = ROOT_URL . "/$asset_dir/$dir/$file";
                yield md5($href) => $href;
            }
        }
    }
}

/**
 * Thanks to Rose Perrone
 * @link https://stackoverflow.com/a/11813996
 */
function human_time($timestamp): string
{
    // Get time difference and setup arrays
    $difference = time() - $timestamp;
    $periods = ["second", "minute", "hour", "day", "week", "month", "years"];
    $lengths = ["60", "60", "24", "7", "4.35", "12"];

    // Past or present
    if ($difference >= 0) {
        $ending = "ago";
    } else {
        $difference = -$difference;
        $ending = "to go";
    }

    // Figure out difference by looping while less than array length
    // and difference is larger than lengths.
    $arr_len = count($lengths);
    for ($j = 0; $j < $arr_len && $difference >= $lengths[$j]; $j++) {
        $difference /= $lengths[$j];
    }

    // Round up
    $difference = (int)round($difference);

    // Make plural if needed
    if ($difference !== 1) {
        $periods[$j] .= "s";
    }

    // Default format
    $text = "$difference $periods[$j] $ending";

    // over 24 hours
    if ($j > 2) {
        // future date over a day format with year
        if ($ending === "to go") {
            if ($j === 3 && $difference === 1) {
                $text = "Tomorrow at " . date("g:i a", $timestamp);
            } else {
                $text = date("F j, Y \a\\t g:i a", $timestamp);
            }

            return $text;
        }

        if ($j === 3 && $difference === 1) { // Yesterday
            $text = "Yesterday at " . date("g:i a", $timestamp);
        } elseif ($j === 3) { // Less than a week display -- Monday at 5:28pm
            $text = date("l \a\\t g:i a", $timestamp);
        } elseif ($j < 6 && ! ($j === 5 && $difference === 12)) { // Less than a year display -- June 25 at 5:23am
            $text = date("F j \a\\t g:i a", $timestamp);
        } else // if over a year or the same month one year ago -- June 30, 2010 at 5:34pm
        {
            $text = date("F j, Y \a\\t g:i a", $timestamp);
        }
    }

    return $text;
}

function redirect_to(): string
{
    return (string)filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL);
}

function escape(string $html): string
{
    return htmlspecialchars($html, ENT_HTML5 | ENT_QUOTES | ENT_SUBSTITUTE);
}

function og_escape(string $html): string
{
    return htmlspecialchars(htmlspecialchars_decode($html), ENT_COMPAT | ENT_HTML5);
}

function part(string $name): void
{
    $name = sanitize_filename($name);
    $filename = THEME_DIR . "parts/$name.php";
    if (! is_readable($filename)) {
        // Fallback to default
        $filename = __DIR__ . "/themes/default/parts/$name.php";
    }
    if (! is_readable($filename)) {
        throw new RuntimeException('unreadable part: ' . $filename);
    }

    require $filename;
}

function li_menu_items()
{
    global $config;
    $items = [];
    $format = '<li><a href="%s/%s">%s</a></li>';
    if (empty($config['menu_items'])) {
        return '';
    }
    foreach ($config['menu_items'] as $label => $slug) {
        $items[] = sprintf($format, ROOT_URL, escape($slug), escape($label));
    }

    return implode(PHP_EOL, $items);
}

function sanitize_filename($filename): string
{
    // Remove any character that is not alphanumeric, a hyphen, or an underscore
    $filename = preg_replace('/[^a-zA-Z0-9-_]/', '_', $filename);

    return (string)$filename;
}

function the_entry_form()
{
    if (isset($_SESSION[SESSION_LOGIN])) : ?>
        <form method="post" action="/" enctype="multipart/form-data">
            <label>
                <textarea placeholder="What's happening?" name="contents" required></textarea>
            </label>
            <input type="submit" name="submit" value="<?= SUBMIT_CREATE; ?>">
            <input type="hidden" name="<?= HIDDEN_CSRF_NAME; ?>" value="<?= csrf_token(); ?>"/>
        </form>
        <?php
    endif;
}
