<?php

namespace Lamb\Restore;

use JsonException;
use Lamb\Post;
use Lamb\Response;
use RedBeanPHP\OODBBean;
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
    // The D modifier: without it `$` also matches before a trailing newline, so
    // "assets/2026/07/photo.jpg\n" would pass a gate that exists to be exact.
    if (preg_match('#^posts/\d{4}/\d{2}/' . $stem . '\.md$#D', $name) === 1) {
        return $name;
    }
    if (preg_match('#^assets/\d{4}/\d{2}/' . $stem . '$#D', $name) === 1) {
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
 * The origin to namespace by, with a stand-in for an archive that names no site.
 *
 * An empty origin is not an identity: every siteless archive would share one
 * namespace, so restoring a second one would count all of its posts as already
 * present and silently drop them. The manifest's own export stamp and counts
 * stand in — stable across a re-import of the same archive, different for two
 * different ones. Not a substitute for --site-url, which is what makes the
 * namespace match a later archive from the same site.
 *
 * @param array<string, mixed> $manifest
 */
function namespaced_origin(array $manifest, string $origin): string
{
    if ($origin !== '') {
        return $origin;
    }

    return 'archive:' . md5(serialize([$manifest['exported_at'] ?? '', $manifest['counts'] ?? []]));
}

/**
 * The dedup key stored on a restored post's import_uuid column.
 */
function restore_uuid(string $origin, int $id): string
{
    return md5('lamb-' . $origin . '#' . $id);
}

/**
 * Turns the manifest's post entries into the items run_import() walks.
 *
 * Each item carries the manifest's own fields, the body read out of the
 * archive (null when the entry is absent) and the dedup uuid, namespaced by
 * $origin — or, when that is empty, by namespaced_origin()'s stand-in. `title` is the
 * in-archive path: it is only ever used for the progress line, and the path is
 * what identifies a post to someone watching a restore.
 *
 * The manifest is part of the untrusted archive, so a `path` it names is
 * validated before anything is read and cross-checked against what the archive
 * actually contains — a path the reader cannot resolve is reported as a missing
 * file rather than restored as an empty post.
 *
 * @param array<string, mixed>     $manifest
 * @param callable(string):?string $reader
 * @return list<array<string, mixed>>
 */
function manifest_items(array $manifest, callable $reader, string $origin): array
{
    $items = [];
    $posts = $manifest['posts'] ?? [];
    if (!is_array($posts)) {
        return [];
    }
    $origin = namespaced_origin($manifest, $origin);

    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }
        $path = (string) ($post['path'] ?? '');
        $safe = safe_entry_path($path);
        // array_merge(), not `+`: the manifest is untrusted, so its own
        // `import_uuid` or `body` must never win over the computed ones.
        $items[] = array_merge($post, [
            'title'       => $path,
            'path'        => $path,
            'body'        => $safe === null ? null : read_entry($reader, $safe),
            'import_uuid' => restore_uuid($origin, (int) ($post['id'] ?? 0)),
        ]);
    }

    return $items;
}

/**
 * Explains why a manifest entry cannot be restored, or null when it can.
 *
 * @param array<string, mixed> $item
 */
function item_skip_reason(array $item): ?string
{
    if (safe_entry_path((string) ($item['path'] ?? '')) === null) {
        return 'bad path';
    }
    $body = $item['body'] ?? null;
    if ($body === null) {
        return 'missing file';
    }
    if (trim((string) $body) === '') {
        return 'empty body';
    }

    return null;
}

/**
 * Restores one manifest entry as a post.
 *
 * The body goes through the ordinary post pipeline, so a restored post is
 * rendered, tagged and slugged exactly as one written today. The manifest state
 * is applied *after* populate_bean(), which stamps created/updated with now()
 * and can rewrite created from front matter — a restore that re-dates every
 * post to the moment it ran is not a restore.
 *
 * $bean is the row to overwrite under --replace; without it a new post is
 * created. No webmentions or WebSub pings: restored posts are not new
 * publications.
 *
 * @param array<string, mixed>     $item
 * @param callable(string,string):?string $_downloader Unused: an archive carries its own assets.
 */
function import_post(array $item, callable $_downloader, bool $dry_run = false, ?OODBBean $bean = null): OODBBean
{
    if ($dry_run) {
        return $bean ?? \RedBeanPHP\R::dispense('post');
    }

    $bean = Post\populate_bean((string) ($item['body'] ?? ''), null, null, $bean);
    apply_manifest_state($bean, $item);
    Post\finalize_and_store_post($bean);

    return $bean;
}

/**
 * Overwrites everything on the bean that the manifest describes.
 *
 * This is deliberately total rather than additive: a restore that leaves a post
 * published when the backup says it was trashed, or dated today when the backup
 * says 2019, has not restored anything. The manifest slug wins only when it is
 * non-empty — a slugless status post keeps the empty slug and its
 * /status/<id> permalink.
 *
 * @param array<string, mixed> $item
 */
function apply_manifest_state(OODBBean $bean, array $item): void
{
    $created = (string) ($item['created'] ?? '');
    $updated = (string) ($item['updated'] ?? '');
    if ($created !== '') {
        $bean->created = $created;
    }
    $bean->updated = $updated !== '' ? $updated : $bean->created;

    $slug = (string) ($item['slug'] ?? '');
    if ($slug !== '') {
        $bean->slug = $slug;
    }

    $bean->draft = empty($item['draft']) ? 0 : 1;
    $bean->deleted = empty($item['deleted']) ? 0 : 1;
    $bean->deleted_at = $item['deleted_at'] ?? null;
    $bean->feed_name = $item['feed_name'] ?? null;
    $bean->feeditem_uuid = $item['feeditem_uuid'] ?? null;
    $bean->source_url = $item['source_url'] ?? null;
    $bean->import_uuid = (string) ($item['import_uuid'] ?? '');
}

/**
 * Writes the archive's assets back under $assets_root.
 *
 * The archive is the same trust boundary as an upload — it is a file the author
 * was handed, possibly by someone else — so every asset clears the same two
 * gates a browser upload does: the extension allowlist, and a content sniff of
 * the bytes themselves. Both run before anything is written, so a rejected
 * asset never exists at a servable path even briefly and --dry-run tallies
 * exactly what a real run would.
 *
 * Destination paths are assembled from the validated `YYYY/MM/<name>` tail, not
 * from the archive's own name, and an existing file is never overwritten: a
 * restore into a live site must not clobber a newer upload that happens to
 * share a filename.
 *
 * @param array<string, mixed>     $manifest
 * @param callable(string):?string $reader
 * @return array{restored:int,skipped:int,rejected:int}
 */
function restore_assets(array $manifest, string $assets_root, callable $reader, bool $dry_run): array
{
    $tally = ['restored' => 0, 'skipped' => 0, 'rejected' => 0];
    $assets = $manifest['assets'] ?? [];
    if (!is_array($assets)) {
        return $tally;
    }

    foreach ($assets as $entry) {
        $name = safe_entry_path((string) $entry);
        if ($name === null || !str_starts_with($name, 'assets/')) {
            $tally['rejected']++;
            continue;
        }
        $relative = substr($name, strlen('assets/'));
        $ext = Response\safe_upload_extension($relative);
        if ($ext === null) {
            $tally['rejected']++;
            continue;
        }

        $dest = "$assets_root/$relative";
        if (is_file($dest)) {
            $tally['skipped']++;
            continue;
        }
        $bytes = read_entry($reader, $name);
        if ($bytes === null) {
            $tally['skipped']++;
            continue;
        }
        // Sniffed in memory, before the dry-run branch, so --dry-run reports the
        // same tally the real run produces.
        if (!Response\upload_content_allowed(Response\sniff_bytes_content_type($bytes), $ext)) {
            $tally['rejected']++;
            continue;
        }
        if ($dry_run) {
            $tally['restored']++;
            continue;
        }

        $dir = dirname($dest);
        // 0755, not 0777: only the user running the import needs to write here.
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $tally['skipped']++;
            continue;
        }
        // Written beside the destination under a non-servable name and only
        // then renamed into place, so a half-written file is never servable.
        $staged = $dest . '.part';
        if (file_put_contents($staged, $bytes) === false) {
            $tally['skipped']++;
            continue;
        }
        rename($staged, $dest);
        $tally['restored']++;
    }

    return $tally;
}
