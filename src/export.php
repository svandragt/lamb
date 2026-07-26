<?php

namespace Lamb\Export;

use JsonException;
use RedBeanPHP\R;
use RuntimeException;
use ZipArchive;

use function Lamb\Import\asset_dir_for_date;

// Identifier written into every manifest, so a consumer can tell which
// revision of the format it is reading before it starts parsing. Bumped only
// on a breaking change; additive fields keep version 1.
const EXPORT_FORMAT = 'lamb-export/1';

/**
 * Whether this PHP build can write the archive.
 *
 * ext-zip is not a hard composer requirement — an existing self-hosted install
 * upgrading into this feature should not fail `composer install` over an
 * extension it never needed before. The export route checks this instead and
 * says so plainly.
 */
function zip_available(): bool
{
    return class_exists(ZipArchive::class);
}

/**
 * The archive's base filename (no extension) for a given `Y-m-d` date.
 */
function export_basename(string $date): string
{
    return 'lamb-export-' . $date;
}

/**
 * Reads every post out of the database as a plain array.
 *
 * Beans are flattened here so everything downstream of this function is pure
 * and unit-testable without a database. The field list is an allowlist rather
 * than `SELECT *`: `preview_token` / `preview_token_expires` are credentials
 * that grant access to an unpublished post, and an export is a file the author
 * hands around. They must never travel with the content.
 *
 * Properties are read off the bean rather than named in SQL because RedBean's
 * fluid mode only creates a column once something has been written to it — a
 * site that has never ingested a feed has no `feed_name` column at all, and
 * naming it in a SELECT would error.
 *
 * @return list<array<string, mixed>> Posts oldest-first.
 */
function collect_posts(): array
{
    $posts = [];
    foreach (R::findAll('post', ' ORDER BY created ASC, id ASC ') as $bean) {
        $posts[] = [
            'id'             => (int) $bean->id,
            'slug'           => (string) ($bean->slug ?? ''),
            'body'           => (string) ($bean->body ?? ''),
            'created'        => (string) ($bean->created ?? ''),
            'updated'        => (string) ($bean->updated ?? ''),
            'draft'          => (bool) $bean->draft,
            'deleted'        => (bool) $bean->deleted,
            'deleted_at'     => $bean->deleted_at === null ? null : (string) $bean->deleted_at,
            'version'        => $bean->version === null ? null : (int) $bean->version,
            'feed_name'      => $bean->feed_name === null ? null : (string) $bean->feed_name,
            'feeditem_uuid'  => $bean->feeditem_uuid === null ? null : (string) $bean->feeditem_uuid,
            'source_url'     => $bean->source_url === null ? null : (string) $bean->source_url,
        ];
    }

    return $posts;
}

/**
 * Reduces a slug to characters that are safe as a filename inside the archive.
 *
 * Slugs produced by slugify() are already `[a-z0-9-]`, but the column also
 * holds slugs pinned by an importer from a foreign permalink, so this cannot
 * assume it. Leading dots are stripped along with the separators: a `..` or
 * `.hidden` stem would otherwise turn into a traversal or an invisible file
 * once the archive is unpacked. Returns '' when nothing usable survives, which
 * {@see post_export_path} replaces with an id-derived stem.
 */
function export_filename_stem(string $slug): string
{
    $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($slug)) ?? '';
    $stem = trim($stem, '-.');
    if (strlen($stem) > 80) {
        $stem = rtrim(substr($stem, 0, 80), '-.');
    }

    return $stem;
}

/**
 * The in-archive path for a post: `posts/YYYY/MM/<slug>.md`.
 *
 * Posts are foldered by creation month, the same YYYY/MM convention uploads
 * already use on disk, so a large archive stays navigable by hand.
 *
 * $taken carries the paths already handed out and is updated in place. Slugs
 * are effectively unique in a live database (finalize_slug() suffixes on
 * collision), but an export must not silently drop a post if two ever do
 * collide — case-insensitively, since the archive may be unpacked on a
 * case-insensitive filesystem. A clash appends the post id, which is unique by
 * construction; the counter after it is belt-and-braces for a slug that
 * already ends in that id.
 *
 * @param array<string, mixed> $post
 * @param array<string, true>  $taken Paths already used, keyed lower-case.
 */
function post_export_path(array $post, array &$taken): string
{
    $id = (int) ($post['id'] ?? 0);
    $dir = 'posts/' . asset_dir_for_date((string) ($post['created'] ?? ''));
    $stem = export_filename_stem((string) ($post['slug'] ?? ''));
    if ($stem === '') {
        $stem = 'post-' . $id;
    }

    $path = "$dir/$stem.md";
    if (isset($taken[strtolower($path)])) {
        $path = "$dir/$stem-$id.md";
    }
    $suffix = 2;
    while (isset($taken[strtolower($path)])) {
        $path = "$dir/$stem-$id-" . $suffix++ . '.md';
    }
    $taken[strtolower($path)] = true;

    return $path;
}

/**
 * Every `/assets/YYYY/MM/<file>` path referenced by a post body.
 *
 * Matches the shape asset_url() writes, which covers both the root-relative
 * form stored in post bodies and the absolute form an importer or the Micropub
 * media endpoint may have left behind — the tail of an absolute URL is the same
 * root-relative path. Only files the posts actually point at are exported, so
 * the archive tracks the content rather than whatever else is sitting in the
 * uploads tree.
 *
 * @return list<string> Distinct `YYYY/MM/<file>` sub-paths, in first-seen order.
 */
function referenced_assets(string $body): array
{
    if (preg_match_all('#/assets/(\d{4}/\d{2}/[A-Za-z0-9._-]+)#', $body, $matches) === 0) {
        return [];
    }

    $found = [];
    foreach ($matches[1] as $relative) {
        if (str_contains($relative, '..')) {
            continue;
        }
        $found[$relative] = true;
    }

    return array_keys($found);
}

/**
 * Resolves an asset sub-path to a readable file inside $assets_root, or null.
 *
 * The sub-path comes out of a post body, which an importer or Micropub client
 * may have supplied, so the resolved path is checked to still be under the
 * uploads root before it is read — a symlink inside the tree must not turn an
 * export into an arbitrary-file read.
 */
function asset_source_path(string $assets_root, string $relative): ?string
{
    $root = realpath($assets_root);
    if ($root === false) {
        return null;
    }

    $path = realpath($root . '/' . $relative);
    if ($path === false || !is_file($path)) {
        return null;
    }
    if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

/**
 * The manifest record for one post.
 *
 * Deliberately carries no title and no body: those live in the `.md` file at
 * `path`, and duplicating them here would create two sources of truth that can
 * drift. What is recorded instead is the state the front matter cannot express
 * — the row's identity, timestamps, draft/deleted flags and feed provenance.
 *
 * The field list is an explicit allowlist so a future column added to the post
 * bean cannot start appearing in exports (and leaking, in the case of another
 * token-like field) just by existing.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function manifest_post_entry(array $post, string $path): array
{
    return [
        'path'          => $path,
        'id'            => (int) ($post['id'] ?? 0),
        'slug'          => (string) ($post['slug'] ?? ''),
        'created'       => (string) ($post['created'] ?? ''),
        'updated'       => (string) ($post['updated'] ?? ''),
        'draft'         => (bool) ($post['draft'] ?? false),
        'deleted'       => (bool) ($post['deleted'] ?? false),
        'deleted_at'    => $post['deleted_at'] ?? null,
        'post_version'  => $post['version'] ?? null,
        'feed_name'     => $post['feed_name'] ?? null,
        'feeditem_uuid' => $post['feeditem_uuid'] ?? null,
        'source_url'    => $post['source_url'] ?? null,
    ];
}

/**
 * Assembles the manifest document.
 *
 * @param list<array<string, mixed>> $posts  Entries from manifest_post_entry().
 * @param list<string>               $assets In-archive asset paths.
 * @param array<string, mixed>       $site   Descriptive site info (title, url).
 * @return array<string, mixed>
 */
function build_manifest(array $posts, array $assets, string $exported_at, array $site = []): array
{
    return [
        'format'      => EXPORT_FORMAT,
        'generator'   => 'lamb',
        'exported_at' => $exported_at,
        'site'        => $site,
        'counts'      => [
            'posts'  => count($posts),
            'assets' => count($assets),
        ],
        'posts'  => $posts,
        'assets' => $assets,
    ];
}

/**
 * Writes the export archive at $zip_path and returns the manifest it contains.
 *
 * Post bodies are stored byte-for-byte as they sit in the database. The body
 * column already holds front-matter Markdown — the exact shape parse_matter()
 * reads and Import\build_post_body() writes — so the export is the inverse of
 * an import without any re-serialisation step that could drift from it.
 *
 * @param list<array<string, mixed>> $posts Rows from collect_posts().
 * @param array<string, mixed>       $site  Descriptive site info for the manifest.
 * @return array<string, mixed> The manifest as written.
 * @throws RuntimeException When the archive cannot be created or finalised.
 * @throws JsonException
 */
function build_export_archive(
    array $posts,
    string $assets_root,
    string $zip_path,
    string $exported_at,
    array $site = []
): array {
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Could not create export archive at $zip_path");
    }

    $taken = [];
    $entries = [];
    $referenced = [];
    foreach ($posts as $post) {
        $path = post_export_path($post, $taken);
        $body = (string) ($post['body'] ?? '');
        $zip->addFromString($path, $body);
        $entries[] = manifest_post_entry($post, $path);
        foreach (referenced_assets($body) as $relative) {
            $referenced[$relative] = true;
        }
    }

    $assets = [];
    foreach (array_keys($referenced) as $relative) {
        $source = asset_source_path($assets_root, $relative);
        // A missing file is not an error: bodies outlive their uploads, and an
        // export that aborts over one dead image link is worse than one that
        // omits it. The manifest lists what was actually stored, so a consumer
        // can tell the difference.
        if ($source !== null && $zip->addFile($source, "assets/$relative")) {
            $assets[] = "assets/$relative";
        }
    }
    sort($assets);

    $manifest = build_manifest($entries, $assets, $exported_at, $site);
    $json = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $zip->addFromString('manifest.json', $json . "\n");

    if (!$zip->close()) {
        throw new RuntimeException("Could not finalise export archive at $zip_path");
    }

    return $manifest;
}
