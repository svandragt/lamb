<?php

/**
 * Known CMS importer — CLI script.
 *
 * Parses a Known RSS export (Site Configuration → Import/Export → RSS) and
 * feeds each published item through Lamb's existing post-creation pipeline.
 * Known's export is a partial WXR veneer: content lives in <description>
 * rather than <content:encoded>, there is no <wp:post_name> (the slug comes
 * from the <link> path leaf instead), and dates only carry <pubDate>.
 * Idempotent: re-running an import never recreates a row (dedup by
 * md5('known-' . guid) on the import_uuid column).
 *
 *   php import-known.php path/to/export.rss [--dry-run] [--replace]
 *
 * --dry-run     Parse and convert every item but write nothing to the DB
 *               or filesystem. Use this first to surface parsing errors.
 * --replace     Re-apply every already-imported item over its existing post
 *               instead of skipping it. Local edits made since the import are
 *               lost. For re-running an export after an HTML→Markdown fix.
 *
 * The script intentionally does NOT emit outbound webmentions or WebSub
 * pings — imported posts are pre-existing content, not new publications.
 *
 * @deprecated Delegates to `bin/lamb import known`. Kept for one release as
 *             a documented entry point; will be removed after that.
 */

namespace Lamb;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-known.php must be run from the command line.\n");
    exit(1);
}

fwrite(STDERR, "import-known.php is deprecated and will be removed in a future release; use `bin/lamb import known` instead.\n");

$command = array_merge(
    [PHP_BINARY, __DIR__ . '/bin/lamb', 'import', 'known'],
    array_slice($argv, 1)
);
passthru(implode(' ', array_map('escapeshellarg', $command)), $exit_code);
exit($exit_code);
