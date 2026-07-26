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
 * md5('known-' . guid) on the feeditem_uuid column).
 *
 *   php import-known.php path/to/export.rss [--dry-run]
 *
 * --dry-run     Parse and convert every item but write nothing to the DB
 *               or filesystem. Use this first to surface parsing errors.
 *
 * The script intentionally does NOT emit outbound webmentions or WebSub
 * pings — imported posts are pre-existing content, not new publications.
 */

namespace Lamb;

use Lamb\Import;
use Lamb\Known;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-known.php must be run from the command line.\n");
    exit(1);
}

define('ROOT_DIR', __DIR__ . '/src');
require __DIR__ . '/vendor/autoload.php';

[$path, $dry_run] = Import\parse_import_args($argv);
if ($path === null) {
    fwrite(STDERR, "Usage: php import-known.php <export.rss> [--dry-run]\n");
    exit(1);
}

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read export.rss file: $path\n");
    exit(1);
}

$data_dir = getenv('LAMB_DATA_DIR') ?: __DIR__ . '/data';
Bootstrap\bootstrap_db($data_dir);

global $config;
$config = Config\load();
Config\apply_timezone($config);

$rss = Import\parse_rss_file($path);
$items = Known\extract_items($rss);

Import\run_import(
    $items,
    Known\skip_reason(...),
    static fn(array $item): string => Known\known_uuid((string) $item['guid']),
    Known\import_item(...),
    $dry_run,
);
