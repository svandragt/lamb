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
    if (empty($_FILES[IMAGE_FILES])) {
        // invalid request http status code
        header('HTTP/1.1 400 Bad Request');
        die('No files uploaded!');
    }
    Security\require_login();

    $files = [];
    foreach ($_FILES[IMAGE_FILES] as $name => $items) {
        foreach ($items as $k => $value) {
            $files[$k][$name] = $_FILES[IMAGE_FILES][$name][$k];
        }
    }

    $out = '';
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            // File upload failed
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
        $seed     = sha1($f['name']);
        $sub_path = upload_subpath();
        $dir      = get_upload_dir($sub_path);

        // Re-encode JPEG/PNG to WebP for smaller files; fall back to the original
        // bytes if conversion fails (assume success, communicate failure).
        $new_fn = store_webp_copy($temp_fp, $ext, $dir, $seed);
        if ($new_fn === null) {
            $new_fn = "$seed.$ext";
            $new_fp = sprintf("%s/%s", $dir, $new_fn);
            if (!move_uploaded_file($temp_fp, $new_fp)) {
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
 * Returns a safe, lower-cased file extension for an uploaded file, or null if the
 * extension is not an allowed image type.
 *
 * Uploads land under the web root (src/assets/), so the extension is the line of
 * defence against writing executable files (e.g. .php). Only the allowlisted image
 * extensions are accepted; anything else (including extensionless names) is rejected.
 *
 * @param string $filename The client-supplied filename.
 * @return string|null The allowed lower-case extension, or null when not permitted.
 */
function safe_upload_extension(string $filename): ?string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, IMAGE_UPLOAD_EXTENSIONS, true)) {
        return null;
    }

    return $ext;
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
 * Re-encodes an image file as WebP, writing the result to $dest_path.
 *
 * Reads $src_path with GD, preserves alpha transparency, downscales anything wider
 * or taller than $max_dimension (so phone-sized screenshots are not served at their
 * full resolution), and writes a WebP. Returns false (writing nothing) when the
 * source cannot be decoded, so callers can fall back to storing the original bytes.
 *
 * @param string $src_path      Path to the source image (e.g. an uploaded temp file).
 * @param string $dest_path     Path the WebP should be written to.
 * @param int    $quality       WebP quality (0-100).
 * @param int    $max_dimension Longest edge to keep; larger images are scaled down.
 * @return bool True when a WebP was written, false on failure.
 */
function convert_to_webp(string $src_path, string $dest_path, int $quality = 82, int $max_dimension = 1600): bool
{
    $data = @file_get_contents($src_path);
    if ($data === false) {
        return false;
    }
    return convert_to_webp_from_bytes($data, $dest_path, $quality, $max_dimension);
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

    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        return false;
    }

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
        if (!mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
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
