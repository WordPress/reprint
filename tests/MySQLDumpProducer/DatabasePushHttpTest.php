<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-processor.php';

/** Real HTTP upload/import, including cancellation with an open request. */
class DatabasePushHttpTest extends MySQLDumpProducerTestBase {
    private string $root;
    private string $remote_reprint_api_url;
    private $server;
    private $receiver;

    protected function setUp(): void {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reprint-db-push-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/site', 0700, true);
        file_put_contents($this->root . '/secret.php', '<?php return "database-push-test-secret";');
        $this->pdo->exec('CREATE DATABASE `' . $this->dbName . '_receiver`');
        $this->receiver = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . '_receiver;charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->receiver->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $this->receiver->exec("INSERT INTO wp_options VALUES (1, 'production')");
        $this->receiver->exec('CREATE TABLE wp_orders (id int PRIMARY KEY) ENGINE=InnoDB');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->remote_reprint_api_url = 'http://' . $address . '/';
        $environment = getenv();
        $environment['REPRINT_DB_TEST_ROOT'] = $this->root;
        $this->server = proc_open([getenv('REPRINT_DB_PUSH_SERVER_PHP') ?: PHP_BINARY, '-d', 'post_max_size=2M', '-S', $address, __DIR__ . '/../fixtures/database-push-router.php'], [['pipe', 'r'], ['file', $this->root . '/server.log', 'a'], ['file', $this->root . '/server.log', 'a']], $pipes, null, $environment);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error, $detail, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('Database push HTTP server did not start.');
    }

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->receiver = null;
        $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '_receiver`');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    /**
     * Interruption cases: confirmed rows stay copied; an incomplete row is read
     * again; edits to unread rows are observed. Large rows cross request limits.
     *
     * @dataProvider streamingKeyProvider
     */
    public function testStreamingResumeReadsCurrentSourceAfterConfirmedRows(bool $primary_key, string $change): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int ' . ( $primary_key ? 'PRIMARY KEY' : '' ) . ', value longblob) ENGINE=InnoDB');
        $original = str_repeat('original', 110000);
        $insert = $this->pdo->prepare('INSERT INTO wp_options VALUES (?, ?)');
        for ($id = 1; $id <= 6; ++$id) {
            $insert->execute([$id, $original]);
        }
        $client = $this->client();
        $processor = $this->processor($client);
        $session = $processor->get_status()['push_session_id'];
        $incoming = '__reprint_db_' . $session . '_t0';
        $confirmed = 0;
        try {
            for ($step = 0; $step < 3000 && $confirmed === 0; ++$step) {
                self::assertTrue($processor->next_step());
                self::assertFileDoesNotExist($this->root . '/state/database.jsonl.building');
                self::assertFileDoesNotExist($this->root . '/state/database.jsonl');
                if ($this->receiver->query("SHOW TABLES LIKE '" . $incoming . "'")->fetchColumn()) {
                    $confirmed = (int) $this->receiver->query('SELECT COUNT(*) FROM `' . $incoming . '`')->fetchColumn();
                }
            }
            self::assertGreaterThan(0, $confirmed, 'Some rows must arrive before the source is fully read.');
            self::assertLessThan(6, $confirmed);
            $processor->cancel();
        } finally {
            $processor->close();
        }
        $client = $this->client();
        $status = $client->send_push_request('GET', 'push_db_status', ['push_session_id' => $session], ['accepted']);
        self::assertSame('complete', $status['status'], json_encode($status));
        $confirmed = (int) $this->receiver->query('SELECT COUNT(*) FROM `' . $incoming . '`')->fetchColumn();
        self::assertLessThan(6, $confirmed);
        $changed = $change === 'same-size' ? str_repeat('newbytes', 110000) : str_repeat('changed', $change === 'grew' ? 140000 : 90000);
        $this->pdo->prepare('UPDATE wp_options SET value=?')->execute([$changed]);
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
                self::assertSame('in_progress', $processor->get_status()['status']);
            }
            self::assertSame('ready', $processor->get_status()['phase']);
            $rows = $this->receiver->query('SELECT id, value FROM `' . $incoming . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(6, $rows);
            foreach ($rows as $row) {
                self::assertSame($row['id'] <= $confirmed ? $original : $changed, $row['value']);
            }
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)) as $entry) {
                self::assertNotContains($entry->getFilename(), ['database.jsonl', 'database.jsonl.building', 'inflight.data']);
            }
            self::assertNotContains('push_db_import', file($this->root . '/requests', FILE_IGNORE_NEW_LINES));
        } finally {
            $processor->close();
        }
    }

    public static function streamingKeyProvider(): array {
        return [[true, 'shrank'], [true, 'grew'], [true, 'same-size'], [false, 'shrank'], [false, 'grew'], [false, 'same-size']];
    }

    /** @dataProvider sourceDatabaseProvider */
    public function testSourceReadSupportDoesNotRequireTargetSwapSupport(string $engine): void {
        if ($engine === 'sqlite') {
            require_once \Reprint\Importer\resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/load.php');
            $dsn = 'mysql-on-sqlite:path=' . $this->root . '/source.sqlite;dbname=source';
            $source = new WP_PDO_MySQL_On_SQLite($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName;
            $source = $this->pdo;
        }
        $source->exec('CREATE TABLE wp_values (id int PRIMARY KEY, value longtext, bytes blob, flag BIT(16), choice ENUM(\'\', \'0\', \'one\')) ENGINE=' . ( $engine === 'sqlite' ? 'InnoDB' : 'MyISAM' ));
        $source->exec("INSERT INTO wp_values VALUES (1, 'https://local.test/page', X'00FF', 257, '0'), (2, NULL, NULL, NULL, '')");
        $source->exec('CREATE TABLE plugin_rows (id int PRIMARY KEY, value text) ENGINE=InnoDB');
        $source->exec("INSERT INTO plugin_rows VALUES (7, 'https://local.test/plugin')");
        $source_hash = $engine === 'sqlite' ? hash_file('sha256', $this->root . '/source.sqlite') : null;
        $client = $this->client();
        $processor = DatabasePushProcessor::start($client, $this->root . '/source-state', ['dsn' => $dsn, 'user' => getenv('DB_USER'), 'pass' => getenv('DB_PASS')], 'wp_', ['https://local.test' => 'https://production.example.com'], ['plugin_rows']);
        try {
            while ($processor->next_step()) {
                self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            }
            $status = $processor->get_status();
            self::assertSame('ready', $status['phase']);
            self::assertSame(['plugin_rows', 'wp_values'], $status['incoming_tables']);
            $commit = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
            self::assertSame('complete', $commit['status'], json_encode($commit));
            self::assertSame([['https://production.example.com/page', '00FF', '257', '0', '2'], [null, null, null, '', '1']], $this->receiver->query('SELECT value, HEX(bytes), CAST(flag+0 AS CHAR), choice, CAST(choice+0 AS CHAR) FROM wp_values ORDER BY id')->fetchAll(PDO::FETCH_NUM));
            self::assertSame('https://production.example.com/plugin', $this->receiver->query('SELECT value FROM plugin_rows')->fetchColumn());
            self::assertSame('InnoDB', $this->receiver->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_values'")->fetchColumn());
            self::assertSame('https://local.test/page', $source->query('SELECT value FROM wp_values')->fetchColumn());
            if ($engine === 'sqlite') {
                self::assertSame($source_hash, hash_file('sha256', $this->root . '/source.sqlite'));
            }
        } finally {
            $processor->close();
        }
    }

    public static function sourceDatabaseProvider(): array {
        return [['myisam'], ['sqlite']];
    }

    public function testOldMysqlSourceExportsEnumBinaryAndSpatialValues(): void {
        $host = getenv('REPRINT_MYSQL55_HOST');
        if (!$host) {
            self::markTestSkipped('REPRINT_MYSQL55_HOST is required for the legacy-source integration test.');
        }
        $source = new PDO('mysql:host=' . $host, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = 'reprint_push_' . bin2hex(random_bytes(6));
        $source->exec('CREATE DATABASE `' . $name . '`');
        try {
            self::assertStringStartsWith('5.5.', $source->query('SELECT VERSION()')->fetchColumn());
            $source->exec('USE `' . $name . '`');
            $source->exec("CREATE TABLE wp_values (id int PRIMARY KEY, value text, bytes blob, choice ENUM('', '0'), location point) ENGINE=MyISAM");
            $source->exec("SET SESSION sql_mode=''");
            $source->exec("INSERT INTO wp_values VALUES (1, 'https://local.test/', X'00FF', 'invalid', GeomFromText('POINT(1 2)', 0))");
            $client = $this->client();
            $processor = DatabasePushProcessor::start($client, $this->root . '/old-source-state', ['dsn' => 'mysql:host=' . $host . ';dbname=' . $name, 'user' => getenv('DB_USER'), 'pass' => getenv('DB_PASS')], 'wp_', ['https://local.test' => 'https://production.example.com']);
            try {
                while ($processor->next_step()) {
                    self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
                }
                $status = $processor->get_status();
                $commit = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
                self::assertSame('complete', $commit['status'], json_encode($commit));
                self::assertSame(['https://production.example.com/', '00FF', '0', 'POINT(1 2)'], $this->receiver->query('SELECT value, HEX(bytes), CAST(choice+0 AS CHAR), ST_AsText(location) FROM wp_values')->fetch(PDO::FETCH_NUM));
                self::assertSame('MyISAM', $source->query("SHOW TABLE STATUS LIKE 'wp_values'")->fetch(PDO::FETCH_ASSOC)['Engine']);
            } finally {
                $processor->close();
            }
        } finally {
            $source->exec('DROP DATABASE `' . $name . '`');
        }
    }

    public function testCancelledUploadResumesWithoutChangingLiveRowsUntilConfirmed(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $value = serialize(array_fill(0, 1000, 'https://local.test/café'));
        $insert = $this->pdo->prepare('INSERT INTO wp_options VALUES (?, ?)');
        for ($row = 1; $row <= 15; ++$row) {
            $insert->execute([$row, $value]);
        }
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->get_status()['phase'] !== 'uploading') {
                self::assertTrue($processor->next_step());
            }
            // At least two real multipart parts leave before cancellation.
            self::assertTrue($processor->next_step());
            self::assertTrue($processor->next_step());
            $processor->cancel();
        } finally {
            $processor->close();
            $client->close();
        }
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            $status = $processor->get_status();
            self::assertSame('ready', $status['phase']);
            self::assertFalse($processor->next_step());
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            self::assertSame($value, $this->pdo->query('SELECT value FROM wp_options WHERE id=1')->fetchColumn());
            $parameters = ['push_session_id' => $status['push_session_id'], 'review' => $status['review']];
            $missing_confirmation = $client->send_push_request('POST', 'push_db_commit', $parameters, ['accepted']);
            self::assertSame('failed', $missing_confirmation['status']);
            $parameters['writers_stopped'] = 'yes';
            $bad_review = $client->send_push_request('POST', 'push_db_commit', ['review' => 'wrong'] + $parameters, ['accepted']);
            self::assertSame('failed', $bad_review['status']);
            $commit_url = $this->remote_reprint_api_url . '?' . http_build_query(['endpoint' => 'push_db_commit'] + $parameters);
            $headers = (new Site_Export_HMAC_Client('database-push-test-secret'))->get_envelope_auth_headers('POST', $commit_url);
            $address = parse_url($commit_url, PHP_URL_HOST) . ':' . parse_url($commit_url, PHP_URL_PORT);
            $socket = stream_socket_client('tcp://' . $address);
            $request = 'POST /?' . parse_url($commit_url, PHP_URL_QUERY) . " HTTP/1.1\r\nHost: " . $address . "\r\nContent-Length: 0\r\nConnection: close\r\n";
            foreach ($headers as $name => $value) {
                $request .= $name . ': ' . $value . "\r\n";
            }
            fwrite($socket, $request . "\r\n");
            fclose($socket);
            // This subsequent real request waits behind the discarded response.
            $observed = $client->send_push_request('GET', 'push_db_status', ['push_session_id' => $status['push_session_id']], ['accepted']);
            self::assertSame('committed', $observed['response']['phase'], json_encode($observed));
            self::assertSame(15, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_options')->fetchColumn());
            self::assertSame(array_fill(0, 1000, 'https://production.example.com/café'), unserialize($this->receiver->query('SELECT value FROM wp_options WHERE id=1')->fetchColumn()));
            $again = $client->send_push_request('POST', 'push_db_commit', $parameters, ['accepted']);
            self::assertSame('committed', $again['response']['phase']);
            do {
                $cleanup = $client->send_push_request('POST', 'push_db_cleanup', ['push_session_id' => $status['push_session_id']], ['accepted']);
                self::assertSame('complete', $cleanup['status'], json_encode($cleanup));
            } while ($cleanup['response']['phase'] !== 'complete');
            $requests = file($this->root . '/requests', FILE_IGNORE_NEW_LINES);
            self::assertLessThan(5, count(array_filter($requests, static fn ($endpoint) => $endpoint === 'push_db_upload')));
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testSourceRowLimitLeavesLiveTablesAndSourceUnchanged(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longblob) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, REPEAT('x', 1048577))");
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            self::fail('An oversized row must not be silently truncated.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('1 MiB', $exception->getMessage());
            self::assertSame('failed', $processor->get_status()['phase']);
            self::assertFalse($processor->next_step());
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            self::assertSame(1048577, (int) $this->pdo->query('SELECT LENGTH(value) FROM wp_options')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    /** @dataProvider triggerSourcesProvider */
    public function testCliWarnsAndLeavesTriggersOffNewLiveTables(bool $source_trigger, bool $target_trigger): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        if ($source_trigger) {
            $this->pdo->exec("CREATE TRIGGER mark_value BEFORE INSERT ON wp_options FOR EACH ROW SET NEW.value=CONCAT('source:', NEW.value)");
        }
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, 'local')");
        if ($target_trigger) {
            $this->receiver->exec("CREATE TRIGGER mark_value BEFORE INSERT ON wp_options FOR EACH ROW SET NEW.value=CONCAT('target:', NEW.value)");
        }
        $this->receiver->exec("INSERT INTO wp_options VALUES (2, 'before')");
        $this->receiver->exec('CREATE TABLE unrelated (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $this->receiver->exec("CREATE TRIGGER mark_unrelated BEFORE INSERT ON unrelated FOR EACH ROW SET NEW.value=CONCAT('unrelated:', NEW.value)");
        $trigger_query = 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME';
        $source_triggers = $this->pdo->query($trigger_query)->fetchAll(PDO::FETCH_ASSOC);
        $target_triggers = $this->receiver->query($trigger_query)->fetchAll(PDO::FETCH_ASSOC);
        $target_rows = $this->receiver->query('SELECT * FROM wp_options ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $arguments = [getenv('REPRINT_DB_PUSH_CLIENT_PHP') ?: PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'db-push', $this->remote_reprint_api_url,
            '--state-dir=' . $this->root . '/cli-state', '--secret=database-push-test-secret', '--force-http'];
        $source = ['--source-dsn=mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, '--source-user=' . getenv('DB_USER'), '--source-pass=' . getenv('DB_PASS')];
        $staged = $this->runCli(array_merge($arguments, $source));
        self::assertSame('ready', $staged['phase']);
        self::assertSame(['Triggers are not copied. After commit, the new live tables will have no triggers.'], $staged['warnings']);
        self::assertSame($target_triggers, $this->receiver->query($trigger_query)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($target_rows, $this->receiver->query('SELECT * FROM wp_options ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $resumed = $this->runCli(array_merge($arguments, $source));
        self::assertSame($staged['warnings'], $resumed['warnings']);
        self::assertSame($staged['review'], $resumed['review']);

        $commit_arguments = array_merge($arguments, ['--commit=' . $staged['review'], '--writers-stopped']);
        $committed = $this->runCli($commit_arguments);
        self::assertSame('committed', $committed['phase']);
        self::assertSame($source_trigger ? 'source:local' : 'local', $this->receiver->query('SELECT value FROM wp_options WHERE id=1')->fetchColumn());
        self::assertSame(0, (int) $this->receiver->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='wp_options'")->fetchColumn());
        $this->receiver->exec("INSERT INTO wp_options VALUES (2, 'after')");
        self::assertSame('after', $this->receiver->query('SELECT value FROM wp_options WHERE id=2')->fetchColumn());
        if ($target_trigger) {
            $old_table = $this->receiver->query("SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='mark_value'")->fetchColumn();
            self::assertContains($old_table, $committed['old_tables']);
            self::assertSame($target_rows, $this->receiver->query('SELECT * FROM `' . $old_table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }
        $replayed = $this->runCli($commit_arguments);
        self::assertSame($committed['old_tables'], $replayed['old_tables']);
        self::assertSame('after', $this->receiver->query('SELECT value FROM wp_options WHERE id=2')->fetchColumn());
        $cleaned = $this->runCli(array_merge($arguments, ['--cleanup']));
        self::assertSame('complete', $cleaned['phase']);
        self::assertSame([['TRIGGER_NAME' => 'mark_unrelated', 'EVENT_OBJECT_TABLE' => 'unrelated']], $this->receiver->query($trigger_query)->fetchAll(PDO::FETCH_ASSOC));
        $this->receiver->exec("INSERT INTO unrelated VALUES (1, 'after')");
        self::assertSame('unrelated:after', $this->receiver->query('SELECT value FROM unrelated')->fetchColumn());
        self::assertSame($source_triggers, $this->pdo->query($trigger_query)->fetchAll(PDO::FETCH_ASSOC));
        $this->pdo->exec("INSERT INTO wp_options VALUES (2, 'after')");
        self::assertSame($source_trigger ? 'source:after' : 'after', $this->pdo->query('SELECT value FROM wp_options WHERE id=2')->fetchColumn());
    }

    /** @return array<string,array{bool,bool}> Trigger placement on the two databases. */
    public static function triggerSourcesProvider(): array {
        return [
            'source only' => [true, false],
            'target only' => [false, true],
            'both' => [true, true],
            'neither' => [false, false],
        ];
    }

    /** @dataProvider sourceDatabaseProvider */
    public function testCliStagesReviewsDiscardsAndLeavesProductionUnchanged(string $engine): void {
        if ($engine === 'sqlite') {
            require_once \Reprint\Importer\resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/load.php');
            $dsn = 'mysql-on-sqlite:path=' . $this->root . '/cli;;source.sqlite;dbname=source';
            $database = new WP_PDO_MySQL_On_SQLite($dsn);
        } else {
            $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName;
            $database = $this->pdo;
        }
        $database->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $database->exec("INSERT INTO wp_options VALUES (1, 'https://local.test/')");
        $this->receiver->exec("CREATE TRIGGER mark_value BEFORE INSERT ON wp_options FOR EACH ROW SET NEW.value=CONCAT('target:', NEW.value)");
        $arguments = [getenv('REPRINT_DB_PUSH_CLIENT_PHP') ?: PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'db-push', $this->remote_reprint_api_url,
            '--state-dir=' . $this->root . '/cli-state', '--secret=database-push-test-secret', '--force-http'];
        $source = ['--source-dsn=' . $dsn, '--source-user=' . getenv('DB_USER'), '--source-pass=' . getenv('DB_PASS'), '--rewrite-url', 'https://local.test', 'https://production.example.com'];
        $staged = $this->runCli(array_merge($arguments, $source));
        self::assertSame('ready', $staged['phase']);
        self::assertSame(['Triggers are not copied. After commit, the new live tables will have no triggers.'], $staged['warnings']);
        self::assertSame(['wp_options', 'wp_orders'], $staged['replace_tables']);
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        $discarded = $this->runCli(array_merge($arguments, ['--abort']));
        self::assertSame('discarded', $discarded['phase']);
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        $this->receiver->exec("INSERT INTO wp_options VALUES (2, 'after')");
        self::assertSame('target:after', $this->receiver->query('SELECT value FROM wp_options WHERE id=2')->fetchColumn());
        $remaining = $this->receiver->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $remaining); // Two live tables and one terminal progress marker.
    }

    public function testCliIncludesNamedTablesOutsidePrefixAndKeepsOtherTables(): void {
        $this->pdo->exec('CREATE TABLE wp_parent (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE plugin_rows (id int PRIMARY KEY, parent_id int, FOREIGN KEY (parent_id) REFERENCES wp_parent(id)) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO wp_parent VALUES (2)');
        $this->pdo->exec('INSERT INTO plugin_rows VALUES (20, 2)');
        $this->pdo->exec('CREATE TABLE not_selected (id int) ENGINE=InnoDB');
        $this->receiver->exec('CREATE TABLE wp_parent (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->receiver->exec('CREATE TABLE plugin_rows (id int PRIMARY KEY, parent_id int, FOREIGN KEY (parent_id) REFERENCES wp_parent(id)) ENGINE=InnoDB');
        $this->receiver->exec('INSERT INTO wp_parent VALUES (1)');
        $this->receiver->exec('INSERT INTO plugin_rows VALUES (10, 1)');
        $this->receiver->exec('CREATE TABLE not_selected (id int) ENGINE=InnoDB');
        $this->receiver->exec('INSERT INTO not_selected VALUES (99)');
        $arguments = [getenv('REPRINT_DB_PUSH_CLIENT_PHP') ?: PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'db-push', $this->remote_reprint_api_url,
            '--state-dir=' . $this->root . '/cli-state', '--secret=database-push-test-secret', '--force-http'];
        $source = ['--source-dsn=mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, '--source-user=' . getenv('DB_USER'), '--source-pass=' . getenv('DB_PASS'), '--include-table=plugin_rows'];
        $staged = $this->runCli(array_merge($arguments, $source));
        self::assertSame(['plugin_rows', 'wp_parent'], $staged['incoming_tables']);
        self::assertSame(['plugin_rows', 'wp_options', 'wp_orders', 'wp_parent'], $staged['replace_tables']);
        self::assertSame(10, (int) $this->receiver->query('SELECT id FROM plugin_rows')->fetchColumn());
        $resumed = $this->runCli(array_merge($arguments, $source));
        self::assertSame($staged['review'], $resumed['review']);
        $committed = $this->runCli(array_merge($arguments, ['--commit=' . $staged['review'], '--writers-stopped']));
        self::assertSame('committed', $committed['phase']);
        self::assertSame([20, 2], array_map('intval', $this->receiver->query('SELECT id, parent_id FROM plugin_rows')->fetch(PDO::FETCH_NUM)));
        self::assertSame('wp_parent', $this->receiver->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plugin_rows' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn());
        $this->runCli(array_merge($arguments, ['--cleanup']));
        self::assertSame(99, (int) $this->receiver->query('SELECT id FROM not_selected')->fetchColumn());
        self::assertSame(20, (int) $this->pdo->query('SELECT id FROM plugin_rows')->fetchColumn());
    }

    public function testBinaryNumericNullAndZeroAutoIncrementValuesRoundTrip(): void {
        $this->pdo->exec('CREATE TABLE wp_values (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, amount decimal(30,10), bytes longblob, flags bit(8), absent text NULL, stamp timestamp) ENGINE=InnoDB');
        $this->pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO', time_zone='+03:00'");
        $insert = $this->pdo->prepare("INSERT INTO wp_values VALUES (?, ?, ?, ?, NULL, '2024-01-02 03:04:05')");
        $bytes = "\x00\xff\x80'\\https://local.test/";
        $insert->execute(['0', '12345678901234567890.1234567890', $bytes, chr(170)]);
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            $status = $processor->get_status();
            $result = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
            self::assertSame('complete', $result['status'], json_encode($result));
            $row = $this->receiver->query('SELECT id, amount, bytes, flags+0 AS flags, absent FROM wp_values')->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0, (int) $row['id']);
            self::assertSame('12345678901234567890.1234567890', $row['amount']);
            self::assertSame($bytes, $row['bytes']);
            self::assertSame(170, (int) $row['flags']);
            self::assertNull($row['absent']);
            self::assertSame($this->pdo->query('SELECT UNIX_TIMESTAMP(stamp) FROM wp_values')->fetchColumn(), $this->receiver->query('SELECT UNIX_TIMESTAMP(stamp) FROM wp_values')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testEnumIndexZeroRemainsDistinctFromZeroAndEmptyLabels(): void {
        $this->pdo->exec("CREATE TABLE wp_enums (id int PRIMARY KEY, value ENUM('0','', 'allowed') NULL) ENGINE=InnoDB");
        $this->pdo->exec("INSERT IGNORE INTO wp_enums VALUES (1, 'invalid'), (2, '0'), (3, ''), (4, 'allowed'), (5, NULL)");
        $expected = $this->pdo->query('SELECT id, value, value+0 AS member FROM wp_enums ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            $status = $processor->get_status();
            $result = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
            self::assertSame('complete', $result['status'], json_encode($result));
            self::assertSame($expected, $this->receiver->query('SELECT id, value, value+0 AS member FROM wp_enums ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testEnumIndexZeroDoesNotPermitTruncatingAnotherColumn(): void {
        $this->pdo->exec("CREATE TABLE wp_enums (id int PRIMARY KEY, value ENUM('allowed') NOT NULL, url varchar(20)) ENGINE=InnoDB");
        $this->pdo->exec("INSERT IGNORE INTO wp_enums VALUES (1, 'invalid', 'https://local.test/x')");
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            self::fail('URL rewriting must not silently truncate another column when restoring ENUM index zero.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ENUM index zero', $exception->getMessage());
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            $incoming = $this->receiver->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '__reprint%_t0'")->fetchColumn();
            self::assertSame(0, (int) $this->receiver->query('SELECT COUNT(*) FROM `' . $incoming . '`')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testGeneratedSpatialPartitionedTablesAndSqlKeywordsRoundTrip(): void {
        $this->pdo->exec("CREATE TABLE wp_features (id int PRIMARY KEY, url varchar(255), generated_url varchar(255) GENERATED ALWAYS AS (concat(url, '/generated')) STORED, point POINT NOT NULL, area GEOMETRY NULL, label varchar(255) DEFAULT 'SELECT; FOREIGN KEY REFERENCES POINT', CONSTRAINT named_check CHECK (id > 0), SPATIAL INDEX (point)) ENGINE=InnoDB COMMENT='CONSTRAINT; DATA DIRECTORY; TRIGGER'");
        $this->pdo->exec("INSERT INTO wp_features (id, url, point, area) VALUES (1, 'https://local.test', ST_GeomFromText('POINT(1 2)', 4326), ST_GeomFromText('LINESTRING(0 0,1 1)', 4326)), (2, 'https://local.test/two', ST_GeomFromText('POINT(3 4)', 0), NULL)");
        $this->pdo->exec('CREATE TABLE wp_partitioned (id int PRIMARY KEY, value text) ENGINE=InnoDB PARTITION BY HASH(id) PARTITIONS 2');
        $this->pdo->exec("INSERT INTO wp_partitioned VALUES (1, 'one'), (2, 'two')");
        // Named CHECK constraints must also coexist with the old live schema.
        $this->receiver->exec('CREATE TABLE wp_features (id int PRIMARY KEY, CONSTRAINT named_check CHECK (id > 0)) ENGINE=InnoDB');
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
                self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            }
            $status = $processor->get_status();
            self::assertSame('ready', $status['phase']);
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            $parameters = ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'];
            $result = $client->send_push_request('POST', 'push_db_commit', $parameters, ['accepted']);
            self::assertSame('complete', $result['status'], json_encode($result));
            $rows = $this->receiver->query('SELECT id, generated_url, ST_AsText(point) AS point, ST_SRID(point) AS srid, ST_AsText(area) AS area, label FROM wp_features ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame('https://production.example.com/generated', $rows[0]['generated_url']);
            self::assertSame('POINT(1 2)', $rows[0]['point']);
            self::assertSame(4326, (int) $rows[0]['srid']);
            self::assertSame('LINESTRING(0 0,1 1)', $rows[0]['area']);
            self::assertNull($rows[1]['area']);
            self::assertSame('SELECT; FOREIGN KEY REFERENCES POINT', $rows[0]['label']);
            self::assertSame(2, (int) $this->receiver->query("SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_partitioned'")->fetchColumn());
            self::assertSame('one,two', $this->receiver->query('SELECT GROUP_CONCAT(value ORDER BY id) FROM wp_partitioned')->fetchColumn());
            try {
                $this->receiver->exec("INSERT INTO wp_features (id, point) VALUES (-1, ST_GeomFromText('POINT(0 0)'))");
                self::fail('The named CHECK constraint must remain enforced after commit.');
            } catch (PDOException $exception) {
                self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
            do {
                $result = $client->send_push_request('POST', 'push_db_cleanup', $parameters, ['accepted']);
                self::assertSame('complete', $result['status'], json_encode($result));
            } while ($result['response']['phase'] !== 'complete');
            self::assertSame('https://local.test/generated', $this->pdo->query('SELECT generated_url FROM wp_features WHERE id=1')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    /** @dataProvider foreignKeyOutcomes */
    public function testCyclicForeignKeysFollowIncomingTablesThroughCommitOrDiscard(bool $commit): void {
        foreach ([$this->pdo, $this->receiver] as $database) {
            $database->exec('CREATE TABLE wp_parent (id int PRIMARY KEY, child_id int NULL) ENGINE=InnoDB');
            $database->exec('CREATE TABLE wp_child (id int PRIMARY KEY, parent_id int, CONSTRAINT child_parent FOREIGN KEY (parent_id) REFERENCES wp_parent(id) ON DELETE CASCADE) ENGINE=InnoDB');
            $database->exec('ALTER TABLE wp_parent ADD CONSTRAINT parent_child FOREIGN KEY (child_id) REFERENCES wp_child(id)');
            $database->exec('INSERT INTO wp_parent VALUES (1, NULL)');
            $database->exec('INSERT INTO wp_child VALUES (2, 1)');
            $database->exec('UPDATE wp_parent SET child_id=2');
        }
        $this->pdo->exec('INSERT INTO wp_parent VALUES (3, NULL)');
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
                self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            }
            $status = $processor->get_status();
            self::assertSame('ready', $status['phase']);
            self::assertSame(1, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_parent')->fetchColumn());
            $references = $this->receiver->query("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,13)='__reprint_db_' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $references);
            foreach ($references as $reference) {
                self::assertStringStartsWith('__reprint_db_', $reference['REFERENCED_TABLE_NAME']);
            }
            $parameters = ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'];
            if (!$commit) {
                do {
                    $result = $client->send_push_request('POST', 'push_db_discard', $parameters, ['accepted']);
                    self::assertSame('complete', $result['status'], json_encode($result));
                } while ($result['response']['phase'] !== 'discarded');
                self::assertSame(1, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_parent')->fetchColumn());
                self::assertSame(1, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_child')->fetchColumn());
                self::assertCount(5, $this->receiver->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
                return;
            }
            $result = $client->send_push_request('POST', 'push_db_commit', $parameters, ['accepted']);
            self::assertSame('complete', $result['status'], json_encode($result));
            do {
                $result = $client->send_push_request('POST', 'push_db_cleanup', $parameters, ['accepted']);
                self::assertSame('complete', $result['status'], json_encode($result));
            } while ($result['response']['phase'] !== 'complete');
            self::assertSame(2, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_parent')->fetchColumn());
            self::assertSame('wp_parent', $this->receiver->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_child' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn());
            $this->receiver->exec('UPDATE wp_parent SET child_id=NULL WHERE id=1');
            $this->receiver->exec('DELETE FROM wp_parent WHERE id=1');
            self::assertSame(0, (int) $this->receiver->query('SELECT COUNT(*) FROM wp_child')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public static function foreignKeyOutcomes(): array {
        return ['commit and cleanup' => [true], 'discard' => [false]];
    }

    public function testForeignKeyValidationRejectsLegacyOrphansWithoutChangingLiveTables(): void {
        $this->pdo->exec('CREATE TABLE wp_parent (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE wp_child (id int PRIMARY KEY, parent_id int, CONSTRAINT child_parent FOREIGN KEY (parent_id) REFERENCES wp_parent(id)) ENGINE=InnoDB');
        // An earlier import with checks disabled can leave valid table
        // definitions with rows that fail a later foreign key validation.
        $this->pdo->exec('SET SESSION foreign_key_checks=0');
        $this->pdo->exec('INSERT INTO wp_child VALUES (1, 999)');
        $this->pdo->exec('SET SESSION foreign_key_checks=1');
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            try {
                while ($processor->next_step()) {
                    self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
                }
                self::fail('The imported foreign key must validate every incoming row before ready.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('foreign key constraint', strtolower($exception->getMessage()));
            }
            $parameters = ['push_session_id' => $processor->get_status()['push_session_id']];
            $before = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            self::assertSame('importing', $before['response']['phase']);
            $processor->close();
            $client = $this->client();
            $processor = $this->processor($client);
            try {
                while ($processor->next_step()) {
                    self::assertSame('in_progress', $processor->get_status()['status']);
                }
                self::fail('Resuming must retry the unconfirmed record and report the same SQL failure.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('foreign key constraint', $exception->getMessage());
            }
            $after = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            self::assertSame($before['response']['records'], $after['response']['records']);
            do {
                $result = $client->send_push_request('POST', 'push_db_discard', $parameters, ['accepted']);
                self::assertSame('complete', $result['status'], json_encode($result));
            } while ($result['response']['phase'] !== 'discarded');
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            self::assertCount(3, $this->receiver->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testSourceStoragePlacementIsNotCopiedToTheTarget(): void {
        $tablespace = null;
        $placement = "DATA DIRECTORY='/tmp'";
        $option = 'DATA DIRECTORY';
        if (stripos($this->pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') === false) {
            $tablespace = 'push_storage_' . bin2hex(random_bytes(6));
            $this->pdo->exec("CREATE TABLESPACE `{$tablespace}` ADD DATAFILE '{$tablespace}.ibd' ENGINE=InnoDB");
            $placement = 'TABLESPACE `' . $tablespace . '`';
            $option = 'TABLESPACE';
        }
        $this->pdo->exec('CREATE TABLE wp_storage (id int PRIMARY KEY, value text) ENGINE=InnoDB ' . $placement);
        $this->pdo->exec("INSERT INTO wp_storage VALUES (1, 'https://local.test/file')");
        $source_definition = $this->pdo->query('SHOW CREATE TABLE wp_storage')->fetch(PDO::FETCH_NUM)[1];
        self::assertStringContainsString($option, $source_definition);
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
                self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            }
            $status = $processor->get_status();
            $result = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
            self::assertSame('complete', $result['status'], json_encode($result));
            self::assertStringNotContainsString($option, $this->receiver->query('SHOW CREATE TABLE wp_storage')->fetch(PDO::FETCH_NUM)[1]);
            self::assertSame('https://production.example.com/file', $this->receiver->query('SELECT value FROM wp_storage')->fetchColumn());
            self::assertSame($source_definition, $this->pdo->query('SHOW CREATE TABLE wp_storage')->fetch(PDO::FETCH_NUM)[1]);
        } finally {
            $processor->close();
            $client->close();
            if ($tablespace !== null) {
                $this->pdo->exec('DROP TABLE wp_storage');
                $this->pdo->exec('DROP TABLESPACE `' . $tablespace . '` ENGINE=InnoDB');
            }
        }
    }

    /** @param list<string> $arguments Real CLI arguments. @return array<string,mixed> */
    private function runCli(array $arguments): array {
        $arguments[] = '--progress=jsonl';
        $process = proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        $records = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($output)));
        self::assertCount(2, $records, $output);
        self::assertIsArray($records[0]);
        self::assertSame('reprint_report', $records[1]['type']);
        self::assertSame('db-push', $records[1]['command']);
        self::assertSame(in_array('--abort', $arguments, true) ? 'aborted' : 'complete', $records[1]['status']);
        return $records[0];
    }

    public function testProcessDeathWithOpenRequestResumesFromConfirmedSourceCursor(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $insert = $this->pdo->prepare('INSERT INTO wp_options VALUES (?, ?)');
        for ($row = 1; $row <= 80; ++$row) {
            $insert->execute([$row, str_repeat('https://local.test/path ', 2000)]);
        }
        $worker = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/database-push-worker.php', $this->remote_reprint_api_url, $this->root . '/state'], [['pipe', 'r'], ['pipe', 'w'], ['file', $this->root . '/worker.log', 'a']], $pipes);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $uploads = 0;
        $finished_request = false;
        $previous_phase = '';
        // php -S buffers an HTTP request before dispatch. Complete one request
        // first, then kill the sender in the next one. Only the earlier request
        // can have target-confirmed rows on this actual server.
        try {
            while (($line = fgets($pipes[1])) !== false) {
                $phase = trim($line);
                if ($previous_phase === 'finishing_request') {
                    $finished_request = true;
                }
                $previous_phase = $phase;
                if ($finished_request && $phase === 'uploading' && ++$uploads === 3) {
                    proc_terminate($worker, 9);
                    break;
                }
            }
            self::assertSame(3, $uploads, file_get_contents($this->root . '/worker.log'));
        } finally {
            fclose($pipes[1]);
            proc_terminate($worker, 9);
            proc_close($worker);
        }
        $state = json_decode(file_get_contents($this->root . '/state/state.json'), true);
        $client = $this->client();
        $processor = null;
        try {
            $remote = $client->send_push_request('GET', 'push_db_status', ['push_session_id' => $state['push_session_id']], ['accepted']);
            self::assertSame('importing', $remote['response']['phase'], json_encode($remote));
            self::assertGreaterThan(1, $remote['response']['records']);
            self::assertNotNull($remote['response']['cursor']);
            $processor = $this->processor($client);
            while ($processor->next_step()) {
            }
            self::assertSame('ready', $processor->get_status()['phase']);
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        } finally {
            if ($processor !== null) {
                $processor->close();
            }
            $client->close();
        }
    }

    public function testLostUploadReplyResumesAfterTargetCommittedRow(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value text) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, 'original first'), (2, 'original second')");
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            // Persist local selection, but stop before opening the upload.
            while ($processor->get_status()['phase'] !== 'opening_request') {
                self::assertTrue($processor->next_step());
            }
            $session = $processor->get_status()['push_session_id'];
        } finally {
            $processor->close();
        }
        $reader = new DatabasePushSource($this->pdo, 'wp_', [], $session);
        $body = '';
        $boundary = 'lost-upload-reply';
        try {
            for ($number = 0; $number < 2; ++$number) {
                self::assertTrue($reader->next_step());
                $record = json_encode($reader->get_record());
                $body .= '--' . $boundary . "\r\nX-Chunk-Type: database\r\nX-Record-Number: " . $number
                    . "\r\nX-Record-Size: " . strlen($record) . "\r\nX-Chunk-Offset: 0\r\nContent-Length: " . strlen($record) . "\r\n\r\n" . $record . "\r\n";
            }
        } finally {
            $reader->close();
        }
        $body .= '--' . $boundary . "--\r\n";
        $url = $this->remote_reprint_api_url . '?' . http_build_query(['endpoint' => 'push_db_upload', 'push_session_id' => $session]);
        $headers = ( new Site_Export_HMAC_Client('database-push-test-secret') )->get_envelope_auth_headers('POST', $url);
        $address = parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT);
        $socket = stream_socket_client('tcp://' . $address);
        $request = 'POST /?' . parse_url($url, PHP_URL_QUERY) . " HTTP/1.1\r\nHost: " . $address
            . "\r\nContent-Type: multipart/mixed; boundary=" . $boundary . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        self::assertSame(strlen($request . "\r\n" . $body), fwrite($socket, $request . "\r\n" . $body));
        fclose($socket); // Discard the reply, not the already delivered request.
        $client = $this->client();
        $status = $client->send_push_request('GET', 'push_db_status', ['push_session_id' => $session], ['accepted']);
        self::assertSame(2, $status['response']['records'], json_encode($status));
        $this->pdo->exec("UPDATE wp_options SET value=CONCAT('changed ', id)");
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
                self::assertSame('in_progress', $processor->get_status()['status']);
            }
            self::assertSame('ready', $processor->get_status()['phase']);
            self::assertSame(['original first', 'changed 2'], $this->receiver->query('SELECT value FROM `__reprint_db_' . $session . '_t0` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        } finally {
            $processor->close();
        }
    }

    public function testChangedTargetTableListNeedsAnotherReview(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            while ($processor->next_step()) {
            }
            $status = $processor->get_status();
            // Installing a plugin while staging can add a live table. The old
            // confirmation must not silently approve removal of this new table.
            $this->receiver->exec('CREATE TABLE wp_new_plugin (id int PRIMARY KEY) ENGINE=InnoDB');
            $result = $client->send_push_request('POST', 'push_db_commit', ['push_session_id' => $status['push_session_id'], 'review' => $status['review'], 'writers_stopped' => 'yes'], ['accepted']);
            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('review token', $result['detail']);
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
            $current = $client->send_push_request('GET', 'push_db_status', ['push_session_id' => $status['push_session_id']], ['accepted']);
            self::assertNotSame($status['review'], $current['response']['review']);
            self::assertContains('wp_new_plugin', $current['response']['replace_tables']);
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testUniqueValueRewriteFailureKeepsConfirmedRowsAndCanBeDiscarded(): void {
        $this->pdo->exec('CREATE TABLE wp_links (id int PRIMARY KEY, link varchar(255) UNIQUE) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_links VALUES (1, 'https://local.test/a'), (2, 'https://production.example.com/a')");
        $client = $this->client();
        $processor = $this->processor($client);
        try {
            try {
                while ($processor->next_step()) {
                }
                self::fail('The duplicate unique value must stop import.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Duplicate entry', $exception->getMessage());
            }
            $parameters = ['push_session_id' => $processor->get_status()['push_session_id']];
            $before = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            $processor->close();
            $client = $this->client();
            $processor = $this->processor($client);
            try {
                while ($processor->next_step()) {
                    self::assertSame('in_progress', $processor->get_status()['status']);
                }
                self::fail('Resuming must retry the unconfirmed record and report the same SQL failure.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Duplicate entry', $exception->getMessage());
            }
            $after = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            self::assertSame($before['response']['records'], $after['response']['records']);
            self::assertGreaterThan(0, $after['response']['records']);
            do {
                $discard = $client->send_push_request('POST', 'push_db_discard', $parameters, ['accepted']);
                self::assertSame('complete', $discard['status'], json_encode($discard));
            } while ($discard['response']['phase'] !== 'discarded');
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    private function client(): MultipartPushStreamClient {
        return new MultipartPushStreamClient([
            'remote_reprint_api_url' => $this->remote_reprint_api_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client('database-push-test-secret'),
            'request_context_headers' => ['User-Agent' => 'Reprint database push test'],
            'chunk_bytes' => 16384,
        ]);
    }

    private function processor(MultipartPushStreamClient $client): DatabasePushProcessor {
        $factory = is_file($this->root . '/state/state.json') ? 'resume' : 'start';
        return DatabasePushProcessor::$factory($client, $this->root . '/state', [
            'dsn' => 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . ';charset=utf8mb4',
            'user' => getenv('DB_USER'),
            'pass' => getenv('DB_PASS'),
        ], 'wp_', ['https://local.test' => 'https://production.example.com']);
    }
}
