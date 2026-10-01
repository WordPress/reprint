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
    public const REASON_NO_KEYS_ENROLLED = 'no_keys_enrolled';
    public const REASON_REQUIRES_KEY_AUTH = HMACServer::REASON_REQUIRES_KEY_AUTH;
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
        $read_body = function () use ($body): ?string {
            return $body;
        };
        return $this->authenticate($headers, $method, $request_target, $read_body, $files, $is_push_endpoint, $now);
    }

    /**
     * @param callable():(string|null) $read_body Returns the raw body. Called only to verify a token
     *                                            signature on a pull endpoint.
     */
    private function authenticate(
        array $headers,
        string $method,
        string $request_target,
        callable $read_body,
        array $files,
        bool $is_push_endpoint,
        ?float $now
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
            $hmac_server = new HMACServer($this->hmac_secret, $this->timestamp_tolerance);
            $error = $is_push_endpoint
                ? $hmac_server->verify_envelope($headers, $method, $request_target, $now)
                : $hmac_server->verify($headers, $read_body(), $files, $now);
            if ($error !== null) {
                return $this->fail($hmac_server->last_error_reason() ?? self::REASON_AUTH_FAILED, $error);
            }
            return null;
        }

        // No keys enrolled answers first: a site that upgraded with only a
        // token stored tells its existing token clients to enroll a key
        // rather than reporting a scheme mismatch they cannot act on.
        if (empty($this->public_keys_by_id)) {
            return $this->fail(self::REASON_NO_KEYS_ENROLLED, 'Export not configured: this host requires key authentication and no keys are enrolled');
        }
        if (!$has_key_id) {
            return $this->fail(self::REASON_REQUIRES_KEY_AUTH, 'This host requires key authentication; connection tokens are not accepted');
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
     * authenticating instead of failing its envelope signature.
     *
     * Only a token signature on a pull endpoint covers the body, so only that
     * path reads php://input. A key request never buffers the body here, and
     * a push endpoint streams php://input itself.
     */
    public function verify_globals(?float $now = null): ?string {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Exact request-line values are covered by the signature.
        $method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the signature is the check.
        $endpoint = isset($_GET['endpoint']) && is_string($_GET['endpoint']) ? $_GET['endpoint'] : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput

        $is_push_endpoint = strpos($endpoint, 'push_') === 0;
        $read_body = function (): string {
            $body = file_get_contents('php://input');
            return $body === false ? '' : $body;
        };

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request headers are covered by the signature, not a nonce field.
        return $this->authenticate(Utils::request_headers(), $method, $request_target, $read_body, $_FILES, $is_push_endpoint, $now);
    }

    public function last_error_reason(): ?string {
        return $this->last_error_reason;
    }

    public function authenticated_key_id(): ?string {
        return $this->authenticated_key_id;
    }

    private function fail(string $reason, string $message): string {
        $this->last_error_reason = $reason;
        return $message;
    }
}
