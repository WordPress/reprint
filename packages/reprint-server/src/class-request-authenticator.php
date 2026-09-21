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

    public const SCHEME_KEY = 'key';
    public const SCHEME_HMAC = 'hmac';

    public const REASON_NOT_CONFIGURED = 'not_configured';
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

    /** @var bool */
    private $key_auth_required;

    /** @var string */
    private $cursor_header_name;

    /** @var string|null */
    private $last_error_reason = null;

    /** @var string|null */
    private $authenticated_key_id = null;

    /**
     * @param string|null          $hmac_secret         Stored connection token, or null when none.
     * @param array<string,string> $public_keys_by_id   Enrolled keys, key id => one-line public key.
     * @param int                  $timestamp_tolerance Seconds either side of now.
     * @param bool|null            $key_auth_required   Test seam; null means Utils::key_auth_required().
     * @param string               $cursor_header_name  $_SERVER key carrying the cursor.
     */
    public function __construct(
        ?string $hmac_secret,
        array $public_keys_by_id,
        int $timestamp_tolerance = 300,
        ?bool $key_auth_required = null,
        string $cursor_header_name = 'HTTP_X_EXPORT_CURSOR'
    ) {
        $this->hmac_secret = $hmac_secret === '' ? null : $hmac_secret;
        $this->public_keys_by_id = $public_keys_by_id;
        $this->timestamp_tolerance = $timestamp_tolerance;
        $this->key_auth_required = $key_auth_required === null ? Utils::key_auth_required() : $key_auth_required;
        $this->cursor_header_name = $cursor_header_name;
    }

    /** Which scheme this host requires: 'key' or 'hmac'. */
    public function required_scheme(): string {
        return $this->key_auth_required ? self::SCHEME_KEY : self::SCHEME_HMAC;
    }

    /**
     * Verifies one request from explicit inputs. Null on success, else an
     * error string with a stable code from last_error_reason().
     *
     * @param bool $is_push_endpoint True for push_* endpoints: HMAC uses envelope
     *                               verification and the key path accepts UNSIGNED-PAYLOAD.
     */
    public function verify(
        array $headers,
        string $method,
        string $request_target,
        ?string $body,
        array $files = [],
        ?string $cursor = null,
        bool $is_push_endpoint = false,
        ?float $now = null
    ): ?string {
        $this->last_error_reason = null;
        $this->authenticated_key_id = null;
        $has_key_id = PublicKeyServer::requested_key_id($headers) !== null;

        if (!$this->key_auth_required) {
            if ($has_key_id) {
                return $this->fail(self::REASON_REQUIRES_TOKEN_AUTH, 'This host accepts connection-token authentication only');
            }
            if ($this->hmac_secret === null) {
                return $this->fail(self::REASON_NOT_CONFIGURED, 'Export not configured: no connection token is stored');
            }
            $hmac_server = new HMACServer($this->hmac_secret, $this->timestamp_tolerance, false);
            $error = $is_push_endpoint
                ? $hmac_server->verify_envelope($headers, $method, $request_target, $now)
                : $hmac_server->verify($headers, $body, $files, $now);
            if ($error !== null) {
                return $this->fail($hmac_server->last_error_reason() ?? self::REASON_AUTH_FAILED, $error);
            }
            return null;
        }

        if (!$has_key_id) {
            return $this->fail(self::REASON_REQUIRES_KEY_AUTH, 'This host requires key authentication; connection tokens are not accepted');
        }
        if (empty($this->public_keys_by_id)) {
            return $this->fail(self::REASON_NOT_CONFIGURED, 'Export not configured: this host requires key authentication and no keys are enrolled');
        }
        $public_key_server = new PublicKeyServer($this->public_keys_by_id, $this->timestamp_tolerance, $this->cursor_header_name, true);
        $error = $public_key_server->verify($headers, $method, $request_target, $body, $files, $cursor, $is_push_endpoint, $now);
        if ($error !== null) {
            return $this->fail($public_key_server->last_error_reason() ?? self::REASON_AUTH_FAILED, $error);
        }
        $this->authenticated_key_id = $public_key_server->authenticated_key_id();
        return null;
    }

    /**
     * Verifies the current PHP request. The push decision comes from the
     * query-string endpoint, exactly as HTTPServer::handle_request() makes it.
     */
    public function verify_globals(?float $now = null): ?string {
        $body = file_get_contents('php://input');
        if ($body === false) {
            $body = '';
        }
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Exact request-line values are covered by the signature.
        $method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $cursor = isset($_SERVER[$this->cursor_header_name]) ? (string) $_SERVER[$this->cursor_header_name] : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the signature is the check.
        $endpoint = isset($_GET['endpoint']) && is_string($_GET['endpoint']) ? $_GET['endpoint'] : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request headers are covered by the signature, not a nonce field.
        return $this->verify($_SERVER, $method, $request_target, $body, $_FILES, $cursor, HTTPServer::is_push_endpoint($endpoint), $now);
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
