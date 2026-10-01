<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/class-local-database-baseline.php';
require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

use WordPress\Reprint\Server\MysqliDriverPDO;

class LocalDatabaseBaselineTest extends MySQLDumpProducerTestBase {
    /** @dataProvider sourceDrivers */
    public function testChangingMysqlFetchTypesDoesNotInventFloatChanges(string $driver): void {
        if ($driver === 'mysqli' && !extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli is not installed.');
        }
        $directory = sys_get_temp_dir() . '/reprint-baseline-' . bin2hex(random_bytes(8));
        $this->pdo->exec('CREATE TABLE numbers (id INT PRIMARY KEY, f FLOAT, d DOUBLE, exact_value DECIMAL(30,20))');
        $this->pdo->exec('INSERT INTO numbers VALUES (1,1.234567,1.2345678901234567,1.23456789012345678901)');
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        try {
            (new LocalDatabaseBaseline($this->pdo, $directory, 'same-source'))->capture(['numbers']);
            if ($driver === 'mysqli') {
                $database = new MysqliDriverPDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'));
            } else {
                $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
                $database = $this->pdo;
            }
            $baseline = new LocalDatabaseBaseline($database, $directory, 'same-source');
            self::assertSame([], iterator_to_array($baseline->changes()), 'Changing fetch types must not create local edits.');
            $this->pdo->exec('UPDATE numbers SET d=1.234567890123456 WHERE id=1');
            $changes = iterator_to_array($baseline->changes());
            self::assertCount(1, $changes);
            self::assertSame(['d'], array_keys($changes[0]['after']));
            self::assertNotSame($changes[0]['before']['d'], $changes[0]['after']['d']);
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testChangesFromDifferentTablesKeepDistinctGeneratorKeys(): void {
        $directory = sys_get_temp_dir() . '/reprint-baseline-' . bin2hex(random_bytes(8));
        try {
            foreach (['posts', 'options'] as $table) {
                $this->pdo->exec("CREATE TABLE `$table` (id INT PRIMARY KEY, value TEXT)");
                $this->pdo->exec("INSERT INTO `$table` VALUES (1,'old')");
            }
            $baseline = new LocalDatabaseBaseline($this->pdo, $directory, 'same-source');
            $baseline->capture(['posts', 'options']);
            foreach (['posts', 'options'] as $table) {
                $this->pdo->exec("UPDATE `$table` SET value='new'");
            }
            $changes = iterator_to_array($baseline->changes());
            self::assertCount(2, $changes);
            self::assertSame(['options', 'posts'], array_column($changes, 'table'));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testCliUsesTheRecordedLocalApplyTargetWithoutSourceOptions(): void {
        $directory = sys_get_temp_dir() . '/reprint-baseline-' . bin2hex(random_bytes(8));
        $remote_reprint_api_url = 'https://127.0.0.1:1/?reprint-api';
        try {
            $this->pdo->exec('CREATE TABLE posts (id INT PRIMARY KEY, title TEXT)');
            $this->pdo->exec("INSERT INTO posts VALUES (1,'Original title')");
            $client = new ImportClient($remote_reprint_api_url, $directory, $directory . '/files');
            write_current_pull_state($client, ['apply' => [
                'target_engine' => 'mysql',
                'target_host' => getenv('DB_HOST'),
                'target_port' => 3306,
                'target_db' => $this->dbName,
                'target_user' => getenv('DB_USER'),
                'target_pass' => getenv('DB_PASS'),
            ]]);
            foreach (['db-baseline' => ' --table=posts', 'db-diff' => ''] as $command => $options) {
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(__DIR__ . '/../../packages/reprint-client/bin/reprint-client')
                    . ' ' . $command . ' ' . escapeshellarg($remote_reprint_api_url)
                    . $options . ' --state-dir=' . escapeshellarg($directory) . ' --progress=jsonl 2>&1', $output, $exit_code);
                self::assertSame(0, $exit_code, implode("\n", $output));
                self::assertStringNotContainsString('database_change', implode("\n", $output));
                self::assertStringContainsString('"status":"complete"', implode("\n", $output));
            }
        } finally {
            if (is_dir($directory)) {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    public static function sourceDrivers(): array {
        return [['pdo-stringified'], ['mysqli']];
    }
}
