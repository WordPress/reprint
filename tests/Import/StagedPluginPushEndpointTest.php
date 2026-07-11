<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test diagnostics are never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The test namespace matches the existing ImportTests suite.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * The plugin entry point, not a direct endpoint object: this catches a
 * body-authentication regression that would buffer or reject an envelope
 * authenticated staged_session_push request before the streaming handler
 * receives it.
 */
final class StagedPluginPushEndpointTest extends TestCase {
    private const SECRET = 'staged-plugin-push-test-secret';

    private string $server_root;
    private string $target_root;
    private string $plugin_install_dir;
    private string $staging_dir;
    private string $secret_path;
    private string $router_path;
    private string $base_url;

    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->server_root = sys_get_temp_dir() . '/staged-plugin-push-server-' . $suffix;
        $this->target_root = $this->server_root . '/target';
        $this->plugin_install_dir = $this->target_root . '/wp-content/plugins/site-export';
        $this->staging_dir = $this->server_root . '/staging';
        $this->secret_path = $this->server_root . '/secret.php';
        $this->router_path = $this->server_root . '/router.php';
        mkdir($this->target_root, 0700, true);
        mkdir(dirname($this->plugin_install_dir), 0700, true);
        $plugin_source = realpath(__DIR__ . '/../../reprint-exporter-wp');
        if ($plugin_source === false || !symlink($plugin_source, $this->plugin_install_dir)) {
            self::fail('Could not create the lexical in-target plugin symlink.');
        }
        file_put_contents($this->secret_path, "<?php\nreturn '" . self::SECRET . "';\n");
        $this->write_router();
        $this->start_server();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
            $this->server_process = null;
        }
        $this->remove_tree($this->server_root);
    }

    public function testPluginAuthenticatesAStagedRequestBeforeParsingItsFormBody(): void
    {
        $url = $this->base_url . '&endpoint=staged_session_push';
        $response = $this->http_response(
            $url,
            'endpoint=file_index',
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );
        $body = json_decode($response['body'], true);

        $this->assertSame(403, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame(['rejected', 'auth_failed'], [$body['status'], $body['reason']]);
        $this->assertSame('Authentication failed.', $body['detail']);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertStringNotContainsString($this->server_root, $response['body']);
        $this->assertStringNotContainsString($this->staging_dir, $response['body']);
    }

    public function testPluginAcceptsEnvelopeAuthenticationForCurrentSessionPushWithoutHashingItsBody(): void
    {
        $url = $this->base_url . '&without_staging=1&endpoint=staged_session_push';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $headers['Content-Type'] = 'application/octet-stream';
        $response = $this->http_response($url, str_repeat('current-stream-body', 4096), $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status'], $response['body']);
        $this->assertIsArray($body);
        $this->assertSame('apply_storage_not_configured', $body['reason']);
    }

    public function testPluginRejectsARetiredLargePushWithoutBufferingItsBody(): void
    {
        $url = $this->base_url . '&low_memory=1&endpoint=staged_push';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $headers['Content-Type'] = 'application/octet-stream';
        $headers['Expect'] = '';
        $response = $this->http_response($url, str_repeat('x', 12 * 1024 * 1024), $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(410, $response['status'], $response['body']);
        $this->assertIsArray($body);
        $this->assertSame('endpoint_retired', $body['reason']);
        $this->assertSame(
            'The staged_push endpoint was removed. Use staged_session_create and staged_session_push.',
            $body['detail']
        );
    }

    public function testPluginDoesNotReturnATraceForAnAuthenticatedMalformedRequest(): void
    {
        $url = $this->base_url . '&endpoint=staged_session_create&create_token=' . str_repeat('b', 32);
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $response = $this->http_response($url, 'endpoint=file_index', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(400, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('endpoint parameter must match in the request target and body.', $body['error']);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertStringNotContainsString($this->server_root, $response['body']);
        $this->assertStringNotContainsString($this->staging_dir, $response['body']);
    }

    public function testPluginReturnsPointedOrdinaryEndpointValidationWithoutATrace(): void
    {
        $requests = [
            [$this->base_url, 'endpoint parameter is required.'],
            [$this->base_url . '&endpoint=not_a_real_endpoint', "Invalid endpoint: 'not_a_real_endpoint'."],
        ];

        foreach ($requests as [$url, $expected_error]) {
            $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_auth_headers('');
            $response = $this->http_response($url, '', $headers);
            $body = json_decode($response['body'], true);

            $this->assertSame(400, $response['status']);
            $this->assertIsArray($body);
            $this->assertStringContainsString($expected_error, $body['error']);
            $this->assertStringContainsString('file_index', $body['error']);
            $this->assertStringContainsString('sql_chunk', $body['error']);
            $this->assertArrayNotHasKey('trace', $body);
            $this->assertArrayNotHasKey('file', $body);
            $this->assertStringNotContainsString($this->server_root, $response['body']);
            $this->assertStringNotContainsString($this->staging_dir, $response['body']);
        }
    }

    public function testPluginRunsCustomAuthenticationBeforeRejectingAStagedEnvelope(): void
    {
        $url = $this->base_url . '&custom_auth=record&without_staging=1&endpoint=staged_session_create&create_token=' . str_repeat('c', 32);
        $response = $this->http_response($url, '', []);
        $body = json_decode($response['body'], true);

        $this->assertSame(403, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame(['rejected', 'auth_failed'], [$body['status'], $body['reason']]);
        $this->assertSame('Authentication failed.', $body['detail']);
        $this->assertSame('called', file_get_contents($this->server_root . '/custom-auth-called'));
        $this->assertStringNotContainsString('custom-auth-output', $response['body']);
    }

    public function testPluginClearsBufferedOutputBeforeReportingAnInternalError(): void
    {
        $url = $this->base_url . '&custom_auth=throw&endpoint=file_index';
        $response = $this->http_response($url, '', []);
        $body = json_decode($response['body'], true);

        $this->assertSame(500, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame(
            ['The Reprint Exporter encountered an internal error.', 500],
            [$body['error'], $body['code']]
        );
        $this->assertStringNotContainsString('custom-auth-output', $response['body']);
        $this->assertStringNotContainsString('custom authenticator failed', $response['body']);
    }

    public function testPluginDoesNotExposeADispatchFailure(): void
    {
        $url = $this->base_url . '&dispatch_error=runtime&endpoint=file_index';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_auth_headers('');
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(500, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame(
            ['The Reprint Exporter encountered an internal error.', 500],
            [$body['error'], $body['code']]
        );
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertStringNotContainsString('dispatch failed', strtolower($response['body']));
        $this->assertStringNotContainsString($this->server_root, $response['body']);
    }

    public function testPluginRejectsSessionCreationWithoutExplicitStorage(): void
    {
        $url = $this->base_url . '&without_staging=1&endpoint=staged_session_create&create_token=' . str_repeat('d', 32);
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('rejected', $body['status']);
        $this->assertSame('apply_storage_not_configured', $body['reason']);
        $this->assertSame(
            'Push requires SITE_EXPORT_STAGING_DIR to name an explicitly managed private staging directory.',
            $body['detail']
        );
    }

    public function testPluginAuthenticatesSessionRequestsBeforeReportingMissingStorage(): void
    {
        $url = $this->base_url . '&without_staging=1&endpoint=staged_session_create&create_token=' . str_repeat('e', 32);
        $header_sets = [
            [],
            ( new Site_Export_HMAC_Client('wrong-secret') )->get_envelope_auth_headers('POST', $url),
        ];

        foreach ($header_sets as $headers) {
            $response = $this->http_response($url, '', $headers);
            $body = json_decode($response['body'], true);

            $this->assertSame(403, $response['status']);
            $this->assertIsArray($body);
            $this->assertSame('rejected', $body['status']);
            $this->assertSame('auth_failed', $body['reason']);
            $this->assertSame('Authentication failed.', $body['detail']);
        }
    }

    public function testPluginAuthenticatesSessionRequestsBeforeReportingInvalidStorage(): void
    {
        $url = $this->base_url . '&invalid_staging=root&endpoint=staged_session_create&create_token=' . str_repeat('f', 32);
        $response = $this->http_response($url, '', []);
        $body = json_decode($response['body'], true);

        $this->assertSame(403, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('auth_failed', $body['reason']);
        $this->assertSame('Authentication failed.', $body['detail']);

        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('rejected', $body['status']);
        $this->assertSame('apply_storage_invalid', $body['reason']);
        $this->assertSame('SITE_EXPORT_STAGING_DIR must not be the filesystem root; observed /.', $body['detail']);
    }

    public function testPluginReportsAnExistingStagingFileAsTerminalConfiguration(): void
    {
        $url = $this->base_url . '&invalid_staging=file&endpoint=staged_session_create&create_token=' . str_repeat('2', 32);
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('rejected', $body['status']);
        $this->assertSame('apply_storage_invalid', $body['reason']);
        $this->assertStringContainsString('must not be blocked by a non-directory path', $body['detail']);
        $this->assertStringNotContainsString('retryable_io_error', $response['body']);
    }

    public function testPluginAuthenticatesSessionRequestsBeforeReportingInvalidApplyTarget(): void
    {
        $url = $this->base_url . '&invalid_apply_root=array&endpoint=staged_session_create&create_token=' . str_repeat('1', 32);
        $header_sets = [
            [],
            ( new Site_Export_HMAC_Client('wrong-secret') )->get_envelope_auth_headers('POST', $url),
        ];

        foreach ($header_sets as $headers) {
            $response = $this->http_response($url, '', $headers);
            $body = json_decode($response['body'], true);

            $this->assertSame(403, $response['status']);
            $this->assertIsArray($body);
            $this->assertSame('auth_failed', $body['reason']);
            $this->assertSame('Authentication failed.', $body['detail']);
        }

        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame('rejected', $body['status']);
        $this->assertSame('apply_target_invalid', $body['reason']);
        $this->assertSame('SITE_EXPORT_APPLY_ROOT must be a string; observed array.', $body['detail']);
    }

    public function testPluginKeepsItsServerOwnedStagingDirectoryOutOfFileIndexes(): void
    {
        $inside_staging_dir = $this->target_root . '/.reprint-staging';
        $visible_path = $this->target_root . '/visible.txt';
        mkdir($inside_staging_dir, 0700, true);
        file_put_contents($inside_staging_dir . '/session-state.json', 'private');
        file_put_contents($visible_path, 'visible');

        $url = $this->base_url
            . '&staging_inside_target=1&endpoint=file_index&list_dir=' . rawurlencode($this->target_root)
            . '&storage_path=' . rawurlencode($visible_path)
            . '&invalid_apply_root=array';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_auth_headers('');
        $response = $this->http_response($url, '', $headers);

        $this->assertSame(200, $response['status'], $response['body']);
        $paths = $this->decode_file_index_paths($response['body']);
        $visible_path = (string) realpath($visible_path);
        $inside_staging_dir = (string) realpath($inside_staging_dir);
        $this->assertContains(
            $visible_path,
            $paths,
            'A client storage_path must not hide an arbitrary target file: ' . json_encode($paths)
        );
        $this->assertNotContains($inside_staging_dir, $paths);
        $this->assertNotContains($inside_staging_dir . '/session-state.json', $paths);
    }

    public function testPluginKeepsOrdinaryPullAvailableWithoutStagingConfiguration(): void
    {
        $visible_path = $this->target_root . '/visible-without-staging.txt';
        file_put_contents($visible_path, 'visible');
        $url = $this->base_url
            . '&without_staging=1&endpoint=file_index&list_dir=' . rawurlencode($this->target_root);
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_auth_headers('');
        $response = $this->http_response($url, '', $headers);

        $this->assertSame(200, $response['status'], $response['body']);
        $visible_path = (string) realpath($visible_path);
        $this->assertContains($visible_path, $this->decode_file_index_paths($response['body']));
    }

    public function testPluginOptionsProtectItsLexicalSymlinkInstallPath(): void
    {
        $url = str_replace('/?reprint-api=1', '/__staged-options', $this->base_url);
        $response = $this->http_response($url, '', []);
        $options = json_decode($response['body'], true);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertIsArray($options);
        $this->assertFalse($options['apply_sessions_enabled']);
        $this->assertContains('wp-content/plugins/site-export', $options['apply_protected_paths']);
    }

    public function testPluginKeepsLiveApplyDisabledUntilNoBootRecoveryExists(): void
    {
        $url = $this->base_url . '&endpoint=staged_session_create&create_token=' . str_repeat('a', 32);
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $response = $this->http_response($url, '', $headers);
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertIsArray($body);
        $this->assertSame(['rejected', 'apply_not_configured'], [$body['status'], $body['reason']]);
        $this->assertSame('Server configuration has not enabled a safe staged-apply recovery entry point.', $body['detail']);
        $this->assertDirectoryDoesNotExist($this->staging_dir . '/apply-sessions');
        $this->assertTrue(is_link($this->plugin_install_dir));
    }

    private function write_router(): void
    {
        $plugin_lib = addslashes($this->plugin_install_dir . '/lib.php');
        $plugin_root = addslashes(dirname($this->plugin_install_dir));
        $target_root = addslashes($this->target_root . '/');
        $staging_dir = addslashes($this->staging_dir);
        $invalid_staging_file = addslashes($this->server_root . '/staging-file');
        $secret_path = addslashes($this->secret_path);
        $custom_auth_marker = addslashes($this->server_root . '/custom-auth-called');
        $dispatch_error_detail = addslashes('Dispatch failed at ' . $this->server_root . '/private-runtime.php.');
        file_put_contents($this->router_path, <<<PHP_ROUTER
<?php
if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}
if ((\$_GET['dispatch_error'] ?? null) === 'runtime') {
    final class Site_Export_HTTP_Server {
        public static function handle_cors_headers_and_terminate_on_options(\$origin = '*'): void {}
        public static function is_staged_session_endpoint(string \$endpoint): bool { return false; }
        public static function serve(array \$options = []): void {
            throw new RuntimeException('{$dispatch_error_detail}');
        }
    }
}
if ((\$_GET['low_memory'] ?? null) === '1') {
    if (ini_set('memory_limit', '8M') === false) {
        throw new RuntimeException('Could not lower the endpoint test memory limit.');
    }
}
define('ABSPATH', '{$target_root}');
if ((\$_GET['without_staging'] ?? null) !== '1') {
    \$configured_staging_dir = '{$staging_dir}';
    if ((\$_GET['invalid_staging'] ?? null) === 'root') {
        \$configured_staging_dir = '/';
    } elseif ((\$_GET['invalid_staging'] ?? null) === 'file') {
        \$configured_staging_dir = '{$invalid_staging_file}';
        file_put_contents(\$configured_staging_dir, 'not a directory');
    } elseif ((\$_GET['staging_inside_target'] ?? null) === '1') {
        \$configured_staging_dir = '{$target_root}.reprint-staging';
    }
    define('SITE_EXPORT_STAGING_DIR', \$configured_staging_dir);
}
\$configured_apply_root = '{$target_root}';
if ((\$_GET['invalid_apply_root'] ?? null) === 'array') {
    \$configured_apply_root = [];
}
define('SITE_EXPORT_APPLY_ROOT', \$configured_apply_root);
define('WP_PLUGIN_DIR', '{$plugin_root}');
define('SITE_EXPORT_SECRET_FILE', '{$secret_path}');
function plugin_dir_path(\$file) { return dirname(\$file) . '/'; }
function plugin_basename(\$file) { return 'site-export/lib.php'; }
require '{$plugin_lib}';
if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__staged-options') {
    \$options = _site_export_staged_options();
    unset(\$options['secret']);
    header('Content-Type: application/json');
    echo json_encode(\$options);
    return true;
}
\$options = [];
if ((\$_GET['custom_auth'] ?? null) === 'record') {
    \$options['authenticate'] = static function () {
        file_put_contents('{$custom_auth_marker}', 'called');
    };
} elseif ((\$_GET['custom_auth'] ?? null) === 'throw') {
    \$options['authenticate'] = static function () {
        echo 'custom-auth-output';
        throw new RuntimeException('custom authenticator failed');
    };
}
_site_export_handle_api_request(\$options);
return true;
PHP_ROUTER
        );
    }

    private function start_server(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error_message);
        if ($socket === false) {
            self::fail('Could not reserve plugin test port: ' . $error_message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr( (string) $address, ':'), 1);
        $this->base_url = 'http://127.0.0.1:' . $port . '/?reprint-api=1';
        $this->server_process = proc_open(
            [PHP_BINARY, '-n', '-d', 'post_max_size=32M', '-S', '127.0.0.1:' . $port, '-t', $this->server_root, $this->router_path],
            [0 => ['pipe', 'r'], 1 => ['file', $this->server_root . '/server.log', 'a'], 2 => ['file', $this->server_root . '/server.log', 'a']],
            $pipes,
            $this->server_root
        );
        if (!is_resource($this->server_process)) {
            self::fail('Could not start plugin endpoint test server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $port . '/__ping') === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::fail('Plugin endpoint test server did not start.');
    }

    /** @return array{status:int,body:string} */
    private function http_response(string $url, string $body, array $headers): array
    {
        $handle = curl_init($url);
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response)) {
            throw new \RuntimeException('Plugin endpoint request failed: ' . $error);
        }
        return ['status' => $status, 'body' => $response];
    }

    /** @return string[] */
    private function decode_file_index_paths(string $response): array
    {
        $decoded = @gzdecode($response);
        $multipart = is_string($decoded) ? $decoded : $response;
        if (preg_match('/^--(boundary-[A-Za-z0-9]+)/m', $multipart, $matches) !== 1) {
            throw new \RuntimeException('Plugin file_index response has no multipart boundary.');
        }

        $paths = [];
        foreach (explode('--' . $matches[1], $multipart) as $part) {
            if (strpos($part, 'X-Chunk-Type: index_batch') === false) {
                continue;
            }
            $header_end = strpos($part, "\r\n\r\n");
            if ($header_end === false) {
                continue;
            }
            $batch = json_decode(rtrim(substr($part, $header_end + 4), "\r\n"), true);
            if (!is_array($batch)) {
                throw new \RuntimeException('Plugin file_index returned an invalid index batch.');
            }
            foreach ($batch as $item) {
                $path = is_array($item) && is_string($item['path'] ?? null)
                    ? base64_decode($item['path'], true)
                    : false;
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }
        return $paths;
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (( file_exists($path) || is_link($path) ) && !@unlink($path)) {
                throw new \RuntimeException('Could not remove test path: ' . $path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->remove_tree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
