<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** The server must keep label SQL for clients that cannot decode SET masks. */
class SetValueFormatHttpTest extends MySQLDumpProducerTestBase {
    private string $root;
    private string $remote_reprint_api_url;
    private $server;
    private $receiver;

    protected function setUp(): void {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reprint-set-http-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/site', 0700, true);
        file_put_contents($this->root . '/secret.php', '<?php return "database-push-test-secret";');
        $this->pdo->exec('CREATE DATABASE `' . $this->dbName . '_receiver`');
        $this->receiver = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . '_receiver;charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
        self::fail('SET export HTTP server did not start.');
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

    /** @dataProvider clientFormatProvider */
    public function testSetFormatMatchesTheRequestingClient(bool $unsigned): void {
        $this->receiver->exec("CREATE TABLE wp_sets (id INT PRIMARY KEY, flags SET('', 'a', 'b'), payload LONGTEXT) ENGINE=InnoDB");
        $this->receiver->exec("INSERT INTO wp_sets VALUES (1,0,''), (2,1,''), (3,6,''), (4,NULL,'')");
        $client = new ImportClient($this->remote_reprint_api_url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        $request = (new ReflectionMethod($client, 'build_request'))->invoke($client, 'sql_chunk', null);
        $params = $request['params'];
        if (!$unsigned) {
            // Old clients never advertised SET masks and executed the SQL
            // without a mask-to-label rewriter. Keep that wire format usable.
            unset($params['set_value_format']);
        }
        $body = http_build_query($params);
        $curl = curl_init($request['url']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => (new Site_Export_HMAC_Client('database-push-test-secret'))->get_curl_headers($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 10,
        ]);
        try {
            $response = curl_exec($curl);
            self::assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $response);
            self::assertMatchesRegularExpression('/boundary="([^"]+)"/', curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
            preg_match('/boundary="([^"]+)"/', curl_getinfo($curl, CURLINFO_CONTENT_TYPE), $matches);
        } finally {
            curl_close($curl);
        }
        $sql = '';
        $parser = new \Reprint\Importer\Protocol\MultipartStreamParser($matches[1], static function (array $event) use (&$sql): void {
            if ($event['type'] === 'body' && ($event['headers']['content-type'] ?? '') === 'application/sql') {
                $sql .= $event['data'];
            }
        });
        $parser->feed($response);
        self::assertNotSame('', $sql);
        $sqlite = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
        $connection = new \Reprint\Importer\Database\PdoDatabaseConnection($sqlite, $sqlite->get_connection()->get_pdo());
        try {
            if ($unsigned) {
                // A MySQL target must still distinguish masks 0 and 1 even
                // though both have the same displayed label.
                $target = $this->executeDumpInNewDatabase($sql);
                self::assertSame(['0', '1', '6', null], $target->query('SELECT CAST(CAST(flags AS UNSIGNED) AS CHAR) FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
                (new ReflectionMethod($client, 'create_database_import_position_table'))->invoke($client, $connection);
                (new ReflectionMethod($client, 'execute_database_import_group'))->invoke($client, $connection, $sql, hash('sha256', 'set-http'), 'next-cursor', null, 'sqlite');
            } else {
                $stream = new \WP_MySQL_FastQueryStream();
                $stream->append_sql($sql);
                $stream->mark_input_complete();
                while ($stream->next_query()) {
                    $sqlite->exec($stream->get_query());
                }
            }
            self::assertSame(['', '', 'a,b', null], $sqlite->query('SELECT flags FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $connection->close();
        }
    }

    public static function clientFormatProvider(): array {
        return ['old client' => [false], 'new client' => [true]];
    }
}
