<?php

/**
 * Lamb export importer — CLI script.
 *
 * Restores a `lamb-export/1` archive written by /export (see docs/export.md):
 * every post as the front-matter Markdown it was stored as, the row state the
 * manifest records, and the assets those posts reference. Accepts the `.zip`
 * or an already-unpacked directory. Idempotent: re-running never recreates a
 * row (dedup by md5('lamb-' . origin . '#' . id) on the import_uuid column,
 * where the origin is the manifest's site.url).
 *
 *   php import-lamb.php path/to/lamb-export-2026-07-29.zip [options]
 *
 * --dry-run          Read and convert everything but write nothing to the DB
 *                    or filesystem. Use this first.
 * --replace          Overwrite posts already imported from this archive —
 *                    body, slug, dates, draft and trash state. Local edits
 *                    made since the backup are lost; that is what it means.
 * --site-url=<url>   The origin to namespace post ids by. Use it when the
 *                    manifest carries no site.url, or when restoring an
 *                    archive from a site that has since moved.
 *
 * The script intentionally does NOT emit outbound webmentions or WebSub
 * pings — restored posts are pre-existing content, not new publications.
 *
 * @deprecated Delegates to `bin/lamb import lamb`. Kept for one release as a
 *             documented entry point; will be removed after that.
 */

namespace Lamb;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-lamb.php must be run from the command line.\n");
    exit(1);
}

fwrite(STDERR, "import-lamb.php is deprecated and will be removed in a future release; use `bin/lamb import lamb` instead.\n");

$command = array_merge(
    [PHP_BINARY, __DIR__ . '/bin/lamb', 'import', 'lamb'],
    array_slice($argv, 1)
);
passthru(implode(' ', array_map('escapeshellarg', $command)), $exit_code);
exit($exit_code);
