<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the established PHPUnit namespace.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/** Exercises staging exclusion and stale-pull recovery through a real endpoint. */
final class StagingExclusionPullTest extends TestCase {

    private static string $case_root;
    private static string $remote_site;
    private static string $external_staging_root;
    private static string $internal_staging_root;
    private static string $state_dir;
    private static string $filesystem_root;
    private static string $mode_path;
    private static string $request_log_path;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process;

    public static function setUpBeforeClass(): void {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('Pull streaming requires the curl extension.');
        }

        self::$case_root = sys_get_temp_dir() . '/staging-exclusion-pull-' . bin2hex(random_bytes(8));
        mkdir(self::$case_root, 0700, true);
        self::$remote_site = self::$case_root . '/remote-site';
        self::$external_staging_root = self::$case_root . '/external-staging';
        self::$internal_staging_root = self::$remote_site . '/.reprint-staging';
        self::$state_dir = self::$case_root . '/state';
        self::$filesystem_root = self::$case_root . '/files';
        self::$mode_path = self::$case_root . '/mode';
        self::$request_log_path = self::$case_root . '/requests.jsonl';

        mkdir(self::$remote_site, 0700, true);
        mkdir(self::$external_staging_root, 0700, true);
        mkdir(self::$internal_staging_root, 0700, true);
        file_put_contents(self::$remote_site . '/allowed.txt', 'allowed-v1');
        file_put_contents(self::$internal_staging_root . '/private.txt', 'private-v1');
        file_put_contents(self::$mode_path, 'external');
        file_put_contents(self::$request_log_path, '');

        self::$remote_site = (string) realpath(self::$remote_site);
        self::$external_staging_root = (string) realpath(self::$external_staging_root);
        self::$internal_staging_root = (string) realpath(self::$internal_staging_root);

        $router_path = self::$case_root . '/router.php';
        self::writeRouter($router_path);
        self::startServer($router_path);
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$server_process)) {
            proc_terminate(self::$server_process);
            proc_close(self::$server_process);
            self::$server_process = null;
        }
        if (isset(self::$case_root)) {
            self::removeTree(self::$case_root);
        }
    }

    public function testStaleListRejectsAtomicallyAndAbortReindexRemovesStaging(): void {
        $this->seedPreflightState();
        $this->runFilesPull();

        $local_site = self::$filesystem_root . self::$remote_site;
        $local_allowed_path = $local_site . '/allowed.txt';
        $local_private_path = $local_site . '/.reprint-staging/private.txt';
        $this->assertSame('allowed-v1', file_get_contents($local_allowed_path));
        $this->assertSame('private-v1', file_get_contents($local_private_path));
        $this->assertContains(self::$internal_staging_root . '/private.txt', $this->indexedPaths());

        file_put_contents(self::$remote_site . '/allowed.txt', 'allowed-version-two');
        file_put_contents(self::$internal_staging_root . '/private.txt', 'private-version-two');
        $this->abortFilesPull();

        // The index still treats the in-tree directory as ordinary, but fetch
        // now sees it as the server-owned staging root. The complete mixed
        // batch must be rejected before either changed file is transmitted.
        file_put_contents(self::$mode_path, 'split');
        try {
            $this->runFilesPull();
            $this->fail('A fetch list made stale by target configuration was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('files-pull --abort', $error->getMessage());
            $this->assertStringContainsString('rerun the full pull', $error->getMessage());
            $this->assertStringContainsString(
                base64_encode(self::$internal_staging_root . '/private.txt'),
                $error->getMessage()
            );
        }

        $this->assertSame('allowed-v1', file_get_contents($local_allowed_path));
        $this->assertSame('private-v1', file_get_contents($local_private_path));
        $this->assertFileExists(self::$state_dir . '/.import-download-list.jsonl');
        $stale_download_paths = $this->encodedPathsFromJsonLines(
            self::$state_dir . '/.import-download-list.jsonl'
        );
        $this->assertContains(self::$remote_site . '/allowed.txt', $stale_download_paths);
        $this->assertContains(self::$internal_staging_root . '/private.txt', $stale_download_paths);

        $this->abortFilesPull();
        file_put_contents(self::$mode_path, 'internal');
        $this->runFilesPull();

        $this->assertSame('allowed-version-two', file_get_contents($local_allowed_path));
        $this->assertFileDoesNotExist($local_private_path);
        $this->assertNotContains(self::$internal_staging_root, $this->indexedPaths());
        $this->assertNotContains(self::$internal_staging_root . '/private.txt', $this->indexedPaths());
        $journal_dir = self::$state_dir . '/push/' . \PushJournal::site_key(
            self::$base_url . '/?directory=' . rawurlencode(self::$remote_site)
        );
        $baseline_path = $journal_dir . '/last-sync-local-files.jsonl';
        $this->assertFileExists($baseline_path);
        $this->assertFileExists($journal_dir . '/last-sync-local-files.identity.json');
        $baseline_paths = $this->encodedPathsFromJsonLines($baseline_path);
        $this->assertNotContains('.reprint-staging', $baseline_paths);
        $this->assertNotContains('.reprint-staging/private.txt', $baseline_paths);
        $this->assertImporterRequestsContainNoPrivateConfiguration();
    }

    public function testMultiRootPullTraversesEveryRootWithoutPublishingPushBaseline(): void {
        $first_root = self::$case_root . '/multi-root-first';
        $second_root = self::$case_root . '/multi-root-second';
        mkdir($first_root, 0700, true);
        mkdir($second_root, 0700, true);
        file_put_contents($first_root . '/first.txt', 'first root');
        file_put_contents($second_root . '/second.txt', 'second root');
        $first_root = (string) realpath($first_root);
        $second_root = (string) realpath($second_root);
        $state_dir = self::$case_root . '/multi-root-state';
        $filesystem_root = self::$case_root . '/multi-root-files';
        $url = self::$base_url . '/?directory%5B%5D=' . rawurlencode($first_root) .
            '&directory%5B%5D=' . rawurlencode($second_root);
        $client = new \ImportClient($url, $state_dir, $filesystem_root);
        $this->writePreflightState($client, $state_dir, [$first_root, $second_root]);

        $client->run([
            'command' => 'files-pull',
            'follow_symlinks' => false,
        ]);

        $this->assertSame('first root', file_get_contents($filesystem_root . $first_root . '/first.txt'));
        $this->assertSame('second root', file_get_contents($filesystem_root . $second_root . '/second.txt'));
        $indexed_paths = $this->encodedPathsFromJsonLines($state_dir . '/.import-index.jsonl');
        $this->assertContains($first_root . '/first.txt', $indexed_paths);
        $this->assertContains($second_root . '/second.txt', $indexed_paths);
        $journal_dir = $state_dir . '/push/' . \PushJournal::site_key($url);
        $this->assertFileDoesNotExist($journal_dir . '/last-sync-local-files.jsonl');
        $this->assertFileDoesNotExist($journal_dir . '/last-sync-local-files.identity.json');
    }

    private function client(): \ImportClient {
        return new \ImportClient(
            self::$base_url . '/?directory=' . rawurlencode(self::$remote_site),
            self::$state_dir,
            self::$filesystem_root
        );
    }

    private function seedPreflightState(): void {
        $client = $this->client();
        $this->writePreflightState($client, self::$state_dir, [self::$remote_site]);
    }

    /** @param list<string> $roots */
    private function writePreflightState(\ImportClient $client, string $state_dir, array $roots): void {
        $state = $client->default_state();
        $state['preflight'] = [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'wp_detect' => ['roots' => array_map(
                    static fn(string $path): array => ['path' => $path],
                    $roots
                )],
                'runtime' => [
                    'document_root' => $roots[0],
                    'ini_get_all' => [],
                ],
                'database' => [
                    'wp' => ['paths_urls' => ['content_dir' => null]],
                ],
                'limits' => ['max_request_bytes' => 4 * 1024 * 1024],
            ],
        ];
        $state['remote_protocol_version'] = 1;
        $state['remote_protocol_min_version'] = 1;
        $state['follow_symlinks'] = false;
        file_put_contents(
            $state_dir . '/.import-state.json',
            json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    private function runFilesPull(): void {
        $this->client()->run([
            'command' => 'files-pull',
            'follow_symlinks' => false,
        ]);
    }

    private function abortFilesPull(): void {
        $this->client()->run([
            'command' => 'files-pull',
            'follow_symlinks' => false,
            'abort' => true,
        ]);
    }

    /** @return list<string> */
    private function indexedPaths(): array {
        return $this->encodedPathsFromJsonLines(
            self::$state_dir . '/.import-index.jsonl'
        );
    }

    /** @return list<string> */
    private function encodedPathsFromJsonLines(string $path): array {
        $paths = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode( (string) ( $entry['path'] ?? '' ), true);
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    private function assertImporterRequestsContainNoPrivateConfiguration(): void {
        $saw_file_index = false;
        $saw_file_fetch = false;
        foreach (file(self::$request_log_path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $endpoint = $request['endpoint'] ?? null;
            $saw_file_index = $saw_file_index || $endpoint === 'file_index';
            $saw_file_fetch = $saw_file_fetch || $endpoint === 'file_fetch';
            $request_keys = array_merge(
                $request['get_keys'] ?? [],
                $request['post_keys'] ?? []
            );
            foreach (['storage_path', 'staging_dir', 'excluded_staging_root'] as $private_key) {
                $this->assertNotContains($private_key, $request_keys, $endpoint ?? 'unknown endpoint');
            }
            if ($endpoint === 'file_fetch') {
                $this->assertSame(['file_list'], $request['file_keys'] ?? []);
            }
        }
        $this->assertTrue($saw_file_index, 'The real importer made no file_index request.');
        $this->assertTrue($saw_file_fetch, 'The real importer made no file_fetch request.');
    }

    private static function writeRouter(string $router_path): void {
        $autoload_path = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $class_path = dirname(__DIR__, 2) . '/packages/reprint-exporter/src/class-http-server.php';
        $router = sprintf(
            <<<'PHP'
<?php
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}

require_once %s;
require_once %s;
$endpoint = (string) ($_GET['endpoint'] ?? $_POST['endpoint'] ?? '');
file_put_contents(
    %s,
    json_encode([
        'endpoint' => $endpoint,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'get_keys' => array_keys($_GET),
        'post_keys' => array_keys($_POST),
        'file_keys' => array_keys($_FILES),
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

$mode = trim((string) file_get_contents(%s));
$staging_root = %s;
if ($mode === 'internal' || ($mode === 'split' && $endpoint === 'file_fetch')) {
    $staging_root = %s;
}

try {
    Site_Export_HTTP_Server::serve([
        'default_directory' => %s,
        'staged' => [
            'staging_dir' => $staging_root,
            'secret' => 'test-secret',
        ],
    ]);
} catch (Throwable $error) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
PHP,
            self::phpStringLiteral($autoload_path),
            self::phpStringLiteral($class_path),
            self::phpStringLiteral(self::$request_log_path),
            self::phpStringLiteral(self::$mode_path),
            self::phpStringLiteral(self::$external_staging_root),
            self::phpStringLiteral(self::$internal_staging_root),
            self::phpStringLiteral(self::$remote_site)
        );
        file_put_contents($router_path, $router);
    }

    private static function phpStringLiteral(string $value): string {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The generated router needs a valid PHP string literal.
        return var_export($value, true);
    }

    private static function startServer(string $router_path): void {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception is not HTML output.
            throw new \RuntimeException('Could not reserve a local HTTP port: ' . $error . ' (' . $errno . ').');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr( (string) $address, ':'), 1);
        self::$base_url = 'http://127.0.0.1:' . $port;
        self::$server_process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$case_root, $router_path],
            [
                0 => ['pipe', 'r'],
                1 => ['file', self::$case_root . '/server.log', 'a'],
                2 => ['file', self::$case_root . '/server.log', 'a'],
            ],
            $pipes,
            self::$case_root
        );
        if (!is_resource(self::$server_process)) {
            throw new \RuntimeException('Could not start the staging exclusion endpoint.');
        }
        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            try {
                $ping = @file_get_contents(self::$base_url . '/__ping');
            } catch (\Throwable $error) {
                $ping = false;
            }
            if ($ping === 'ok') {
                return;
            }
            usleep(100000);
        }

        self::tearDownAfterClass();
        throw new \RuntimeException('The staging exclusion endpoint did not start.');
    }

    private static function removeTree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
