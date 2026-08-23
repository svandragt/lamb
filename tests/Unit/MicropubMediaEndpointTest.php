<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * respond_micropub_media() (src/micropub.php) sets the response via
 * http_response_code()/header() and then exit()s — a shape PHPUnit's own
 * @runInSeparateProcess isolation cannot observe, because it relies on the
 * test method returning normally to hand its result back to the parent
 * process (exit() inside the child skips that entirely). So this spawns a
 * genuinely separate `php` process for the code under test, capturing its
 * response code/headers via Xdebug (xdebug_get_headers(), which — unlike
 * headers_list() — works under the CLI SAPI) from a shutdown function that
 * still runs after exit(), and asserts on that from the normal test process.
 */
class MicropubMediaEndpointTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lamb_micropub_media_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Covers the regression #653 fixed: a failed store_upload_or_fallback()
     * must answer 500 with no Location header, not a 201 the file was never
     * written to. A gif skips the WebP re-encode branch (should_convert_to_webp()
     * is false for gif) and falls straight to move_uploaded_file(), which
     * always refuses a file that did not arrive via a real HTTP upload — the
     * exact failure this endpoint has to turn into a 500, not a false-positive 201.
     */
    public function testStoreFailureReturns500WithNoLocationHeader(): void
    {
        $result = $this->runProbe();

        $this->assertSame(
            500,
            $result['meta']['status'],
            "Expected HTTP 500 when the file could not be stored.\nstderr: {$result['stderr']}"
        );

        foreach ($result['meta']['headers'] as $header) {
            $this->assertStringStartsNotWithLocation($header);
        }

        $body = json_decode($result['body'], true);
        $this->assertIsArray($body, "Response body was not valid JSON: {$result['body']}");
        $this->assertSame('server_error', $body['error'] ?? null);
    }

    private function assertStringStartsNotWithLocation(string $header): void
    {
        $this->assertStringStartsNotWith(
            'Location:',
            $header,
            'No Location header may be sent for a file that was never stored.'
        );
    }

    /**
     * Runs respond_micropub_media() in its own `php` process against a gif
     * upload that store_upload_or_fallback() cannot store (see class docblock
     * for why this needs a real subprocess rather than @runInSeparateProcess).
     *
     * @return array{body: string, meta: array{status: int|false, headers: string[]}, stderr: string}
     */
    private function runProbe(): array
    {
        $tmpFile = $this->tempDir . '/upload.gif';
        // GIF89a header + a 1x1 logical screen descriptor + trailer: enough for
        // mime_content_type() to sniff image/gif, with no real pixel data needed.
        file_put_contents($tmpFile, "GIF89a" . pack('vvC3', 1, 1, 0, 0, 0) . "\x3B");

        $script = $this->tempDir . '/probe.php';
        file_put_contents($script, $this->probeScript($tmpFile));

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes);
        $this->assertIsResource($process, 'Failed to spawn the probe subprocess.');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        [$body, $metaJson] = array_pad(explode('===META===', $stdout, 2), 2, '{}');

        return [
            'body' => trim($body),
            'meta' => json_decode(trim($metaJson), true) ?? [],
            'stderr' => $stderr,
        ];
    }

    private function probeScript(string $tmpFile): string
    {
        $autoload = var_export(__DIR__ . '/../../vendor/autoload.php', true);
        $rootDir = var_export($this->tempDir, true);
        $tmpFileExport = var_export($tmpFile, true);

        return <<<PHP
<?php
require {$autoload};

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', {$rootDir});
}
if (!defined('ROOT_URL')) {
    define('ROOT_URL', 'https://example.com');
}

global \$config;
\$config = ['site_url' => 'https://example.com'];

\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';

\$_FILES['file'] = [
    'name'     => 'x.gif',
    'type'     => 'image/gif',
    'tmp_name' => {$tmpFileExport},
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize({$tmpFileExport}),
];

\$adapter = new \\Tests\\Support\\StubMicropubAdapter();
\$adapter->stubResponse = ['me' => 'https://example.com', 'scope' => 'create'];

register_shutdown_function(function () {
    fwrite(STDOUT, "\\n===META===\\n" . json_encode([
        'status'  => http_response_code(),
        'headers' => function_exists('xdebug_get_headers') ? xdebug_get_headers() : [],
    ]));
});

\\Lamb\\Micropub\\respond_micropub_media(null, \$adapter);
PHP;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
