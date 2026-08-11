<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * --include and --exclude reuse the existing `value-or-next` option type (like
 * --new-site-url), but are repeatable because commas are valid path bytes. The
 * parser lives inside the CLI bootstrap guard (not require-able), so this
 * exercises the real binary.
 */
class OnlyCliParseTest extends TestCase
{
    private $tempDir;
    /** @var resource|null */
    private $serverProcess = null;
    /** @var array<int, resource> */
    private $serverPipes = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-cli-' . uniqid();
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProcess);
            $this->serverProcess = null;
            $this->serverPipes = array();
        }

        // The real CLI run may write remote pull state and an audit log into
        // the state directory, so a plain rmdir wouldn't clear it — recurse.
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
            (is_dir($path) && !is_link($path)) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runCli(array $args): string
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $cmd = 'php ' . escapeshellarg($entry);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        return shell_exec($cmd . ' 2>&1') ?? '';
    }


    private function findUnusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            $this->fail("Failed to find unused port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function startDirectoryCaptureServer(string $requestsLog): string
    {
        $router = $this->tempDir . '/capture-directories.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
$log = %s;
file_put_contents($log, json_encode(array(
    'endpoint' => $_GET['endpoint'] ?? null,
    'directory' => $_GET['directory'] ?? null,
    'exclude_path' => $_GET['exclude_path'] ?? null,
), JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$boundary = 'reprint-test-boundary';
header('Content-Type: multipart/mixed; boundary=' . $boundary);
echo "--{$boundary}\r\n";
echo "X-Chunk-Type: completion\r\n";
echo "X-Status: complete\r\n";
echo "X-Total-Entries: 0\r\n";
echo "Content-Length: 0\r\n\r\n";
echo "\r\n--{$boundary}--\r\n";
PHP, var_export($requestsLog, true)));

        $port = $this->findUnusedPort();
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($router)
        );

        $this->serverProcess = proc_open($command, $descriptors, $this->serverPipes, $this->tempDir);
        if (!is_resource($this->serverProcess)) {
            $this->fail('Failed to start capture server');
        }
        fclose($this->serverPipes[0]);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return "http://127.0.0.1:{$port}/export.php?site-export-api";
            }
            usleep(100000);
        }

        $this->fail('Capture server did not start');
    }

    private function capturedRequests(string $requestsLog): array
    {
        if (!file_exists($requestsLog)) {
            return array();
        }

        $requests = array();
        foreach (file($requestsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
            $requests[] = json_decode($line, true);
        }
        return $requests;
    }

    private function writePreflightState(
        string $remoteReprintApiUrl,
        bool $includeRoots = false
    ): void
    {
        $data = array(
            'ok' => true,
            'database' => array(
                'wp' => array(
                    'paths_urls' => array(
                        'content_dir' => '/var/www/html/wp-content',
                        'uploads' => array('basedir' => '/var/www/html/wp-content/uploads'),
                    ),
                ),
            ),
        );
        if ($includeRoots) {
            $data['wp_detect'] = array(
                'roots' => array(
                    array('path' => '/var/www/html'),
                ),
            );
        }

        \write_current_pull_state(
            new \ImportClient(
                $remoteReprintApiUrl,
                $this->tempDir . '/state',
                $this->tempDir . '/fs'
            ),
            array(
                'preflight' => array(
                    'data' => $data,
                    'http_code' => 200,
                ),
            )
        );
    }

    public function testIncludeOptionIsRecognizedAsRepeatableInBothForms(): void
    {
        $tail = array(
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
            '--secret=x',
        );
        // Both forms fail later (unreachable host) but must not be rejected as
        // an unknown option.
        $equals = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--include=:wp-content:', '--include=:wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $equals);

        $space = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--include', ':wp-content:', '--include', ':wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $space);
    }

    public function testOnlyAliasIsRecognizedAsRepeatableInBothForms(): void
    {
        $tail = array(
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
            '--secret=x',
        );

        $equals = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only=:wp-content:', '--only=:wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $equals);

        $space = $this->runCli(array_merge(
            array('files-pull', 'http://fake.invalid/?site-export-api', '--only', ':wp-content:', '--only', ':wp-uploads:/2025'),
            $tail
        ));
        $this->assertStringNotContainsString('Unknown option', $space);
    }

    public function testRepeatedIncludeOptionsAreAllPreserved(): void
    {
        // If repeated --include values are collapsed to the last one, this would
        // succeed because :wp-content: is resolvable. Preserving both values
        // forces resolution of :abspath:, which this preflight intentionally
        // omits.
        $this->writePreflightState('http://fake.invalid/?site-export-api');

        $output = $this->runCli(array(
            'files-pull',
            'http://fake.invalid/?site-export-api',
            '--include',
            ':abspath:/wp-admin',
            '--include',
            ':wp-content:',
            '--abort',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $result = null;
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $result = $decoded;
                break;
            }
        }
        $this->assertSame(
            'Cannot resolve token ":abspath:": not available in preflight data. Run preflight first.',
            $result['error'] ?? null
        );
        $this->assertStringNotContainsString('"status":"aborted"', $output);
    }

    public function testPullPreflightFailureReportsOriginalExceptionInCliJson(): void
    {
        $output = $this->runCli(array(
            'pull',
            'http://fake.invalid/?site-export-api',
            '--runtime=none',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $error = null;
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: array()) as $line) {
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['exception'])) {
                $error = $decoded;
                break;
            }
        }

        $this->assertSame(\RuntimeException::class, $error['exception'] ?? null, "Output:
{$output}");
        $this->assertStringNotContainsString('PullFailureReportedException', $output);
    }

    public function testPullFilesPreflightFailureReportsOriginalExceptionInCliJson(): void
    {
        $output = $this->runCli(array(
            'pull-files',
            'http://fake.invalid/?site-export-api',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $error = null;
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: array()) as $line) {
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['exception'])) {
                $error = $decoded;
                break;
            }
        }

        $this->assertSame(\RuntimeException::class, $error['exception'] ?? null, "Output:
{$output}");
        $this->assertStringNotContainsString('PullFailureReportedException', $output);
    }

    public function testIncludeAndOnlyAliasValuesAreUsedByFilesPull(): void
    {
        $requestsLog = $this->tempDir . '/requests.jsonl';
        $remoteReprintApiUrl = $this->startDirectoryCaptureServer($requestsLog);
        $this->writePreflightState($remoteReprintApiUrl, true);

        $output = $this->runCli(array(
            'files-pull',
            $remoteReprintApiUrl,
            '--include',
            ':wp-content:/plugins',
            '--include',
            ':wp-uploads:/2025',
            '--only',
            ':wp-content:/themes',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $this->assertStringNotContainsString('"status":"error"', $output);

        $fileIndexRequest = null;
        foreach ($this->capturedRequests($requestsLog) as $request) {
            if (($request['endpoint'] ?? null) === 'file_index') {
                $fileIndexRequest = $request;
                break;
            }
        }

        $this->assertNotNull($fileIndexRequest, "files-pull should request file_index. Output:\n{$output}");
        $this->assertSame(array(
            '/var/www/html/wp-content/plugins',
            '/var/www/html/wp-content/uploads/2025',
            '/var/www/html/wp-content/themes',
        ), $fileIndexRequest['directory'] ?? null);
    }

    public function testRepeatedExcludeOptionsStayOutOfFileIndexRequest(): void
    {
        $requestsLog = $this->tempDir . '/requests.jsonl';
        $remoteUrl = $this->startDirectoryCaptureServer($requestsLog);
        $this->writePreflightState($remoteUrl, true);

        $output = $this->runCli(array(
            'files-pull',
            $remoteUrl,
            '--exclude',
            ':wp-content:/cache',
            '--exclude',
            ':wp-uploads:',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $this->assertStringNotContainsString('"status":"error"', $output);

        $fileIndexRequest = null;
        foreach ($this->capturedRequests($requestsLog) as $request) {
            if (($request['endpoint'] ?? null) === 'file_index') {
                $fileIndexRequest = $request;
                break;
            }
        }

        $this->assertNotNull($fileIndexRequest, "files-pull should request file_index. Output:\n{$output}");
        $this->assertNull($fileIndexRequest['exclude_path'] ?? null);
    }

    public function testIncludeOptionKeepsCommaInsideSourcePath(): void
    {
        // --abort runs after --include resolution, avoiding a network request while
        // still proving the CLI did not split the SOURCE at the comma.
        $this->writePreflightState('http://fake.invalid/?site-export-api');

        $output = $this->runCli(array(
            'files-pull',
            'http://fake.invalid/?site-export-api',
            '--include',
            ':wp-content:/plugins,custom',
            '--abort',
            '--state-dir=' . $this->tempDir . '/state',
            '--fs-root=' . $this->tempDir . '/fs',
        ));

        $this->assertStringContainsString('"status":"aborted"', $output);
        $this->assertStringNotContainsString('path "custom"', $output);
    }
}
