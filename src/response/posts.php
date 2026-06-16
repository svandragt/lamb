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
use function Lamb\notify_post_subscribers;
use function Lamb\parse_bean;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;
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
    global $config;
    Security\require_login();
    Security\require_csrf();
    if ($_POST['submit'] !== SUBMIT_CREATE) {
        return;
    }
    $contents = trim($_POST['contents'] ?? '');
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
            $redirections = $config['redirections'] ?? [];
            if (isset($redirections[$bean->slug])) {
                $_SESSION['flash'][] = 'A manual redirect for <code>' . $bean->slug
                    . '</code> still exists in Settings → [redirections]. You may want to remove it.';
            }
        }
    } catch (SQL $e) {
        $_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
    }
    notify_post_subscribers($bean);
    redirect_uri('/');
}

/**
 * Soft-deletes a post and redirects to the homepage.
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
    if ($post->id) {
        soft_delete_post($post);
    }
    redirect_uri('/');
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
    global $config;

    Security\require_login();
    Security\require_csrf();
    $validSubmits = [SUBMIT_EDIT];
    if (!in_array($_POST['submit'], $validSubmits, true)) {
        return;
    }

    $contents = trim(($_POST['contents']));
    $id = trim(filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT) ?: '');
    if (empty($contents) || empty($id)) {
        return;
    }

    $bean = R::load('post', (int)$id);
    $old_slug = $bean->slug;

    $bean->body = $contents;

    parse_bean($bean);
    \Lamb\ensure_preview_token($bean);
    $bean->version = 1;
    $bean->updated = \Lamb\now();

    if (is_reserved_route($bean->slug)) {
        $_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $bean->slug . '</code>';

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
        $_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();
    }

    $new_slug = $bean->slug;
    if (!empty($old_slug) && $old_slug !== $new_slug) {
        // Remove any redirect pointing to the new slug to avoid redirect loops
        delete_redirect_for_slug($new_slug);
        // Store a redirect from the old slug to the new one
        $auto_redirect = R::dispense('redirect');
        $auto_redirect->from_slug = $old_slug;
        $auto_redirect->to_url = '/' . $new_slug;
        R::store($auto_redirect);
    }

    if (!empty($new_slug)) {
        $redirections = $config['redirections'] ?? [];
        if (isset($redirections[$new_slug])) {
            $_SESSION['flash'][] = 'A manual redirect for <code>' . $new_slug
                . '</code> still exists in Settings → [redirections]. You may want to remove it.';
        }
    }

    notify_post_subscribers($bean);

    $redirect = $_SESSION['edit-referrer'];
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

    $bean->body = toggle_checkbox((string) $bean->body, $index, $checked);
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
    if (!\Lamb\is_viewable($bean) && !\Lamb\preview_token_valid($bean, $_GET['preview'] ?? null)) {
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
    if ($post === null || (!\Lamb\is_viewable($post) && !\Lamb\preview_token_valid($post, $_GET['preview'] ?? null))) {
        return respond_404([]);
    }
    $data['posts'] = [$post];

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}
