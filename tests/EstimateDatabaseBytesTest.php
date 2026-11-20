<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstimateDatabaseBytesTest extends TestCase {
    #[DataProvider('sqliteDatabasePaths')]
    public function testSqliteDatabaseSize(?string $db_path, ?string $fqdb, ?int $expected_bytes): void
    {
        $directory = sys_get_temp_dir() . '/reprint-database-size-' . uniqid();
        $this->assertTrue(mkdir($directory));

        try {
            $this->assertSame(123, file_put_contents($directory . '/active.sqlite', str_repeat('a', 123)));
            $this->assertSame(456, file_put_contents($directory . '/legacy.sqlite', str_repeat('b', 456)));
            $this->assertSame(0, file_put_contents($directory . '/empty.sqlite', ''));

            $script = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            require $argv[1] . '/packages/reprint-server/src/export.php';
            foreach (json_decode($argv[2], true) as $constant => $path) {
                if ($path !== null) {
                    define($constant, $path === ':memory:' ? $path : getcwd() . '/' . $path);
                }
            }
            echo json_encode(estimate_database_bytes(null, 'sqlite'));
            PHP;

            $process = proc_open(
                [PHP_BINARY, '-r', $script, dirname(__DIR__), json_encode(['DB_PATH' => $db_path, 'FQDB' => $fqdb])],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $directory
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $stderr . $stdout);
            $this->assertSame($expected_bytes, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($directory . '/active.sqlite');
            unlink($directory . '/legacy.sqlite');
            unlink($directory . '/empty.sqlite');
            rmdir($directory);
        }
    }

    public static function sqliteDatabasePaths(): array
    {
        return [
            'DB_PATH overrides conflicting FQDB' => ['active.sqlite', 'legacy.sqlite', 123],
            'DB_PATH without FQDB' => ['active.sqlite', null, 123],
            'legacy FQDB fallback' => [null, 'legacy.sqlite', 456],
            'empty database has zero bytes' => ['empty.sqlite', 'legacy.sqlite', 0],
            'missing DB_PATH file does not fall back' => ['missing.sqlite', 'legacy.sqlite', null],
            'in-memory DB_PATH does not fall back' => [':memory:', 'legacy.sqlite', null],
            'missing legacy FQDB file' => [null, 'missing.sqlite', null],
            'in-memory legacy FQDB' => [null, ':memory:', null],
            'no database path constants' => [null, null, null],
        ];
    }
}
