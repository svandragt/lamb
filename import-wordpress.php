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

use Lamb\WordPress;
use RedBeanPHP\R;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-wordpress.php must be run from the command line.\n");
    exit(1);
}

[$path, $dry_run] = parse_import_args($argv);
if ($path === null) {
    fwrite(STDERR, "Usage: php import-wordpress.php <wxr.xml> [--dry-run]\n");
    exit(1);
}

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read WXR file: $path\n");
    exit(1);
}

define('ROOT_DIR', __DIR__ . '/src');
require __DIR__ . '/vendor/autoload.php';

$data_dir = getenv('LAMB_DATA_DIR') ?: __DIR__ . '/data';
Bootstrap\bootstrap_db($data_dir);

global $config;
$config = Config\load();
Config\apply_timezone($config);

$rss = WordPress\parse_wxr_file($path);
$items = WordPress\extract_items($rss);

$downloader = $dry_run
    ? static fn(): ?string => null
    : 'Lamb\\WordPress\\default_image_downloader';

$created = 0;
$existed = 0;
$skipped = 0;
/** @var array<string, int> $skip_reasons */
$skip_reasons = [];
$total = count($items);

foreach ($items as $i => $item) {
    $reason = WordPress\skip_reason($item);
    if ($reason !== null) {
        $skipped++;
        $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
        continue;
    }
    $uuid = WordPress\wordpress_uuid((string) $item['guid']);
    if (R::findOne('post', ' feeditem_uuid = ? ', [$uuid])) {
        $existed++;
        continue;
    }

    $bean = WordPress\import_item($item, $downloader, $dry_run);
    if ($bean === null) {
        $skipped++;
        $skip_reasons['conversion failed'] = ($skip_reasons['conversion failed'] ?? 0) + 1;
        echo "[" . ($i + 1) . "/$total] skipped (conversion failed): "
            . trim((string) $item['title']) . "\n";
        continue;
    }
    $created++;
    $verb = $dry_run ? 'would import' : 'imported';
    echo "[" . ($i + 1) . "/$total] $verb: " . trim((string) $item['title']) . "\n";
}

$prefix = $dry_run ? '[dry-run] ' : '';
echo "\n{$prefix}Done. created=$created existed=$existed skipped=$skipped total=$total\n";
if ($skip_reasons) {
    arsort($skip_reasons);
    echo "Skipped breakdown:\n";
    foreach ($skip_reasons as $reason => $count) {
        echo "  $count\t$reason\n";
    }
}

/**
 * Parses argv into [path, dry_run]. Returns [null, false] when the path is
 * missing.
 *
 * @param array<int,string> $argv
 * @return array{0: ?string, 1: bool}
 */
function parse_import_args(array $argv): array
{
    $path = null;
    $dry_run = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dry_run = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            return [null, false];
        } elseif ($path === null) {
            $path = $arg;
        }
    }
    return [$path, $dry_run];
}
