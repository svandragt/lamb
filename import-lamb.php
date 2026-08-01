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
 */

namespace Lamb;

use Lamb\Import;
use Lamb\Restore;
use RedBeanPHP\R;
use RuntimeException;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-lamb.php must be run from the command line.\n");
    exit(1);
}

define('ROOT_DIR', __DIR__ . '/src');
require __DIR__ . '/vendor/autoload.php';

[$path, $dry_run, $replace, $site_url] = Restore\parse_restore_args($argv);
if ($path === null) {
    fwrite(STDERR, "Usage: php import-lamb.php <lamb-export.zip|directory> [--dry-run] [--replace] [--site-url=<url>]\n");
    exit(1);
}

try {
    [, $reader] = Restore\open_source($path);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$data_dir = getenv('LAMB_DATA_DIR') ?: __DIR__ . '/data';
Bootstrap\bootstrap_db($data_dir);

global $config;
$config = Config\load();
Config\apply_timezone($config);

if (!Config\experimental_features_enabled($config)) {
    fwrite(STDERR, "The Lamb export importer is experimental. Enable it first: set experimental_features = true in Settings.\n");
    exit(1);
}

try {
    $manifest = Restore\read_manifest($reader);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$origin = Restore\origin_id($manifest, $site_url);
if ($origin === '') {
    fwrite(STDERR, "Warning: this archive names no site URL, so imported ids are namespaced by an empty origin. Pass --site-url=<url> to keep two sites' posts apart.\n");
}

Import\run_import(
    Restore\manifest_items($manifest, $reader, $origin),
    Restore\item_skip_reason(...),
    static fn(array $item): string => (string) $item['import_uuid'],
    Restore\import_post(...),
    $dry_run,
    static fn(string $uuid): ?\RedBeanPHP\OODBBean => R::findOne('post', ' import_uuid = ? ', [$uuid]),
    $replace,
);

$assets = Restore\restore_assets($manifest, ROOT_DIR . '/assets', $reader, $dry_run);
echo "Assets: restored={$assets['restored']} skipped={$assets['skipped']} rejected={$assets['rejected']}\n";
