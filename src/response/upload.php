<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Lamb\Security;
use RuntimeException;

use const ROOT_DIR;

/**
 * Responds to an upload request by processing the uploaded files.
 *
 * @param array<int, string> $_args The arguments for the upload request.
 * @return void
 * @throws JsonException
 */
#[NoReturn]
function respond_upload(array $_args): void
{
    // Authenticate before inspecting the request, so an anonymous caller cannot
    // tell a malformed upload apart from a rejected one.
    Security\require_login();

    // Without this the response defaults to text/html, and the client-supplied
    // filename is echoed back inside it — json_encode() leaves `<` and `>`
    // alone, so the filename would be parsed as markup.
    header('Content-Type: application/json');

    if (empty($_FILES[IMAGE_FILES])) {
        // invalid request http status code
        header('HTTP/1.1 400 Bad Request');
        die('No files uploaded!');
    }

    $files = normalize_uploaded_files($_FILES[IMAGE_FILES]);

    $out = '';
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            // The status is load-bearing: upload-image.js keys on response.ok to
            // tell markdown-to-insert from error-to-report.
            header('HTTP/1.1 400 Bad Request');
            echo json_encode('File upload error: ' . $f['error'], JSON_THROW_ON_ERROR);
            die();
        }
        // File upload successful
        $ext = safe_upload_extension($f['name']);
        if ($ext === null) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode('Unsupported file type.', JSON_THROW_ON_ERROR);
            die();
        }
        $temp_fp  = $f['tmp_name'];
        if (!upload_content_allowed(sniff_file_content_type($temp_fp), $ext)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode('File contents do not match its type.', JSON_THROW_ON_ERROR);
            die();
        }
        // Salt with uniqid() so two uploads with the same client-supplied
        // filename in the same month don't collide on disk — without this,
        // an attacker-controlled filename can silently overwrite an earlier,
        // already-published upload at the same path.
        $seed     = sha1($f['name'] . uniqid('', true));
        $sub_path = upload_subpath();
        $dir      = get_upload_dir($sub_path);

        // Re-encode JPEG/PNG to WebP for smaller files; fall back to the original
        // bytes if conversion fails (assume success, communicate failure).
        $new_fn = store_webp_copy($temp_fp, $ext, $dir, $seed);
        if ($new_fn === null) {
            $new_fn = "$seed.$ext";
            $new_fp = sprintf("%s/%s", $dir, $new_fn);
            if (!move_uploaded_file($temp_fp, $new_fp)) {
                header('HTTP/1.1 500 Internal Server Error');
                echo json_encode('Move upload error: ' . $temp_fp, JSON_THROW_ON_ERROR);
                die();
            }
        }
        $out .= sprintf("![%s](%s)", $f['name'], asset_url($sub_path, $new_fn));
    }

    echo json_encode($out, JSON_THROW_ON_ERROR);
    die();
}

/**
 * Turns PHP's attribute-major $_FILES entry into one array per uploaded file.
 *
 * PHP groups a multi-file field by attribute (`['name' => [...], 'tmp_name' => [...]]`);
 * every caller wants it the other way round, one `['name' => …, 'tmp_name' => …, …]`
 * per file. Extracted from respond_upload() so this reshaping — the part with all
 * the index bookkeeping — is unit-testable, unlike the responder around it, which
 * needs real $_FILES entries and dies on every exit path.
 *
 * A field posted without `[]` (attributes are scalars rather than arrays) yields
 * the single file it describes.
 *
 * @param array<string, mixed> $field One $_FILES entry, e.g. $_FILES['imageFiles'].
 * @return array<int, array<string, mixed>> One entry per uploaded file.
 */
function normalize_uploaded_files(array $field): array
{
    $files = [];
    foreach ($field as $attribute => $values) {
        foreach ((array) $values as $index => $value) {
            $files[$index][$attribute] = $value;
        }
    }

    return $files;
}

/**
 * Returns a safe, lower-cased file extension for an uploaded file, or null if the
 * extension is not an allowed image or video type.
 *
 * Uploads land under the web root (src/assets/), so the extension is the line of
 * defence against writing executable files (e.g. .php). Only the allowlisted image
 * and video extensions are accepted; anything else (including extensionless names)
 * is rejected.
 *
 * @param string $filename The client-supplied filename.
 * @return string|null The allowed lower-case extension, or null when not permitted.
 */
function safe_upload_extension(string $filename): ?string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = array_merge(IMAGE_UPLOAD_EXTENSIONS, VIDEO_UPLOAD_EXTENSIONS);
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        return null;
    }

    return $ext;
}

/**
 * The content types accepted for each allowed upload extension.
 *
 * `safe_upload_extension()` only inspects the client-supplied filename, so on its
 * own it lets any bytes at all be stored under an image extension and served from
 * this origin. That is how an HTML or SVG payload ends up at a URL on the site's
 * own domain — the extension keeps it from being executed as PHP, but a browser
 * that sniffs (or any future change that serves by content) would still render it.
 *
 * The container formats also accept `application/octet-stream`: libmagic cannot
 * always name an ISOBMFF/Matroska stream, and a real video must not be rejected
 * because of that. Scripted payloads never sniff as octet-stream — they come back
 * as `text/html`, `text/x-php`, `image/svg+xml` and the like — so the check still
 * does its job for the case it exists to cover.
 *
 * @return array<string, list<string>> Extension => acceptable sniffed MIME types.
 */
function upload_content_types(): array
{
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif', 'image/heif', 'image/heic', 'application/octet-stream'],
        'mp4'  => ['video/mp4', 'application/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'video/x-matroska', 'application/octet-stream'],
        'mov'  => ['video/quicktime', 'video/mp4', 'application/octet-stream'],
    ];
}

/**
 * Whether a sniffed content type is acceptable for the given upload extension.
 *
 * Returns true when fileinfo is unavailable: the check is a second line of
 * defence behind the extension allowlist, and a host without the extension
 * should keep uploading rather than fail every attempt.
 *
 * @param string|false $mime The sniffed MIME type, or false when sniffing failed.
 * @param string       $ext  The lower-case extension from safe_upload_extension().
 * @return bool
 */
function upload_content_allowed(string|false $mime, string $ext): bool
{
    if ($mime === false || $mime === '') {
        return true;
    }
    $allowed = upload_content_types()[$ext] ?? null;

    return $allowed === null || in_array(strtolower($mime), $allowed, true);
}

/**
 * Sniffs the content type of an uploaded file on disk, or false when unavailable.
 */
function sniff_file_content_type(string $path): string|false
{
    if (!function_exists('mime_content_type') || !is_readable($path)) {
        return false;
    }

    return @mime_content_type($path);
}

/**
 * Sniffs the content type of raw bytes in memory, or false when unavailable.
 */
function sniff_bytes_content_type(string $bytes): string|false
{
    if (!class_exists(\finfo::class)) {
        return false;
    }
    $finfo = @new \finfo(FILEINFO_MIME_TYPE);

    return @$finfo->buffer($bytes);
}

/**
 * Whether an uploaded image of the given extension should be re-encoded to WebP.
 *
 * Only JPEG and PNG are converted: they are common, lossy/lossless raster formats
 * that GD round-trips cleanly and that shrink noticeably as WebP. Already-efficient
 * formats (webp, avif) are left untouched, and gif is passed through because it may
 * be animated — GD would flatten it to a single frame.
 *
 * Conversion also requires the gd extension: without it the original bytes are
 * stored as-is instead of fataling on undefined GD functions.
 *
 * @param string|null $ext          A lower-case extension as returned by safe_upload_extension().
 * @param bool|null   $gd_available Overrides the gd extension check (for tests); null = detect.
 * @return bool
 */
function should_convert_to_webp(?string $ext, ?bool $gd_available = null): bool
{
    $gd_available ??= extension_loaded('gd');

    return $gd_available && in_array($ext, ['jpg', 'jpeg', 'png'], true);
}

/**
 * Persists raw image bytes under $dest_dir, preferring a WebP re-encode and
 * falling back to the original bytes when conversion isn't possible.
 *
 * Owns the full "land bytes in src/assets/" pipeline for callers that already
 * have the image content in memory (WordPress import downloader, Micropub
 * inline photos). The temp file lives under $dest_dir rather than
 * sys_get_temp_dir() so the final rename never crosses filesystems — a real
 * failure mode in containers where /tmp is tmpfs and the project root is a
 * bind-mount. respond_upload() keeps its own path because it must use
 * move_uploaded_file() for the PHP-managed safety check.
 *
 * @param string $bytes    Raw image bytes, fully buffered in memory.
 * @param string $ext      The lower-case extension from safe_upload_extension().
 * @param string $dest_dir Destination directory (no trailing slash, must exist).
 * @param string $seed     The hashed base filename (without extension).
 * @return string|null The saved filename relative to $dest_dir, or null on any failure.
 */
function persist_image_bytes(string $bytes, string $ext, string $dest_dir, string $seed): ?string
{
    if ($bytes === '' || !is_dir($dest_dir)) {
        return null;
    }
    if (!upload_content_allowed(sniff_bytes_content_type($bytes), $ext)) {
        return null;
    }
    if (should_convert_to_webp($ext)) {
        $webp_fn = $seed . '.webp';
        if (convert_to_webp_from_bytes($bytes, "$dest_dir/$webp_fn")) {
            return $webp_fn;
        }
    }
    $filename = "$seed.$ext";
    $tmp = tempnam($dest_dir, 'lamb_img_');
    if ($tmp === false) {
        return null;
    }
    // tempnam → rename gives us an atomic publish on the destination filesystem,
    // so an interrupted write can't leave a half-finished file at the final path
    // (which a re-run would otherwise mistake for a completed download).
    if (file_put_contents($tmp, $bytes) === false || !rename($tmp, "$dest_dir/$filename")) {
        @unlink($tmp);
        return null;
    }
    // tempnam() creates its file 0600, and rename() carries that mode to the
    // published asset — every other upload path (move_uploaded_file(),
    // imagewebp(), the restore's file_put_contents()) lands on the usual
    // 0666 & ~umask. A 0600 asset is readable by the PHP user alone, so a
    // separate static-file server user, a backup, or the author's own account
    // cannot read an image the site is serving.
    @chmod("$dest_dir/$filename", 0666 & ~umask());

    return $filename;
}

/**
 * Re-encodes an upload to WebP under $dest_dir, or returns null to fall back.
 *
 * Owns the shared "convert to WebP or fall back to the original bytes" decision used
 * by every upload path (web upload, Micropub inline photos, Micropub media endpoint):
 * the destination filename is the $seed plus the chosen extension. When the extension
 * is a convertible raster format (should_convert_to_webp()) and convert_to_webp()
 * succeeds, the WebP is written at "$dest_dir/$seed.webp" and that filename is
 * returned. Otherwise nothing is written and null is returned, leaving each caller to
 * store the original bytes under "$seed.$ext" via its own move semantics
 * (move_uploaded_file() vs UploadedFileInterface::moveTo()) and build its own URL.
 *
 * @param string $src_path A readable path to the source image bytes.
 * @param string $ext      The lower-case extension from safe_upload_extension().
 * @param string $dest_dir The upload directory from get_upload_dir() (no trailing slash).
 * @param string $seed     The hashed base filename (without extension).
 * @return string|null The "$seed.webp" filename on success, or null to fall back.
 */
function store_webp_copy(string $src_path, string $ext, string $dest_dir, string $seed): ?string
{
    if (!should_convert_to_webp($ext)) {
        return null;
    }

    $webp_fn = $seed . '.webp';
    if (convert_to_webp($src_path, sprintf('%s/%s', $dest_dir, $webp_fn))) {
        return $webp_fn;
    }

    return null;
}

/**
 * Upper bound on a source image's declared width*height before WebP conversion
 * decodes it. GD allocates the full pixel buffer as soon as it decodes an
 * image's header, before any of this app's own downscaling runs — a small
 * file can declare an enormous width/height ("decompression bomb") and force
 * a multi-gigabyte allocation. 40 megapixels comfortably covers any real
 * photo (including high-resolution phone modes) while capping the worst case.
 *
 * GD's pixel buffers are allocated outside PHP's memory manager, so they
 * neither count against memory_limit nor are limited by it — the real
 * ceiling is the host's actual free RAM. LAMB_MAX_UPLOAD_PIXELS lets a
 * self-hoster on a memory-constrained box lower the cap if conversions are
 * getting OOM-killed, without a code change.
 *
 * @return int The pixel cap: LAMB_MAX_UPLOAD_PIXELS if set to a positive
 *             integer, otherwise the 40-megapixel default.
 */
function max_upload_pixels(): int
{
    $env = getenv('LAMB_MAX_UPLOAD_PIXELS');
    if ($env !== false && ctype_digit($env) && (int) $env > 0) {
        return (int) $env;
    }

    return 40_000_000;
}

/**
 * Maps an IMAGETYPE_* constant to the GD function that decodes that format
 * directly from a file path, so the encoded bytes never have to be read into
 * a PHP string first — GD's per-format decoders stream the file themselves.
 *
 * @param int $image_type One of the IMAGETYPE_* constants from getimagesize().
 * @return (callable(string): (\GdImage|false))|null
 */
function image_decoder_for_type(int $image_type): ?callable
{
    return match ($image_type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg(...),
        IMAGETYPE_PNG => imagecreatefrompng(...),
        IMAGETYPE_GIF => imagecreatefromgif(...),
        IMAGETYPE_WEBP => imagecreatefromwebp(...),
        IMAGETYPE_BMP => imagecreatefrombmp(...),
        default => null,
    };
}

/**
 * Re-encodes an image file as WebP, writing the result to $dest_path.
 *
 * Reads $src_path with GD, preserves alpha transparency, downscales anything wider
 * or taller than $max_dimension (so phone-sized screenshots are not served at their
 * full resolution), and writes a WebP. Returns false (writing nothing) when the
 * source cannot be decoded, so callers can fall back to storing the original bytes.
 *
 * Decodes straight from $src_path with a format-specific GD function
 * (image_decoder_for_type()) rather than file_get_contents() +
 * imagecreatefromstring(), so the encoded file is never also held as a full
 * PHP string alongside the decoded pixel buffer. Formats GD can't be told
 * apart in advance fall back to the string-based decode.
 *
 * @param string $src_path      Path to the source image (e.g. an uploaded temp file).
 * @param string $dest_path     Path the WebP should be written to.
 * @param int    $quality       WebP quality (0-100).
 * @param int    $max_dimension Longest edge to keep; larger images are scaled down.
 * @return bool True when a WebP was written, false on failure.
 */
function convert_to_webp(string $src_path, string $dest_path, int $quality = 82, int $max_dimension = 1600): bool
{
    $size = @getimagesize($src_path);
    if (is_array($size) && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] > max_upload_pixels()) {
        return false;
    }

    $decoder = is_array($size) ? image_decoder_for_type($size[2]) : null;
    $image = $decoder !== null ? @$decoder($src_path) : false;

    if ($image === false) {
        // Unknown/undetected format: fall back to the old string-based decode,
        // which auto-detects from the bytes themselves.
        $data = @file_get_contents($src_path);
        if ($data === false) {
            return false;
        }
        $image = @imagecreatefromstring($data);
        unset($data);
    }

    if ($image === false) {
        return false;
    }

    return finish_webp_from_image($image, $dest_path, $quality, $max_dimension);
}

/**
 * Same as {@see convert_to_webp} but starts from raw bytes already in memory.
 *
 * Lets callers that have just fetched (WordPress importer) or just streamed
 * (Micropub inline photo) image bytes skip the temp-file round-trip — the
 * original disk-based path wrote bytes to tmp, then immediately read them
 * back with file_get_contents inside this function.
 *
 * @param string $bytes         Raw image bytes.
 * @param string $dest_path     Path the WebP should be written to.
 * @param int    $quality       WebP quality (0-100).
 * @param int    $max_dimension Longest edge to keep; larger images are scaled down.
 * @return bool True when a WebP was written, false on failure.
 */
function convert_to_webp_from_bytes(string $bytes, string $dest_path, int $quality = 82, int $max_dimension = 1600): bool
{
    if ($bytes === '') {
        return false;
    }

    // Reject an oversized declared width*height before decoding: a cheap
    // header-only read via getimagesizefromstring(), ahead of the actual
    // (memory-hungry) decode in imagecreatefromstring() below. A source that
    // getimagesizefromstring() can't parse is left to imagecreatefromstring()
    // itself, unchanged from prior behaviour.
    $size = @getimagesizefromstring($bytes);
    if (is_array($size) && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] > max_upload_pixels()) {
        return false;
    }

    $image = @imagecreatefromstring($bytes);
    // The decoded pixel buffer (below) and the resize's own buffer are both
    // still to come; drop the encoded-bytes buffer now instead of waiting for
    // $bytes to go out of scope, so it isn't resident during those.
    unset($bytes);
    if ($image === false) {
        return false;
    }

    return finish_webp_from_image($image, $dest_path, $quality, $max_dimension);
}

/**
 * Preserves alpha, downscales to $max_dimension, and writes the WebP —
 * the tail shared by convert_to_webp() and convert_to_webp_from_bytes()
 * once each has its own decoded $image.
 *
 * @param \GdImage $image         Decoded source image; consumed and destroyed.
 * @param string   $dest_path     Path the WebP should be written to.
 * @param int      $quality       WebP quality (0-100).
 * @param int      $max_dimension Longest edge to keep; larger images are scaled down.
 * @return bool True when a WebP was written, false on failure.
 */
function finish_webp_from_image(\GdImage $image, string $dest_path, int $quality, int $max_dimension): bool
{
    // Preserve transparency from PNG sources.
    imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    [$new_width, $new_height] = scaled_dimensions(imagesx($image), imagesy($image), $max_dimension);
    if ($new_width !== imagesx($image) || $new_height !== imagesy($image)) {
        $resized = imagecreatetruecolor(max(1, $new_width), max(1, $new_height));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, imagesx($image), imagesy($image));
        imagedestroy($image);
        $image = $resized;
    }

    $ok = imagewebp($image, $dest_path, $quality);
    imagedestroy($image);

    return $ok;
}

/**
 * Scales width/height down so the longest edge is at most $max, preserving aspect ratio.
 *
 * Images already within the limit (or with a non-positive longest edge) are returned
 * unchanged — this never upscales. Scaled edges are clamped to a minimum of 1px.
 *
 * @param int $width  Source width in pixels.
 * @param int $height Source height in pixels.
 * @param int $max    Maximum length of the longest edge.
 * @return array{0:int,1:int} The [width, height] to render at.
 */
function scaled_dimensions(int $width, int $height, int $max): array
{
    $longest = max($width, $height);
    if ($longest <= $max || $longest <= 0) {
        return [$width, $height];
    }

    $ratio = $max / $longest;
    return [
        max(1, (int) round($width * $ratio)),
        max(1, (int) round($height * $ratio)),
    ];
}

/**
 * The YYYY/MM subpath new uploads are stored under (under src/assets/).
 *
 * Callers capture this once and pass it to both get_upload_dir() and asset_url()
 * so the stored file and the URL written into the post can never disagree across
 * a month boundary.
 */
function upload_subpath(): string
{
    return date("Y/m");
}

/**
 * Retrieves the upload directory for storing files, creating it if necessary.
 *
 * @param string|null $sub_path The YYYY/MM subpath (defaults to the current month).
 * @return string The absolute path to the upload directory.
 * @throws RuntimeException If the directory cannot be created.
 */
function get_upload_dir(?string $sub_path = null): string
{
    $upload_dir = sprintf("%s/assets/%s", ROOT_DIR, $sub_path ?? upload_subpath());
    if (!is_dir($upload_dir)) {
        // 0755, not 0777: the web-server user is the only one that needs to write
        // here. Under a permissive umask (0002/0000, not unusual in containers)
        // 0777 leaves the upload tree writable by every local user.
        if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $upload_dir));
        }
    }

    return $upload_dir;
}

/**
 * The public URL for an asset stored at src/assets/<sub_path>/<filename>.
 *
 * Root-relative (leading slash) so it resolves on every route — / and /slug but
 * also nested ones like /page/N, /search/x and /tag/x, where a bare "assets/..."
 * is resolved against the current path and 404s. It also carries no host, so it
 * survives a domain change and is produced identically by the CLI WXR importer,
 * which has no $_SERVER host to build an absolute ROOT_URL from. Callers that
 * must emit an absolute URL (e.g. the Micropub media endpoint's Location header)
 * prefix ROOT_URL onto the result.
 */
function asset_url(string $sub_path, string $filename): string
{
    return "/assets/$sub_path/$filename";
}

/**
 * The pixel dimensions of a locally stored asset, given the URL a post body
 * points at, or null when they cannot be determined.
 *
 * The inverse of asset_url(): it maps a root-relative `/assets/…` URL back to
 * the file under ROOT_DIR so the renderer can emit intrinsic `width`/`height`
 * on `<img>` (see LambDown::setImageSizeResolver()). Kept here rather than in
 * the parser because this file already owns the URL ↔ disk-path mapping.
 *
 * Everything that isn't provably a file inside src/assets/ returns null, so
 * the caller renders exactly as it did before rather than with wrong numbers:
 *
 * - A URL with a scheme or host, including protocol-relative `//host/assets/…`:
 *   a remote path that merely starts with /assets/ must not be resolved
 *   against the local tree.
 * - Anything that escapes src/assets/ once resolved. Post bodies are
 *   hand-written Markdown, so `/assets/../../etc/passwd` (percent-encoded or
 *   not) is reachable input; realpath() containment rejects it, and covers
 *   symlinks out of the tree too.
 * - Files getimagesize() can't measure — a video, a truncated upload, or an
 *   asset that has since been deleted.
 *
 * @param string $url The `src` from a post body's Markdown image.
 * @return array{0:int,1:int}|null The [width, height] in pixels, or null.
 */
function asset_dimensions(string $url): ?array
{
    if (!defined('ROOT_DIR')) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return null;
    }
    $path = $parts['path'] ?? '';
    if (!str_starts_with($path, '/assets/')) {
        return null;
    }

    $assets_root = realpath(ROOT_DIR . '/assets');
    $file = realpath(ROOT_DIR . rawurldecode($path));
    if ($assets_root === false || $file === false) {
        return null;
    }
    if (!str_starts_with($file, $assets_root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    $size = @getimagesize($file);
    if (!is_array($size) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
        return null;
    }

    return [(int) $size[0], (int) $size[1]];
}
