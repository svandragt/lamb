<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Security;
use RedBeanPHP\R;

use function Lamb\Http\request_string;
use function Lamb\Post\load_posts_in_order;
use function Lamb\Post\post_ids_by_tag;
use function Lamb\Post\split_reply_targets;

use const Lamb\SQL_IS_DELETED;
use const Lamb\SQL_IS_DRAFT;
use const Lamb\SQL_IS_SCHEDULED;
use const ROOT_URL;

/**
 * Retrieves and prepares the homepage data, including paginated posts and site title.
 *
 * @return array<string, mixed> The prepared homepage data, including posts, pagination details, and the site title.
 */
function respond_home(): array
{
    global $config;
    if (!empty($_POST)) {
        redirect_created();
    }

    $public = public_posts_clause();

    return listing_data($config['site_title'] ?? '', 'created DESC', $public['sql'], $public['params']);
}

/**
 * Assembles the view data for a paginated post listing.
 *
 * The shared tail of every listing route (home, drafts, trash, scheduled): run the
 * query through paginate_posts() and hand the template its three keys. Callers own
 * the query and any authentication they need — this only assembles the result.
 *
 * @param string $title           Page title for the template.
 * @param string $order_by_clause SQL ORDER BY expression (without the keyword).
 * @param string $where_sql       WHERE clause selecting the posts to list.
 * @param array<int, mixed> $params Bound parameters for the WHERE clause.
 * @return array{title: string, posts: array<int, mixed>, pagination: array<string, mixed>}
 */
function listing_data(string $title, string $order_by_clause, string $where_sql, array $params = []): array
{
    $paginated = paginate_posts('post', $order_by_clause, $where_sql, $params);

    return [
        'title'      => $title,
        'posts'      => $paginated['items'],
        'pagination' => $paginated['pagination'],
    ];
}

/**
 * Responds with the drafts page showing all draft posts (login required).
 *
 * @return array<string, mixed> The drafts page data including posts and pagination.
 */
function respond_drafts(): array
{
    Security\require_login();

    return listing_data('Drafts', 'created DESC', SQL_IS_DRAFT);
}

/**
 * Returns paginated soft-deleted posts for the Trash view.
 *
 * @return array<string, mixed>
 */
function respond_trash(): array
{
    Security\require_login();

    return listing_data('Trash', 'deleted_at DESC', SQL_IS_DELETED);
}

/**
 * Returns the count of draft posts.
 *
 * @return int
 */
function count_drafts(): int
{
    return R::count('post', SQL_IS_DRAFT);
}

/**
 * Returns the count of soft-deleted (trashed) posts.
 *
 * @return int
 */
function count_trash(): int
{
    return R::count('post', SQL_IS_DELETED);
}

/**
 * Responds with the scheduled page showing posts dated in the future (login required).
 *
 * @return array<string, mixed> The scheduled page data including posts and pagination.
 */
function respond_scheduled(): array
{
    Security\require_login();

    return listing_data('Scheduled', 'created ASC', SQL_IS_SCHEDULED, [\Lamb\now()]);
}

/**
 * Returns the count of scheduled (future-dated) posts.
 *
 * @return int
 */
function count_scheduled(): int
{
    return R::count('post', SQL_IS_SCHEDULED, [\Lamb\now()]);
}

/**
 * Redirects the user to a search page with the provided query.
 *
 * @param string $query The search query to be included in the redirected URL.
 * @return void
 */
#[NoReturn]
function redirect_search(string $query): void
{
    // Percent-encoded: the term becomes a path segment, and an unencoded `#`
    // made the browser read the rest as a fragment (so searching for a hashtag
    // from the box searched for nothing at all), while `/` split the term and
    // a space made an invalid Location header.
    $location = \Lamb\Http\sanitize_location('/search/' . \Lamb\encode_path_segment($query));
    header("Location: $location", true, 301);
    die();
}

/**
 * Returns the updated timestamp for the feed.
 *
 * @param array<int, \RedBeanPHP\OODBBean> $posts List of post beans.
 * @return string Date string suitable for strtotime(), falls back to current time when empty.
 */
function get_feed_updated_date(array $posts): string
{
    $first = reset($posts);
    return $first !== false ? $first->updated : \Lamb\now();
}

/**
 * Returns the data needed to render the main Atom feed.
 *
 * @return array{posts: array<int, \RedBeanPHP\OODBBean>, title: mixed, feed_url: string, updated: mixed}
 */
function get_feed_data(): array
{
    global $config;

    $public = public_posts_clause();
    $posts  = R::find('post', $public['sql'] . ' ORDER BY updated DESC LIMIT 20', $public['params']);

    $first_post = reset($posts);
    return [
        'updated'  => $first_post['updated'] ?? \Lamb\now(),
        'title'    => $config['site_title'] ?? '',
        'feed_url' => ROOT_URL . '/feed',
        'posts'    => $posts,
    ];
}

/**
 * Sets caching headers for an anonymous feed response.
 *
 * Feeds are polled by readers rather than browsed, so they get a longer max-age
 * than regular pages, plus a conditional-GET 304 short-circuit keyed on the
 * feed's freshest item. No-op for logged-in users (their responses are private).
 * See response/README.md ("Conditional GET, ETag, and 304 caching").
 *
 * @param string $updated The feed's latest-updated datetime string.
 * @param int    $shape   A discriminator for a response's structure when that can
 *                        change without $updated moving — folded into the ETag so
 *                        the change invalidates conditional GETs. The sitemap
 *                        passes its page count (index vs urlset, and how many child
 *                        pages), which count_visible_posts() can change while the
 *                        newest post is untouched (e.g. a non-newest post trashed).
 *                        0 (the default) leaves the ETag keyed on content alone.
 * @return void
 */
function feed_cache(string $updated, int $shape = 0): void
{
    if (isset($_SESSION[SESSION_LOGIN])) {
        return;
    }
    header('Cache-Control: max-age=1800');
    // Fold in config edits so changes to feed-affecting settings (title, menu
    // exclusions, …) invalidate cached feeds immediately.
    $config_ts = Config\config_modified_timestamp();
    $ts = max(strtotime($updated) ?: 0, $config_ts) + $shape;
    send_304_if_current($ts, $config_ts);
}

/**
 * Decodes and HTML-escapes the tag name from a tag-feed route's arguments.
 *
 * @param array<int, string> $args Route arguments; the first element is the raw tag segment.
 * @return string The sanitised tag name.
 */
function sanitize_tag_arg(array $args): string
{
    [$tag] = $args;

    // rawurldecode(), not urldecode(): a `+` is a legal tag character, not a
    // space (see Lamb\parse_tags, which encodes the link the same way).
    return htmlspecialchars(rawurldecode($tag));
}

/**
 * Composes a feed item's content_html: the reply-context line followed by the
 * post body with relative URLs absolutised. Shared by the Atom and JSON
 * renderers so the two feeds cannot drift on what an item's content holds.
 *
 * The caller applies any feed-specific wrapping: the Atom renderer passes the
 * result through normalize_utf8() for the XML text node; the JSON renderer
 * relies on json_encode()'s JSON_INVALID_UTF8_SUBSTITUTE instead.
 *
 * @param \RedBeanPHP\OODBBean $bean The post bean.
 * @return string The item content as HTML.
 */
function feed_item_content_html(\RedBeanPHP\OODBBean $bean): string
{
    return \Lamb\Theme\the_reply_context($bean) . \Lamb\absolute_urls($bean->transformed);
}

/**
 * Renders the Atom feed for the given view data.
 *
 * Feeds live in code, not the theme layer: a theme that omitted feed.php used to
 * lose the site's feed silently — the same omission-by-default failure #684 (D7)
 * records for other parts. emit_feed() still honours a theme shipping its own
 * feed.php, with a deprecation notice, for one release.
 *
 * @param array<string, mixed> $data   Feed view data (posts, title, feed_url, updated).
 * @param array<string, mixed> $config Site configuration.
 * @return void
 */
function render_atom_feed(array $data, array $config): void
{
    header('Content-type: application/atom+xml');
    $channel_link = $data['feed_url'] ?? ROOT_URL . '/feed';

    $xml = new \SimpleXMLElement(
        '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:thr="http://purl.org/syndication/thread/1.0"></feed>'
    );
    $xml->addChild('title', \Lamb\Theme\escape_xml($data['title'] ?? $config['site_title']));
    $xml->addChild('id', \Lamb\Theme\escape_xml($channel_link));
    $xml->addChild('updated', date(DATE_ATOM, strtotime($data['updated'])));
    $xml->addChild('generator', 'Lamb');

    // Atom <icon>/<logo> by convention from the web root: favicon.png / logo.png
    // next to index.php. Emitted only when present, so no broken image URL.
    if (defined('ROOT_DIR')) {
        foreach (['favicon.png' => 'icon', 'logo.png' => 'logo'] as $file => $element) {
            if (file_exists(ROOT_DIR . '/' . $file)) {
                $xml->addChild($element, \Lamb\Theme\escape_xml(ROOT_URL . '/' . $file));
            }
        }
    }

    $self = $xml->addChild('atom:link');
    $self->addAttribute('rel', 'self');
    // Raw URL: addAttribute() escapes for us, so pre-escaping would double-encode
    // a query-string `&`.
    $self->addAttribute('href', $channel_link);

    foreach (\Lamb\Websub\hub_urls($config) as $websub_hub) {
        $hub = $xml->addChild('link');
        $hub->addAttribute('rel', 'hub');
        $hub->addAttribute('href', $websub_hub);
    }

    $author = $xml->addChild('author');
    $author->addChild('name', \Lamb\Theme\escape_xml($config['author_name']));
    $author->addChild('uri', ROOT_URL);

    foreach ($data['posts'] as $bean) {
        $entry = $xml->addChild('entry');
        // addChild() does not escape, so a permalink carrying `&` would break the
        // element — escape_xml() first (an empty <id/> made the whole feed invalid).
        $entry->addChild('id', \Lamb\Theme\escape_xml(\Lamb\permalink($bean)));
        $entry->addChild('title', \Lamb\Theme\escape_xml($bean->title ?: ''));
        $entry->addChild('published', date(DATE_ATOM, strtotime($bean->created)));
        $entry->addChild('updated', date(DATE_ATOM, strtotime($bean->updated)));

        // Content via a DOM text node, not addChild(): addChild() escapes `<` but
        // not `&`, stripping one layer off the stored (already safe-mode-escaped)
        // HTML and handing subscribers live markup. A text node escapes both.
        $content = $entry->addChild('content');
        $content->addAttribute('type', 'html');
        $content_html = \Lamb\normalize_utf8(feed_item_content_html($bean));
        $content_node = dom_import_simplexml($content);
        $content_document = $content_node->ownerDocument;
        if ($content_document !== null) {
            $content_node->appendChild($content_document->createTextNode($content_html));
        }

        // in_reply_to is not author-only (a Micropub `create` token sets it,
        // unvalidated), so a non-http(s) scheme must not be syndicated as a
        // link. A post may carry several targets (#583, RFC 4685 allows
        // multiple thr:in-reply-to elements per entry): each gets its own
        // thr:in-reply-to and rel="related" link, independently guarded.
        foreach (split_reply_targets((string) ($bean->in_reply_to ?? '')) as $target) {
            if (!\Lamb\Http\is_valid_http_url($target)) {
                continue;
            }
            $thread = $entry->addChild('in-reply-to', null, 'http://purl.org/syndication/thread/1.0');
            $thread->addAttribute('ref', $target);
            $thread->addAttribute('href', $target);
            $thread->addAttribute('type', 'text/html');
            $related = $entry->addChild('link');
            $related->addAttribute('rel', 'related');
            $related->addAttribute('href', $target);
        }
        $alt = $entry->addChild('link');
        $alt->addAttribute('rel', 'alternate');
        $alt->addAttribute('type', 'text/html');
        $alt->addAttribute('href', \Lamb\permalink($bean));
    }
    echo $xml->asXML();
}

/**
 * Renders the JSON Feed (jsonfeed.org 1.1) for the given view data.
 *
 * The JSON counterpart of {@see render_atom_feed}; see that docblock for why
 * feeds are rendered in code rather than the theme layer.
 *
 * @param array<string, mixed> $data   Feed view data (posts, title, feed_url, updated).
 * @param array<string, mixed> $config Site configuration.
 * @return void
 */
function render_json_feed(array $data, array $config): void
{
    header('Content-type: application/feed+json');
    $channel_link = $data['feed_url'] ?? ROOT_URL . '/feed.json';

    $feed = [
        'version'       => 'https://jsonfeed.org/version/1.1',
        'title'         => $data['title'] ?? $config['site_title'],
        'home_page_url' => ROOT_URL,
        'feed_url'      => $channel_link,
        'authors'       => [
            [
                'name' => $config['author_name'] ?? '',
                'url'  => ROOT_URL,
            ],
        ],
        'items'         => [],
    ];

    $websub_hubs = \Lamb\Websub\hub_urls($config);
    if ($websub_hubs !== []) {
        $feed['hubs'] = array_map(
            fn($hub) => ['type' => 'WebSub', 'url' => $hub],
            $websub_hubs
        );
    }

    foreach ($data['posts'] as $bean) {
        $url = \Lamb\permalink($bean);
        $item = [
            'id'             => $url,
            'url'            => $url,
            // Reply context inside content_html as well as _microblog below: the
            // extension is a best-effort hint (micro.blog is not documented to
            // read it from an external feed), whereas the u-in-reply-to markup is
            // what a plain reader shows and what mf2 consumers parse.
            'content_html'   => feed_item_content_html($bean),
            'date_published' => date(DATE_RFC3339, strtotime($bean->created)),
            'date_modified'  => date(DATE_RFC3339, strtotime($bean->updated)),
        ];
        if (!empty($bean->title)) {
            $item['title'] = $bean->title;
        }
        // Guarded like content_html above: the consumer links this, and
        // in_reply_to is not author-only. `_microblog.in_reply_to_url` is a
        // JSON Feed extension field that carries a single target (micro.blog is
        // not documented to read it from an external feed; #586), so a post with
        // several (#583) reports only the first valid one here — every target
        // still reaches the reader via content_html's u-in-reply-to links above.
        foreach (split_reply_targets((string) ($bean->in_reply_to ?? '')) as $target) {
            if (\Lamb\Http\is_valid_http_url($target)) {
                $item['_microblog'] = ['in_reply_to_url' => $target];
                break;
            }
        }
        $feed['items'][] = $item;
    }

    // JSON_INVALID_UTF8_SUBSTITUTE: one invalid byte would otherwise make
    // json_encode() return false for the whole document (an empty 200); degrade
    // that character to U+FFFD instead.
    echo json_encode(
        $feed,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * The active theme's own feed part path, or null when it does not ship one.
 *
 * The built-in renderers above are the default; a theme that still carries its
 * own feed.php / feed_json.php keeps working for one release, via emit_feed(),
 * with a deprecation notice. base no longer ships either part, so this returns
 * a path only for a genuine third-party override.
 *
 * @param string $template 'feed' or 'feed_json'.
 * @return string|null The override file path, or null.
 */
function feed_part_override(string $template): ?string
{
    if (!defined('THEME_DIR')) {
        return null;
    }
    $path = THEME_DIR . \Lamb\Theme\sanitize_filename($template) . '.php';

    return is_readable($path) ? $path : null;
}

/**
 * Renders a feed with the given feed data and terminates the request.
 *
 * Shared tail of all four feed responders: merge the feed data into the global
 * view data, emit cache headers (with a conditional-GET 304 short-circuit),
 * upgrade stale posts, render (built-in, or a deprecated theme override), die.
 *
 * @param array<string, mixed> $feed_data As built by get_feed_data()/get_tag_feed_data().
 * @param string      $template  Feed template name ('feed' or 'feed_json').
 * @param string|null $feed_url  Optional feed_url override (the JSON variants).
 * @return never
 */
function emit_feed(array $feed_data, string $template, ?string $feed_url = null): never
{
    global $data, $config;

    foreach ($feed_data as $key => $value) {
        $data[$key] = $value;
    }
    if ($feed_url !== null) {
        $data['feed_url'] = $feed_url;
    }
    feed_cache($data['updated']);
    upgrade_posts($data['posts']);

    $override = feed_part_override($template);
    if ($override !== null) {
        // The one developer-visible change in #684 (D7): a theme feed part still
        // works, but only an override is now deprecated — omitting it inherits a
        // correct feed instead of losing it.
        //
        // Warn at most once per template per process: feeds are polled hard by
        // aggregators, and a logging error handler that ignores error_reporting()
        // would otherwise record the same notice on every hit.
        static $warned = [];
        if (!isset($warned[$template])) {
            $warned[$template] = true;
            @trigger_error(
                sprintf(
                    "Theme feed part '%s.php' is deprecated and will be removed; feeds are rendered by "
                    . 'Lamb\\Response now. Remove the theme part to inherit the built-in feed.',
                    $template
                ),
                E_USER_DEPRECATED
            );
        }
        require $override;
    } elseif ($template === 'feed_json') {
        render_json_feed($data, $config);
    } else {
        render_atom_feed($data, $config);
    }
    die();
}

/**
 * Responds to a feed request by fetching and rendering the Atom feed.
 *
 * @return void
 */
#[NoReturn]
function respond_feed(): void
{
    emit_feed(get_feed_data(), 'feed');
}

/**
 * Responds to a JSON feed request by fetching and rendering the JSON Feed.
 *
 * @return void
 */
#[NoReturn]
function respond_feed_json(): void
{
    emit_feed(get_feed_data(), 'feed_json', ROOT_URL . '/feed.json');
}

/**
 * Returns the data needed to render a tag Atom feed.
 *
 * @param string $tag The already-sanitised tag name.
 * @return array{posts: array<int, \RedBeanPHP\OODBBean>, title: string, feed_url: string, updated: string}
 */
function get_tag_feed_data(string $tag): array
{
    global $config;

    // Twenty newest by `updated`, chosen from ids so the scan can stop as soon
    // as it has them: this used to load a bean per match and then slice, which
    // on a tag covering a large archive exhausted memory before it got here.
    $posts = load_posts_in_order(post_ids_by_tag($tag, true, 20));

    return [
        'updated'  => get_feed_updated_date($posts),
        'title'    => ($config['site_title'] ?? '') . ' — #' . $tag,
        'feed_url' => ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed',
        'posts'    => $posts,
    ];
}

/**
 * Responds to a tag feed request by rendering an Atom feed for posts with a specific tag.
 *
 * @param array<int, string> $args An array where the first element is the tag name.
 * @return void
 */
#[NoReturn]
function respond_tag_feed(array $args): void
{
    emit_feed(get_tag_feed_data(sanitize_tag_arg($args)), 'feed');
}

/**
 * Responds to a tag JSON feed request by rendering a JSON Feed for posts with a specific tag.
 *
 * @param array<int, string> $args An array where the first element is the tag name.
 * @return void
 */
#[NoReturn]
function respond_tag_feed_json(array $args): void
{
    $tag = sanitize_tag_arg($args);
    emit_feed(get_tag_feed_data($tag), 'feed_json', ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed.json');
}

/**
 * Responds to a search query with paginated results.
 *
 * @param array<int, string> $args The first element should be the search query.
 * @return array<string, mixed>
 */
function respond_search(array $args): array
{
    $query = rawurldecode((string) ($args[0] ?? ''));
    if (empty($query)) {
        $query = request_string($_GET['s'] ?? null) ?? '';
        if (empty($query)) {
            return [];
        }
        redirect_search($query);
    }
    // Keep $query raw: SQL uses bound parameters, and every output path
    // (page title, search box) escapes at render time. Escaping here too would
    // double-encode HTML metacharacters in the displayed search term.

    // Support multiple words filtering
    $words = explode(' ', $query);
    $where_clauses = [];
    $params = [];
    foreach ($words as $word) {
        // The search term is literal text: without escaping, a `%` in it matched
        // every post and an `_` matched any character.
        $where_clauses[] = "body LIKE ? ESCAPE '\\'";
        $params[] = '%' . \Lamb\like_escape($word) . '%';
    }
    $public = public_posts_clause();
    $where_sql = '(' . implode(' AND ', $where_clauses) . ') AND' . $public['sql'];
    $params = array_merge($params, $public['params']);

    $paginated = paginate_posts('post', 'created DESC', $where_sql, $params);

    $data['query'] = $query;
    $data['title'] = 'Searched for "' . $query . '"';
    return get_results($data, $paginated['items'], $paginated['pagination']);
}

/**
 * Builds the response array for search/tag results, including intro text and pagination.
 *
 * @param array<string, mixed> $data       Base data array to enrich.
 * @param array<int, mixed>    $posts      Posts for the current page.
 * @param array<string, mixed> $pagination Pagination metadata, as built by build_pagination_meta().
 * @return array<string, mixed>
 */
function get_results(array $data, array $posts, array $pagination): array
{
    $total_posts = (int) $pagination['total_posts'];
    if ($total_posts > 0) {
        $result = ngettext("result", "results", $total_posts);
        $data['intro'] = $total_posts . " $result found.";
    } else {
        $data['intro'] = "No results found.";
    }

    $data['posts'] = $posts;
    $data['pagination'] = $pagination;

    upgrade_posts($posts);

    return $data;
}

/**
 * Retrieves posts tagged with the given tag and returns paginated, enriched data.
 *
 * @param array<int, string> $args The first element is the tag name.
 * @return array<string, mixed>
 */
function respond_tag(array $args): array
{
    [$tag] = $args;
    $tag = rawurldecode((string) $tag);
    // Keep $tag raw: matching, URL-encoding and the page title each handle it
    // correctly, and the title is escaped at render time (so no double-encoding).

    // The id list, not the posts: it is paginated first and only the page being
    // rendered is loaded, so a tag covering the whole archive costs one id per
    // match rather than one full post.
    $all_ids = post_ids_by_tag($tag);

    if ($all_ids === []) {
        return respond_404();
    }

    $paginated = paginate_posts($all_ids);
    // paginate_posts() is generic over its source, so its items come back as
    // array<int, mixed>; narrow them here rather than loosening the loader.
    $page_ids = array_values(array_map('intval', $paginated['items']));
    $paginated['items'] = load_posts_in_order($page_ids);

    $data['title'] = 'Tagged with #' . $tag;
    $data['feed_url'] = ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed';
    return get_results($data, $paginated['items'], $paginated['pagination']);
}
