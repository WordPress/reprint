<?php

namespace WordPress\Reprint\Server;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/class-database-push.php';

/** Authenticated database endpoints. URL rewriting remains entirely in the client. */
final class DatabasePushEndpoints {
    /** @var array<string,mixed> */
    private $options;

    /**
     * @param array $options {
     *     Trusted host configuration, never request parameters.
     *     @type string $reprint_directory Private directory outside the web document root.
     *     @type string $docroot Actual web document root, used to validate private storage.
     *     @type list<string> $excluded_paths File-push exclusions (not database selections).
     * }
     */
    public function __construct(array $options) {
        // Reuse the established outside-document-root validation first.
        new PushEndpoints($options);
        $private_root = $options['reprint_directory'] . '/.reprint/database';
        $options['reprint_directory'] = $private_root . '/transfers';
        $options['docroot'] = $private_root . '/artifacts';
        $options['excluded_paths'] = [];
        $this->options = $options;
    }

    /**
     * @param array $config {
     *     Signed endpoint parameters; database credentials and prefix are host configuration.
     *     @type string $endpoint Registered database push endpoint.
     *     @type string $push_session_id Existing or new 32-character session ID.
     *     @type string $review Required for commit. Token from the current table review.
     *     @type string $writers_stopped Required for commit. Must be the literal yes.
     * }
     */
    public function handle(array $config): void {
        $push = null;
        try {
            $endpoint = $config['endpoint'];
            $method = $endpoint === 'push_db_status' ? 'GET' : 'POST';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compare the exact signed HTTP method; this also runs without WordPress.
            if (( $_SERVER['REQUEST_METHOD'] ?? '' ) !== $method) {
                throw new RuntimeException('Database push endpoint requires HTTP ' . $method . '.');
            }
            $credentials = \resolve_db_credentials();
            if (( $credentials['db_engine'] ?? 'mysql' ) !== 'mysql' || empty($credentials['table_prefix'])) {
                throw new RuntimeException('Database push requires a MySQL target and a trusted WordPress table prefix.');
            }
            // Native prepares keep binary row values as data, not SQL syntax.
            // Do not fall back to wpdb: transactional cursor commits need this
            // dedicated connection and its session lock.
            $database = new PDO(Utils::build_pdo_dsn($credentials['db_host'], $credentials['db_name']), $credentials['db_user'], $credentials['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $database->exec('SET NAMES utf8mb4');
            $push_session_id = $config['push_session_id'] ?? '';
            $push = new DatabasePush($database, $credentials['table_prefix'], $push_session_id);
            $uploads = new PushEndpoints($this->options);
            if ($endpoint === 'push_db_create') {
                $push->start();
                if (!is_dir($this->options['docroot']) && !mkdir($this->options['docroot'], 0700, true)) {
                    throw new RuntimeException('Cannot create private database archive storage.');
                }
                $uploads->create($config);
                return;
            }
            $state = $push->get_status();
            if ($endpoint === 'push_db_upload') {
                if ($state['phase'] !== 'importing' || $state['offset'] !== 0) {
                    throw new RuntimeException('Database archive is immutable after import starts.');
                }
                $uploads->upload($config);
                return;
            }
            $archive_status = null;
            if (!in_array($state['phase'], ['committed', 'complete', 'discarding', 'discarded'], true)) {
                $session = PushSession::open($this->options['reprint_directory'], $this->options['docroot'], $push_session_id, []);
                $archive_status = $session->get_status('database.jsonl')['path'];
                if ($endpoint === 'push_db_import') {
                    if (( $archive_status['state'] ?? null ) !== 'complete' || ( $archive_status['type'] ?? null ) !== 'file') {
                        throw new RuntimeException('Database import requires the completed database.jsonl archive.');
                    }
                    // The HTTP caller supplies a small request budget; each
                    // processor call applies one bounded archive record.
                    $deadline = microtime(true) + 2;
                    for ($records = 0; $records < 128; ++$records) {
                        $push->import_next_record($session->get_push_directory() . '/work/files/database.jsonl');
                        if ($push->get_status()['phase'] !== 'importing' || microtime(true) >= $deadline) {
                            break;
                        }
                    }
                }
            }
            if ($endpoint === 'push_db_commit') {
                if (( $config['writers_stopped'] ?? '' ) !== 'yes') {
                    throw new RuntimeException('Stop and drain web requests, cron, queues, and other writers before confirming the overwrite.');
                }
                if ($state['phase'] === 'ready') {
                    $review = hash('sha256', json_encode([$push_session_id, $state['incoming_tables'], $state['replace_tables']]));
                    if (!is_string($config['review'] ?? null) || !hash_equals($review, $config['review'])) {
                        throw new RuntimeException('The overwrite review token does not match the current table list. Read push_db_status again.');
                    }
                }
                $push->commit();
            } elseif ($endpoint === 'push_db_discard') {
                $push->discard_next_table();
            } elseif ($endpoint === 'push_db_cleanup') {
                $push->cleanup_next_table();
            }
            $state = $push->get_status();
            if (( $endpoint === 'push_db_cleanup' && $state['phase'] === 'complete' ) || ( $endpoint === 'push_db_discard' && $state['phase'] === 'discarded' )) {
                if (!PushSession::remove($this->options['reprint_directory'], $this->options['docroot'], $push_session_id, [])) {
                    $state['phase'] = $endpoint === 'push_db_cleanup' ? 'cleaning_archive' : 'discarding_archive';
                }
            }
            $state['review'] = $state['phase'] === 'ready' ? hash('sha256', json_encode([$push_session_id, $state['incoming_tables'], $state['replace_tables']])) : null;
            $state['table_prefix'] = $credentials['table_prefix'];
            $state['path'] = $archive_status;
            $this->respond(200, ['status' => 'accepted'] + $state);
        } catch (Throwable $exception) {
            $busy = $exception instanceof PushException && $exception->get_error_code() === 'busy';
            $this->respond($busy ? 409 : 400, ['status' => 'rejected', 'reason' => $busy ? 'busy' : 'database_push_failed', 'detail' => $exception->getMessage()]);
        } finally {
            if ($push !== null) {
                $push->close();
            }
        }
    }

    /** @param array<string,mixed> $response Bounded protocol result, never HTML. */
    private function respond(int $code, array $response): void {
        http_response_code($code);
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-store');
        echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
