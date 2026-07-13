<?php

if (!class_exists('Site_Export_Staged_Endpoints', false)) {
    require_once __DIR__ . '/class-staged-endpoints.php';
}

/**
 * Small non-WordPress bootstrap for an interrupted staged commit.
 *
 * A broken plugin or theme can prevent the normal WordPress router from
 * loading exactly when a session needs to finish its maintenance-protected
 * switch. Hosts can point a narrow emergency route at this class with the
 * same server-owned staged options used by the plugin. It deliberately offers
 * the normal status, commit, and discard routes rather than inventing a
 * recovery-only protocol.
 */
final class Site_Export_Staged_Session_Recovery_Server {

    /** @var string[] */
    private const ENDPOINTS = [
        'staged_session_status',
        'staged_session_commit',
        'staged_session_discard',
    ];

    /** @param array<string,mixed> $staged_options */
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

    /** @param array{http_code:int,body:array<string,mixed>} $response */
    private static function emit(array $response): void {
        http_response_code($response['http_code']);
        header('Content-Type: application/json');
        echo json_encode($response['body']);
    }
}
