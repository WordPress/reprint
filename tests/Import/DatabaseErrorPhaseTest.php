<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The request stub belongs to this test.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class DatabaseErrorPhaseTest extends TestCase
{
    private string $temp_dir;
    private string $state_dir;
    private string $filesystem_root;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . '/database-error-phase-' . uniqid();
        $this->state_dir = $this->temp_dir . '/state';
        $this->filesystem_root = $this->temp_dir . '/filesystem';
        mkdir($this->state_dir, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->temp_dir,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->temp_dir);
    }

    public function testSqlErrorsUseSqlPhase(): void
    {
        $this->assertSame(
            'sql',
            $this->get_error_phase_from_download('download_sql')
        );
    }

    public function testDbIndexErrorsUseDbIndexPhase(): void
    {
        $this->assertSame(
            'db-index',
            $this->get_error_phase_from_download('download_db_index')
        );
    }

    private function get_error_phase_from_download(string $method_name): string
    {
        $client = new DatabaseErrorPhaseClient(
            'http://fake.invalid',
            $this->state_dir,
            $this->filesystem_root
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        $method = $reflection->getMethod($method_name);
        $method->invoke($client);

        foreach ($client->progress_events as $event) {
            $event_type = $event['type'] ?? null;
            if ($event_type === 'error') {
                return $event['phase'];
            }
        }

        $this->fail("The {$method_name} request did not report its remote error");
    }
}

class DatabaseErrorPhaseClient extends \ImportClient
{
    public array $progress_events = [];

    public function output_progress(array $data, bool $force = false): void
    {
        $this->progress_events[] = $data;
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $on_chunk = $context->on_chunk;
        $on_chunk([
            'headers' => [
                'x-chunk-type' => 'error',
            ],
            'body' => json_encode([
                'error_type' => 'php_error',
                'path' => '',
                'message' => 'Simulated remote error',
            ]),
        ]);
        $on_chunk([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
            ],
            'body' => '',
        ]);
    }
}
