<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\DatabaseTarget;
use Reprint\Importer\DatabaseTargetResolutionPolicy;
use Reprint\Importer\DatabaseTargetResolver;
use Reprint\Importer\State\DatabaseApplyCommandState;

require_once __DIR__ . '/../../importer/import.php';

class DatabaseTargetResolverTest extends TestCase {

    public function testPullNormalizesAndValidatesAMysqlTarget(): void
    {
        $target = DatabaseTargetResolver::resolve(
            [
                'target_engine' => 'MYSQL',
                'target_db' => 'wordpress',
                'target_user' => 'root',
            ],
            null,
            DatabaseTargetResolutionPolicy::for_pull(),
        );

        $this->assertSame('mysql', $target->engine);
        $this->assertSame('wordpress', $target->database_name);
        $this->assertSame('root', $target->user);
        $this->assertSame('127.0.0.1', $target->host);
        $this->assertSame(3306, $target->port);
    }

    public function testRuntimeMergesOptionsWithAMatchingRecordedTarget(): void
    {
        $recorded = new DatabaseTarget(
            'sqlite',
            'from-state',
            sys_get_temp_dir() . '/database-target-resolver.sqlite',
        );

        $target = DatabaseTargetResolver::resolve(
            [
                'target_engine' => 'sqlite',
                'target_db' => 'from-options',
            ],
            $recorded,
            DatabaseTargetResolutionPolicy::for_runtime(),
        );

        $this->assertSame('sqlite', $target->engine);
        $this->assertSame('from-options', $target->database_name);
        $this->assertSame(sys_get_temp_dir() . '/database-target-resolver.sqlite', $target->sqlite_path);
    }

    public function testRuntimeDiscardsAnIncompatibleRecordedTarget(): void
    {
        $recorded = new DatabaseTarget(
            'mysql',
            'from-state',
            null,
            'database.internal',
            3307,
            'state-user',
            'state-password',
        );

        $target = DatabaseTargetResolver::resolve(
            ['target_engine' => 'sqlite'],
            $recorded,
            DatabaseTargetResolutionPolicy::for_runtime(),
        );

        $this->assertSame('sqlite', $target->engine);
        $this->assertSame('sqlite_database', $target->database_name);
        $this->assertNull($target->sqlite_path);
    }

    public function testRuntimeRejectsTargetOptionsWithoutAnEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--target-sqlite-path');

        DatabaseTargetResolver::resolve(
            ['target_sqlite_path' => '/tmp/database/.ht.sqlite'],
            null,
            DatabaseTargetResolutionPolicy::for_runtime(),
        );
    }

    public function testTargetMapsToAndFromDatabaseApplyState(): void
    {
        $original = new DatabaseTarget(
            'mysql',
            'wordpress',
            null,
            'database.internal',
            3307,
            'wordpress',
            'password',
        );
        $state = new DatabaseApplyCommandState();

        $original->store_in_apply_state($state);
        $restored = DatabaseTarget::from_apply_state($state);

        $this->assertNotNull($restored);
        $this->assertSame($original->engine, $restored->engine);
        $this->assertSame($original->database_name, $restored->database_name);
        $this->assertSame($original->host, $restored->host);
        $this->assertSame($original->port, $restored->port);
        $this->assertSame($original->user, $restored->user);
        $this->assertSame($original->password, $restored->password);
    }

    public function testAuditLogMasksTargetDatabasePasswords(): void
    {
        $root = sys_get_temp_dir() . '/database-target-audit-' . uniqid('', true);
        $state_dir = $root . '/state';
        $filesystem_root = $root . '/files';
        $password = 'database-password-' . bin2hex(random_bytes(4));

        try {
            $client = new \ImportClient('https://source.example/export.php', $state_dir, $filesystem_root);
            $client->audit_log_argv('apply-runtime', [
                'reprint',
                'apply-runtime',
                'https://source.example/export.php',
                '--target-pass=' . $password,
            ]);

            $audit_log = (string) file_get_contents($state_dir . '/audit.log');
            $this->assertStringNotContainsString($password, $audit_log);
            $this->assertStringContainsString('--target-pass=***', $audit_log);
        } finally {
            $this->remove_directory($root);
        }
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
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
