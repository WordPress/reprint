<?php

namespace WordPress\Reprint\Server;

/**
 * The one authentication entry point embedders should call.
 *
 * Holds whatever credentials the embedder stores and routes to the verifier
 * the host requires. The embedder decides nothing: it passes both kinds of
 * credential if it has both, and core ignores the one the host does not
 * accept.
 */
final class RequestAuthenticator {

    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_REQUIRES_TOKEN_AUTH = PublicKeyServer::REASON_REQUIRES_TOKEN_AUTH;
    public const REASON_UNKNOWN_KEY = PublicKeyServer::REASON_UNKNOWN_KEY;
    public const REASON_AUTH_FAILED = 'auth_failed';

    /** @var string|null */
    private $hmac_secret;

    /** @var array<string,string> */
    private $public_keys_by_id;

    /** @var int */
    private $timestamp_tolerance;

    /** @var string|null */
    private $last_error_reason = null;

    /** @var string|null */
    private $authenticated_key_id = null;

    /**
     * @param string|null          $hmac_secret         Stored connection token, or null when none.
     * @param array<string,string> $public_keys_by_id   Enrolled keys, key id => one-line public key.
     * @param int                  $timestamp_tolerance Seconds either side of now.
     */
    public function __construct(?string $hmac_secret, array $public_keys_by_id, int $timestamp_tolerance = 300) {
        $this->hmac_secret = $hmac_secret === '' ? null : $hmac_secret;
        $this->public_keys_by_id = $public_keys_by_id;
        $this->timestamp_tolerance = $timestamp_tolerance;
    }

    /**
     * Verifies one request from explicit inputs. Null on success, else an
     * error string with a stable code from last_error_reason().
     *
     * @param string|null $body             Raw body. Only a token signature on a pull endpoint covers it.
     * @param array       $files            $_FILES-style uploads, hashed instead of $body when non-empty.
     * @param bool        $is_push_endpoint True for push_* endpoints, where a token signature uses
     *                                      envelope verification. A key signature never covers the body.
     */
    public function verify(
        array $headers,
        string $method,
        string $request_target,
        ?string $body,
        array $files = [],
        bool $is_push_endpoint = false,
        ?float $now = null
    ): ?string {
        $this->last_error_reason = null;
        $this->authenticated_key_id = null;
        $has_key_id = Utils::request_header($headers, 'X-Auth-Key-Id') !== null;

        if (!Utils::key_auth_required()) {
            if ($has_key_id) {
                return $this->fail(self::REASON_REQUIRES_TOKEN_AUTH, 'This host accepts connection-token authentication only');
            }
            if ($this->hmac_secret === null) {
                return $this->fail(self::REASON_NOT_CONFIGURED, 'Export not configured: no connection token is stored');
            }
            return $this->verify_hmac($this->hmac_secret, $headers, $method, $request_target, $body, $files, $is_push_endpoint, $now);
        }

        // Tokens stay accepted here until clients can sign with keys.
        if (!$has_key_id) {
            if ($this->hmac_secret === null) {
                return $this->fail(self::REASON_NOT_CONFIGURED, 'Export not configured: no connection token is stored');
            }
            return $this->verify_hmac($this->hmac_secret, $headers, $method, $request_target, $body, $files, $is_push_endpoint, $now);
        }
        if (empty($this->public_keys_by_id)) {
            return $this->fail(self::REASON_NOT_CONFIGURED, 'Export not configured: no keys are enrolled');
        }
        $public_key_server = new PublicKeyServer($this->public_keys_by_id, $this->timestamp_tolerance);
        $error = $public_key_server->verify($headers, $method, $request_target, $now);
        if ($error !== null) {
            return $this->fail($public_key_server->last_error_reason() ?? self::REASON_AUTH_FAILED, $error);
        }
        $this->authenticated_key_id = $public_key_server->authenticated_key_id();
        return null;
    }

    /**
     * Verifies the current PHP request. The push decision comes from the
     * query-string endpoint, exactly as HTTPServer::handle_request() makes it:
     * every push_-prefixed endpoint, known or not, uses the push request
     * contract, so an unknown one answers "Invalid endpoint" after
     * authenticating instead of failing its envelope signature. Push
     * endpoints leave the body unread because the envelope contract signs
     * method and target only. The endpoint streams php://input itself.
     */
    public function verify_globals(?float $now = null): ?string {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Exact request-line values are covered by the signature.
        $method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the signature is the check.
        $endpoint = isset($_GET['endpoint']) && is_string($_GET['endpoint']) ? $_GET['endpoint'] : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput

        $is_push_endpoint = strpos($endpoint, 'push_') === 0;
        $body = null;
        if (!$is_push_endpoint) {
            $body = file_get_contents('php://input');
            if ($body === false) {
                $body = '';
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request headers are covered by the signature, not a nonce field.
        return $this->verify(Utils::request_headers(), $method, $request_target, $body, $_FILES, $is_push_endpoint, $now);
    }

    public function last_error_reason(): ?string {
        return $this->last_error_reason;
    }

    public function authenticated_key_id(): ?string {
        return $this->authenticated_key_id;
    }

    private function verify_hmac(
        string $hmac_secret,
        array $headers,
        string $method,
        string $request_target,
        ?string $body,
        array $files,
        bool $is_push_endpoint,
        ?float $now
    ): ?string {
        $hmac_server = new HMACServer($hmac_secret, $this->timestamp_tolerance);
        $error = $is_push_endpoint
            ? $hmac_server->verify_envelope($headers, $method, $request_target, $now)
            : $hmac_server->verify($headers, $body, $files, $now);
        if ($error !== null) {
            return $this->fail($hmac_server->last_error_reason() ?? self::REASON_AUTH_FAILED, $error);
        }
        return null;
    }

    private function fail(string $reason, string $message): string {
        $this->last_error_reason = $reason;
        return $message;
    }
}
