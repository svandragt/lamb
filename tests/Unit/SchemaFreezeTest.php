<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bootstrap_db() declares every table/column up front (Bootstrap\SCHEMA) and
 * ends by calling R::freeze(true), so an accidental column is rejected
 * instead of silently created (issue tracked in the explicit-schema-freeze
 * work). Both halves of that guarantee need a real R::setup() connection,
 * which bootstrap_db() creates itself — running it here would clobber the
 * shared :memory: connection the rest of the Unit suite depends on, so it
 * runs in a subprocess (mirrors LoginThrottleTest's WAL check).
 */
class SchemaFreezeTest extends TestCase
{
    public function testFrozenSchemaAcceptsEveryDeclaredColumnAndRejectsAnUndeclaredOne(): void
    {
        $dir = sys_get_temp_dir() . '/lamb_schema_freeze_test_' . uniqid();

        $script = <<<'PHP'
        use RedBeanPHP\R;

        require "vendor/autoload.php";
        \Lamb\Bootstrap\bootstrap_db($argv[1]);

        $beans = [
            'post' => [
                'body' => 'b', 'slug' => 's', 'title' => 't', 'description' => 'd',
                'transformed' => 'x', 'created' => '2024-01-01', 'updated' => '2024-01-01',
                'version' => 1, 'feed_name' => 'f', 'feeditem_uuid' => 'u',
                'import_uuid' => 'i', 'source_url' => 'url', 'in_reply_to' => 'r',
                'syndicated_to' => 'y', 'draft' => 0, 'deleted' => 0,
                'deleted_at' => '2024-01-01', 'feed_locked' => 0,
                'preview_token' => 'tok', 'preview_token_expires' => '2024-01-01',
            ],
            'option' => ['name' => 'n', 'value' => 'v', 'updated' => '2024-01-01 00:00:00'],
            'redirect' => ['from_slug' => 'a', 'to_url' => 'b'],
            'webmention' => [
                'source' => 's', 'target' => 't', 'post_id' => 1, 'type' => 'like',
                'author' => 'a', 'content' => 'c', 'status' => 'verified',
                'created' => '2024-01-01', 'verified_at' => '2024-01-01',
            ],
            'webmentionoutbox' => [
                'post_id' => 1, 'source' => 's', 'target' => 't', 'endpoint' => 'e',
                'status' => 'pending', 'attempts' => 0, 'created' => '2024-01-01',
                'processed_at' => '2024-01-01', 'resend' => 0,
            ],
            'feedstatus' => [
                'feedkey' => 'k', 'name' => 'n', 'url' => 'u', 'last_success' => 1,
                'last_item_date' => 1, 'last_attempt' => 1, 'last_error' => 0,
                'item_count' => 0, 'error_message' => 'e',
            ],
        ];

        foreach ($beans as $type => $columns) {
            $bean = R::dispense($type);
            foreach ($columns as $name => $value) {
                $bean->$name = $value;
            }
            $id = R::store($bean);
            $loaded = R::load($type, $id);
            foreach ($columns as $name => $value) {
                if ($loaded->$name != $value) {
                    fwrite(STDERR, "MISMATCH $type.$name\n");
                    exit(1);
                }
            }
        }

        try {
            $bean = R::dispense('post');
            $bean->slug = 'ok';
            $bean->this_column_does_not_exist = 'nope';
            R::store($bean);
            fwrite(STDERR, "UNDECLARED COLUMN WAS ACCEPTED\n");
            exit(1);
        } catch (\RedBeanPHP\RedException\SQL $e) {
            // Expected: frozen mode refuses the undeclared column.
        }

        echo "OK\n";
        PHP;

        $boot = new Process(['php', '-r', $script, $dir]);
        $boot->run();

        @unlink($dir . '/lamb.db');
        @unlink($dir . '/lamb.db-wal');
        @unlink($dir . '/lamb.db-shm');
        if (is_dir($dir . '/sessions')) {
            @rmdir($dir . '/sessions');
        }
        @rmdir($dir);

        $this->assertSame(
            "OK\n",
            $boot->getOutput(),
            "stdout:\n" . $boot->getOutput() . "\nstderr:\n" . $boot->getErrorOutput()
        );
    }
}
