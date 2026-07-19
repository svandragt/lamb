<?php

/**
 * WordPress importer — CLI script.
 *
 * Parses a WordPress WXR (Tools → Export → "All content") and feeds each
 * published Post and Page through Lamb's existing post-creation pipeline.
 * Drafts, private posts, comments and custom post types are skipped this
 * round. Idempotent: re-running an import never recreates a row (dedup by
 * md5('wordpress-' . guid) on the feeditem_uuid column).
 *
 *   php import-wordpress.php path/to/wxr.xml [--dry-run]
 *
 * --dry-run     Parse and convert every item but write nothing to the DB
 *               or filesystem. Use this first to surface parsing errors.
 *
 * The script intentionally does NOT emit outbound webmentions or WebSub
 * pings — imported posts are pre-existing content, not new publications.
 */

namespace Lamb;

use Lamb\Import;
use Lamb\WordPress;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-wordpress.php must be run from the command line.\n");
    exit(1);
}

define('ROOT_DIR', __DIR__ . '/src');
require __DIR__ . '/vendor/autoload.php';

[$path, $dry_run] = Import\parse_import_args($argv);
if ($path === null) {
    fwrite(STDERR, "Usage: php import-wordpress.php <wxr.xml> [--dry-run]\n");
    exit(1);
}

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read WXR file: $path\n");
    exit(1);
}

$data_dir = getenv('LAMB_DATA_DIR') ?: __DIR__ . '/data';
Bootstrap\bootstrap_db($data_dir);

global $config;
$config = Config\load();
Config\apply_timezone($config);

$rss = WordPress\parse_wxr_file($path);
$items = WordPress\extract_items($rss);

Import\run_import(
    $items,
    WordPress\skip_reason(...),
    static fn(array $item): string => WordPress\wordpress_uuid((string) $item['guid']),
    WordPress\import_item(...),
    $dry_run,
);
