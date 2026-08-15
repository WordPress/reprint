<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\DatabaseUrlRewriteReviewLog;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class DatabaseUrlRewriteReviewLogTest extends TestCase {

    private string $temp_dir;
    private string $report_path;
    private string $job_id = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/reprint-url-review-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->report_path = $this->temp_dir . '/review.jsonl';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->report_path)) {
            unlink($this->report_path);
        }
        rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testReopenSkipsTheSameFinalRowAndRepairsAPartialLine(): void
    {
        $row = $this->row_to_verify(42);
        $log = $this->open_log();
        $this->assertTrue($log->append_row_to_verify($row));
        $log->close();
        $complete_contents = file_get_contents($this->report_path);

        $log = $this->open_log();
        $this->assertFalse($log->append_row_to_verify($row));
        $log->close();
        $this->assertSame($complete_contents, file_get_contents($this->report_path));

        file_put_contents($this->report_path, '{"type":"row_to_', FILE_APPEND);
        $log = $this->open_log();
        $this->assertFalse($log->append_row_to_verify($row));
        $log->close();
        $this->assertSame($complete_contents, file_get_contents($this->report_path));
    }

    public function testWritesAReadableHeaderAndBytePreservingPrimaryKeys(): void
    {
        $log = $this->open_log();
        $row = $this->row_to_verify(7);
        $row['primary_key'] = [
            'integer_id' => 7,
            'binary_id' => "\xff\x00",
        ];
        $this->assertTrue($log->append_row_to_verify($row));
        $log->close();

        $lines = array_map(
            static fn (string $line): array => json_decode($line, true),
            file($this->report_path, FILE_IGNORE_NEW_LINES)
        );
        $this->assertSame([
            ['from' => 'https://old.example', 'to' => 'https://new.example'],
        ], $lines[0]['rewrite_url']);
        $this->assertSame($this->job_id, $lines[0]['job_id']);
        $this->assertSame([
            'integer_id' => ['type' => 'integer', 'value' => 7],
            'binary_id' => ['type' => 'bytes', 'base64' => '/wA='],
        ], $lines[1]['primary_key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $lines[1]['id']);
        $this->assertSame(0600, fileperms($this->report_path) & 0777);
    }

    private function open_log(): DatabaseUrlRewriteReviewLog
    {
        return new DatabaseUrlRewriteReviewLog(
            $this->report_path,
            $this->job_id,
            ['https://old.example' => 'https://new.example'],
            [
                'engine' => 'sqlite',
                'sqlite_path' => '/tmp/wordpress.sqlite',
                'db' => 'wordpress',
            ]
        );
    }

    /** @return array<string,mixed> */
    private function row_to_verify(int $id): array
    {
        return [
            'table' => 'wp_posts',
            'primary_key' => ['ID' => $id],
            'columns' => [
                'post_content' => [
                    'original_sha256' => hash('sha256', 'https://old.example'),
                    'intended_sha256' => hash('sha256', 'https://new.example'),
                ],
            ],
        ];
    }
}
