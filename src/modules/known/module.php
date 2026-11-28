<?php

/**
 * Known import: registers the `known` source for `bin/lamb import`, which
 * reads a Known RSS export. The importer code loads only when bin/lamb asks
 * for import sources, never on a web request.
 */

namespace Lamb\Known;

use Lamb\Hooks;
use Lamb\Import;

return static function (): void {
    Hooks\add_action('import.sources', static function (): void {
        require_once __DIR__ . '/known.php';
        Import\register_source([
            'name'        => 'known',
            'parse'       => Import\parse_rss_file(...),
            'extract'     => extract_items(...),
            'skip_reason' => skip_reason(...),
            'uuid'        => static fn(array $item): string => known_uuid((string) $item['guid']),
            'import'      => import_item(...),
        ]);
    });
};
