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
        $this->server = proc_open([PHP_BINARY, '-d', 'post_max_size=2M', '-S', $address, __DIR__ . '/../fixtures/database-push-router.php'], [['pipe', 'r'], ['file', $this->root . '/server.log', 'a'], ['file', $this->root . '/server.log', 'a']], $pipes, null, $environment);
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

    public function testSourceRowLimitFailsBeforeUploadAndKeepsSourceUnchanged(): void {
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
            self::assertStringNotContainsString('push_db_upload', file_get_contents($this->root . '/requests'));
            self::assertSame(1048577, (int) $this->pdo->query('SELECT LENGTH(value) FROM wp_options')->fetchColumn());
        } finally {
            $processor->close();
            $client->close();
        }
    }

    public function testCliStagesReviewsDiscardsAndLeavesProductionUnchanged(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, 'https://local.test/')");
        $arguments = [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'db-push', $this->remote_reprint_api_url,
            '--state-dir=' . $this->root . '/cli-state', '--secret=database-push-test-secret', '--force-http'];
        $source = ['--source-dsn=mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, '--source-user=' . getenv('DB_USER'), '--source-pass=' . getenv('DB_PASS'), '--rewrite-url', 'https://local.test', 'https://production.example.com'];
        $staged = $this->runCli(array_merge($arguments, $source));
        self::assertSame('ready', $staged['phase']);
        self::assertSame(['wp_options', 'wp_orders'], $staged['replace_tables']);
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        $discarded = $this->runCli(array_merge($arguments, ['--abort']));
        self::assertSame('discarded', $discarded['phase']);
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        $remaining = $this->receiver->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $remaining); // Two live tables and one terminal progress marker.
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
            $retry = $client->send_push_request('POST', 'push_db_import', $parameters, ['accepted']);
            self::assertSame('failed', $retry['status']);
            $after = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            self::assertSame($before['response']['offset'], $after['response']['offset']);
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

    public function testProcessDeathWithOpenRequestResumesFromReceiverBytes(): void {
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
        // can have target-confirmed bytes on this actual server.
        try {
            while (($line = fgets($pipes[1])) !== false) {
                $phase = trim($line);
                if ($phase === 'checking' && $previous_phase === 'finishing_request') {
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
            self::assertSame('partial', $remote['response']['path']['state'], json_encode($remote));
            self::assertGreaterThan(0, $remote['response']['path']['accepted_bytes']);
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
            $retry = $client->send_push_request('POST', 'push_db_import', $parameters, ['accepted']);
            self::assertSame('failed', $retry['status']);
            $after = $client->send_push_request('GET', 'push_db_status', $parameters, ['accepted']);
            self::assertSame($before['response']['offset'], $after['response']['offset']);
            self::assertGreaterThan(0, $after['response']['offset']);
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
