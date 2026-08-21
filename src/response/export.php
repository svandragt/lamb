<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Export;
use Lamb\Security;
use Throwable;

use const ROOT_DIR;
use const ROOT_URL;

/**
 * Streams a full site export as a zip download.
 *
 * The archive is Lamb's own format, documented in docs/export.md and
 * DECISIONS.md ("2026-07-26"); this function only owns the HTTP delivery —
 * see response/README.md ("Export download"). Drafts and trashed posts are
 * included and flagged in the manifest — this is a backup, so leaving them
 * out would make it a partial one.
 *
 * @param array<int, string> $_args
 * @return void
 */
#[NoReturn]
function respond_export(array $_args): void
{
    Security\require_login();

    if (!Export\zip_available()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        die("Export needs PHP's zip extension, which is not installed on this server.\n");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'lamb_export_');
    if ($tmp === false) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        die("Could not create a temporary file for the export.\n");
    }

    try {
        Export\build_export_archive(
            Export\collect_posts(),
            ROOT_DIR . '/assets',
            $tmp,
            date('c'),
            export_site_info(),
        );
    } catch (Throwable $e) {
        @unlink($tmp);
        error_log('Export failed: ' . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        // The exception message can name server paths; the detail goes to the
        // error log rather than to the browser.
        die("The export could not be generated. See the server error log for details.\n");
    }

    stream_export_download($tmp, Export\export_basename(date('Y-m-d')) . '.zip');
}

/**
 * The descriptive site block recorded in the manifest.
 *
 * It tells a human (or a converter) which site an archive came from, and
 * import-lamb.php uses `url` as the origin it namespaces restored post ids by,
 * so two archives from different sites do not collide on id. An archive
 * without it still imports; the importer warns and offers --site-url.
 *
 * @return array<string, mixed>
 */
function export_site_info(): array
{
    global $config;

    return [
        'title' => (string) ($config['site_title'] ?? ''),
        'url'   => defined('ROOT_URL') ? ROOT_URL : (Config\canonical_site_url($config ?? []) ?? ''),
    ];
}

/**
 * Sends a generated file as an attachment and deletes it.
 *
 * Output buffering is unwound first so a buffered archive isn't held in memory
 * alongside the file itself, and Content-Length is sent so the browser can show
 * real download progress on what may be a large file.
 *
 * $filename is generated from a date, never from user input, so it is safe to
 * place in the header unquoted-escaped.
 */
#[NoReturn]
function stream_export_download(string $path, string $filename): void
{
    $size = filesize($path);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    @unlink($path);
    die();
}
