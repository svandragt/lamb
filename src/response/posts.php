<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Security;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\delete_redirect_for_slug;
use function Lamb\Http\request_string;
use function Lamb\notify_post_subscribers;
use function Lamb\parse_bean;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;
use function Lamb\Post\rendered_checkbox_states;
use function Lamb\Post\sanitize_explicit_slug;
use function Lamb\Post\toggle_checkbox;
use function Lamb\Route\is_reserved_route;

/**
 * Handles post creation from a form submission.
 *
 * Validates the CSRF token, submit value, and content, stores the post, then redirects.
 * Returns early (void) when validation fails or the submit button does not match.
 *
 * @return void
 */
function redirect_created(): void
{
    Security\require_login();
    Security\require_csrf();
    if (request_string($_POST['submit'] ?? null) !== SUBMIT_CREATE) {
        return;
    }
    $contents = trim(request_string($_POST['contents'] ?? null) ?? '');
    if (empty($contents)) {
        return;
    }

    $bean = populate_bean($contents);
    \Lamb\ensure_preview_token($bean);

    try {
        finalize_and_store_post($bean);
        // Remove any existing redirect for this slug — the new post takes priority
        if (!empty($bean->slug)) {
            delete_redirect_for_slug($bean->slug);
            warn_if_manual_redirect((string) $bean->slug);
        }
    } catch (SQL $e) {
        $_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
    }
    notify_post_subscribers($bean);
    redirect_uri('/');
}

/**
 * Flashes a warning when a slug a post just claimed is also listed under
 * `[redirections]` in the INI config.
 *
 * The manual redirect wins over the post (it is checked first), so the post would
 * be unreachable at its own URL without the author being told why. Both save paths
 * (create and edit) check this, so the message lives in one place.
 *
 * @param string $slug The slug the post was saved with.
 * @return void
 */
function warn_if_manual_redirect(string $slug): void
{
    global $config;

    if ($slug === '') {
        return;
    }
    if (!isset($config['redirections'][$slug])) {
        return;
    }

    // Plain text: the themes escape a flash before printing it, so markup here
    // reaches the author as literal tags.
    $_SESSION['flash'][] = 'A manual redirect for "' . $slug
        . '" still exists in Settings → [redirections]. You may want to remove it.';
}

/**
 * Records the 301 that keeps a renamed post's old URL working.
 *
 * Points $old_slug at $new_slug, first dropping any redirect that leads *away*
 * from the new slug — a leftover from an earlier rename would otherwise bounce
 * visitors of the new URL straight back out, or loop.
 *
 * The target is passed through sanitize_explicit_slug(): the same sanitiser runs
 * when a slug is first read from front matter, but a slug that reached this point
 * some other way must not be able to turn the stored redirect into a
 * protocol-relative "//host/..." open redirect either.
 *
 * @param string $old_slug The slug the post used to live at.
 * @param string $new_slug The slug it now lives at.
 * @return void
 */
function store_slug_change_redirect(string $old_slug, string $new_slug): void
{
    delete_redirect_for_slug($new_slug);

    $auto_redirect = R::dispense('redirect');
    $auto_redirect->from_slug = $old_slug;
    // Encoded the same way permalink_path() encodes it, so the 301 lands on a
    // URL the router can read back (a slug may carry a space or a non-ASCII
    // character).
    $auto_redirect->to_url    = '/' . \Lamb\encode_path_segment(sanitize_explicit_slug($new_slug));
    R::store($auto_redirect);
}

/**
 * Reduces a request Referer to a safe same-origin redirect target (path plus
 * query), or '/' when it is missing, unparseable, or points at another origin.
 *
 * This is the open-redirect guard the redirect-after-action handlers share:
 * redirect_uri()/sanitize_location() only strip control characters and do not
 * check the host, so an off-site Referer would otherwise redirect off-site.
 *
 * The host check alone was not enough for that. A same-origin Referer of
 * `https://site/​/evil.test/x` has host `site`, so it passed — and yielded the
 * path `//evil.test/x`, which a browser resolves as protocol-relative and
 * follows off-site. `/\evil.test` does the same, since browsers normalise the
 * backslash. Referer is a request header, so its path is caller-supplied
 * either way. local_redirect_target() already refuses both shapes for
 * `?redirect_to=` and states why, and sanitize_explicit_slug() refuses them for
 * a slug — so the final answer is delegated to it rather than restated here.
 *
 * @param string|null $referer The request Referer header (may be null).
 * @return string A same-origin path (with query), or '/'.
 */
function safe_referer_path(?string $referer): string
{
    if ($referer === null || $referer === '') {
        return '/';
    }
    $parts = parse_url($referer);
    if ($parts === false) {
        return '/';
    }
    if (isset($parts['host']) && $parts['host'] !== parse_url(ROOT_URL, PHP_URL_HOST)) {
        return '/';
    }
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        return '/';
    }
    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    return local_redirect_target($path);
}

/**
 * Computes where to send the user after deleting a post: back to the page the
 * delete button was pressed on (the request Referer), so deleting from a tag,
 * search, or drafts listing no longer bounces them to the home page.
 *
 * Falls back to the home page when there is no usable same-origin Referer (see
 * safe_referer_path()), or when it is the deleted post's own permalink — that
 * page now 404s, so a delete from a status page still lands on the home page.
 *
 * @param string|null $referer  The request Referer header.
 * @param string      $own_path The deleted post's permalink path (e.g. /status/12).
 * @return string A same-origin path to redirect to, or '/'.
 */
function delete_return_path(?string $referer, string $own_path): string
{
    $target = safe_referer_path($referer);
    if (explode('?', $target, 2)[0] === $own_path) {
        return '/';
    }
    return $target;
}

/**
 * Soft-deletes a post and redirects back to the page the delete was pressed on.
 *
 * @param mixed $args Expects first element to be the post ID.
 * @return void
 */
#[NoReturn]
function redirect_deleted(mixed $args): void
{
    if (empty($_POST)) {
        redirect_uri('/');
    }
    Security\require_login();
    Security\require_csrf();

    [$id] = $args;
    $post = R::load('post', (int)$id);
    $own_path = (string) parse_url(\Lamb\permalink($post), PHP_URL_PATH);
    if ($post->id) {
        soft_delete_post($post);
    }
    redirect_uri(delete_return_path($_SERVER['HTTP_REFERER'] ?? null, $own_path));
}

/**
 * Restores a soft-deleted post and redirects back to the trash page.
 *
 * @param mixed $args Expects first element to be the post ID.
 * @return void
 */
#[NoReturn]
function redirect_restored(mixed $args): void
{
    if (empty($_POST)) {
        redirect_uri('/trash');
    }
    Security\require_login();
    Security\require_csrf();

    [$id] = $args;
    $post = R::load('post', (int)$id);
    if ($post->id) {
        restore_post($post);
    }
    redirect_uri('/trash');
}

/**
 * Soft-delete a post by setting its deleted flag and recording the deletion timestamp.
 *
 * @param OODBBean $post
 * @return void
 */
function soft_delete_post(OODBBean $post): void
{
    $post->deleted    = 1;
    $post->deleted_at = \Lamb\now();
    R::store($post);

    // Re-send any webmentions this post previously sent so receivers re-fetch
    // the now-gone source and drop the displayed mention (#331).
    \Lamb\Webmention\enqueue_deletion_resends((int) $post->id);
}

/**
 * Restore a soft-deleted post by clearing its deleted flags.
 *
 * @param OODBBean $post
 * @return void
 */
function restore_post(OODBBean $post): void
{
    $post->deleted    = null;
    $post->deleted_at = null;
    R::store($post);

    // The post is back: abandon deletion re-sends /_cron has not yet drained,
    // and re-queue any it already delivered so receivers re-display the mention
    // (#331).
    \Lamb\Webmention\reconcile_resends_on_restore((int) $post->id);
}

/**
 * Redirects the user after editing a post.
 *
 * Validates the CSRF token and submit button, parses the updated content, stores
 * the post, and handles slug-change redirects. Returns early (void) when validation fails.
 *
 * @return void
 */
function redirect_edited(): void
{
    Security\require_login();
    Security\require_csrf();
    $validSubmits = [SUBMIT_EDIT];
    if (!in_array(request_string($_POST['submit'] ?? null), $validSubmits, true)) {
        return;
    }

    $contents = trim(request_string($_POST['contents'] ?? null) ?? '');
    // filter_var() over $_POST rather than filter_input(INPUT_POST, ...): the
    // same FILTER_SANITIZE_NUMBER_INT over the same value, but read where every
    // other input in this function is read. filter_input() reads the SAPI's
    // request data, which does not exist under the CLI SAPI the test suite runs
    // on — it returns null there whatever $_POST holds, so everything below this
    // line was unreachable from a unit test (hence the existing coverage
    // stopping at "early-return paths"). Non-numeric input still sanitises to ''
    // and returns here, exactly as before.
    $id = trim((string) filter_var(request_string($_POST['id'] ?? null) ?? '', FILTER_SANITIZE_NUMBER_INT));
    if (empty($contents) || empty($id)) {
        return;
    }

    $bean = R::load('post', (int)$id);
    $old_slug = $bean->slug;

    $bean->body = $contents;

    parse_bean($bean);
    \Lamb\ensure_preview_token($bean);
    // Must match the format parse_bean() above just rendered, or upgrade_posts()
    // re-parses and re-stores the post on the next read.
    $bean->version = POST_VERSION;
    $bean->updated = \Lamb\now();

    if (is_reserved_route($bean->slug)) {
        $_SESSION['flash'][] = 'Failed to save, slug is in use: "' . $bean->slug . '"';

        return;
    }

    // A slug claimed by another post gets an id suffix, and the final slug is
    // pinned into the body's front matter so the edit form shows it.
    finalize_slug($bean);

    // Editing a feed-sourced post through the form marks it author-owned, so
    // later crawls stop overwriting it (they still never duplicate it).
    lock_if_feed_sourced($bean);

    try {
        R::store($bean);
    } catch (SQL $e) {
        // Return, like the reserved-slug check above: everything below this
        // point is a consequence of the edit having been saved. Falling through
        // on a failed write — a locked SQLite file while /_cron holds it is the
        // realistic one — pointed the post's live URL at a slug that was never
        // stored (a 301 to a 404), and announced the unsaved content to
        // webmention receivers and the WebSub hub.
        $_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();

        return;
    }

    $new_slug = $bean->slug;
    if (!empty($old_slug) && $old_slug !== $new_slug) {
        store_slug_change_redirect((string) $old_slug, (string) $new_slug);
    }

    warn_if_manual_redirect((string) $new_slug);

    notify_post_subscribers($bean);

    $redirect = safe_referer_path($_SESSION['edit-referrer'] ?? null);
    unset($_SESSION['edit-referrer']);
    redirect_uri($redirect);
}

/**
 * Marks a feed-sourced post as author-owned so feed re-ingestion leaves it alone.
 *
 * Feed crawls dedupe on `feeditem_uuid` and re-sync source updates onto matching
 * posts. Once the author edits such a post through the edit form, that auto-sync
 * would clobber their changes, so set `feed_locked` to opt the post out of future
 * updates. Posts that did not originate from a feed (`feeditem_uuid` empty) are
 * left untouched.
 *
 * @param OODBBean $bean The post being saved.
 * @return void
 */
function lock_if_feed_sourced(OODBBean $bean): void
{
    if (!empty($bean->feeditem_uuid)) {
        $bean->feed_locked = 1;
    }
}

/**
 * Toggles a GitHub-style task-list checkbox and persists it as a post edit.
 *
 * AJAX endpoint for the logged-in author: flips the Nth `[ ]`/`[x]` marker in
 * the post body (the index supplied by the rendered checkbox's
 * `data-checkbox-index`), re-parses so `transformed` reflects the new state,
 * and bumps `updated`. Login-only, no CSRF — matching respond_upload() and the
 * SameSite=Strict session, which already blocks cross-site POSTs. Webmention
 * and WebSub are intentionally skipped: ticking a box is a minor edit and must
 * not re-notify subscribers.
 *
 * @param array<int, string> $_args Unused route arguments.
 * @return void
 * @throws \JsonException
 */
#[NoReturn]
function respond_checkbox(array $_args): void
{
    Security\require_login();

    header('Content-Type: application/json');

    $id      = (int) ($_POST['id'] ?? 0);
    $index   = (int) ($_POST['index'] ?? -1);
    $checked = filter_var($_POST['checked'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!apply_checkbox_toggle($id, $index, $checked)) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['ok' => false], JSON_THROW_ON_ERROR);
        die();
    }

    echo json_encode(['ok' => true, 'checked' => $checked], JSON_THROW_ON_ERROR);
    die();
}

/**
 * Toggles a task-list checkbox in a post and persists it as an edit.
 *
 * Loads the post, flips the Nth `[ ]`/`[x]` marker in its body, re-parses so
 * `transformed`/`description` reflect the new state, and bumps `updated`. The
 * testable core of respond_checkbox() (which adds auth and the JSON response).
 *
 * @param int  $id      The post id.
 * @param int  $index   Zero-based checkbox index.
 * @param bool $checked The desired checked state.
 * @return bool True on success, false when the post is missing or the index invalid.
 */
function apply_checkbox_toggle(int $id, int $index, bool $checked): bool
{
    if ($index < 0) {
        return false;
    }

    $bean = R::load('post', $id);
    if (!$bean->id) {
        return false;
    }

    // The index names a *rendered* checkbox, so the rewrite is checked against
    // the renderer rather than trusted: no such checkbox, or a rewrite that
    // moves any other box, is refused. The client then reverts the tick instead
    // of the author silently finding a different task crossed off — which is
    // what a document the source scan reads differently from the renderer
    // (see Post\checkbox_marker_offsets) used to do.
    $body   = (string) $bean->body;
    $before = rendered_checkbox_states($body);
    if (!isset($before[$index])) {
        return false;
    }

    $toggled  = toggle_checkbox($body, $index, $checked);
    $expected = $before;
    $expected[$index] = $checked;
    if (rendered_checkbox_states($toggled) !== $expected) {
        return false;
    }

    $bean->body = $toggled;
    parse_bean($bean);
    $bean->updated = \Lamb\now();

    try {
        R::store($bean);
    } catch (SQL $e) {
        $_SESSION['flash'][] = 'Failed to update checkbox: ' . $e->getMessage();
        return false;
    }

    return true;
}

/**
 * Responds with the status of a post.
 *
 * @param array<int, string> $args An array containing the post ID.
 * @return array<string, mixed> The transformed data representing the post's status.
 */
function respond_status(array $args): array
{
    [$id] = $args;
    $bean = R::load('post', (int)$id);
    if (!\Lamb\is_viewable($bean) && !\Lamb\preview_token_valid($bean, request_string($_GET['preview'] ?? null))) {
        return respond_404([], true);
    }

    $posts = [$bean];
    $data['posts'] = $posts;

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}

/**
 * Responds to an edit request, returning the post to render in the edit form.
 *
 * @param array<int, string> $args The first element should be the post ID.
 * @return array<string, mixed>
 */
function respond_edit(array $args): array
{
    if (!empty($_POST)) {
        redirect_edited();
    }
    Security\require_login();

    [$id] = $args;

    $_SESSION['edit-referrer'] = $_SERVER['HTTP_REFERER'] ?? null;

    return ['post' => R::load('post', (int)$id)];
}

/**
 * Responds to a slug-based post request by retrieving and transforming a single post.
 *
 * @param array<int, string> $args The first element is the post slug.
 * @return array<string, mixed> The transformed post.
 */
function respond_post(array $args): array
{
    [$slug] = $args;
    $post = R::findOne('post', ' slug = ? ', [$slug]);
    if ($post === null || (!\Lamb\is_viewable($post) && !\Lamb\preview_token_valid($post, request_string($_GET['preview'] ?? null)))) {
        return respond_404([]);
    }
    $data['posts'] = [$post];

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}
