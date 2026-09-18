<?php

namespace Lamb;

global $template, $action;


define('ROOT_DIR', __DIR__);

require '../vendor/autoload.php';

$data_dir = Bootstrap\data_dir();
Bootstrap\bootstrap_db($data_dir);
Bootstrap\bootstrap_session($data_dir);
Post\register_default_subscribers();

$config = Config\load();
Config\apply_timezone($config);

// Prefer the author's configured canonical URL; fall back to the requested host
// for installs that have not set one. The fallback is validated to a strict host
// charset, so a malformed Host can no longer be reflected into HTML or headers —
// but it is still attacker-chosen, which is why security decisions (the Micropub
// IndieAuth `me` check) use Config\canonical_site_url() directly instead of this.
$root_url = Config\canonical_site_url($config) ?? Http\request_root_url($_SERVER);
if ($root_url === null) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 400 Bad Request');
    die('Bad Request');
}
define('ROOT_URL', $root_url);
// The configured theme comes verbatim from the admin-editable config INI, and
// is used both to build a require() path (THEME_DIR) and echoed raw into HTML
// on every page view (THEME_URL, e.g. themes/2026/html.php's font preload
// links). Theme\resolve_theme() sanitizes it to a safe filename charset first
// — closing both a path-traversal primitive and a site-wide stored-XSS
// surface a stray HTML-breaking character in `theme = ...` would otherwise
// open up — then falls back to the base theme for any name (no `theme` key,
// a `[theme]` section header, a typo saved at /settings, a custom theme
// whose directory was deleted) that has no matching directory under
// src/themes/, rather than letting a broken value produce a broken page.
define("THEME", Theme\resolve_theme($config['theme'] ?? null));
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');
define("THEME_URL", 'themes/' . THEME . '/');

# Bootstrap
foreach (Bootstrap\cache_headers(isset($_SESSION[SESSION_LOGIN])) as $cache_header) {
    header($cache_header);
}
header('Link: <' . ROOT_URL . '/micropub>; rel="micropub"', false);
header('Link: <' . ROOT_URL . '/webmention>; rel="webmention"', false);
header('Link: <' . $config['authorization_endpoint'] . '>; rel="authorization_endpoint"', false);
header('Link: <' . $config['token_endpoint'] . '>; rel="token_endpoint"', false);

# Routing
$request_uri = Http\get_request_uri();

# GET/HEAD only: a 301 on POST would make a Micropub or webmention client
# re-issue the request without its body. get_request_uri() maps '/' to
# '/home', so the trailing-slash check reads $_SERVER['REQUEST_URI'] directly.
$canonical = Http\canonical_redirect($_SERVER['REQUEST_URI'] ?? '');
if ($canonical !== null && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Location: ' . Http\sanitize_location($canonical), true, 301);
    exit;
}

# Strip a trailing /page/N pagination segment so list routes keep routing on
# their base path; the page number flows through the normal $_GET['page'] path.
# Cleared unconditionally first: PHP itself populates $_GET['page'] from a
# `?page=` query string, and the path segment is the only canonical source,
# so a stale `?page=` link cannot serve paginated content at a second URL.
[$request_uri, $page_from_path] = Http\extract_page_segment((string)$request_uri);
unset($_GET['page']);
if ($page_from_path !== null) {
    $_GET['page'] = $page_from_path;
}

$action = strtok($request_uri, '/');
$lookup = strtok('/');
$sublookup = strtok('/');

$request_uri_with_query = $_SERVER['REQUEST_URI'] ?? '';

# Lookups against stored slugs and redirect keys run on the decoded path: the
# request arrives percent-encoded, while a slug is stored as the author wrote it
# (`café`, `my post`). Matching the two without decoding meant such a post 404ed
# at the very permalink every link on the site — and its own feed entry — points
# at. Route names are matched on the raw segment, as before.
$redirect_path = rawurldecode(trim((string) $request_uri, '/'));
if (str_contains($redirect_path, '/')) {
    $redirect_url = find_redirect($redirect_path);
    if ($redirect_url !== null) {
        header('Location: ' . Http\sanitize_location($redirect_url), true, 301);
        exit;
    }
}

Route\register_app_routes($action, $lookup, $sublookup);

# Decided before the route runs, because a handler may send its own body and
# die() (feeds, the export download) — by then it is too late for a header.
if (Response\should_noindex($action, $_GET)) {
    Response\mark_noindex();
}

$template = $action;
# Empty when the request has no first segment at all (e.g. `//`), which must not
# be looked up: every status post has an empty slug, so it would match one.
$slug_lookup = is_string($action) ? rawurldecode($action) : '';
# A reserved name is never re-registered as a post: register_route() overwrites,
# and this runs after register_app_routes(), so a post slugged `login` used to
# replace the login route and lock the author out of their own site. finalize_slug()
# suffixes such a slug at save time, but a database can carry one that predates
# that guard or came in through an importer, and the site has to keep working.
# The post is still served at its /status/<id> permalink.
if ($slug_lookup !== '' && !Route\is_reserved_route($action) && post_has_slug($slug_lookup) !== null) {
    Route\register_route($action, __NAMESPACE__ . '\\Response\respond_post', $slug_lookup);
    $template = 'status';
} elseif ($action !== false && !Route\is_reserved_route($action)) {
    $redirect_url = find_redirect($slug_lookup);
    if ($redirect_url !== null) {
        header('Location: ' . Http\sanitize_location($redirect_url), true, 301);
        exit;
    }
}
$data = Route\call_route($action);
$action = $data['action'] ?? $action;

switch ($action) {
    case false:
    case '404':
        $action = '404';
        $template = '404';
        break;
}

# Views
if (isset($_SESSION[SESSION_LOGIN])) {
    ob_start();
    Theme\part('html', '');
    $page = ob_get_clean();
    echo preg_replace(
        '/<body([^>]*)>/',
        '<body$1>' . Theme\admin_toolbar_html(),
        $page,
        1
    );
} else {
    // Conditional GET for cacheable content pages. Excludes the always-fresh
    // login page and the (intentionally cacheable but non-200) 404 response,
    // which must not answer with 304.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD'], true) && !in_array($action, ['login', '404'], true)) {
        Response\send_304_if_current(
            Response\latest_content_timestamp(),
            \Lamb\Config\config_modified_timestamp()
        );
    }
    Theme\part('html', '');
}
