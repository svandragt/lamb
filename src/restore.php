<?php

namespace Lamb\Restore;

use JsonException;
use RuntimeException;
use ZipArchive;

use const Lamb\Export\EXPORT_FORMAT;

// The only archive format this importer reads. An archive naming anything else
// is refused rather than parsed on a guess: a future breaking format bump would
// otherwise be silently half-applied to a live database.
const SUPPORTED_FORMAT = EXPORT_FORMAT;

// Caps on what a single archive may contain, so a hostile (or corrupt) zip
// cannot exhaust the disk or the file table before anything is validated.
// Twenty thousand entries and two gigabytes are both far above a real Lamb
// export and far below "fills the volume".
const MAX_ENTRIES = 20000;
const MAX_UNCOMPRESSED_BYTES = 2147483648; // 2 GiB

/**
 * Parses argv into [path, dry_run, replace, site_url].
 *
 * @param array<int,string> $argv
 * @return array{0: ?string, 1: bool, 2: bool, 3: ?string}
 */
function parse_restore_args(array $argv): array
{
    $path = null;
    $dry_run = false;
    $replace = false;
    $site_url = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dry_run = true;
        } elseif ($arg === '--replace') {
            $replace = true;
        } elseif (str_starts_with($arg, '--site-url=')) {
            $site_url = substr($arg, strlen('--site-url='));
        } elseif ($arg === '--help' || $arg === '-h') {
            return [null, false, false, null];
        } elseif ($path === null) {
            $path = $arg;
        }
    }

    return [$path, $dry_run, $replace, $site_url];
}

/**
 * Opens a `.zip` archive or an already-unpacked directory for reading.
 *
 * Returns the entry names it contains plus a reader closure that resolves one
 * name to its bytes. The archive is never extracted: nothing downstream ever
 * sees an attacker-chosen path on the filesystem, so there is no zip-slip
 * surface to defend. Destination paths are built later from validated
 * `YYYY/MM/<name>` components instead.
 *
 * @return array{0: list<string>, 1: callable(string):?string}
 * @throws RuntimeException When the source cannot be read or exceeds the caps.
 */
function open_source(string $path): array
{
    if (is_dir($path)) {
        return open_directory($path);
    }
    if (!is_file($path)) {
        throw new RuntimeException("Cannot read export archive: $path");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Not a readable zip archive: $path");
    }

    if ($zip->numFiles > MAX_ENTRIES) {
        $zip->close();
        throw new RuntimeException("Archive has more than " . MAX_ENTRIES . " entries: $path");
    }

    $names = [];
    $bytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false || str_ends_with((string) $stat['name'], '/')) {
            continue;
        }
        $names[] = (string) $stat['name'];
        $bytes += (int) $stat['size'];
    }
    if ($bytes > MAX_UNCOMPRESSED_BYTES) {
        $zip->close();
        throw new RuntimeException("Archive unpacks to more than " . MAX_UNCOMPRESSED_BYTES . " bytes: $path");
    }

    // $zip stays referenced by the closure, so the handle lives as long as the
    // reader does.
    $reader = static function (string $name) use ($zip): ?string {
        $contents = $zip->getFromName($name);

        return $contents === false ? null : $contents;
    };

    return [$names, $reader];
}

/**
 * The directory equivalent of {@see open_source}: a partially recovered backup
 * is often already unpacked, and unpacking is not the importer's business.
 *
 * @return array{0: list<string>, 1: callable(string):?string}
 * @throws RuntimeException When the tree exceeds the entry cap.
 */
function open_directory(string $root): array
{
    $names = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }
        $names[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if (count($names) > MAX_ENTRIES) {
            throw new RuntimeException("Source has more than " . MAX_ENTRIES . " entries: $root");
        }
    }

    $reader = static function (string $name) use ($root): ?string {
        // Only names this function itself listed are ever asked for, but the
        // read still goes through safe_entry_path() so a manifest-supplied name
        // cannot walk out of the tree.
        if (safe_entry_path($name) === null) {
            return null;
        }
        $contents = @file_get_contents("$root/$name");

        return $contents === false ? null : $contents;
    };

    return [$names, $reader];
}

/**
 * Reads one entry's bytes, or null when it is absent.
 *
 * @param callable(string):?string $reader
 */
function read_entry(callable $reader, string $name): ?string
{
    return $reader($name);
}

/**
 * Validates an in-archive entry name, returning it unchanged when it is one of
 * the three shapes an export contains, and null otherwise.
 *
 * This is the single gate for every name that comes out of an archive or a
 * manifest. Names are matched whole against the layout build_export_archive()
 * writes, so traversal, absolute paths, nested directories, dotfiles and
 * unexpected extensions are all refused by construction rather than by
 * spotting the dangerous cases.
 */
function safe_entry_path(string $name): ?string
{
    if ($name === 'manifest.json') {
        return $name;
    }

    $stem = '[A-Za-z0-9_-][A-Za-z0-9._-]*';
    if (preg_match('#^posts/\d{4}/\d{2}/' . $stem . '\.md$#', $name) === 1) {
        return $name;
    }
    if (preg_match('#^assets/\d{4}/\d{2}/' . $stem . '$#', $name) === 1) {
        return $name;
    }

    return null;
}

/**
 * Reads and validates the archive's manifest.
 *
 * @param callable(string):?string $reader
 * @return array<string, mixed>
 * @throws RuntimeException When it is missing, unparseable, or a format this importer does not read.
 */
function read_manifest(callable $reader): array
{
    $json = read_entry($reader, 'manifest.json');
    if ($json === null) {
        throw new RuntimeException('Archive has no manifest.json — is this a Lamb export?');
    }

    try {
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('manifest.json is not valid JSON: ' . $e->getMessage());
    }
    if (!is_array($manifest)) {
        throw new RuntimeException('manifest.json is not a JSON object.');
    }

    $format = (string) ($manifest['format'] ?? '');
    if ($format !== SUPPORTED_FORMAT) {
        throw new RuntimeException("Unsupported export format '$format' (this importer reads " . SUPPORTED_FORMAT . ').');
    }

    /** @var array<string, mixed> $manifest */
    return $manifest;
}

/**
 * The identity a restored post's dedup uuid is namespaced by.
 *
 * Post ids are only unique within the site that issued them, so two archives
 * from different sites both carry an id 7. The origin keeps them apart.
 * ROOT_URL is not used: it is host-derived, so the same install reached on two
 * hostnames would mint two origins for one site.
 *
 * @param array<string, mixed> $manifest
 */
function origin_id(array $manifest, ?string $override): string
{
    $site = $manifest['site'] ?? [];
    $url = $override ?? (is_array($site) ? (string) ($site['url'] ?? '') : '');

    return rtrim(strtolower(trim($url)), '/');
}

/**
 * The dedup key stored on a restored post's import_uuid column.
 */
function restore_uuid(string $origin, int $id): string
{
    return md5('lamb-' . $origin . '#' . $id);
}
