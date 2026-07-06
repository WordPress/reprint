<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteExportLibraryAuthTest extends TestCase
{
    private const LIB_PATH = __DIR__ . '/../reprint-exporter-wp/lib.php';

    public function testLargeBodyStagedEndpointsAuthenticateInTheirHandlers(): void
    {
        $script = <<<PHP
        define('ABSPATH', __DIR__ . '/');
        define('SITE_EXPORT_PLUGIN_DIR', __DIR__ . '/');
        require '{$this->libPath()}';

        echo '__RESULT__' . json_encode([
            'chunk' => _site_export_endpoint_authenticates_in_handler('staged_upload'),
            'batch' => _site_export_endpoint_authenticates_in_handler('staged_upload_batch'),
            'control' => _site_export_endpoint_authenticates_in_handler('staged_status'),
        ]);
        PHP;

        $result = json_decode($this->resultJson($this->runScript($script)), true);

        $this->assertSame([
            'chunk' => true,
            'batch' => true,
            'control' => false,
        ], $result);
    }

    public function testAuthGateResolvesTheEndpointTheDispatcherWillRun(): void
    {
        // The dispatcher merges GET and POST with POST winning, so a request
        // that names a self-authenticating route in the query string but a
        // different endpoint in a form body must resolve to the body's
        // endpoint — otherwise it would skip the default HMAC check and run
        // an unauthenticated read/control endpoint.
        $script = <<<PHP
        define('ABSPATH', __DIR__ . '/');
        define('SITE_EXPORT_PLUGIN_DIR', __DIR__ . '/');
        require '{$this->libPath()}';

        // filter_input() returns the query/form value, or null when absent
        // and false for a non-scalar (endpoint[]=...).
        \$bypass = _site_export_resolve_endpoint('staged_upload', 'file_index');
        echo '__RESULT__' . json_encode([
            // POST wins, so the gate sees the endpoint that will dispatch.
            'resolved' => \$bypass,
            'bypass_skips_auth' => _site_export_endpoint_authenticates_in_handler(\$bypass),
            // Legitimate data-plane upload: endpoint only in the query string.
            'query_only' => _site_export_resolve_endpoint('staged_upload', null),
            // Array endpoint resolves to '' so default auth still runs.
            'array_endpoint' => _site_export_resolve_endpoint(null, false),
        ]);
        PHP;

        $result = json_decode($this->resultJson($this->runScript($script)), true);

        $this->assertSame([
            'resolved' => 'file_index',
            'bypass_skips_auth' => false,
            'query_only' => 'staged_upload',
            'array_endpoint' => '',
        ], $result);
    }

    private function resultJson(string $output): string
    {
        $marker = '__RESULT__';
        $position = strrpos($output, $marker);
        $this->assertNotFalse($position, $output);
        return substr($output, $position + strlen($marker));
    }

    private function libPath(): string
    {
        $path = realpath(self::LIB_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function runScript(string $body): string
    {
        $tmp_dir = sys_get_temp_dir() . '/site-export-library-auth-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            $php_path = $tmp_dir . '/run.php';
            file_put_contents($php_path, "<?php\n" . $body . "\n");

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open([PHP_BINARY, $php_path], $descriptors, $pipes, $tmp_dir);
            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (($stdout ?: '') === '') {
                return $stderr ?: '';
            }
            return $stdout;
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }
}
