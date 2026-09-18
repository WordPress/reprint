<?php

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../MySQLDumpProducer/MySQLDumpProducerTestBase.php';

use Reprint\Importer\PostProcess;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\MultisiteTarget;
use WordPress\Reprint\Server\Utils;

/** Exercise activation edits against a real target database. */
class ReprintDatabaseCleanupTest extends MySQLDumpProducerTestBase {
    private string $directory;

    /** Keep an unrelated option beside the imported Reprint connection token. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/reprint-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->pdo->exec('CREATE TABLE custom_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))');
        $this->pdo->exec("INSERT INTO custom_options (option_name, option_value, autoload) VALUES ('reprint_server_connection_token', 'source-secret', 'yes'), ('another_plugin_token', 'keep', 'no')");
    }

    /** Failed validation can leave a transaction open for the caller to roll back. */
    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Utils::rmdir_recursive($this->directory);
        parent::tearDown();
    }

    /** WordPress's serialized byte counts need not describe the column charset. */
    public static function charsets(): array
    {
        return [
            ['utf8mb4', 'latin1', 'café/index.php'],
            ['latin1', 'utf8mb4', "caf\xe9/index.php"],
            ['utf8mb4', 'utf8mb4', '🌍/index.php'],
            ['latin1', 'latin1', "caf\xe9/index.php"],
        ];
    }

    /** @dataProvider charsets */
    public function testPreservesOtherPluginBytesAndRowFields(string $site_charset, string $column_charset, string $other_plugin): void
    {
        $this->pdo->exec("ALTER TABLE custom_options MODIFY option_value longtext CHARACTER SET {$column_charset}");
        $this->pdo->exec("SET NAMES {$site_charset}");
        $this->pdo->prepare("INSERT INTO custom_options VALUES (17, 'active_plugins', ?, 'no')")
            ->execute([serialize(['renamed/index.php', $other_plugin, 'renamed-extra/index.php'])]);
        $expected = serialize([$other_plugin, 'renamed-extra/index.php']);
        $statement = $this->pdo->prepare("SELECT CAST(CONVERT(? USING {$column_charset}) AS BINARY)");
        $statement->execute([$expected]);
        $expected_bytes = $statement->fetchColumn();
        // The importer uses UTF-8 regardless of the source WordPress charset.
        $this->pdo->exec('SET NAMES utf8mb4');
        $client = $this->client($site_charset);
        $this->cleanup($client);
        $this->cleanup($client);
        $this->assertSame($expected_bytes, $this->pdo->query("SELECT CAST(option_value AS BINARY) FROM custom_options WHERE option_name = 'active_plugins'")->fetchColumn());
        $this->assertSame(['option_id' => 17, 'autoload' => 'no'], $this->pdo->query("SELECT option_id, autoload FROM custom_options WHERE option_name = 'active_plugins'")->fetch(PDO::FETCH_ASSOC));
        $this->assertSame(['active_plugins', 'another_plugin_token'], $this->pdo->query('SELECT option_name FROM custom_options ORDER BY option_name')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Re-encoding must catch changed bytes even when the PHP lengths still fit. */
    public function testRejectsLossyCharsetConversion(): void
    {
        $this->pdo->exec('ALTER TABLE custom_options MODIFY option_value longtext CHARACTER SET latin1');
        $this->pdo->exec('SET NAMES latin1');
        $this->pdo->prepare("INSERT INTO custom_options (option_name, option_value) VALUES ('active_plugins', ?)")
            ->execute([serialize(['renamed/index.php', "caf\xe9/index.php"])]);
        $before = $this->pdo->query('SELECT CAST(option_value AS BINARY) FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_COLUMN);
        try {
            $this->cleanup($this->client('ascii'));
            $this->fail('Lossy charset conversion must stop cleanup.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot round-trip', $error->getMessage());
        }
        $this->pdo->rollBack();
        $this->assertSame($before, $this->pdo->query('SELECT CAST(option_value AS BINARY) FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Noncanonical and malformed serialized values must not be silently replaced. */
    public function testRejectsTamperedSerialization(): void
    {
        foreach (['a:2:{broken', str_replace('i:0;', 'i:00;', serialize(['renamed/index.php']))] as $serialized) {
            $this->pdo->prepare("REPLACE INTO custom_options (option_name, option_value) VALUES ('active_plugins', ?)")->execute([$serialized]);
            try {
                $this->cleanup($this->client('utf8mb4'));
                $this->fail('Tampered serialization must stop cleanup.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('does not round-trip as a serialized plugin array', $error->getMessage());
            }
            $this->pdo->rollBack();
            $this->assertSame($serialized, $this->pdo->query("SELECT option_value FROM custom_options WHERE option_name = 'active_plugins'")->fetchColumn());
            $this->assertSame('source-secret', $this->pdo->query("SELECT option_value FROM custom_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
        }
    }

    /** Replaying network adoption must not reactivate the renamed Reprint plugin. */
    public function testSelectedSiteAndNetworkStayCleanAfterAdoptionRepeats(): void
    {
        $this->pdo->exec('CREATE TABLE custom_2_options LIKE custom_options');
        $this->pdo->exec("INSERT INTO custom_2_options SELECT * FROM custom_options");
        $this->pdo->exec('CREATE TABLE custom_users (ID bigint PRIMARY KEY, user_login varchar(191))');
        $this->pdo->exec("INSERT INTO custom_users VALUES (1, 'admin')");
        $this->pdo->exec('CREATE TABLE custom_usermeta (umeta_id bigint AUTO_INCREMENT PRIMARY KEY, user_id bigint, meta_key varchar(191), meta_value longtext)');
        $this->pdo->exec('CREATE TABLE custom_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(191), meta_value longtext)');
        $this->pdo->prepare("INSERT INTO custom_2_options (option_name, option_value) VALUES ('active_plugins', ?)")
            ->execute([serialize(['renamed/index.php', 'site-only/index.php'])]);
        $insert = $this->pdo->prepare("INSERT INTO custom_sitemeta (site_id, meta_key, meta_value) VALUES (?, 'active_sitewide_plugins', ?)");
        $insert->execute([7, serialize(['renamed/index.php' => 12, 'network-only/index.php' => 34])]);
        $other_network = serialize(['renamed/index.php' => 56]);
        $insert->execute([8, $other_network]);
        $insert_token = $this->pdo->prepare('INSERT INTO custom_sitemeta (site_id, meta_key, meta_value) VALUES (?, ?, ?)');
        foreach ([7, 8] as $network_id) {
            foreach (['reprint_server_connection_token', 'site_export_secret'] as $option_name) {
                $insert_token->execute([$network_id, $option_name, 'network-token-' . $network_id]);
            }
        }
        $selection = [
            'site_id' => 2, 'network_id' => 7, 'base_prefix' => 'custom_',
            'home_url' => 'https://source.test/shop', 'site_url' => 'https://source.test/shop',
            'content_url' => 'https://source.test/wp-content', 'network_content_url' => 'https://source.test/wp-content',
            'uploads_url' => 'https://source.test/wp-content/uploads/sites/2',
        ];
        $client = $this->client('utf8mb4');
        $preflight = $client->get_state()->preflight_record();
        $preflight['data']['database']['wp']['multisite']['selection'] = $selection;
        $client->get_state()->set_preflight_record($preflight);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->cleanup($client);
            ( new MultisiteTarget($selection, 'https://target.test') )->configure_database(new PdoDatabaseConnection($this->pdo), 'admin');
            $this->assertSame(['network-only/index.php', 'site-only/index.php'], unserialize($this->pdo->query("SELECT option_value FROM custom_2_options WHERE option_name = 'active_plugins'")->fetchColumn()));
            $this->assertSame(['network-only/index.php' => 34], unserialize($this->pdo->query('SELECT meta_value FROM custom_sitemeta WHERE site_id = 7')->fetchColumn()));
        }
        $this->assertSame($other_network, $this->pdo->query('SELECT meta_value FROM custom_sitemeta WHERE site_id = 8')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM custom_sitemeta WHERE site_id = 7 AND meta_key <> 'active_sitewide_plugins'")->fetchColumn());
        $this->assertSame(['network-token-8', 'network-token-8'], $this->pdo->query("SELECT meta_value FROM custom_sitemeta WHERE site_id = 8 AND meta_key <> 'active_sitewide_plugins'")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('source-secret', $this->pdo->query("SELECT option_value FROM custom_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM custom_2_options WHERE option_name = 'reprint_server_connection_token'")->fetchColumn());
    }

    /** Supply the same installation facts that preflight saves for an import. */
    private function client(string $charset): ImportClient
    {
        $client = new ImportClient('https://source.test', $this->directory . '/state', $this->directory . '/files');
        $client->get_state()->set_preflight_record(['http_code' => 200, 'data' => [
            'path_format' => 'unix',
            'reprint_plugin' => ['basename_b64' => base64_encode('renamed/index.php'), 'paths_b64' => [base64_encode('/source/renamed')]],
            'database' => ['wp' => ['table_prefix' => 'custom_', 'wpdb_charset' => $charset]],
        ]]);
        return $client;
    }

    /** The local cleanup uses the real connection, without replacing its queries. */
    private function cleanup(ImportClient $client): void
    {
        PostProcess::remove_reprint_plugin_data_from_the_imported_database(new PdoDatabaseConnection($this->pdo), 'mysql', 'renamed/index.php', $client->get_state()->preflight_record()['data']['database']['wp']);
    }
}
