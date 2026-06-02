<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Lamb\Security;
use RuntimeException;

use const ROOT_DIR;
use const ROOT_URL;

/**
 * Responds to an upload request by processing the uploaded files.
 *
 * @param array $_args The arguments for the upload request.
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
        $temp_fp = $f['tmp_name'];

        // Re-encode JPEG/PNG to WebP for smaller files; fall back to the original
        // bytes if conversion fails (assume success, communicate failure).
        if (should_convert_to_webp($ext)) {
            $webp_fn = sha1($f['name']) . '.webp';
            $webp_fp = sprintf("%s/%s", get_upload_dir(), $webp_fn);
            if (convert_to_webp($temp_fp, $webp_fp)) {
                $upload_url = str_replace(ROOT_DIR, ROOT_URL, get_upload_dir());
                $out .= sprintf("![%s](%s)", $f['name'], "$upload_url/$webp_fn");
                continue;
            }
        }

        $new_fn = sha1($f['name']) . ".$ext";
        $new_fp = sprintf("%s/%s", get_upload_dir(), $new_fn);
        if (!move_uploaded_file($temp_fp, $new_fp)) {
            echo json_encode('Move upload error: ' . $temp_fp, JSON_THROW_ON_ERROR);
            die();
        }
        $upload_url = str_replace(ROOT_DIR, ROOT_URL, get_upload_dir());
        $out .= sprintf("![%s](%s)", $f['name'], "$upload_url/$new_fn");
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
 * @param string|null $ext A lower-case extension as returned by safe_upload_extension().
 * @return bool
 */
function should_convert_to_webp(?string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png'], true);
}

/**
 * Re-encodes an image file as WebP, writing the result to $dest_path.
 *
 * Reads $src_path with GD, preserves alpha transparency, and writes a WebP. Returns
 * false (writing nothing) when the source cannot be decoded, so callers can fall back
 * to storing the original bytes.
 *
 * @param string $src_path  Path to the source image (e.g. an uploaded temp file).
 * @param string $dest_path Path the WebP should be written to.
 * @param int $quality      WebP quality (0-100).
 * @return bool True when a WebP was written, false on failure.
 */
function convert_to_webp(string $src_path, string $dest_path, int $quality = 82): bool
{
    $data = @file_get_contents($src_path);
    if ($data === false) {
        return false;
    }

    $image = @imagecreatefromstring($data);
    if ($image === false) {
        return false;
    }

    // Preserve transparency from PNG sources.
    imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $ok = imagewebp($image, $dest_path, $quality);
    imagedestroy($image);

    return $ok;
}

/**
 * Retrieves the upload directory for storing files, creating it if necessary.
 *
 * @return string The absolute path to the upload directory.
 * @throws RuntimeException If the directory cannot be created.
 */
function get_upload_dir(): string
{
    $upload_dir = sprintf("%s/assets/%s", ROOT_DIR, date("Y/m"));
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $upload_dir));
        }
    }

    return $upload_dir;
}
