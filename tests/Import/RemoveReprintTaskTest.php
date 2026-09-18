<?php

namespace Reprint\Tests;

use ImportClient;
use MySQLDumpProducerTestBase;
use PDO;
use ReflectionMethod;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../MySQLDumpProducer/MySQLDumpProducerTestBase.php';

/** Run the CLI against saved migration state and real destination databases. */
class RemoveReprintTaskTest extends MySQLDumpProducerTestBase {
    private string $directory;
    private ImportClient $client;

    /** Prepare a renamed plugin and the credentials copied by a migration. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/remove-reprint-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/site/wp-content/plugins/renamed', 0777, true);
        file_put_contents($this->directory . '/site/wp-load.php', '<?php throw new Exception("Must not load WordPress");');
        file_put_contents($this->directory . '/site/wp-content/plugins/renamed/secret.php', '<?php return "source-token";');
        $this->pdo->exec('CREATE TABLE custom_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))');
        $insert = $this->pdo->prepare('INSERT INTO custom_options (option_name, option_value) VALUES (?, ?)');
        foreach ([
            'active_plugins' => serialize(['renamed/index.php', 'renamed-extra/index.php']),
            'reprint_server_connection_token' => 'source-token',
            'reprint_server_push_authorized_token_fingerprint' => 'source-fingerprint',
            'site_export_secret' => 'old-token',
            'site_export_push_authorized_token_fingerprint' => 'old-fingerprint',
            'another_plugin_token' => 'keep',
        ] as $name => $value) {
            $insert->execute([$name, $value]);
        }
        $this->client = new ImportClient('https://source.invalid', $this->directory . '/state', $this->directory . '/site');
        $state = $this->client->get_state();
        $state->set_preflight_record(['http_code' => 200, 'data' => [
            'reprint_plugin' => ['basename_b64' => base64_encode('renamed/index.php')],
            'database' => ['wp' => ['table_prefix' => 'custom_', 'wpdb_charset' => 'utf8mb4']],
        ]]);
        $state->apply->target_engine = 'mysql';
        $state->apply->target_host = getenv('DB_HOST') ?: '127.0.0.1';
        $state->apply->target_port = 3306;
        $state->apply->target_user = getenv('DB_USER') ?: 'root';
        $state->apply->target_pass = getenv('DB_PASS') ?: '';
        $state->apply->target_db = $this->dbName;
        $state->apply->remote_paths_removed_from_local_site = ['wp-content/mu-plugins/previous-host.php'];
        $this->client->save_state();
    }

    /** Restore permissions so a failed removal test still releases its files. */
    protected function tearDown(): void
    {
        chmod($this->directory . '/site/wp-content/plugins', 0777);
        \Reprint\Importer\rmdir_recursive($this->directory);
        parent::tearDown();
    }

    /** An offline source and an unusable wp-load.php must not block cleanup. */
    public function testRemovesOnlyReprintAndRepeatsWithoutLoadingWordPressOrContactingSource(): void
    {
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame(['remove-reprint'], array_column($result['results'], 'task'));
        $this->assertSame(['wp-content/plugins/renamed'], $result['results'][0]['removed_paths']);
        $this->assertDirectoryDoesNotExist($this->directory . '/site/wp-content/plugins/renamed');
        $options = $this->pdo->query('SELECT option_name, option_value FROM custom_options')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['active_plugins' => serialize(['renamed-extra/index.php']), 'another_plugin_token' => 'keep'], $options);
        $state_file = ImportClient::remote_state_directory_path('https://source.invalid', $this->directory . '/state') . '/pull/state.json';
        $state = json_decode(file_get_contents($state_file), true);
        $this->assertSame(['wp-content/mu-plugins/previous-host.php', 'wp-content/plugins/renamed'], $state['apply']['remote_paths_removed_from_local_site']);
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame([], $result['results'][0]['removed_paths']);
    }

    /** Old preflight reports cannot identify a renamed installation. */
    public function testRejectsMissingPluginMetadataBeforeRemovingFiles(): void
    {
        $record = $this->client->get_state()->preflight_record();
        unset($record['data']['reprint_plugin']);
        $this->client->get_state()->set_preflight_record($record);
        $this->client->save_state();
        $result = $this->run_task();
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('rerun preflight', $result['message']);
        $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
    }

    /** A later import stage could otherwise restore the removed credentials. */
    public function testRefusesAnUnfinishedDatabaseImport(): void
    {
        $this->client->get_state()->active_resumable_command->command_name = 'db-apply';
        $this->client->get_state()->active_resumable_command->completion_state = 'in_progress';
        $this->client->save_state();
        $result = $this->run_task();
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Finish', $result['message']);
        $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
        $this->assertSame('source-token', $this->pdo->query("SELECT option_value FROM custom_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
    }

    /** Source credentials must never be used to guess the destination database. */
    public function testRequiresSavedTargetSettingsBeforeDeletingAnything(): void
    {
        $this->client->get_state()->apply->target_engine = null;
        $this->client->save_state();
        $result = $this->run_task();
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('target database settings', $result['message']);
        $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
    }

    /** A standalone exporter did not install a plugin for this task to remove. */
    public function testLeavesFilesAloneWhenPreflightReportsNoSourcePlugin(): void
    {
        $record = $this->client->get_state()->preflight_record();
        $record['data']['reprint_plugin'] = null;
        $this->client->get_state()->set_preflight_record($record);
        $this->client->save_state();
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame([], $result['results'][0]['removed_paths']);
        $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
    }

    /** Invalid metadata must not turn a plugin basename into a removal path. */
    public function testRejectsTamperedPluginBasenames(): void
    {
        foreach (['../outside/index.php', '/outside/index.php', 'renamed/../index.php', 'renamed\\other/index.php', "renamed\0/index.php", 'index.php', 'renamed//index.php'] as $basename) {
            $record = $this->client->get_state()->preflight_record();
            $record['data']['reprint_plugin']['basename_b64'] = base64_encode($basename);
            $this->client->get_state()->set_preflight_record($record);
            $this->client->save_state();
            $result = $this->run_task();
            $this->assertSame('failed', $result['status'], $basename);
            $this->assertStringContainsString('relative Reprint plugin basename', $result['message']);
            $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
        }
    }

    /** Deny the actual DELETE query, then retry using the same saved migration. */
    public function testFailedDatabaseCleanupKeepsFilesAndCanRepeatAfterDeletePermissionReturns(): void
    {
        $user = 'cleanup_' . bin2hex(random_bytes(4));
        $this->pdo->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY 'test'");
        try {
            $this->pdo->exec("GRANT SELECT, UPDATE ON `{$this->dbName}`.* TO '{$user}'@'%'");
            $this->client->get_state()->apply->target_user = $user;
            $this->client->get_state()->apply->target_pass = 'test';
            $this->client->save_state();
            $result = $this->run_task();
            $this->assertSame('failed', $result['status']);
            $this->assertStringContainsString('DELETE command denied', $result['message']);
            $this->assertFileExists($this->directory . '/site/wp-content/plugins/renamed/secret.php');
            $this->assertSame('source-token', $this->pdo->query("SELECT option_value FROM custom_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
            $this->pdo->exec("GRANT DELETE ON `{$this->dbName}`.* TO '{$user}'@'%'");
            $result = $this->run_task();
            $this->assertSame('complete', $result['status'], json_encode($result));
            $this->assertDirectoryDoesNotExist($this->directory . '/site/wp-content/plugins/renamed');
        } finally {
            $this->pdo->exec("DROP USER '{$user}'@'%'");
        }
    }

    /** A completed database cleanup must not hide a failed filesystem removal. */
    public function testReportsRemovalFailureAndCanRepeatAfterPermissionsAreRestored(): void
    {
        chmod($this->directory . '/site/wp-content/plugins', 0555);
        if (is_writable($this->directory . '/site/wp-content/plugins')) {
            $this->markTestSkipped('Run as an unprivileged user to exercise directory removal failure.');
        }
        $result = $this->run_task();
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Could not remove', $result['message']);
        chmod($this->directory . '/site/wp-content/plugins', 0777);
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertDirectoryDoesNotExist($this->directory . '/site/wp-content/plugins/renamed');
    }

    /** Delete copied credentials inside this site, not another site's shared package. */
    public function testRemovesAnInSiteSymlinkTargetButPreservesAnOutsideSharedInstallation(): void
    {
        $plugin = $this->directory . '/site/wp-content/plugins/renamed';
        $target = $this->directory . '/site/reprint-package';
        rename($plugin, $target);
        symlink($target, $plugin);
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame(['reprint-package', 'wp-content/plugins/renamed'], $result['results'][0]['removed_paths']);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFalse(is_link($plugin));

        mkdir($this->directory . '/shared');
        file_put_contents($this->directory . '/shared/secret.php', 'shared-token');
        symlink($this->directory . '/shared', $plugin);
        $result = $this->run_task();
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame('shared-token', file_get_contents($this->directory . '/shared/secret.php'));
        $this->assertFalse(is_link($plugin));
    }

    /** Use the real MySQL-on-SQLite connection used by db-apply. */
    public function testRemovesConnectionStateFromSqlite(): void
    {
        $state = $this->client->get_state();
        $state->apply->target_engine = 'sqlite';
        $state->apply->target_sqlite_path = $this->directory . '/target.sqlite';
        $this->client->save_state();
        $target = ['engine' => 'sqlite', 'db' => $this->dbName, 'sqlite_path' => $state->apply->target_sqlite_path];
        [$database] = ( new ReflectionMethod($this->client, 'create_target_database_connection') )->invoke($this->client, $target, false);
        $database->exec('CREATE TABLE custom_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))');
        $database->execute('INSERT INTO custom_options (option_name, option_value) VALUES (?, ?), (?, ?)', [
            'active_plugins', serialize(['renamed/index.php', 'another/index.php']),
            'reprint_server_connection_token', 'source-token',
        ]);
        try {
            $result = $this->run_task();
            $this->assertSame('complete', $result['status'], json_encode($result));
            $this->assertSame(serialize(['another/index.php']), $database->query("SELECT option_value FROM custom_options WHERE option_name = 'active_plugins'")->fetchColumn());
            $this->assertSame(0, (int) $database->query("SELECT COUNT(*) FROM custom_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
        } finally {
            $database->close();
        }
    }

    /**
     * @return array {
     *     @type string $status  Complete or failed.
     *     @type array  $results Attempted task results.
     *     @type string $message Failure detail, present only on failure.
     * }
     */
    private function run_task(): array
    {
        $command = [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'post-process',
            '--tasks=remove-reprint', '--fs-root=' . $this->directory . '/site', '--state-dir=' . $this->directory . '/state'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode($stdout, true);
        $this->assertIsArray($result, $stdout . $stderr);
        $this->assertSame($result['status'] === 'complete' ? 0 : 1, $exit, $stderr);
        return $result;
    }
}
