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
        $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
        $sizes = [1024, 0, 0, 20 * 1024 * 1024, 10 * 1024 * 1024 - 1024, 0, 0, 0, 0, 0];
        $offset = 0;
        foreach ($sizes as $index => $size) {
            $line = json_encode(['path' => base64_encode('/file-' . $index), 'size' => $size]) . "\n";
            file_put_contents($list_file, $line, FILE_APPEND);
            if ($index < 3) {
                $offset += strlen($line);
            }
        }
        $client->get_state()->fetch->offset = $offset;
        $reflection->getProperty('progress_reporter')->getValue($client)
            ->load_file_list($list_file, $client->get_state()->fetch);
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
            'bytes' => [
                'done' => 1536,
                'total' => 30 * 1024 * 1024,
            ],
            'current_file' => [
                'path_b64' => base64_encode($remote_path),
                'bytes_done' => 512,
                'bytes_total' => 20 * 1024 * 1024,
            ],
            'current_table' => null,
        ];
        $this->assertSame(1, $jsonl_record['schema_version']);
        $this->assertSame('Downloading files', $jsonl_record['message']);
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
        $this->assertSame('Downloading files', $progress_file['message']);
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

        usleep(1100000);
        $client->output_progress($this->byte_progress_record(3072), true);
        $updated_progress = $this->read_progress_file();
        $this->assertSame(3072, $updated_progress['progress']['bytes']['done']);
        fclose($progress_stream);
    }

    public function testDatabaseProgressCombinesExporterCursorWithTableEstimate(): void
    {
        $client = $this->make_client();
        file_put_contents(
            $this->state_directory . '/db-tables.jsonl',
            json_encode(['name' => 'wp_posts', 'rows' => 12000]) . "\n"
        );
        $cursor = base64_encode(json_encode([
            'progress' => [
                'tables' => ['done' => 2, 'total' => 12],
                'current_table' => [
                    'name' => 'wp_posts',
                    'rows_done' => 500,
                ],
            ],
        ]));

        $progress_table = null;
        $progress = ( new \ReflectionClass($client) )
            ->getMethod('database_pull_progress_details')
            ->invokeArgs($client, [$cursor, 500100, &$progress_table]);

        $this->assertSame([
            'items' => [
                'unit' => 'tables',
                'done' => 2,
                'total' => 12,
            ],
            'bytes' => [
                'done' => 500100,
                'total' => null,
            ],
            'current_file' => null,
            'current_table' => [
                'name' => 'wp_posts',
                'rows_done' => 500,
                'rows_total' => 12000,
                'rows_total_is_estimate' => true,
            ],
        ], $progress);
    }

    public function testLogMessagesDoNotReplaceTheScreenLabel(): void
    {
        $client = $this->make_client();
        $command = $client->get_state()->active_resumable_command;
        $command->command_name = 'db-pull';
        $command->completion_state = 'in_progress';
        $command->current_stage = 'sql';
        $client->output_progress($this->byte_progress_record(1024), true);
        $client->output_progress(['type' => 'skip', 'message' => 'Skipped one path'], true);
        $client->write_progress_file();

        $this->assertSame('1024 bytes', $this->read_progress_file()['message']);
        $this->assertSame(1024, $this->read_progress_file()['progress']['bytes']['done']);
    }

    public function testPhaseChangeClearsCountersAndTerminalWriteKeepsLatestUpdate(): void
    {
        $client = $this->make_client();
        $command = $client->get_state()->active_resumable_command;
        $command->command_name = 'db-pull';
        $command->completion_state = 'in_progress';
        $command->current_stage = 'sql';
        $client->output_progress($this->byte_progress_record(1024), true);
        $client->output_progress($this->byte_progress_record(2048), true);
        $command->completion_state = 'partial';
        $client->save_state();
        $this->assertSame(2048, $this->read_progress_file()['progress']['bytes']['done']);
        $this->assertSame('partial', $this->read_progress_file()['status']);

        $command->current_stage = 'db-index';
        $client->write_progress_file();
        $this->assertNull($this->read_progress_file()['message']);
        $this->assertNull($this->read_progress_file()['progress']['bytes']);
    }

    public function testFileErrorClearsCurrentFileProgress(): void
    {
        $client = $this->make_client();
        $context = new StreamingContext();
        $context->remote_file_path = '/uploads/a.bin';
        $context->remote_file_size = 32769;
        $reflection = new \ReflectionClass($client);
        $reflection->getMethod('handle_error_chunk')->invoke($client, [
            'body' => json_encode([
                'error_type' => 'file_missing',
                'path' => base64_encode($context->remote_file_path),
                'message' => 'File disappeared during stream',
            ]),
        ], 'files', $context);

        $this->assertNull($context->remote_file_path);
        $this->assertNull($context->remote_file_size);
        $this->assertNull(
            $reflection->getProperty('progress_reporter')->getValue($client)
                ->get_file_details($context)['current_file']
        );
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
                'current_table' => null,
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
