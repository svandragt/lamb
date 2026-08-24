<?php

/**
 * WordPress importer — CLI script.
 *
 * Parses a WordPress WXR (Tools → Export → "All content") and feeds each
 * published Post and Page through Lamb's existing post-creation pipeline.
 * Drafts, private posts, comments and custom post types are skipped this
 * round. Idempotent: re-running an import never recreates a row (dedup by
 * md5('wordpress-' . guid) on the import_uuid column).
 *
 *   php import-wordpress.php path/to/wxr.xml [--dry-run] [--replace]
 *
 * --dry-run     Parse and convert every item but write nothing to the DB
 *               or filesystem. Use this first to surface parsing errors.
 * --replace     Re-apply every already-imported item over its existing post
 *               instead of skipping it. Local edits made since the import are
 *               lost. For re-running an export after a conversion fix.
 *
 * The script intentionally does NOT emit outbound webmentions or WebSub
 * pings — imported posts are pre-existing content, not new publications.
 *
 * @deprecated Delegates to `bin/lamb import wordpress`. Kept for one release
 *             as a documented entry point; will be removed after that.
 */

namespace Lamb;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-wordpress.php must be run from the command line.\n");
    exit(1);
}

fwrite(STDERR, "import-wordpress.php is deprecated and will be removed in a future release; use `bin/lamb import wordpress` instead.\n");

$command = array_merge(
    [PHP_BINARY, __DIR__ . '/bin/lamb', 'import', 'wordpress'],
    array_slice($argv, 1)
);
passthru(implode(' ', array_map('escapeshellarg', $command)), $exit_code);
exit($exit_code);
