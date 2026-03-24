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
use function Lamb\parse_bean;
use function Lamb\Post\populate_bean;
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
    if ($bean === null) {
        $_SESSION['flash'][] = 'Failed to create status: Invalid content.';
        return;
    }

    try {
        $id = R::store($bean);
        if (is_reserved_route($bean->slug)) {
            $bean->slug .= "-" . $id;
            R::store($bean);
        }
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
    $post->deleted_at = date('Y-m-d H:i:s');
    R::store($post);
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
}

/**
 * Publish a draft post by clearing its draft flag.
 *
 * @param OODBBean $post
 * @return void
 */
function publish_post(OODBBean $post): void
{
    $post->draft = null;
    R::store($post);
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
    $id = trim(filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT));
    if (empty($contents) || empty($id)) {
        return;
    }

    $bean = R::load('post', (int)$id);
    $old_slug = $bean->slug;

    $bean->body = $contents;

    parse_bean($bean);
    $bean->version = 1;
    $bean->updated = date("Y-m-d H:i:s");

    if (is_reserved_route($bean->slug)) {
        $_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $bean->slug . '</code>';

        return;
    }

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

    $redirect = $_SESSION['edit-referrer'];
    unset($_SESSION['edit-referrer']);
    redirect_uri($redirect);
}

/**
 * Responds with the status of a post.
 *
 * @param array $args An array containing the post ID.
 * @return array The transformed data representing the post's status.
 */
function respond_status(array $args): array
{
    [$id] = $args;
    $bean = R::load('post', (int)$id);
    if (!$bean->id || $bean->deleted) {
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
 * @param array $args The first element should be the post ID.
 * @return array
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
 * @param array $args The first element is the post slug.
 * @return array The transformed post.
 */
function respond_post(array $args): array
{
    [$slug] = $args;
    $post = R::findOne('post', ' slug = ? ', [$slug]);
    if ($post === null || $post->draft == 1 || $post->deleted == 1) {
        return respond_404([]);
    }
    $data['posts'] = [$post];

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}
