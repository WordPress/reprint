<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


require_once __DIR__ . '/class-database-push-archive.php';

/** One caller-stepped preparation, upload, and import lifecycle. Never commits. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client library class, not a WordPress plugin API.
class DatabasePushProcessor {
    /** @var MultipartPushStreamClient */
    private $client;
    /** @var string */
    private $state_dir;
    /** @var array<string,mixed> */
    private $state;
    /** @var array<string,mixed> */
    private $source;
    /** @var array<string,string> */
    private $url_mapping;
    /** @var string */
    private $table_prefix;
    /** @var DatabasePushArchive|null */
    private $archive;
    /** @var resource|null */
    private $input;
    /** @var resource|null */
    private $lock;
    /** @var bool */
    private $request_open = false;
    /** @var int */
    private $offset = 0;
    /** @var int */
    private $total_bytes = 0;
    /** @var string */
    private $phase = 'creating';
    /** @var array<string,mixed> */
    private $result = [];
    /** @var string|null */
    private $failure_detail;

    /**
     * @param array<string,mixed> $source Local connection settings described by the constructor.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     */
    public static function start(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping): self {
        if (is_file($state_dir . '/state.json')) {
            throw new RuntimeException('Database push state already exists; resume it instead of starting another push.');
        }
        return new self($client, $state_dir, $source, $table_prefix, $url_mapping);
    }

    /**
     * @param array<string,mixed> $source Local connection settings described by the constructor.
     * @param array<string,string> $url_mapping The original URL mapping.
     */
    public static function resume(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping): self {
        if (!is_file($state_dir . '/state.json')) {
            throw new RuntimeException('No database push state exists to resume.');
        }
        return new self($client, $state_dir, $source, $table_prefix, $url_mapping);
    }

    /**
     * @param MultipartPushStreamClient $client Authenticated streaming client.
     * @param string $state_dir Private directory dedicated to this database push.
     * @param array $source {
     *     Dedicated local MySQL or SQLite connection settings. The password is not saved.
     *     @type string $dsn mysql: or mysql-on-sqlite: connection string.
     *     @type string $user MySQL username, empty for SQLite.
     *     @type string $pass MySQL password, empty for SQLite.
     * }
     * @param string $table_prefix Identical local and hosted site table prefix.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     */
    private function __construct(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping) {
        if (!isset($source['dsn'], $source['user'], $source['pass']) || ( strpos($source['dsn'], 'mysql:') !== 0 && strpos($source['dsn'], 'mysql-on-sqlite:') !== 0 )) {
            throw new InvalidArgumentException('Database push requires a mysql: or mysql-on-sqlite: source DSN and user/pass settings.');
        }
        if (!is_dir($state_dir) && !mkdir($state_dir, 0700, true)) {
            throw new RuntimeException('Cannot create database push state directory: ' . $state_dir);
        }
        $this->lock = fopen($state_dir . '/lock', 'c+b');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another local database push is using this state directory.');
        }
        $this->client = $client;
        $this->state_dir = $state_dir;
        $this->source = $source;
        $this->table_prefix = $table_prefix;
        $this->url_mapping = $url_mapping;
        $identity = $source;
        unset($identity['pass']);
        $identity['table_prefix'] = $table_prefix;
        $identity['url_mapping'] = $url_mapping;
        if (is_file($state_dir . '/state.json')) {
            $this->state = json_decode(file_get_contents($state_dir . '/state.json'), true);
            if (!is_array($this->state) || $this->state['source'] !== $identity) {
                throw new RuntimeException('Database push source or URL mapping changed. Use the original settings or a new state directory.');
            }
        } else {
            $this->state = ['push_session_id' => bin2hex(random_bytes(16)), 'source' => $identity];
            $this->save_state();
        }
    }

    public function next_step(): bool {
        if (in_array($this->phase, ['ready', 'committed', 'complete', 'discarded', 'failed', 'closed'], true)) {
            return false;
        }
        try {
            switch ($this->phase) {
                case 'creating':
                    $response = $this->request('POST', 'push_db_create', ['created']);
                    $this->client->apply_reported_limits([$response['post_max_bytes'] ?? null]);
                    $this->client->set_max_part_bytes( (int) $response['max_part_bytes']);
                    $this->phase = 'checking';
                    return true;
                case 'checking':
                    $response = $this->request('GET', 'push_db_status', ['accepted']);
                    if ($response['table_prefix'] !== $this->table_prefix) {
                        throw new RuntimeException('Local table prefix ' . $this->table_prefix . ' differs from target prefix ' . $response['table_prefix'] . '.');
                    }
                    if (in_array($response['phase'], ['ready', 'committed', 'complete', 'discarded'], true)) {
                        $this->result = $response;
                        $this->phase = $response['phase'];
                        return false;
                    }
                    if (( $response['path']['state'] ?? null ) === 'complete') {
                        $this->phase = 'importing';
                    } elseif (is_file($this->state_dir . '/database.jsonl')) {
                        $this->offset = (int) ( $response['path']['accepted_bytes'] ?? 0 );
                        $this->total_bytes = filesize($this->state_dir . '/database.jsonl');
                        $this->input = fopen($this->state_dir . '/database.jsonl', 'rb');
                        if ($this->input === false || $this->offset > $this->total_bytes || fseek($this->input, $this->offset) !== 0) {
                            throw new RuntimeException('Cannot resume the prepared database archive at the target-confirmed byte offset.');
                        }
                        $this->phase = 'opening_request';
                    } else {
                        $this->phase = 'preparing';
                    }
                    return true;
                case 'preparing':
                    if ($this->archive === null) {
                        if (strpos($this->source['dsn'], 'mysql-on-sqlite:') === 0) {
                            require_once \Reprint\Importer\resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/load.php');
                            $settings = \WordPress\Reprint\Server\Utils::parse_pdo_dsn($this->source['dsn']);
                            if (empty($settings['path']) || !is_file($settings['path'])) {
                                throw new RuntimeException('Database push requires an existing SQLite source file: ' . ( $settings['path'] ?? '(missing path)' ));
                            }
                            // Opening the source must not create a database or
                            // upgrade its metadata as a side effect of exporting.
                            $sqlite = new PDO('sqlite:' . $settings['path'], null, null, [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                ( defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS ) => ( defined('Pdo\\Sqlite::OPEN_READONLY') ? constant('Pdo\\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY ),
                            ]);
                            $database = new WP_PDO_MySQL_On_SQLite($this->source['dsn'], null, null, ['pdo' => $sqlite]);
                        } else {
                            $database = \WordPress\Reprint\Server\Utils::connect_mysql($this->source['dsn'], $this->source['user'], $this->source['pass']);
                            $database->exec('SET NAMES utf8mb4');
                        }
                        $this->archive = new DatabasePushArchive($database, $this->state_dir . '/database.jsonl', $this->table_prefix, $this->url_mapping, $this->state['push_session_id']);
                        return true;
                    }
                    if (!$this->archive->next_step()) {
                        $this->archive->close();
                        $this->archive = null;
                        $this->phase = 'checking';
                    }
                    return true;
                case 'opening_request':
                    if (!$this->client->start_upload_request($this->state['push_session_id'], 'push_db_upload')) {
                        throw new RuntimeException($this->client->get_last_error());
                    }
                    $this->request_open = true;
                    $this->phase = 'uploading';
                    return true;
                case 'uploading':
                    $maximum = $this->client->next_file_body_bytes('database.jsonl', $this->total_bytes, $this->offset);
                    if ($this->offset === $this->total_bytes || $maximum === 0 || $this->client->should_finish_request()) {
                        $this->phase = 'finishing_request';
                        return true;
                    }
                    $chunk = fread($this->input, $maximum);
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Cannot read the next prepared database archive chunk.');
                    }
                    if (!$this->client->send_part(['type' => 'file', 'path' => 'database.jsonl', 'total_bytes' => $this->total_bytes, 'offset' => $this->offset, 'payload' => $chunk])) {
                        $this->phase = 'finishing_request';
                        return true;
                    }
                    $this->offset += strlen($chunk);
                    return true;
                case 'finishing_request':
                    $result = $this->client->finish_request();
                    $this->request_open = false;
                    $this->state['request_sizer'] = $this->client->get_request_sizer_state();
                    $this->save_state();
                    if ($result['status'] !== 'complete') {
                        throw new RuntimeException($result['detail'] ?? 'Database archive upload failed. Run the command again to resume.');
                    }
                    fclose($this->input);
                    $this->input = null;
                    // Never advance from bytes consumed by cURL. Ask the target
                    // for its confirmed file cursor, including after interruption.
                    $this->phase = 'checking';
                    return true;
                case 'importing':
                    $response = $this->request('POST', 'push_db_import', ['accepted']);
                    if ($response['phase'] === 'ready') {
                        $this->result = $response;
                        $this->phase = 'ready';
                        return false;
                    }
                    return true;
            }
            throw new RuntimeException('Unknown database push phase: ' . $this->phase);
        } catch (Throwable $exception) {
            $this->phase = 'failed';
            $this->failure_detail = $exception->getMessage();
            $this->cancel();
            throw $exception;
        }
    }

    /** @return array<string,mixed> Current phase and target review details when ready. */
    public function get_status(): array {
        $terminal = in_array($this->phase, ['ready', 'committed', 'complete', 'discarded'], true);
        return [
            'status' => $terminal ? 'complete' : ( in_array($this->phase, ['failed', 'closed'], true) ? 'failed' : 'in_progress' ),
            'reason' => $this->failure_detail !== null ? 'database_push_failed' : ( $this->phase === 'closed' ? 'cancelled' : null ),
            'detail' => $this->failure_detail,
            'phase' => $this->phase,
            'push_session_id' => $this->state['push_session_id'],
        ] + $this->result;
    }

    public function cancel(): void {
        if ($this->request_open) {
            $this->client->cancel_request();
            $this->request_open = false;
        }
        if (!in_array($this->phase, ['ready', 'committed', 'complete', 'failed'], true)) {
            $this->phase = 'closed';
        }
    }

    public function close(): void {
        $this->client->close();
        $this->request_open = false;
        if (!in_array($this->phase, ['ready', 'committed', 'complete', 'discarded', 'failed'], true)) {
            $this->phase = 'closed';
        }
        if ($this->archive !== null) {
            $this->archive->close();
            $this->archive = null;
        }
        if (is_resource($this->input)) {
            fclose($this->input);
            $this->input = null;
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** @param list<string> $statuses Accepted protocol results. @return array<string,mixed> */
    private function request(string $method, string $endpoint, array $statuses): array {
        $result = $this->client->send_push_request($method, $endpoint, ['push_session_id' => $this->state['push_session_id']], $statuses);
        if ($result['status'] !== 'complete') {
            throw new RuntimeException($result['detail'] ?? 'Database push request failed.');
        }
        return $result['response'];
    }

    private function save_state(): void {
        $json = json_encode($this->state, JSON_THROW_ON_ERROR);
        if (file_put_contents($this->state_dir . '/state.json.swap', $json) !== strlen($json)
            || !rename($this->state_dir . '/state.json.swap', $this->state_dir . '/state.json')) {
            throw new RuntimeException('Cannot persist database push state.');
        }
    }
}
