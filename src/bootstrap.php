<?php

namespace Lamb\Bootstrap;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use RuntimeException;
use RedBeanPHP\R;

/**
 * Loads the project's .env file into the process environment for the dev server.
 *
 * The PHP built-in server (`composer serve`) does not read .env, so
 * LAMB_LOGIN_PASSWORD would be unset and every login would fail. This pulls it
 * in via getenv() (the Putenv adapter — phpdotenv populates only $_ENV/$_SERVER
 * by default). Loading is immutable, so a real environment variable always wins
 * over the file; production deployments set LAMB_LOGIN_PASSWORD directly and are
 * never affected. phpdotenv is a dev dependency, so this no-ops when it is absent
 * (e.g. a `--no-dev` production install).
 *
 * @param string $root Directory containing the .env file (project root).
 * @return void
 */
function load_dotenv(string $root): void
{
    if (!class_exists(Dotenv::class)) {
        return;
    }

    $repository = RepositoryBuilder::createWithDefaultAdapters()
        ->addAdapter(PutenvAdapter::class)
        ->immutable()
        ->make();

    Dotenv::create($repository, $root)->safeLoad();
}

/**
 * The directory holding this install's mutable state: the SQLite database, the
 * session files, the SimplePie cache and the /_cron lock.
 *
 * `LAMB_DATA_DIR` moves all of it (the release-verify workflow and the
 * acceptance suite both do), so anything writing under `data/` has to ask here
 * rather than hardcode the default. A hardcoded `../data` was how the /_cron
 * lock ended up in a directory that, on an install with LAMB_DATA_DIR set, does
 * not exist at all — the lock could never be opened, and every run reported
 * "Already running" forever.
 *
 * The default is relative to the web root, which is the working directory of a
 * request (php-fpm and `php -S -t src` both chdir there). CLI entry points pass
 * their own absolute path via $cli_base, whose data dir is "$cli_base/data" —
 * relative to the repo root rather than to src/, which is why the two defaults
 * must stay distinct.
 *
 * @param string|null $cli_base Absolute base path for a CLI entry point (pass
 *                              __DIR__), or null for a web request.
 * @return string The data directory path.
 */
function data_dir(?string $cli_base = null): string
{
    $env = getenv('LAMB_DATA_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }

    return $cli_base !== null ? $cli_base . '/data' : '../data';
}

/**
 * The per-install login credential: a base64-encoded bcrypt hash of the admin
 * password, read from LAMB_LOGIN_PASSWORD.
 *
 * response.php's LOGIN_PASSWORD constant and should_start_session()'s marker
 * verification used to call getenv('LAMB_LOGIN_PASSWORD') independently —
 * the same "read independently" duplication LAMB_DATA_DIR had before
 * data_dir() converged it (issue #732, building on #691). Both now go
 * through this single resolver.
 *
 * @return string The bcrypt hash (base64-encoded), or '' when unset.
 */
function login_password(): string
{
    return (string) (getenv('LAMB_LOGIN_PASSWORD') ?: '');
}

/**
 * Initializes the database by configuring the SQLite connection and setting up the writer cache.
 *
 * @param string $data_dir The directory path where the database file will be stored.
 * @return void
 * @throws RuntimeException If the specified directory cannot be created.
 */
function bootstrap_db(string $data_dir): void
{
    if (!is_dir($data_dir)) {
        // 0750, not 0777: this directory holds lamb.db and the session files, so
        // only the web-server user has any business reading it. Under a permissive
        // umask 0777 would leave the database world-readable and replaceable.
        if (!mkdir($data_dir, 0750, true) && !is_dir($data_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $data_dir));
        }
    }
    R::setup(sprintf("sqlite:%s/lamb.db", $data_dir));
    R::useWriterCache(true);

    ensure_post_columns();
}

/**
 * Ensures the post table has the columns introduced by the soft-delete, draft
 * and export-import features.
 * Safe to call on any DB: no-ops if the table or columns don't exist yet.
 *
 * @return void
 */
function ensure_post_columns(): void
{
    $postTableExists = (bool) R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='post'");
    if (!$postTableExists) {
        return;
    }
    $columns = array_column(R::getAll('PRAGMA table_info(post)'), 'name');
    if (!in_array('deleted', $columns, true)) {
        R::exec('ALTER TABLE post ADD COLUMN deleted INTEGER');
    }
    if (!in_array('draft', $columns, true)) {
        R::exec('ALTER TABLE post ADD COLUMN draft INTEGER');
    }
    if (!in_array('import_uuid', $columns, true)) {
        R::exec('ALTER TABLE post ADD COLUMN import_uuid TEXT');
    }
    backfill_post_version($columns);
    backfill_imported_post_identity($columns);
    // The backfills above want the columns as they were; the indexes want them
    // as they now are, so the three the ALTERs just guaranteed are folded back
    // in — otherwise indexing `draft`/`deleted` would lag a boot behind the
    // upgrade that added them.
    ensure_post_indexes(array_merge($columns, ['deleted', 'draft', 'import_uuid']));
}

/**
 * The single-column indexes the post table is queried through, and the column
 * each one covers.
 *
 * RedBeanPHP's fluid mode creates columns but never indexes, so every one of
 * these lookups was a full table scan of `post` on every request that ran it:
 *
 * - `slug`      — index.php resolves the request path against it before any
 *                 route runs, and Micropub/Webmention map a URL back to a post
 *                 the same way. finalize_slug() also probes it once per save.
 * - `updated`   — latest_content_timestamp() takes the newest row by it for the
 *                 conditional-GET validator, on every anonymous page view, and
 *                 the feeds order by it.
 * - `version`   — backfill_post_version()'s "is there anything left to migrate"
 *                 probe. Once nothing is, the answer costs a whole scan to find.
 * - `feed_name` — same, for backfill_imported_post_identity().
 * - `draft`,
 *   `deleted`   — the admin toolbar's drafts/trash counts, on every logged-in
 *                 page render.
 *
 * `created` is deliberately absent: it would serve the listings' `ORDER BY
 * created DESC`, but SQLite then also picks it for the search page's `body
 * LIKE` queries, where an index scan plus a row lookup per row is slower than
 * the table scan it replaces. Measured on 30,000 posts, that trade cost the
 * search page about as much as it saved the home page.
 */
const POST_INDEXES = [
    'idx_post_slug'      => 'slug',
    'idx_post_updated'   => 'updated',
    'idx_post_version'   => 'version',
    'idx_post_feed_name' => 'feed_name',
    'idx_post_draft'     => 'draft',
    'idx_post_deleted'   => 'deleted',
];

/**
 * Creates any missing index from POST_INDEXES.
 *
 * Gated on a read of sqlite_master rather than issuing `CREATE INDEX IF NOT
 * EXISTS` unconditionally: DDL takes a write lock even when it changes nothing,
 * and writers serialise, so the unconditional form would make every request —
 * an anonymous page view included — queue behind whatever else holds the lock
 * (the same reasoning as backfill_post_version()'s probe). The statement still
 * carries IF NOT EXISTS so two requests racing on a fresh upgrade both succeed.
 *
 * A column the install does not have yet is skipped; fluid mode adds it on the
 * first write that needs it, and the next boot indexes it.
 *
 * @param list<string> $columns Column names of the post table.
 * @return void
 */
function ensure_post_indexes(array $columns): void
{
    $existing = R::getCol("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='post'");
    foreach (POST_INDEXES as $name => $column) {
        if (in_array($name, $existing, true) || !in_array($column, $columns, true)) {
            continue;
        }
        R::exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON post(' . $column . ')');
    }
}

/**
 * One-time migration: mark pre-versioning posts as version 1.
 *
 * `transformed` is already populated for these posts (parse_bean() ran at
 * creation/edit time); only the version column needs stamping so
 * upgrade_posts() never writes them again.
 *
 * Probes with a SELECT before writing. SQLite takes a write lock for an UPDATE
 * even when no row matches it, so running this unconditionally made every
 * request — an anonymous page view included — a writer, and writers serialise:
 * with another request or a /_cron run holding the lock, the read blocked
 * behind it (up to PDO's 60-second busy timeout) instead of being served under
 * a shared read lock. The probe is a read, so it does not.
 *
 * Also skipped when the column does not exist yet: a `post` table predating it
 * has nothing to stamp, and naming it in an UPDATE is an error rather than a
 * no-op. Runs from ensure_post_columns(), which has already established that
 * the table exists and collected its columns.
 *
 * @param list<string> $columns Column names as they were before the ALTERs above.
 */
function backfill_post_version(array $columns): void
{
    if (!in_array('version', $columns, true)) {
        return;
    }
    if (!R::getCell('SELECT 1 FROM post WHERE version IS NULL LIMIT 1')) {
        return;
    }

    R::exec('UPDATE post SET version = 1 WHERE version IS NULL');
}

/**
 * One-time migration: the WordPress and Known importers used to stamp migrated
 * posts as feed items, which made every one of them render "Via wordpress" and
 * barred them from webmentions and WebSub forever. Move them onto import_uuid.
 *
 * The `source_url IS NULL` guard is what keeps a genuinely subscribed feed
 * literally named `wordpress` or `known` out of the migration: feed ingestion
 * records the item permalink in source_url (src/post.php), the importers never
 * did. bootstrap_db() runs before Config\load(), so the configured feed names
 * are not available here — the guard is the only discriminator there is.
 *
 * Rows whose uuid is already claimed by another post's import_uuid are left
 * alone rather than duplicated. Idempotent, so running it every boot is
 * correct — but it probes with a SELECT first, because an UPDATE that matches
 * nothing still takes a write lock (see backfill_post_version()).
 *
 * @param list<string> $columns Column names as they were before this call.
 */
function backfill_imported_post_identity(array $columns): void
{
    if (!in_array('feed_name', $columns, true) || !in_array('feeditem_uuid', $columns, true)) {
        return;
    }
    $source_url = in_array('source_url', $columns, true) ? ' AND source_url IS NULL' : '';
    // One predicate, used by the probe and the update, so the two cannot
    // disagree about which rows this migration is for.
    $where = "feed_name IN ('wordpress', 'known') AND feeditem_uuid IS NOT NULL" . $source_url
        . ' AND NOT EXISTS (SELECT 1 FROM post other WHERE other.import_uuid = post.feeditem_uuid)';

    // Probe first: see backfill_post_version() for why an unconditional UPDATE
    // makes every request a writer even once there is nothing left to migrate.
    if (!R::getCell('SELECT 1 FROM post WHERE ' . $where . ' LIMIT 1')) {
        return;
    }

    R::exec('UPDATE post SET import_uuid = feeditem_uuid, feeditem_uuid = NULL, feed_name = NULL WHERE ' . $where);
}

/**
 * Configures session security settings without starting the session.
 *
 * This hardens the session by enabling strict mode, making cookies inaccessible
 * via JavaScript, ensuring secure transmission over HTTPS, and setting a SameSite
 * attribute to mitigate CSRF. It also disables PHP's session cache limiter so that
 * starting a session does not emit no-cache headers — the application manages cache
 * headers itself (see cache_headers()).
 *
 * @return void
 */
function configure_session(): void
{
    // Make cookies inaccessible via JavaScript (XSS).
    ini_set("session.cookie_httponly", 1);
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set("session.cookie_secure", $secure ? 1 : 0);
    ini_set("session.use_strict_mode", 1);

    // Remember the login for a week: persist the server-side session that long so
    // GC doesn't reap it, and give the session cookie a matching lifetime so it
    // survives a browser restart instead of dying as a session cookie.
    ini_set("session.gc_maxlifetime", (string) REMEMBER_LIFETIME);

    // Prevent the browser from sending cookies along with cross-site requests (CSRF)
    $cookie_params = [
        'lifetime' => REMEMBER_LIFETIME,
        'samesite' => 'Strict',
        'path' => '/',
        'httponly' => true,
    ];
    if ($secure) {
        $cookie_params['secure'] = true;
    }
    session_set_cookie_params($cookie_params);
    session_name('LAMBSESSID');

    // We manage Cache-Control ourselves (cache_headers()). Without this, session_start()
    // would emit no-store/no-cache on every page that has a session, defeating caching.
    session_cache_limiter('');
}

/**
 * Builds the signed value for the lamb_logged_in marker cookie: a random id
 * with an HMAC tag so the server can later confirm it issued the cookie without
 * touching session storage.
 *
 * The key is the per-install bcrypt login hash (LAMB_LOGIN_PASSWORD): high
 * entropy, never sent to the client, and one-way HMAC means the cookie leaks
 * nothing about it. Changing the password rotates the key and invalidates
 * outstanding markers (logs the author out everywhere).
 *
 * @param string $id     Random opaque identifier.
 * @param string $secret HMAC key (the bcrypt login hash).
 * @return string Signed marker value "<id>.<hmac>".
 */
function sign_login_marker(string $id, string $secret): string
{
    return $id . '.' . hash_hmac('sha256', $id, $secret);
}

/**
 * Verifies a lamb_logged_in marker cookie's HMAC in constant time. A missing,
 * malformed, tampered, or wrong-key value is rejected — so a forged cookie costs
 * nothing beyond this check and never reaches session_start().
 *
 * @param string $value  The cookie value to verify.
 * @param string $secret HMAC key (the bcrypt login hash).
 * @return bool
 */
function valid_login_marker(string $value, string $secret): bool
{
    if ($secret === '') {
        return false;
    }
    $dot = strrpos($value, '.');
    if ($dot === false) {
        return false;
    }
    $id = substr($value, 0, $dot);
    $sig = substr($value, $dot + 1);
    if ($id === '' || $sig === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $id, $secret), $sig);
}

/**
 * Decides whether a request should resume/start a PHP session.
 *
 * A session is only warranted when the request carries a lamb_logged_in marker
 * cookie whose HMAC validates — evidence the server itself issued it at login.
 * Bare cookie presence (a forged marker, or any LAMBSESSID value) is NOT enough:
 * starting a session on unvalidated input lets an attacker flood the server with
 * junk cookies and force a new session file per request (resource-exhaustion DoS).
 * Anonymous visitors get no session — and therefore no Set-Cookie and no no-cache
 * headers — so their pages remain cacheable (issue #116).
 *
 * @param array<string, mixed> $cookies Typically $_COOKIE.
 * @return bool
 */
function should_start_session(array $cookies): bool
{
    $marker = $cookies['lamb_logged_in'] ?? null;
    if (!is_string($marker)) {
        return false;
    }
    return valid_login_marker($marker, login_password());
}

/**
 * Starts the session if one is not already active. Idempotent.
 *
 * Routes that need a session for an otherwise-anonymous request (the login page,
 * CSRF-protected POSTs, setting a flash before redirecting) call this explicitly.
 * configure_session() must have run first.
 *
 * @return void
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_start();
}

/**
 * Points PHP's session storage at a dedicated directory under the app's data dir.
 *
 * The default save_path is usually shared with every other PHP app on the host.
 * Because PHP's GC sweeps the whole save_path against the running request's
 * gc_maxlifetime, Lamb's week-long lifetime would otherwise spare other apps'
 * short-lived sessions (they linger), and their shorter GC would reap Lamb's
 * week-long sessions early. A dedicated directory isolates Lamb's GC to its own
 * files in both directions, and putting it under the persistent data dir means
 * a deploy or container restart that wipes ephemeral storage no longer logs the
 * author out before the marker cookie expires.
 *
 * @param string $data_dir The app data directory (same one that holds lamb.db).
 * @return void
 * @throws RuntimeException If the sessions directory cannot be created.
 */
function configure_session_save_path(string $data_dir): void
{
    $path = $data_dir . '/sessions';
    if (!is_dir($path)) {
        // 0700: only the web-server/PHP user should read session files. Same
        // check-and-throw as bootstrap_db()'s data dir: an ini_set() pointing
        // at a directory that was never created would silently degrade every
        // session to "starts, never persists" rather than fail loudly.
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $path));
        }
    }
    ini_set('session.save_path', $path);
}

/**
 * Initializes and secures a session, starting it only for (previously) logged-in users.
 *
 * @param string $data_dir The app data directory (same one that holds lamb.db).
 * @return void
 */
function bootstrap_session(string $data_dir): void
{
    configure_session();
    configure_session_save_path($data_dir);
    if (should_start_session($_COOKIE)) {
        start_session();
    }
}

/**
 * Returns the Cache-Control headers to emit for the current request.
 *
 * Logged-in responses are private and uncacheable; anonymous responses are
 * cacheable so a CDN/reverse-proxy/browser can serve them without hitting PHP.
 *
 * Vary: Cookie tells shared caches to key on the request cookies, so a cached
 * anonymous page is never served to a logged-in user (who always carries the
 * session/login cookie) and vice versa.
 *
 * @param bool $logged_in Whether the current visitor is logged in.
 * @return string[] Header strings ready to pass to header().
 */
function cache_headers(bool $logged_in): array
{
    if ($logged_in) {
        return [
            'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma: no-cache',
            'Vary: Cookie',
        ];
    }
    return [
        'Cache-Control: max-age=300',
        'Vary: Cookie',
    ];
}

/**
 * Formats a Unix timestamp as an RFC 7231 HTTP-date (always GMT).
 *
 * @param int $ts Unix timestamp.
 * @return string e.g. "Thu, 01 Jan 1970 00:00:00 GMT".
 */
function http_date(int $ts): string
{
    return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
}

/**
 * Builds a strong ETag from the content and config change timestamps.
 *
 * The two components are kept distinct rather than collapsed to their max(): a
 * settings edit and a post update can land in the same whole second, and a
 * single-timestamp ETag would not change in that case, so the edit would not
 * invalidate cached pages. Folding both in means either source moving (even
 * within the same second) yields a different ETag (issue #279).
 *
 * @param int $contentTs Unix timestamp of the most recent content change (the response's last-modified).
 * @param int $configTs  Unix timestamp of the last config edit.
 * @return string A quoted ETag value.
 */
function content_etag(int $contentTs, int $configTs): string
{
    return '"' . dechex($contentTs) . '-' . dechex($configTs) . '"';
}

/**
 * Decides whether the client already holds the current version of a response,
 * so a 304 Not Modified can be returned instead of a full body.
 *
 * Honours If-None-Match (against the ETag), and If-Modified-Since (against the
 * last-modified timestamp) only when no If-None-Match was sent — RFC 9110
 * §13.1.3: "A recipient MUST ignore If-Modified-Since if the request contains
 * an If-None-Match header field."
 *
 * That precedence is load-bearing here, not pedantry. A browser revalidating
 * sends both, and latest_content_timestamp() is not actually monotonic: it is
 * the newest `updated` among published posts, so trashing the newest post moves
 * it *backwards*. Checking the date after a non-matching ETag therefore answered
 * 304 to a client holding the pre-deletion page — the deleted post stayed in
 * its cache until the timestamp climbed back past where it had been. The ETag
 * had already noticed (it is what #279 added for same-second changes); it just
 * wasn't allowed to decide.
 *
 * @param array<string, mixed> $server          Typically $_SERVER.
 * @param string $etag            The current response ETag.
 * @param int    $lastModifiedTs  The current last-modified Unix timestamp.
 * @return bool
 */
function client_has_current_version(array $server, string $etag, int $lastModifiedTs): bool
{
    $if_none_match = trim($server['HTTP_IF_NONE_MATCH'] ?? '');
    if ($if_none_match !== '') {
        return $if_none_match === $etag;
    }
    $if_modified_since = $server['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($if_modified_since !== '') {
        $since = strtotime($if_modified_since);
        if ($since !== false && $lastModifiedTs <= $since) {
            return true;
        }
    }
    return false;
}
