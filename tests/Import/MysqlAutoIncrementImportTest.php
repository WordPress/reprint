<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\MysqliDatabaseConnection;
use Reprint\Importer\MyIsamAutoIncrementStatementRewriter;
use WordPress\Reprint\Server\MySQLDumpProducer;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class MysqlAutoIncrementImportTest extends TestCase
{
    private ?PDO $source = null;
    private ?MysqliDatabaseConnection $target = null;
    private bool $supports_enforced_engine;
    private string $source_name;
    private string $target_name;
    private string $temp_directory;
    private \ImportClient $client;
    private $output;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASS') ?: '';
        $source = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->supports_enforced_engine = (bool) $source->query("SHOW VARIABLES LIKE 'enforce_storage_engine'")->fetch();

        $this->source_name = 'reprint_auto_source_' . bin2hex(random_bytes(4));
        $this->target_name = 'reprint_auto_target_' . bin2hex(random_bytes(4));
        $this->source = $source;
        $source->exec("CREATE DATABASE `{$this->source_name}`");
        $source->exec("CREATE DATABASE `{$this->target_name}`");
        $source->exec("USE `{$this->source_name}`");
        if ($this->supports_enforced_engine) {
            $source->exec('SET SESSION enforce_storage_engine=NULL');
        }

        $mysqli = new \mysqli($host, $user, $password, $this->target_name, $port);
        $mysqli->set_charset('utf8mb4');
        $this->target = new MysqliDatabaseConnection($mysqli);
        if ($this->supports_enforced_engine) {
            $this->target->exec("SET SESSION enforce_storage_engine='InnoDB'");
        }
        $this->target->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");

        $this->temp_directory = sys_get_temp_dir() . '/reprint-auto-increment-' . bin2hex(random_bytes(4));
        mkdir($this->temp_directory);
        $this->client = new \ImportClient('https://source.example/?reprint-api', $this->temp_directory, $this->temp_directory . '/files');
        $this->output = fopen('php://temp', 'w+');
        (new \ReflectionProperty(\ImportClient::class, 'progress_fd'))->setValue($this->client, $this->output);
        (new \ReflectionProperty(\ImportClient::class, 'progress'))->setValue(
            $this->client,
            new \TerminalProgress(false, $this->output)
        );
        (new \ReflectionMethod(\ImportClient::class, 'create_database_import_position_table'))
            ->invoke($this->client, $this->target);
    }

    protected function tearDown(): void
    {
        if ($this->target !== null) {
            $this->target->close();
        }
        if ($this->source !== null) {
            $this->source->exec("DROP DATABASE `{$this->source_name}`");
            $this->source->exec("DROP DATABASE `{$this->target_name}`");
        }
        if (isset($this->temp_directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temp_directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temp_directory);
        }
        if (is_resource($this->output)) {
            fclose($this->output);
        }
    }

    /** @dataProvider importModeProvider */
    public function testImportsPublicBackupPluginSchemaAndRowsWhenTargetForcesInnoDb(string $mode): void
    {
        if (!$this->supports_enforced_engine) {
            $this->markTestSkipped('MariaDB is required to exercise enforced InnoDB conversion.');
        }
        $this->source->exec(file_get_contents(__DIR__ . '/fixtures/wponlinebackup-items.sql'));
        $this->source->exec(
            "INSERT INTO wp_wponlinebackup_items (bin,parent_id,type,name,name_bin,activity_id,counter,path) VALUES " .
            "(1,0,0,'a','a',0,0,'/a'),(1,0,0,'b','b',0,0,'/b'),(2,0,0,'c','c',0,0,'/c')"
        );
        $rows = $this->source->query('SELECT * FROM wp_wponlinebackup_items ORDER BY bin,item_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals([1, 2, 1], array_column($rows, 'item_id'));
        $producer = new MySQLDumpProducer($this->source);
        $sql = '';
        while ($producer->next_sql_fragment()) {
            $sql .= ($producer->get_sql_fragment() ?? '') . "\n";
        }
        $cursor = base64_encode($producer->get_reentrancy_cursor());
        if ($mode === 'group') {
            (new \ReflectionMethod(\ImportClient::class, 'execute_database_import_group'))->invoke(
                $this->client, $this->target, $sql, hash('sha256', 'backup-plugin'), $cursor, null, 'mysql'
            );
        } else {
            if ($this->source->query('SELECT @@global.enforce_storage_engine')->fetchColumn() !== 'InnoDB') {
                $this->markTestSkipped('db-apply needs a dedicated MariaDB server with global enforce_storage_engine=InnoDB.');
            }
            file_put_contents(
                $this->temp_directory . '/db.sql',
                $sql . '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 ' . $cursor . "\n"
            );
            \write_current_pull_state($this->client, []);
            $this->client->run([
                'command' => 'db-apply',
                'target_engine' => 'mysql',
                'target_host' => getenv('DB_HOST') ?: '127.0.0.1',
                'target_port' => (int) (getenv('DB_PORT') ?: 3306),
                'target_user' => getenv('DB_USER') ?: 'root',
                'target_pass' => getenv('DB_PASS') ?: '',
                'target_db' => $this->target_name,
                'progress' => $mode,
                'pipeline_step' => 1,
                'pipeline_steps' => 1,
            ]);
            // The file itself remains a faithful MyISAM export.
            $this->assertStringNotContainsString(', KEY (`item_id`)', file_get_contents($this->temp_directory . '/db.sql'));
        }

        $this->assertEquals($rows, $this->target->query('SELECT * FROM wp_wponlinebackup_items ORDER BY bin,item_id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame('InnoDB', $this->target->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_wponlinebackup_items'")->fetchColumn());
        $indexes = $this->target->query('SHOW INDEX FROM wp_wponlinebackup_items')->fetchAll(PDO::FETCH_ASSOC);
        $primary_key = array_values(array_filter($indexes, static function ($index) {
            return $index['Key_name'] === 'PRIMARY';
        }));
        $this->assertSame(['bin', 'item_id'], array_column($primary_key, 'Column_name'));
        $added_index = array_values(array_filter($indexes, static function ($index) {
            return $index['Column_name'] === 'item_id' && (int) $index['Seq_in_index'] === 1;
        }));
        $this->assertCount(1, $added_index);
        $this->assertSame(1, (int) $added_index[0]['Non_unique']);

        $output = $this->readOutput();
        $this->assertSame(1, substr_count($output, 'Warning: The target forces InnoDB.'));
        $this->assertStringContainsString('wp_wponlinebackup_items.item_id', $output);
        $this->assertStringContainsString('table-wide sequence instead of per-group sequences', $output);
        if ($mode === 'group' || $mode === 'jsonl') {
            $records = array_map(static function ($line) {
                return json_decode($line, true);
            }, array_filter(explode("\n", $output)));
            $warnings = array_values(array_filter($records, static function ($record) {
                return ($record['type'] ?? null) === 'warning';
            }));
            $this->assertCount(1, $warnings);
            $this->assertSame('auto_increment_index_added', $warnings[0]['reason']);
            $this->assertSame('wp_wponlinebackup_items', $warnings[0]['table']);
            $this->assertSame('item_id', $warnings[0]['column']);
        }
        $this->assertStringContainsString(
            'Adding a non-unique index on wp_wponlinebackup_items.item_id',
            file_get_contents($this->temp_directory . '/audit.log')
        );

        $this->assertSame('MyISAM', $this->source->query(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_wponlinebackup_items'"
        )->fetchColumn());
        $this->target->exec(
            "INSERT INTO wp_wponlinebackup_items (bin,parent_id,type,name,name_bin,activity_id,counter,path) " .
            "VALUES (2,0,0,'d','d',0,0,'/d')"
        );
        $this->assertSame(3, (int) $this->target->query("SELECT item_id FROM wp_wponlinebackup_items WHERE name='d'")->fetchColumn());
    }

    public static function importModeProvider(): array
    {
        return [['group'], ['jsonl'], ['tty'], ['compact']];
    }

    public function testLeavesGroupedAutoIncrementUnchangedWhenMyIsamIsAllowed(): void
    {
        if ($this->supports_enforced_engine) {
            $this->target->exec('SET SESSION enforce_storage_engine=NULL');
        }
        $sql = file_get_contents(__DIR__ . '/fixtures/wponlinebackup-items.sql');
        $this->source->exec($sql);
        $this->importSql($sql);
        $this->assertSame(
            $this->source->query('SHOW CREATE TABLE wp_wponlinebackup_items')->fetch(PDO::FETCH_NUM)[1],
            $this->target->query('SHOW CREATE TABLE wp_wponlinebackup_items')->fetch(PDO::FETCH_NUM)[1]
        );
        $this->assertSame('MyISAM', $this->target->query(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_wponlinebackup_items'"
        )->fetchColumn());
        $this->assertSame('', $this->readOutput());
    }

    /** @dataProvider unchangedSchemaProvider */
    public function testLeavesOtherSchemasUnchanged(string $sql): void
    {
        $this->target->exec(str_replace('CREATE TABLE t ', 'CREATE TABLE expected ', $sql));
        $this->importSql($sql);
        $expected_schema = str_replace(
            'CREATE TABLE `expected`',
            'CREATE TABLE `t`',
            $this->target->query('SHOW CREATE TABLE expected')->fetch(PDO::FETCH_NUM)[1]
        );
        $this->assertSame($expected_schema, $this->target->query('SHOW CREATE TABLE t')->fetch(PDO::FETCH_NUM)[1]);
        $this->assertSame('', $this->readOutput());
    }

    public static function unchangedSchemaProvider(): array
    {
        return [
            'leading primary key' => ['CREATE TABLE t (id INT AUTO_INCREMENT, bin INT, PRIMARY KEY (id,bin)) ENGINE=MyISAM;'],
            'leading secondary key' => ['CREATE TABLE t (bin INT, id INT AUTO_INCREMENT, PRIMARY KEY (bin,id), KEY (ID)) ENGINE=MyISAM;'],
            'inline primary key' => ['CREATE TABLE t (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=MyISAM;'],
            'inline unique key' => ['CREATE TABLE t (id INT AUTO_INCREMENT UNIQUE) ENGINE=MyISAM;'],
            'no explicit engine' => ['CREATE TABLE t (bin INT, id INT AUTO_INCREMENT, PRIMARY KEY (id,bin));'],
            'table option only' => ['CREATE TABLE t (id INT PRIMARY KEY) ENGINE=MyISAM AUTO_INCREMENT=8;'],
            'column default text' => ["CREATE TABLE t (v VARCHAR(255) DEFAULT 'AUTO_INCREMENT') ENGINE=MyISAM;"],
        ];
    }

    public function testLeavesSchemaTextInRowDataUnchanged(): void
    {
        $this->target->exec('CREATE TABLE t (value TEXT)');
        $value = 'CREATE TABLE x (id INT AUTO_INCREMENT) ENGINE=MyISAM;';
        $this->importSql("INSERT INTO t VALUES ('{$value}');");
        $this->assertSame($value, $this->target->query('SELECT value FROM t')->fetchColumn());
        $this->assertSame('', $this->readOutput());
    }

    public function testDoesNotRepairMultipleAutoIncrementColumns(): void
    {
        try {
            $this->importSql('CREATE TABLE t (a INT AUTO_INCREMENT, b INT AUTO_INCREMENT, PRIMARY KEY (a,b)) ENGINE=MyISAM;');
            $this->fail('The database must reject multiple AUTO_INCREMENT columns.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('there can be only one auto column', $error->getMessage());
            $this->assertSame('', $this->readOutput());
        }
    }

    public function testEscapedNamesAndIndexNameCollisionAreHandledWithoutRepeatedRewrite(): void
    {
        if (!$this->supports_enforced_engine) {
            $this->markTestSkipped('MariaDB is required to exercise enforced InnoDB conversion.');
        }
        $this->source->exec(
            'CREATE TABLE `odd``table` (bin INT NOT NULL, `item``id` INT NOT NULL AUTO_INCREMENT, ' .
            'PRIMARY KEY (bin,`item``id`), KEY `item``id` (bin)) ENGINE=MyISAM'
        );
        $row = $this->source->query('SHOW CREATE TABLE `odd``table`')->fetch(PDO::FETCH_NUM);
        $this->importSql($row[1] . ';');
        $indexes = $this->target->query('SHOW INDEX FROM `odd``table`')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertContains('item`id', array_column($indexes, 'Key_name'));
        $this->assertContains('item`id_2', array_column($indexes, 'Key_name'));
        $row = $this->target->query('SHOW CREATE TABLE `odd``table`')->fetch(PDO::FETCH_NUM);
        $this->target->exec('DROP TABLE `odd``table`');
        $this->importSql($row[1] . ';');
        $this->assertSame($row[1], $this->target->query('SHOW CREATE TABLE `odd``table`')->fetch(PDO::FETCH_NUM)[1]);
        $this->assertSame(1, substr_count($this->readOutput(), 'Warning: The target forces InnoDB.'));
    }

    public function testStatementRewriterReturnsSqlAndWarningWithoutExecutingOrPrinting(): void
    {
        $rewriter = new MyIsamAutoIncrementStatementRewriter($this->target);
        $sql = file_get_contents(__DIR__ . '/fixtures/wponlinebackup-items.sql');
        $rewritten = $rewriter->rewrite($sql);
        if (!$this->supports_enforced_engine) {
            $this->assertNull($rewritten);
            return;
        }

        $this->assertSame(
            substr_replace($sql, ', KEY (`item_id`)', strrpos($sql, ')'), 0),
            $rewritten['sql']
        );
        $this->assertSame('wp_wponlinebackup_items', $rewritten['table']);
        $this->assertSame('item_id', $rewritten['column']);
        $this->assertStringContainsString('table-wide sequence instead of per-group sequences', $rewritten['message']);
        $this->assertFalse($this->target->query("SHOW TABLES LIKE 'wp_wponlinebackup_items'")->fetchColumn());
        $this->assertSame('', $this->readOutput());
        $this->assertNull($rewriter->rewrite($rewritten['sql']));
        $this->assertNull($rewriter->rewrite('SELECT 1;'));
        $this->target->exec('SET SESSION enforce_storage_engine=NULL');
        $this->assertNull($rewriter->rewrite($sql));
    }

    private function importSql(string $sql): void
    {
        (new \ReflectionMethod(\ImportClient::class, 'execute_database_import_group'))->invoke(
            $this->client, $this->target, $sql, hash('sha256', 'schema-test'), base64_encode('{}'), null, 'mysql'
        );
    }

    private function readOutput(): string
    {
        rewind($this->output);
        return stream_get_contents($this->output);
    }
}
