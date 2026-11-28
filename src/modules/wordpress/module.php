<?php

/**
 * WordPress import: registers the `wordpress` source for `bin/lamb import`,
 * which reads a WordPress export (WXR) file. The importer code loads only when
 * bin/lamb asks for import sources, never on a web request.
 */

namespace Lamb\WordPress;

use Lamb\Hooks;
use Lamb\Import;

return static function (): void {
    Hooks\add_action('import.sources', static function (): void {
        require_once __DIR__ . '/wordpress.php';
        Import\register_source([
            'name'        => 'wordpress',
            'parse'       => parse_wxr_file(...),
            'extract'     => extract_items(...),
            'skip_reason' => skip_reason(...),
            'uuid'        => static fn(array $item): string => wordpress_uuid((string) $item['guid']),
            'import'      => import_item(...),
        ]);
    });
};
