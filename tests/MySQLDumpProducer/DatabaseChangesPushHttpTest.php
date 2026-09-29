<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Real authenticated requests: any rejected row must undo earlier row changes. */
class DatabaseChangesPushHttpTest extends MySQLDumpProducerTestBase {
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


    public function testMixedChangesLeaveProductionOnlyRowsIntact(): void {
        $this->receiver->exec("INSERT INTO wp_options VALUES (2, 'remove'), (99, 'live-only')");
        $result = $this->push([
            $this->table('wp_options'),
            $this->change('update', 1, ['value' => 'production'], ['value' => 'local']),
            $this->change('delete', 2, ['id' => '2', 'value' => 'remove'], null),
            $this->change('insert', 3, null, ['id' => '3', 'value' => 'new']),
        ]);
        self::assertSame('accepted', $result['status'], json_encode($result));
        self::assertSame('complete', $result['phase']);
        self::assertSame([[1, 'local'], [3, 'new'], [99, 'live-only']], $this->receiver->query('SELECT * FROM wp_options ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    /** @dataProvider conflictProvider */
    public function testConflictRollsBackEarlierUpdates(string $action, int $id, ?array $before, ?array $after): void {
        $result = $this->push([
            $this->table('wp_options'),
            $this->change('update', 1, ['value' => 'production'], ['value' => 'first edit']),
            $this->change($action, $id, $before, $after),
        ]);
        self::assertSame('conflict', $result['reason'] ?? null, json_encode($result));
        self::assertSame([[1, 'production']], $this->receiver->query('SELECT * FROM wp_options')->fetchAll(PDO::FETCH_NUM));
    }

    public static function conflictProvider(): array {
        return [
            'insert existing key' => ['insert', 1, null, ['id' => '1', 'value' => 'duplicate']],
            'update missing row' => ['update', 4, ['value' => 'old'], ['value' => 'new']],
            'delete missing row' => ['delete', 4, ['id' => '4', 'value' => 'old'], null],
            'update changed column' => ['update', 1, ['value' => 'production'], ['value' => 'second edit']],
            'delete changed row' => ['delete', 1, ['id' => '1', 'value' => 'production'], null],
            'wrong insert key' => ['insert', 4, null, ['id' => '5', 'value' => 'wrong key']],
        ];
    }

    public function testOtherProductionColumnMayChangeAndNoOpUpdateIsNotMissing(): void {
        $this->receiver->exec("ALTER TABLE wp_options ADD note text; UPDATE wp_options SET note='live note'");
        $result = $this->push([
            $this->table('wp_options'),
            $this->change('update', 1, ['value' => 'production'], ['value' => 'production']),
        ]);
        self::assertSame('accepted', $result['status'], json_encode($result));
        self::assertSame('live note', $this->receiver->query('SELECT note FROM wp_options')->fetchColumn());
    }

    /** @dataProvider unsafeTableProvider */
    public function testUnsafeSideEffectsAreRejectedBeforeWriting(string $setup): void {
        $this->receiver->exec($setup);
        $result = $this->push([
            $this->table('wp_options'),
            $this->change('update', 1, ['value' => 'production'], ['value' => 'local']),
        ]);
        self::assertSame('rejected', $result['status'], json_encode($result));
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
    }

    public static function unsafeTableProvider(): array {
        return [
            'nontransactional table' => ['ALTER TABLE wp_options ENGINE=MyISAM'],
            'trigger' => ['CREATE TRIGGER side_effect BEFORE UPDATE ON wp_options FOR EACH ROW SET NEW.value=\'triggered\''],
            'cascade' => ['CREATE TABLE child (id int PRIMARY KEY, parent int, FOREIGN KEY(parent) REFERENCES wp_options(id) ON DELETE CASCADE) ENGINE=InnoDB'],
        ];
    }

    public function testConstraintErrorRollsBackBothTables(): void {
        $this->receiver->exec("CREATE TABLE plugin_data (id int PRIMARY KEY, value varchar(8) UNIQUE) ENGINE=InnoDB; INSERT INTO plugin_data VALUES(10, 'existing')");
        $result = $this->push([
            $this->table('wp_options'),
            $this->change('update', 1, ['value' => 'production'], ['value' => 'first edit']),
            $this->table('plugin_data'),
            $this->change('insert', 11, null, ['id' => '11', 'value' => 'existing'], 'plugin_data'),
        ]);
        self::assertSame('conflict', $result['reason'] ?? null, json_encode($result));
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        self::assertSame(1, (int) $this->receiver->query('SELECT COUNT(*) FROM plugin_data')->fetchColumn());
    }

    public function testMissingEndRecordRollsBack(): void {
        $result = $this->push([$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local'])], false);
        self::assertSame('rejected', $result['status'], json_encode($result));
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
    }

    public function testReceiptAndReplayDoNotReapplyCommittedRows(): void {
        $records = [$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local'])];
        $result = $this->push($records);
        self::assertSame('accepted', $result['status'], json_encode($result));
        $this->receiver->exec("UPDATE wp_options SET value='later live edit'");
        $result = $this->push($records);
        self::assertSame('accepted', $result['status'], json_encode($result));
        self::assertSame('later live edit', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
    }

    public function testCliSelectedDiffRewritesBothSidesAndKeepsUnselectedRows(): void {
        $ddl = 'CREATE TABLE plugin_posts (id bigint unsigned PRIMARY KEY, title text, url text, note text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($ddl);
        $this->receiver->exec($ddl);
        $this->pdo->exec("INSERT INTO plugin_posts VALUES (1,'old','https://local.test/old','unchanged'), (2,'remove',NULL,NULL), (4,'do not push',NULL,NULL)");
        $this->receiver->exec("INSERT INTO plugin_posts VALUES (1,'old','https://live.test/old','production edit'), (2,'remove',NULL,NULL), (4,'do not push',NULL,NULL), (99,'live-only',NULL,NULL)");
        $this->cli('db-baseline', ['--table=plugin_posts']);
        $this->pdo->exec("UPDATE plugin_posts SET title='new',url='https://local.test/new' WHERE id=1; DELETE FROM plugin_posts WHERE id=2; UPDATE plugin_posts SET title='not selected' WHERE id=4; INSERT INTO plugin_posts VALUES (3,'insert','https://local.test/new',NULL)");
        $diff = array_values(array_filter($this->cli('db-diff'), static function ($record) { return ($record['type'] ?? '') === 'database_change' && $record['key']['id'] !== base64_encode('4'); }));
        $file = $this->root . '/changes.jsonl';
        file_put_contents($file, implode('', array_map(static function ($record) { return json_encode($record) . "\n"; }, $diff)));
        $options = ['--changes=' . $file, '--rewrite-url', 'https://local.test', 'https://live.test'];
        $review = $this->cli('db-push-changes', $options)[0];
        self::assertSame(['insert' => 1, 'update' => 1, 'delete' => 1], $review['tables']['plugin_posts']);
        self::assertFileDoesNotExist($this->root . '/requests', 'Review must not contact production.');
        $result = $this->cli('db-push-changes', array_merge($options, ['--commit=' . $review['review'], '--secret=database-push-test-secret']));
        self::assertSame('complete', $result[1]['phase'], json_encode($result));
        self::assertSame([[1,'new','https://live.test/new','production edit'], [3,'insert','https://live.test/new',null], [4,'do not push',null,null], [99,'live-only',null,null]], $this->receiver->query('SELECT * FROM plugin_posts ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        $this->receiver->exec("UPDATE plugin_posts SET title='later live edit' WHERE id=1");
        $this->cli('db-push-changes', array_merge($options, ['--commit=' . $review['review'], '--secret=database-push-test-secret']));
        self::assertSame('later live edit', $this->receiver->query('SELECT title FROM plugin_posts WHERE id=1')->fetchColumn());
        self::assertSame('https://local.test/new', $this->pdo->query('SELECT url FROM plugin_posts WHERE id=1')->fetchColumn());
    }

    public function testCliRoundTripsBinaryCompositeKeysAndMysqlTypes(): void {
        $ddl = "CREATE TABLE typed_rows (id bigint unsigned, segment varbinary(8), bytes blob, amount float, exact_value decimal(30,10), flags SET('','a'), choice ENUM('','0','a'), latin varchar(12) CHARACTER SET latin1, counter int, doubled int GENERATED ALWAYS AS (counter*2) STORED, PRIMARY KEY(id,segment)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->pdo->exec($ddl);
        $this->receiver->exec($ddl);
        foreach ([$this->pdo, $this->receiver] as $database) {
            $database->exec("INSERT INTO typed_rows (id,segment,bytes,amount,exact_value,flags,choice,latin,counter) VALUES (18446744073709551615,X'00FF',X'00FE',1.234567,12345678901234567890.1234567890,0,2,X'E9',3)");
        }
        $this->cli('db-baseline', ['--table=typed_rows']);
        $this->pdo->exec("UPDATE typed_rows SET bytes=X'00FF00',amount=1.234568,flags=1,choice=1,latin=X'E9E9',counter=4");
        $this->push_cli_diff();
        $sql = 'SELECT CAST(id AS CHAR),HEX(segment),HEX(bytes),CAST(amount+0e0 AS CHAR),CAST(exact_value AS CHAR),CAST(flags AS UNSIGNED),CAST(choice AS UNSIGNED),HEX(latin),counter,doubled FROM typed_rows';
        self::assertSame($this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM), $this->receiver->query($sql)->fetchAll(PDO::FETCH_NUM));
    }

    /** @dataProvider largeSelectionProvider */
    public function testCliStreamsLargeSelectionsUnder64MiB(int $rows, int $payload_bytes): void {
        $ddl = 'CREATE TABLE plugin_rows (id int PRIMARY KEY, payload longblob) ENGINE=InnoDB';
        $this->pdo->exec($ddl);
        $this->receiver->exec($ddl);
        $this->cli('db-baseline', ['--table=plugin_rows']);
        $insert = $this->pdo->prepare('INSERT INTO plugin_rows VALUES (?,?)');
        for ($id = 0; $id < $rows; ++$id) {
            $insert->execute([$id, str_repeat(chr($id % 256), $payload_bytes)]);
        }
        $this->push_cli_diff();
        self::assertSame($rows, (int) $this->receiver->query('SELECT COUNT(*) FROM plugin_rows')->fetchColumn());
        self::assertSame($rows * $payload_bytes, (int) $this->receiver->query('SELECT SUM(OCTET_LENGTH(payload)) FROM plugin_rows')->fetchColumn());
        self::assertSame($this->pdo->query('SELECT id, SHA2(payload,256) FROM plugin_rows ORDER BY id')->fetchAll(PDO::FETCH_NUM), $this->receiver->query('SELECT id, SHA2(payload,256) FROM plugin_rows ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public static function largeSelectionProvider(): array {
        return ['many rows' => [3000, 12], 'many multipart parts' => [10, 80 * 1024]];
    }

    public function testAutomaticTimestampDoesNotChangeAnUnselectedColumn(): void {
        $this->receiver->exec("ALTER TABLE wp_options ADD modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP; UPDATE wp_options SET modified='2001-01-01 00:00:00'");
        $result = $this->push([$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local'])]);
        self::assertSame('accepted', $result['status'], json_encode($result));
        self::assertSame('2001-01-01 00:00:00', $this->receiver->query('SELECT modified FROM wp_options')->fetchColumn());
    }

    private function push_cli_diff(): void {
        $diff = $this->cli('db-diff');
        $file = $this->root . '/changes.jsonl';
        file_put_contents($file, implode('', array_map(static function ($record) { return json_encode($record) . "\n"; }, $diff)));
        $review = $this->cli('db-push-changes', ['--changes=' . $file])[0];
        $result = $this->cli('db-push-changes', ['--changes=' . $file, '--commit=' . $review['review'], '--secret=database-push-test-secret']);
        self::assertSame('complete', $result[1]['phase'], json_encode($result));
    }

    private function cli(string $command, array $options = [], int $expected_exit = 0): array {
        $arguments = [PHP_BINARY, '-d', 'memory_limit=64M', getenv('REPRINT_SELECTIVE_CLIENT') ?: __DIR__ . '/../../packages/reprint-client/bin/reprint-client', $command, $this->remote_reprint_api_url, '--state-dir=' . $this->root . '/state', '--progress=jsonl', '--allow-unsafe-http'];
        if ($command !== 'db-push-changes') {
            $arguments = array_merge($arguments, ['--source-dsn=mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . ';charset=utf8mb4', '--source-user=' . getenv('DB_USER'), '--source-pass=' . getenv('DB_PASS')]);
        }
        $process = proc_open(array_merge($arguments, $options), [['pipe', 'r'], ['pipe', 'w'], ['file', $this->root . '/cli.log', 'a']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        self::assertSame($expected_exit, $code, $output . "\n" . file_get_contents($this->root . '/cli.log'));
        return array_map(static function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, explode("\n", trim($output)));
    }

    public function testProductionRowsStayLockedUntilCommitAndProcessDeathRollsBack(): void {
        $this->receiver->exec("INSERT INTO wp_options VALUES(2,'blocked')");
        $records = [$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local']), $this->change('update', 2, ['value' => 'blocked'], ['value' => 'second'])];
        $this->receiver->beginTransaction();
        $this->receiver->exec("UPDATE wp_options SET value='blocked' WHERE id=2");
        $socket = $this->send_without_waiting($records);
        $observer = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . '_receiver', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $observer->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        $deadline = microtime(true) + 3;
        do {
            $value = $observer->query('SELECT value FROM wp_options WHERE id=1')->fetchColumn();
            if ($value === 'local') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        try {
            self::assertSame('local', $value, 'Observe the first uncommitted write while the second row waits on a real production writer.');
            $observer->exec('SET innodb_lock_wait_timeout=1');
            try {
                $observer->exec("UPDATE wp_options SET value='concurrent' WHERE id=1");
                self::fail('A concurrent writer must wait for the push transaction.');
            } catch (PDOException $exception) {
                self::assertSame(1205, $exception->errorInfo[1]);
            }
            proc_terminate($this->server, 9);
        } finally {
            $this->receiver->rollBack();
            fclose($socket);
        }
        self::assertSame([[1,'production'],[2,'blocked']], $this->receiver->query('SELECT * FROM wp_options ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(0, (int) $this->receiver->query('SELECT COUNT(*) FROM __reprint_db_change_receipts')->fetchColumn());
    }

    public function testLostSuccessResponseCanBeResolvedWithoutReapplying(): void {
        $records = [$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local'])];
        $socket = $this->send_without_waiting($records);
        fclose($socket); // All request bytes left; deliberately do not read the response.
        $deadline = microtime(true) + 3;
        do {
            $value = $this->receiver->query('SELECT value FROM wp_options')->fetchColumn();
            if ($value === 'local') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertSame('local', $value);
        $this->receiver->exec("UPDATE wp_options SET value='later live edit'");
        $result = $this->push($records);
        self::assertSame('complete', $result['phase'], json_encode($result));
        self::assertSame('later live edit', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
    }

    /** @dataProvider incompleteRequestProvider */
    public function testIncompleteOrChangedReviewRollsBack(string $damage): void {
        $records = [$this->table('wp_options'), $this->change('update', 1, ['value' => 'production'], ['value' => 'local'])];
        [$url, $headers, $body] = $this->request($records);
        if ($damage === 'multipart') {
            $body = substr($body, 0, -strlen("--selective-push-test--\r\n"));
        } elseif ($damage === 'review') {
            $body = str_replace(base64_encode('local'), base64_encode('other'), $body);
        } else {
            $body .= 'unexpected trailer';
        }
        $result = $this->request_result($url, $headers, $body);
        self::assertSame('rejected', $result['status'], json_encode($result));
        self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        self::assertSame(0, (int) $this->receiver->query('SELECT COUNT(*) FROM __reprint_db_change_receipts')->fetchColumn());
    }

    public static function incompleteRequestProvider(): array {
        return [['multipart'], ['review'], ['trailer']];
    }

    private function send_without_waiting(array $records) {
        [$url, $headers, $body] = $this->request($records);
        $address = parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT);
        $socket = stream_socket_client('tcp://' . $address);
        $request = 'POST /?' . parse_url($url, PHP_URL_QUERY) . " HTTP/1.1\r\nHost: " . $address . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
        self::assertSame(strlen($request), fwrite($socket, $request));
        return $socket;
    }

    public function testHiddenCrossDatabaseCascadeCannotDeleteUnselectedRows(): void {
        $other_database = $this->dbName . '_hidden_child';
        $user = 'reprint_metadata_' . bin2hex(random_bytes(5));
        $this->pdo->exec('CREATE DATABASE `' . $other_database . '`');
        $this->pdo->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY 'metadata-test-password'");
        try {
            $this->pdo->exec('CREATE TABLE `' . $other_database . '`.child (id int PRIMARY KEY, parent int, FOREIGN KEY(parent) REFERENCES `' . $this->dbName . '_receiver`.wp_options(id) ON DELETE CASCADE) ENGINE=InnoDB');
            $this->pdo->exec('INSERT INTO `' . $other_database . '`.child VALUES (9,1)');
            $this->pdo->exec("GRANT ALL ON `" . $this->dbName . "_receiver`.* TO '" . $user . "'@'%'");
            $limited = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . '_receiver', $user, 'metadata-test-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::assertSame(0, (int) $limited->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='wp_options'")->fetchColumn(), 'The production account genuinely cannot see this incoming constraint.');
            $records = [$this->table('wp_options'), $this->change('delete', 1, ['id' => '1', 'value' => 'production'], null), ['type' => 'end']];
            $lines = array_map(static function ($record) { return json_encode($record) . "\n"; }, $records);
            $review = hash('sha256', implode('', $lines));
            array_unshift($lines, json_encode(['type' => 'begin', 'review' => $review]) . "\n");
            $push = new WordPress\Reprint\Server\DatabaseChangesPush($limited, substr($review, 0, 32));
            $failure = null;
            try {
                foreach ($lines as $number => $line) {
                    $push->accept_record_chunk($number, strlen($line), 0, $line);
                }
                $push->finish_record_request();
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            } finally {
                $push->close();
            }
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $other_database . '`.child')->fetchColumn(), 'A hidden cascading constraint must not remove the unselected child row.');
            self::assertNotNull($failure);
            self::assertStringContainsString('REFERENCES', $failure);
            self::assertSame('production', $this->receiver->query('SELECT value FROM wp_options')->fetchColumn());
        } finally {
            $this->pdo->exec('DROP DATABASE `' . $other_database . '`');
            $this->pdo->exec("DROP USER '" . $user . "'@'%'");
        }
    }

    public function testCliConflictIsReportedAndTheWholeSelectionRollsBack(): void {
        $ddl = 'CREATE TABLE selected_rows (id int PRIMARY KEY, value text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        foreach ([$this->pdo, $this->receiver] as $database) {
            $database->exec($ddl);
            $database->exec("INSERT INTO selected_rows VALUES (1,'old'),(2,'old')");
        }
        $this->cli('db-baseline', ['--table=selected_rows']);
        $this->pdo->exec("UPDATE selected_rows SET value='local'");
        $this->receiver->exec("UPDATE selected_rows SET value='production' WHERE id=2");
        $file = $this->root . '/changes.jsonl';
        file_put_contents($file, implode('', array_map(static function ($record) { return json_encode($record) . "\n"; }, $this->cli('db-diff'))));
        $review = $this->cli('db-push-changes', ['--changes=' . $file])[0];
        $result = $this->cli('db-push-changes', ['--changes=' . $file, '--commit=' . $review['review'], '--secret=database-push-test-secret'], 1);
        self::assertSame('conflict', $result[1]['error_code']);
        self::assertSame([[1,'old'],[2,'production']], $this->receiver->query('SELECT * FROM selected_rows ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public function testCliRequestLimitNeverSplitsSelectionIntoPartialCommits(): void {
        $ddl = 'CREATE TABLE large_selection (id int PRIMARY KEY, payload longblob) ENGINE=InnoDB';
        $this->pdo->exec($ddl);
        $this->receiver->exec($ddl);
        $this->cli('db-baseline', ['--table=large_selection']);
        $insert = $this->pdo->prepare('INSERT INTO large_selection VALUES (?,?)');
        for ($id = 0; $id < 25; ++$id) {
            $insert->execute([$id, str_repeat('x', 80 * 1024)]);
        }
        $file = $this->root . '/changes.jsonl';
        file_put_contents($file, implode('', array_map(static function ($record) { return json_encode($record) . "\n"; }, $this->cli('db-diff'))));
        $review = $this->cli('db-push-changes', ['--changes=' . $file])[0];
        $result = $this->cli('db-push-changes', ['--changes=' . $file, '--commit=' . $review['review'], '--secret=database-push-test-secret'], 1);
        self::assertStringContainsString('one push request', $result[1]['error']);
        self::assertSame(0, (int) $this->receiver->query('SELECT COUNT(*) FROM large_selection')->fetchColumn());
    }

    public function testMetadataLocksPreventAddingACascadeAfterValidation(): void {
        $database = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . '_receiver', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('SET unique_checks=0');
        $table = json_encode($this->table('wp_options')) . "\n";
        $review = hash('sha256', $table . json_encode(['type' => 'end']) . "\n");
        $begin = json_encode(['type' => 'begin', 'review' => $review]) . "\n";
        $push = new WordPress\Reprint\Server\DatabaseChangesPush($database, substr($review, 0, 32));
        try {
            $push->accept_record_chunk(0, strlen($begin), 0, $begin);
            self::assertSame(1, (int) $database->query('SELECT @@unique_checks')->fetchColumn());
            $push->accept_record_chunk(1, strlen($table), 0, $table);
            $this->receiver->exec('SET lock_wait_timeout=1');
            try {
                $this->receiver->exec('CREATE TABLE late_child (id int PRIMARY KEY, parent int, FOREIGN KEY(parent) REFERENCES wp_options(id) ON DELETE CASCADE) ENGINE=InnoDB');
                self::fail('Incoming FK creation must wait until the reviewed transaction ends.');
            } catch (PDOException $exception) {
                self::assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            $push->close();
        }
    }

    private function table(string $table): array {
        $columns = [];
        foreach ($this->receiver->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            unset($column['Privileges']);
            $columns[$column['Field']] = self::encode($column);
        }
        $keys = $this->receiver->query('SHOW KEYS FROM `' . $table . '` WHERE Key_name=\'PRIMARY\'')->fetchAll(PDO::FETCH_ASSOC);
        return ['type' => 'database_table', 'table' => $table, 'schema' => ['primary_key' => array_column($keys, 'Column_name'), 'columns' => $columns]];
    }

    private function change(string $action, int $id, ?array $before, ?array $after, string $table = 'wp_options'): array {
        return ['type' => 'database_change', 'table' => $table, 'action' => $action, 'key' => self::encode(['id' => $id]), 'before' => $before === null ? null : self::encode($before), 'after' => $after === null ? null : self::encode($after)];
    }

    private static function encode(array $values): array {
        return array_map(static function ($value) { return $value === null ? null : base64_encode((string) $value); }, $values);
    }

    private function push(array $records, bool $end = true): array {
        return $this->request_result(...$this->request($records, $end));
    }

    private function request(array $records, bool $end = true): array {
        if ($end) {
            $records[] = ['type' => 'end'];
        }
        $lines = array_map(static function ($record) { return json_encode($record) . "\n"; }, $records);
        $review = hash('sha256', implode('', $lines));
        array_unshift($lines, json_encode(['type' => 'begin', 'review' => $review]) . "\n");
        $boundary = 'selective-push-test';
        $body = '';
        foreach ($lines as $number => $line) {
            for ($offset = 0; $offset < strlen($line); $offset += 8192) {
                $piece = substr($line, $offset, 8192);
                $body .= '--' . $boundary . "\r\nX-Chunk-Type: database\r\nX-Record-Number: " . $number . "\r\nX-Record-Size: " . strlen($line) . "\r\nX-Chunk-Offset: " . $offset . "\r\nContent-Length: " . strlen($piece) . "\r\n\r\n" . $piece . "\r\n";
            }
        }
        $body .= '--' . $boundary . "--\r\n";
        $url = $this->remote_reprint_api_url . '?' . http_build_query(['endpoint' => 'push_db_changes', 'push_session_id' => substr($review, 0, 32)]);
        $headers = (new Site_Export_HMAC_Client('database-push-test-secret'))->get_envelope_auth_headers('POST', $url);
        $header_lines = ['Content-Type: multipart/mixed; boundary=' . $boundary, 'Expect:'];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        return [$url, $header_lines, $body];
    }

    private function request_result(string $url, array $header_lines, string $body): array {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $header_lines, CURLOPT_RETURNTRANSFER => true]);
        $response = curl_exec($curl);
        $decoded = json_decode($response, true);
        self::assertIsArray($decoded, (string) $response);
        return $decoded;
    }
}
