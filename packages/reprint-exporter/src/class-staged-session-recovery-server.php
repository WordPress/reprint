<?php

if (!class_exists('Site_Export_Staged_Endpoints', false)) {
    require_once __DIR__ . '/class-staged-endpoints.php';
}

/**
 * Dispatches staged-session recovery controls without booting WordPress.
 *
 * Replacing a plugin or theme can make the normal WordPress request bootstrap
 * fail exactly while a checkpointed commit still needs to finish installation or
 * remove its maintenance marker. A host may expose a small standalone PHP
 * route which loads the exporter classes and passes the same server-owned
 * staged options used by the normal router:
 *
 *     require_once '/private/reprint/class-staged-session-recovery-server.php';
 *
 *     Site_Export_Staged_Session_Recovery_Server::serve([
 *         'staging_dir' => '/srv/reprint-private',
 *         'secret' => getenv('REPRINT_PUSH_SECRET'),
 *         'apply_target_root' => '/srv/www',
 *         'apply_protected_paths' => ['wp-content/plugins/reprint'],
 *     ]);
 *
 * The route accepts the ordinary signed status, commit, and discard request
 * targets. It does not define a recovery-only protocol, weaken HMAC checks, or
 * accept a caller-selected storage directory or target root. In particular, it
 * cannot create a session or upload new bytes; its purpose is only to inspect
 * or drive durable work which the target already owns.
 *
 * This bootstrap should remain isolated from WordPress and other application
 * code. Loading WordPress here would reintroduce the failure which the recovery
 * route exists to bypass. HTTP authentication still occurs before dispatch,
 * and endpoint configuration is revalidated by Site_Export_Staged_Endpoints.
 */
final class Site_Export_Staged_Session_Recovery_Server {

    /**
     * Existing control endpoints safe to expose through the emergency route.
     *
     * Create and upload are deliberately absent: recovery may resume or abandon
     * existing private state but may not start accepting a new deployment.
     *
     * @var string[]
     */
    private const ENDPOINTS = [
        'staged_session_status',
        'staged_session_commit',
        'staged_session_discard',
    ];

    /**
     * Authenticates and dispatches one control request without booting WordPress.
     *
     * The endpoint is read from the signed request's `endpoint` query value.
     * Request method, URI, and HMAC headers come from $_SERVER, while decoded
     * query parameters come from $_GET and are checked against that signed URI.
     * This method emits exactly one JSON response and then returns.
     *
     * @param array<string,mixed> $staged_options Same server-owned options used
     *     to construct Site_Export_Staged_Endpoints in the normal router.
     */
    public static function serve(array $staged_options): void {
        $endpoint = $_GET['endpoint'] ?? null;
        if (!is_string($endpoint) || !in_array($endpoint, self::ENDPOINTS, true)) {
            self::emit([
                'http_code' => 404,
                'body' => [
                    'status' => 'error',
                    'reason' => 'recovery_endpoint_not_found',
                    'detail' => 'The standalone recovery server accepts staged_session_status, staged_session_commit, and staged_session_discard.',
                    'send_next_request' => false,
                ],
            ]);
            return;
        }

        $endpoints = new Site_Export_Staged_Endpoints($staged_options);
        $authentication = $endpoints->pre_authenticate_envelope($_SERVER, $endpoint);
        if ($authentication !== null) {
            self::emit($authentication);
            return;
        }

        if ($endpoint === 'staged_session_status') {
            self::emit($endpoints->session_status($_GET, $_SERVER));
            return;
        }
        if ($endpoint === 'staged_session_commit') {
            self::emit($endpoints->session_commit($_GET, $_SERVER));
            return;
        }
        self::emit($endpoints->session_discard($_GET, $_SERVER));
    }

    /**
     * Emits one endpoint envelope as an application/json HTTP response.
     *
     * @param array{http_code:int,body:array<string,mixed>} $response Validated
     *     response returned by Site_Export_Staged_Endpoints.
     */
    private static function emit(array $response): void {
        http_response_code($response['http_code']);
        header('Content-Type: application/json');
        echo json_encode($response['body']);
    }
}
