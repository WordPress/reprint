<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class RemoteUploadProxyRuntimeTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $outputDir;
    /** @var resource[] */
    private array $server_processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remote-upload-proxy-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->server_processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }

        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            'active_resumable_command' => [
                'command_name' => null,
                'completion_state' => null,
                'current_stage' => null,
                'remote_cursor' => null,
            ],
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => $this->fsRoot,
                                'uploads' => [
                                    'baseurl' => 'https://source.example/wp-content/uploads',
                                ],
                                'home_url' => 'https://source.example',
                                'site_url' => 'https://source.example',
                            ],
                        ],
                    ],
                ],
            ],
            'webhost' => 'other',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        \write_current_pull_state($this->makeClient(), array_replace_recursive($defaults, $state));
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $method_reflection = $reflection->getMethod($method);
        return $method_reflection->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setValue($client, $value);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    private function runApplyRuntime(
        \ImportClient $client,
        ?string $flat_document_root = null
    ): string
    {
        ob_start();
        try {
            $this->callPrivate($client, 'run_apply_runtime', [[
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
                'flat_document_root' => $flat_document_root ?? $this->fsRoot,
            ]]);
        } finally {
            ob_end_clean();
        }

        return file_get_contents($this->outputDir . '/runtime.php');
    }

    public function testProxyRejectsParentSegmentsBeforeCheckingOrForwardingPaths(): void
    {
        $url = $this->startProxyRuntime();
        mkdir($this->tempDir . '/private');
        file_put_contents($this->tempDir . '/private/existing.txt', 'private');
        foreach ([
            '../../../private/existing.txt', '../../../private/missing.txt',
            '%2e%2e/%2e%2e/%2e%2e/private/existing.txt',
            '%2e%2e/%2e%2e/%2e%2e/private/missing.txt',
            '..%5c..%5c..%5cprivate/missing.txt', '%00/missing.txt', './missing.txt',
        ] as $suffix) {
            [$status] = $this->request($url . '/wp-content/uploads/' . $suffix);
            $this->assertSame(404, $status, $suffix);
        }
        $this->assertFileDoesNotExist($this->tempDir . '/upstream-requests');
    }

    public function testProxyKeepsLocalFilesAndForwardsEncodedMissingUploads(): void
    {
        $url = $this->startProxyRuntime();
        file_put_contents($this->fsRoot . '/wp-content/uploads/2026/photo.txt', 'local photo');
        [$status, $body] = $this->request($url . '/wp-content/uploads/2026/photo.txt');
        $this->assertSame(200, $status);
        $this->assertSame('local photo', $body);
        $this->assertFileDoesNotExist($this->tempDir . '/upstream-requests');
        foreach (['GET', 'HEAD'] as $method) {
            [$status, $body] = $this->request($url . '/wp-content/uploads/2026/missing%20photo.txt?v=1', $method);
            $this->assertSame(206, $status);
            $this->assertSame($method === 'HEAD' ? '' : 'remote photo', $body);
        }
        $this->assertSame(
            "GET /2026/missing%20photo.txt?v=1\nHEAD /2026/missing%20photo.txt?v=1\n",
            file_get_contents($this->tempDir . '/upstream-requests')
        );
    }

    private function startProxyRuntime(): string
    {
        mkdir($this->tempDir . '/upstream');
        file_put_contents($this->tempDir . '/upstream/index.php', '<?php '
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode the fixture path as a PHP literal.
            . 'file_put_contents(' . var_export($this->tempDir . '/upstream-requests', true)
            . ', $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);'
            . 'http_response_code(206); echo "remote photo";');
        $upstream_url = $this->startServer($this->tempDir . '/upstream', $this->tempDir . '/upstream/index.php');
        mkdir($this->fsRoot . '/wp-content/uploads/2026', 0755, true);
        file_put_contents($this->fsRoot . '/index.php', '<?php http_response_code(404); echo "local missing";');
        $this->writeState(['filter' => 'essential-files', 'preflight' => ['data' => ['database' => ['wp' => [
            'paths_urls' => ['uploads' => ['baseurl' => $upstream_url]],
        ]]]]]);
        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);
        return $this->startServer($this->fsRoot, $this->outputDir . '/runtime.php');
    }

    private function startServer(string $document_root, string $router): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $document_root, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tempDir . '/server.log', 'a'],
                2 => ['file', $this->tempDir . '/server.log', 'a']], $pipes);
        $this->assertIsResource($process);
        $this->server_processes[] = $process;
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->tempDir . '/server.log'));
    }

    /** @return array{int,string} HTTP status and response body from the real router. */
    private function request(string $url, string $method = 'GET'): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_PATH_AS_IS => true, CURLOPT_NOBODY => $method === 'HEAD']);
        $body = curl_exec($handle);
        $this->assertIsString($body, curl_error($handle));
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, $body];
    }

    public function testApplyRuntimeKeepsTheFilesystemRootAsTheFlatDocumentRoot(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client, '/');

        $this->assertStringContainsString(
            "-t '/'",
            file_get_contents($this->outputDir . '/start.sh')
        );
    }

    /**
     * The source document root and WordPress core may map to different local directories.
     *
     * @dataProvider raw_runtime_paths
     * @param string $document_root Remote document root.
     * @param string $abspath Remote WordPress core directory.
     * @param string $local_document_root Mapped document root below fsRoot.
     * @param string $local_abspath Mapped WordPress core below fsRoot.
     * @param string $path_format Format reported by the source preflight.
     */
    public function testApplyRuntimeFindsMappedAbspathInTheRawDownload(
        string $document_root,
        string $abspath,
        string $local_document_root,
        string $local_abspath,
        string $path_format
    ): void {
        mkdir($this->fsRoot . $local_document_root, 0755, true);
        if ($local_abspath !== '') {
            mkdir($this->fsRoot . $local_abspath, 0755, true);
            file_put_contents($this->fsRoot . $local_abspath . '/index.php', "<?php // WordPress core");
        }
        file_put_contents(
            $this->fsRoot . $local_document_root . '/index.php',
            "<?php echo 'document root';\n",
        );
        $this->writeState([
            'preflight' => [
                'data' => [
                    'path_format' => $path_format,
                    'runtime' => [
                        'document_root' => $document_root,
                    ],
                    'database' => [
                        'wp' => [
                            'paths_urls' => [
                                'abspath' => $abspath,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        ob_start();
        try {
            $this->callPrivate($client, 'run_apply_runtime', [[
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
            ]]);
        } finally {
            ob_end_clean();
        }
        $runtime = file_get_contents($this->outputDir . '/runtime.php');
        $resolved_filesystem_root = realpath($this->fsRoot . $local_abspath);
        $this->assertIsString($resolved_filesystem_root);

        $this->assertStringContainsString(
            "\$wordpress_core_dir = '" . addslashes($resolved_filesystem_root) . "';",
            $runtime,
        );
    }

    /** @return array[] Source paths, Linux locations, and explicit source format. */
    public static function raw_runtime_paths(): array
    {
        return [
            'Unix root' => ['/remote-document-root', '/', '/remote-document-root', '', 'unix'],
            'Windows drive' => ['D:\\Sites', 'D:\\WordPress', '/D:/Sites', '/D:/WordPress', 'windows'],
            'Windows share' => ['\\\\server\\share\\Sites', '\\\\server\\share\\WordPress', '/UNC/SERVER/SHARE/Sites', '/UNC/SERVER/SHARE/WordPress', 'windows'],
        ];
    }

    public function testApplyRuntimeAddsProxyForEssentialFilesFilter(): void
    {
        $client = $this->makeClient();
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'db-apply',
                'completion_state' => 'complete',
            ],
            'filter' => 'essential-files',
        ]);
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);
        $resolvedPullStateDirectory =
            realpath($client->pull_state_directory)
            ?: $client->pull_state_directory;

        $this->assertStringContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringContainsString(
            "define('REPRINT_PULL_STATE_FILE', '"
                . $resolvedPullStateDirectory
                . "/state.json');",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "REPRINT_PULL_SKIPPED_FETCH_LIST_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "https://source.example/wp-content/uploads",
            $runtime,
        );
        $this->assertStringContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }


    public function testApplyRuntimeAddsProxyWhileFilesPullIsIncomplete(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'fetch',
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringContainsString(
            "REPRINT_PULL_STATE_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "Proxy missing uploads from the source site until files-pull completes.",
            $runtime,
        );
    }

    public function testApplyRuntimeOmitsProxyAfterFilesPullCompletes(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
            ],
            'filter' => 'none',
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringNotContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "REPRINT_PULL_STATE_FILE",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }
}
