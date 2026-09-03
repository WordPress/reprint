<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

class ProgressScreenTest extends TestCase {
    private $temporary_directory;
    private $state_directory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/progress-screen-test-'
            . uniqid();
        $this->state_directory = $this->temporary_directory . '/state';
        $this->filesystem_root = $this->temporary_directory . '/files';
        mkdir($this->state_directory, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_directory($this->temporary_directory);
        parent::tearDown();
    }

    public function testFileProgressUsesTheSameCountersInJsonlAndProgressFile(): void
    {
        $client = $this->make_client();
        $command = $client->get_state()->active_resumable_command;
        $command->command_name = 'files-pull';
        $command->completion_state = 'in_progress';
        $command->current_stage = 'fetch';

        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('fetch_list_done')->setValue($client, 3);
        $reflection->getProperty('fetch_list_total')->setValue($client, 10);
        $progress_stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($progress_stream);
        $reflection->getProperty('progress_fd')->setValue($client, $progress_stream);
        $reflection->getProperty('remote_to_local_path_mapper')->setValue(
            $client,
            new \RemoteToLocalPathMapper(
                (string) realpath($this->filesystem_root),
                ['/']
            )
        );

        $context = new StreamingContext();
        $remote_path = '/wp-content/uploads/large.bin';
        $downloaded_bytes = str_repeat('a', 512);
        $reflection->getMethod('handle_file_chunk')->invoke($client, [
            'headers' => [
                'x-file-path' => base64_encode($remote_path),
                'x-file-size' => (string) ( 20 * 1024 * 1024 ),
                'x-file-ctime' => '1234',
                'x-first-chunk' => '1',
                'x-last-chunk' => '0',
            ],
            'body' => $downloaded_bytes,
        ], $context);

        rewind($progress_stream);
        $jsonl_record = json_decode(
            trim( (string) stream_get_contents($progress_stream) ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        fclose($progress_stream);
        $progress_file = json_decode(
            (string) file_get_contents($this->state_directory . '/progress.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $expected = [
            'items' => [
                'unit' => 'files',
                'done' => 3,
                'total' => 10,
            ],
            'bytes' => null,
            'current_file' => [
                'path_b64' => base64_encode($remote_path),
                'bytes_done' => 512,
                'bytes_total' => 20 * 1024 * 1024,
            ],
        ];
        $this->assertSame(1, $jsonl_record['schema_version']);
        $this->assertSame($expected, $jsonl_record['progress']);
        $this->assertSame([
            'schema_version',
            'step',
            'steps',
            'command',
            'status',
            'phase',
            'message',
            'progress',
            'error',
            'error_code',
            'reason',
            'detail',
            'ts',
        ], array_keys($progress_file));
        $this->assertSame(1, $progress_file['schema_version']);
        $this->assertSame($expected, $progress_file['progress']);
        fclose($context->file_handle);
    }

    public function testStreamedProgressFileUpdatesAreLimitedToOncePerSecond(): void
    {
        $client = $this->make_client();
        $command = $client->get_state()->active_resumable_command;
        $command->command_name = 'db-pull';
        $command->completion_state = 'in_progress';
        $command->current_stage = 'sql';
        $progress_stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($progress_stream);
        $progress_fd = new \ReflectionProperty(\ImportClient::class, 'progress_fd');
        $progress_fd->setValue($client, $progress_stream);

        $client->output_progress($this->byte_progress_record(1024), true);
        $first_progress = $this->read_progress_file();
        $client->output_progress($this->byte_progress_record(2048), true);
        $throttled_progress = $this->read_progress_file();

        $this->assertSame(1024, $first_progress['progress']['bytes']['done']);
        $this->assertSame($first_progress, $throttled_progress);

        $last_write = new \ReflectionProperty(
            \ImportClient::class,
            'last_progress_file_write'
        );
        $last_write->setValue($client, microtime(true) - 2.0);
        $client->output_progress($this->byte_progress_record(3072), true);
        $updated_progress = $this->read_progress_file();
        $this->assertSame(3072, $updated_progress['progress']['bytes']['done']);
        fclose($progress_stream);
    }

    private function make_client(): \ImportClient
    {
        return new \ImportClient(
            'http://fake.url',
            $this->state_directory,
            $this->filesystem_root
        );
    }

    private function byte_progress_record(int $bytes_done): array
    {
        return [
            'command' => 'db-pull',
            'phase' => 'sql',
            'message' => $bytes_done . ' bytes',
            'progress' => [
                'items' => null,
                'bytes' => [
                    'done' => $bytes_done,
                    'total' => null,
                ],
                'current_file' => null,
            ],
        ];
    }

    private function read_progress_file(): array
    {
        return json_decode(
            (string) file_get_contents($this->state_directory . '/progress.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
