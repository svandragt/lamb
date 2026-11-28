<?php

/**
 * WordPress import: registers the `wordpress` source for `bin/lamb import`,
 * which reads a WordPress export (WXR) file.
 */

namespace Lamb\WordPress;

use Lamb\Import;

require_once __DIR__ . '/wordpress.php';

return static function (): void {
    Import\register_source([
        'name'        => 'wordpress',
        'parse'       => parse_wxr_file(...),
        'extract'     => extract_items(...),
        'skip_reason' => skip_reason(...),
        'uuid'        => static fn(array $item): string => wordpress_uuid((string) $item['guid']),
        'import'      => import_item(...),
    ]);
};
