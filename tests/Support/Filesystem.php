<?php

namespace Tests\Support;

/**
 * Filesystem helpers shared by tests that build a temporary directory tree.
 */
final class Filesystem
{
    /**
     * Removes a directory and everything in it. A path that is not a directory is ignored.
     *
     * @param string $path The directory to remove.
     * @return void
     */
    public static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? self::removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
