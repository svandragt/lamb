<?php

/**
 * Replies from Micro.blog, fetched by /_cron and shown under each post.
 *
 * Micro.blog's conversation.js embed is a per-post script that writes the
 * replies into the page with document.write(), so it cannot be served locally
 * and stalls the page while micro.blog is slow or down. Lamb asks the same
 * endpoint for its JSON form (`format=jf2`) from the server instead, keeps the
 * replies in its own table, and renders them with the theme. Visitors never
 * contact micro.blog, and a micro.blog outage leaves the last good copy showing.
 *
 * Turned on by setting `microblog_username`. User docs: docs/microblog-replies.md.
 */

namespace Lamb\Microblog;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Http\fetch_guarded;
use function Lamb\Http\is_valid_http_url;
use function Lamb\Webmention\cap_text;

/** The conversation endpoint; `url` and `format` are appended per post. */
const CONVERSATION_ENDPOINT = 'https://micro.blog/conversation.js';

/** Posts older than this are no longer checked for new replies. */
const SYNC_WINDOW_DAYS = 30;

/** How often a single post is checked again. */
const SYNC_INTERVAL = 60 * MINUTE_IN_SECONDS;

/** Most posts checked in one /_cron run, so a run stays short. */
const SYNC_POSTS_PER_RUN = 10;

/** Seconds before a single fetch is abandoned. */
const FETCH_TIMEOUT = 10;

/** Largest response kept; a busy conversation is a few tens of kilobytes. */
const FETCH_MAX_BYTES = 1_000_000;

/** Most replies stored (and shown) per post. */
const REPLIES_PER_POST = 100;

/** Longest reply text kept, in characters. */
const CONTENT_MAX = 2000;

/** Longest author name kept, in characters. */
const AUTHOR_MAX = 200;

/**
 * The configured Micro.blog username, or null when the feature is off.
 *
 * A leading `@` is accepted and dropped. Anything that is not a plain
 * username (letters, digits, underscores) reads as unset rather than being
 * put into a link.
 *
 * @param array<string, mixed> $config
 * @return string|null
 */
function username(array $config): ?string
{
    $value = $config['microblog_username'] ?? null;
    if (!is_string($value)) {
        return null;
    }
    $value = ltrim(trim($value), '@');

    return preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) === 1 ? $value : null;
}

/**
 * The micro.blog profile URL readers are sent to for replying.
 *
 * @param string $username
 * @return string
 */
function profile_url(string $username): string
{
    return 'https://micro.blog/' . rawurlencode($username);
}

/**
 * The JSON (jf2) conversation URL for one of this site's posts.
 *
 * @param string $permalink Absolute post URL.
 * @return string
 */
function conversation_url(string $permalink): string
{
    return CONVERSATION_ENDPOINT . '?url=' . rawurlencode($permalink) . '&format=jf2';
}

/**
 * Reads a jf2 conversation response into reply rows.
 *
 * Returns null when the body is not a jf2 feed at all, so the caller keeps
 * what it has. Entries without an id or an http(s) URL are skipped; the
 * rest are trimmed to plain text with capped lengths. HTML content is
 * reduced to text: the theme escapes what it prints, so nothing from
 * micro.blog is ever rendered as markup.
 *
 * @param string $body
 * @return list<array{wm_id:int, url:string, author_name:string, author_url:?string, content:string, published:?string}>|null
 */
function parse_replies(string $body): ?array
{
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['children']) || !is_array($data['children'])) {
        return null;
    }

    $replies = [];
    foreach ($data['children'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = $entry['wm-id'] ?? null;
        $url = $entry['url'] ?? null;
        if (!is_int($id) || $id <= 0 || !is_string($url) || !is_valid_http_url($url)) {
            continue;
        }

        $author = is_array($entry['author'] ?? null) ? $entry['author'] : [];
        $author_url = is_string($author['url'] ?? null) && is_valid_http_url($author['url'])
            ? $author['url']
            : null;
        $author_name = is_string($author['name'] ?? null) ? trim($author['name']) : '';
        if ($author_name === '') {
            $author_name = $author_url !== null ? (string) parse_url($author_url, PHP_URL_HOST) : 'Someone';
        }

        $published = null;
        if (is_string($entry['published'] ?? null)) {
            $timestamp = strtotime($entry['published']);
            $published = $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        }

        $replies[$id] = [
            'wm_id' => $id,
            'url' => $url,
            'author_name' => cap_text($author_name, AUTHOR_MAX),
            'author_url' => $author_url,
            'content' => cap_text(reply_text($entry['content'] ?? null), CONTENT_MAX),
            'published' => $published,
        ];
        if (count($replies) >= REPLIES_PER_POST) {
            break;
        }
    }

    return array_values($replies);
}

/**
 * The plain text of a jf2 `content` value (a string, or `text`/`html` keys).
 *
 * @param mixed $content
 * @return string
 */
function reply_text(mixed $content): string
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    if (is_string($content['text'] ?? null) && trim($content['text']) !== '') {
        return trim($content['text']);
    }
    if (is_string($content['html'] ?? null)) {
        $text = html_entity_decode(strip_tags($content['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    return '';
}

/**
 * Replaces a post's stored replies with the given set.
 *
 * Rows are matched on the micro.blog id, so a reply that is edited is
 * updated in place and one that is gone from micro.blog is deleted here too.
 *
 * @param int $post_id
 * @param list<array{wm_id:int, url:string, author_name:string, author_url:?string, content:string, published:?string}> $replies
 * @return void
 */
function store_replies(int $post_id, array $replies): void
{
    R::begin();
    try {
        $existing = [];
        foreach (R::find('microblogreply', ' post_id = ? ', [$post_id]) as $bean) {
            $existing[(int) $bean->wm_id] = $bean;
        }

        foreach ($replies as $reply) {
            $bean = $existing[$reply['wm_id']] ?? R::dispense('microblogreply');
            unset($existing[$reply['wm_id']]);
            $bean->post_id = $post_id;
            $bean->wm_id = $reply['wm_id'];
            $bean->url = $reply['url'];
            $bean->author_name = $reply['author_name'];
            $bean->author_url = $reply['author_url'];
            $bean->content = $reply['content'];
            $bean->published = $reply['published'];
            R::store($bean);
        }

        foreach ($existing as $gone) {
            R::trash($gone);
        }
        R::commit();
    } catch (\Throwable $e) {
        R::rollback();
        throw $e;
    }
}

/**
 * The stored replies for a post, oldest first.
 *
 * @param int $post_id
 * @return OODBBean[]
 */
function replies_for_post(int $post_id): array
{
    return array_values(R::find(
        'microblogreply',
        ' post_id = ? ORDER BY published IS NULL, published ASC, id ASC LIMIT ? ',
        [$post_id, REPLIES_PER_POST]
    ));
}

/**
 * The published posts due a check this run, newest first.
 *
 * @param int $now
 * @return OODBBean[]
 */
function posts_due(int $now): array
{
    $since = date('Y-m-d H:i:s', $now - SYNC_WINDOW_DAYS * 24 * 60 * MINUTE_IN_SECONDS);
    $posts = array_filter(
        R::find('post', ' created >= ? ORDER BY created DESC ', [$since]),
        static fn(OODBBean $post): bool => \Lamb\is_publicly_visible($post)
    );

    // Only a check inside the last interval can make a post not due, so older
    // bookkeeping rows need not be read.
    $recent = [];
    foreach (R::find('microblogsync', ' checked_at > ? ', [$now - SYNC_INTERVAL]) as $row) {
        $recent[(int) $row->post_id] = true;
    }

    // Newest first, so a fresh post gets its first check before older ones.
    $due = array_filter($posts, static fn(OODBBean $post): bool => !isset($recent[(int) $post->id]));

    return array_slice(array_values($due), 0, SYNC_POSTS_PER_RUN);
}

/**
 * Records that a post was just checked.
 *
 * @param int $post_id
 * @param int $now
 * @return void
 */
function mark_checked(int $post_id, int $now): void
{
    $row = R::findOne('microblogsync', ' post_id = ? ', [$post_id]) ?? R::dispense('microblogsync');
    $row->post_id = $post_id;
    $row->checked_at = $now;
    R::store($row);
}

/**
 * Drops replies of posts that no longer exist, and bookkeeping rows too old
 * to matter (a post is due again once SYNC_INTERVAL has passed anyway).
 *
 * @param int $now
 * @return void
 */
function prune(int $now): void
{
    R::exec('DELETE FROM microblogreply WHERE post_id NOT IN (SELECT id FROM post)');
    R::exec(
        'DELETE FROM microblogsync WHERE checked_at <= ? OR post_id NOT IN (SELECT id FROM post)',
        [$now - SYNC_INTERVAL]
    );
}

/**
 * Fetches the replies of the posts that are due, from /_cron.
 *
 * Only a 200 response holding a jf2 feed changes what is stored. Anything
 * else (micro.blog down, an error page, a timeout) leaves the stored replies
 * as they are and the post is tried again after SYNC_INTERVAL.
 *
 * @param array<string, mixed> $config
 * @param callable|null        $fetcher fn(string $url, array $opts): ?array — defaults to fetch_guarded().
 * @param int|null             $now
 * @return string Cron output line ('' when the feature is off or nothing was due).
 */
function sync_replies(array $config, ?callable $fetcher = null, ?int $now = null): string
{
    if (username($config) === null) {
        return '';
    }
    $now ??= time();
    $fetcher ??= static fn(string $u, array $o): ?array => fetch_guarded($u, $o);

    prune($now);

    $checked = 0;
    $failed = 0;
    foreach (posts_due($now) as $post) {
        $checked++;
        $result = $fetcher(conversation_url(\Lamb\permalink($post)), [
            'headers' => ['Accept: application/json'],
            'timeout' => FETCH_TIMEOUT,
            'max_bytes' => FETCH_MAX_BYTES,
        ]);
        $replies = $result !== null && $result['status'] === 200 ? parse_replies($result['body']) : null;
        if ($replies === null) {
            $failed++;
        } else {
            store_replies((int) $post->id, $replies);
        }
        mark_checked((int) $post->id, $now);
    }

    if ($checked === 0) {
        return '';
    }

    return sprintf('Micro.blog replies: checked %d post(s), %d failed.', $checked, $failed) . PHP_EOL;
}
