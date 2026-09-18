<?php

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\MultisiteDatabaseSelection;
use WordPress\Reprint\Server\MySQLDumpProducer;
use WordPress\Reprint\Server\SqliteDriverPDO;

require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-sqlite-driver-pdo.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class MultisiteSqliteSelectionTest extends TestCase {
    private string $directory;
    private WP_PDO_MySQL_On_SQLite $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/multisite-sqlite-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->database = $this->open_database();
        foreach ([7, 8] as $site) {
            $this->database->exec("CREATE TABLE wp_{$site}_posts (ID bigint PRIMARY KEY, post_author bigint, post_title longtext)");
            $this->database->exec("CREATE TABLE wp_{$site}_comments (comment_ID bigint PRIMARY KEY, user_id bigint)");
            $this->database->exec("CREATE TABLE wp_{$site}_links (link_id bigint PRIMARY KEY, link_owner bigint)");
        }
        $this->database->exec('CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60))');
        $this->database->exec("INSERT INTO wp_users VALUES (1,'member'),(2,'author'),(3,'sibling')");
        $this->database->exec('CREATE TABLE wp_usermeta (umeta_id bigint PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext)');
        $this->database->exec("INSERT INTO wp_usermeta VALUES (1,1,'wp_7_capabilities','member'),(2,3,'wp_8_capabilities','sibling'),(3,1,'session_tokens','secret')");
        $this->database->exec("INSERT INTO wp_7_posts VALUES (1,2,'one'),(2,2,'two'),(3,2,'three')");
    }

    protected function tearDown(): void
    {
        unset($this->database);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_export_resumes_after_every_fragment_and_keeps_only_selected_users(): void
    {
        $cursor = null;
        $sql = '';
        $steps = 0;
        do {
            $connection = $this->open_database();
            $producer = new MySQLDumpProducer($this->adapter($connection), [
                'multisite_selection' => new MultisiteDatabaseSelection('wp_', 7, 1),
                'tables_to_process' => ['wp_users', 'wp_usermeta'],
                'batch_size' => 1,
                'cursor' => $cursor,
            ]);
            $more = $producer->next_sql_fragment();
            if ($more) {
                $sql .= $producer->get_sql_fragment() . "\n";
                $cursor = $producer->get_reentrancy_cursor();
            }
            $producer->close();
            unset($producer, $connection);
            $this->assertLessThan(150, ++$steps);
        } while ($more);
        $target = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=target');
        $target->get_connection()->get_pdo()->sqliteCreateFunction('FROM_BASE64', 'base64_decode');
        require_once __DIR__ . '/../../packages/reprint-client/src/lib/mysql-query-stream/load.php';
        $client = new ImportClient('https://source.test/?reprint-api', $this->directory, $this->directory);
        $execute = new ReflectionMethod($client, 'execute_db_apply_query');
        $execute->setAccessible(true);
        $target_connection = new Reprint\Importer\Database\PdoDatabaseConnection($target, $target->get_connection()->get_pdo());
        $queries = new WP_MySQL_Naive_Query_Stream();
        $queries->append_sql($sql);
        $queries->mark_input_complete();
        while ($queries->next_query()) {
            $executed = '';
            $execute->invokeArgs($client, [$target_connection, $queries->get_query(), null, &$executed]);
        }
        $this->assertSame(['member', 'author'], $target->query('SELECT user_login FROM wp_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['wp_7_capabilities'], $target->query('SELECT meta_key FROM wp_usermeta')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM wp_7_reprint_users')->fetchColumn());
        $this->assertGreaterThan(5, $steps);
    }

    public function test_same_site_is_locked_but_other_sites_can_write_and_close_is_idempotent(): void
    {
        $first = new MultisiteDatabaseSelection('wp_', 7, 1);
        $first->open_user_set($this->adapter($this->database), null);
        $second = new MultisiteDatabaseSelection('wp_', 7, 1);
        try {
            $second->open_user_set($this->adapter($this->open_database()), null);
            $this->fail('An overlapping request must not replace site 7 user IDs.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Another SQL export request', $error->getMessage());
        }
        $other = new MultisiteDatabaseSelection('wp_', 8, 1);
        $other->open_user_set($this->adapter($this->open_database()), null);
        $other->close();
        $generation = $first->get_generation();
        $first->close();
        $first->close();
        $second->open_user_set($this->adapter($this->open_database()), $generation);
        $second->close();
    }

    public function test_new_export_invalidates_an_older_cursor(): void
    {
        $selection = new MultisiteDatabaseSelection('wp_', 7, 1);
        $selection->open_user_set($this->adapter($this->database), null);
        $generation = $selection->get_generation();
        $selection->close();
        $selection->open_user_set($this->adapter($this->database), null);
        $selection->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replaced or are missing');
        $selection->open_user_set($this->adapter($this->database), $generation);
    }

    public function test_an_existing_unmarked_table_is_not_replaced(): void
    {
        $this->database->exec('CREATE TABLE wp_7_reprint_users (value text)');
        $this->database->exec("INSERT INTO wp_7_reprint_users VALUES ('Existing plugin data')");
        try {
            (new MultisiteDatabaseSelection('wp_', 7, 1))->open_user_set($this->adapter($this->database), null);
            $this->fail('An unrelated table with the same name must not be dropped.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('without Reprint', $error->getMessage());
        }
        $this->assertSame('Existing plugin data', $this->database->query('SELECT value FROM wp_7_reprint_users')->fetchColumn());
    }

    public function test_existing_source_transaction_is_rejected_without_committing_it(): void
    {
        $this->database->beginTransaction();
        try {
            (new MultisiteDatabaseSelection('wp_', 7, 1))->open_user_set($this->adapter($this->database), null);
            $this->fail('The export must not join a WordPress transaction.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('transaction', $error->getMessage());
            $this->assertTrue($this->database->inTransaction());
        } finally {
            $this->database->rollBack();
        }
    }

    public function test_process_death_releases_lock_and_preserves_saved_users(): void
    {
        $script = 'require ' . var_export(__DIR__ . '/../../packages/reprint-server/src/class-multisite-database-selection.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../../packages/reprint-server/src/class-sqlite-driver-pdo.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../../packages/reprint-server/src/class-pdo-constants.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php', true) . ';'
            . '$pdo = new WP_PDO_MySQL_On_SQLite(' . var_export('mysql-on-sqlite:path=' . $this->directory . '/source.sqlite;dbname=network', true) . ');'
            . '$selection = new WordPress\\Reprint\\Server\\MultisiteDatabaseSelection("wp_", 7, 1);'
            . '$selection->open_user_set(new WordPress\\Reprint\\Server\\SqliteDriverPDO($pdo, $pdo->get_connection()->get_pdo()), null);'
            . '$selection->collect_user_references("wp_7_posts", "SELECT ID, post_author FROM wp_7_posts ORDER BY ID LIMIT 2");'
            . 'echo $selection->get_generation() . "\\n"; fflush(STDOUT); fgets(STDIN);';
        $process = proc_open([PHP_BINARY, '-r', $script], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['file', $this->directory . '/child.log', 'w']], $pipes);
        try {
            $generation = trim((string) fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generation, file_get_contents($this->directory . '/child.log'));
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($process);
        }
        $resumed = new MultisiteDatabaseSelection('wp_', 7, 1);
        $resumed->open_user_set($this->adapter($this->database), $generation);
        $this->assertSame([2], array_map('intval', $this->database->query('SELECT user_id FROM wp_7_reprint_users')->fetchAll(PDO::FETCH_COLUMN)));
        $resumed->close();
    }

    private function open_database(): WP_PDO_MySQL_On_SQLite
    {
        return new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->directory . '/source.sqlite;dbname=network', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function adapter(WP_PDO_MySQL_On_SQLite $database): SqliteDriverPDO
    {
        return new SqliteDriverPDO($database, $database->get_connection()->get_pdo());
    }
}
