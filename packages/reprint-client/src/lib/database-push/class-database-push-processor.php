<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


require_once __DIR__ . '/class-database-push-source.php';

/** One caller-stepped source and upload lifecycle. Never commits. */
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
    /** @var DatabasePushSource|null */
    private $reader;
    /** @var string|null One encoded record, kept across request boundaries. */
    private $record;
    /** @var int */
    private $record_number = 0;
    /** @var array<string,mixed>|null */
    private $source_cursor;
    /** @var resource|null */
    private $lock;
    /** @var bool */
    private $request_open = false;
    /** @var int */
    private $offset = 0;
    /** @var string */
    private $phase = 'creating';
    /** @var array<string,mixed> */
    private $result = [];
    /** @var string|null */
    private $failure_detail;

    /**
     * @param array<string,mixed> $source Local connection settings described by the constructor.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     * @param list<string> $extra_tables Explicit extra tables outside the prefix.
     */
    public static function start(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping, array $extra_tables = []): self {
        if (is_file($state_dir . '/state.json')) {
            throw new RuntimeException('Database push state already exists; resume it instead of starting another push.');
        }
        return new self($client, $state_dir, $source, $table_prefix, $url_mapping, $extra_tables);
    }

    /**
     * @param array<string,mixed> $source Local connection settings described by the constructor.
     * @param array<string,string> $url_mapping The original URL mapping.
     * @param list<string> $extra_tables The original explicit table selection.
     */
    public static function resume(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping, array $extra_tables = []): self {
        if (!is_file($state_dir . '/state.json')) {
            throw new RuntimeException('No database push state exists to resume.');
        }
        return new self($client, $state_dir, $source, $table_prefix, $url_mapping, $extra_tables);
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
     * @param list<string> $extra_tables Explicit extra tables outside the prefix.
     */
    private function __construct(MultipartPushStreamClient $client, string $state_dir, array $source, string $table_prefix, array $url_mapping, array $extra_tables) {
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
        $identity['extra_tables'] = \WordPress\Reprint\Server\DatabasePush::normalize_extra_tables($extra_tables, $table_prefix);
        if (is_file($state_dir . '/state.json')) {
            $this->state = json_decode(file_get_contents($state_dir . '/state.json'), true);
            if (!is_array($this->state) || $this->state['source'] !== $identity) {
                throw new RuntimeException('Database push source, table selection, or URL mapping changed. Use the original settings or a new state directory.');
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
                    $this->record_number = (int) $response['records'];
                    $this->source_cursor = $response['cursor'];
                    $this->phase = 'preparing';
                    return true;
                case 'preparing':
                    if ($this->reader === null) {
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
                        $this->reader = new DatabasePushSource($database, $this->table_prefix, $this->url_mapping, $this->state['push_session_id'], $this->source_cursor, $this->state['tables'] ?? null, $this->state['source']['extra_tables']);
                        if (!isset($this->state['tables'])) {
                            $this->state['tables'] = $this->reader->get_tables();
                            $this->save_state();
                        }
                        return true;
                    }
                    if (!$this->reader->next_step()) {
                        $this->phase = 'finishing_request';
                        return true;
                    }
                    $record = $this->reader->get_record();
                    if ($record !== null) {
                        $this->record = json_encode($record, JSON_THROW_ON_ERROR);
                        if (strlen($this->record) > \WordPress\Reprint\Server\DatabasePush::MAX_RECORD_BYTES) {
                            throw new RuntimeException('Prepared database record exceeds the 2 MiB record limit. Live tables have not been changed.');
                        }
                        $this->offset = 0;
                        $this->phase = $this->request_open ? 'uploading' : 'opening_request';
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
                    $total_bytes = strlen($this->record);
                    $maximum = $this->client->next_database_body_bytes($this->record_number, $total_bytes, $this->offset);
                    if ($maximum === 0 || $this->client->should_finish_request()) {
                        $this->phase = 'finishing_request';
                        return true;
                    }
                    $chunk = substr($this->record, $this->offset, $maximum);
                    if (!$this->client->send_part(['type' => 'database', 'record_number' => $this->record_number, 'total_bytes' => $total_bytes, 'offset' => $this->offset, 'payload' => $chunk])) {
                        $this->phase = 'finishing_request';
                        return true;
                    }
                    $this->offset += strlen($chunk);
                    if ($this->offset === $total_bytes) {
                        ++$this->record_number;
                        $this->record = null;
                        $this->phase = 'preparing';
                    }
                    return true;
                case 'finishing_request':
                    $result = $this->client->finish_request();
                    $this->request_open = false;
                    $this->state['request_sizer'] = $this->client->get_request_sizer_state();
                    $this->save_state();
                    if ($result['status'] !== 'complete') {
                        throw new RuntimeException($result['detail'] ?? 'Database streaming upload failed. Run the command again to resume.');
                    }
                    $response = $result['response'];
                    // Written bytes alone are not confirmed. On a failed or
                    // lost response this run stops; resume reads the target's
                    // source cursor and re-reads only unconfirmed source data.
                    if ( (int) $response['records'] !== $this->record_number) {
                        throw new RuntimeException('Target confirmed ' . $response['records'] . ' database records; expected ' . $this->record_number . '. Resume from its source cursor.');
                    }
                    $expected_partial_bytes = $this->record === null ? 0 : $this->offset;
                    if ( (int) $response['partial_bytes'] !== $expected_partial_bytes) {
                        throw new RuntimeException('Target confirmed ' . $response['partial_bytes'] . ' partial record bytes; expected ' . $expected_partial_bytes . '. Resume from its source cursor.');
                    }
                    if ($response['phase'] === 'ready') {
                        $this->result = $response;
                        $this->phase = 'ready';
                        return false;
                    }
                    $this->phase = $this->record === null ? 'preparing' : 'opening_request';
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
        $this->record = null;
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
        if ($this->reader !== null) {
            $this->reader->close();
            $this->reader = null;
        }
        $this->record = null;
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** @param list<string> $statuses Accepted protocol results. @return array<string,mixed> */
    private function request(string $method, string $endpoint, array $statuses): array {
        $parameters = ['push_session_id' => $this->state['push_session_id']];
        if ($endpoint === 'push_db_create') {
            $parameters['extra_tables'] = json_encode($this->state['source']['extra_tables'], JSON_THROW_ON_ERROR);
        }
        $result = $this->client->send_push_request($method, $endpoint, $parameters, $statuses);
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
