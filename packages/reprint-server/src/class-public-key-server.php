<?php

namespace WordPress\Reprint\Server;

use InvalidArgumentException;
use RuntimeException;

/**
 * Verifies requests signed by PublicKeyClient against enrolled public keys.
 *
 * The host rule is enforced here, not in a wrapper an embedder might skip:
 * on a host that does not accept key signatures this class refuses every
 * request before reading a credential.
 */
final class PublicKeyServer {

    public const ALGORITHM = 'reprint-rsa-sha256-v1';

    /** Must match PublicKeyClient::UNSIGNED_PAYLOAD. */
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    public const REASON_REQUIRES_TOKEN_AUTH = 'requires_token_auth';
    public const REASON_UNKNOWN_KEY = 'unknown_key';
    public const REASON_AUTH_FAILED = 'auth_failed';

    /** @var array<string,string> key id => one-line public key */
    private $public_keys_by_id;

    /** @var int */
    private $timestamp_tolerance;

    /** @var string */
    private $cursor_header_name;

    /** @var bool */
    private $key_auth_required;

    /** @var string|null */
    private $last_error_reason = null;

    /** @var string|null */
    private $authenticated_key_id = null;

    /**
     * @param array<string,string> $public_keys_by_id   key id => one-line public key.
     * @param int                  $timestamp_tolerance Seconds either side of now.
     * @param string               $cursor_header_name  $_SERVER key carrying the cursor.
     * @param bool|null            $key_auth_required   Test seam; null means Utils::key_auth_required().
     */
    public function __construct(
        array $public_keys_by_id,
        int $timestamp_tolerance = 300,
        string $cursor_header_name = 'HTTP_X_EXPORT_CURSOR',
        ?bool $key_auth_required = null
    ) {
        $this->public_keys_by_id = $public_keys_by_id;
        $this->timestamp_tolerance = $timestamp_tolerance;
        $this->cursor_header_name = $cursor_header_name;
        $this->key_auth_required = $key_auth_required === null ? Utils::key_auth_required() : $key_auth_required;
    }

    /**
     * Verifies one request from explicit inputs. Returns null on success or an
     * error string, with a stable code available from last_error_reason().
     *
     * @param array       $headers                Request headers, either convention.
     * @param string      $method                 HTTP method as received.
     * @param string      $request_target         path?query as received.
     * @param string|null $body                   Raw body, or null when unavailable.
     * @param array       $files                  $_FILES-style uploads; hashed instead of $body when non-empty.
     * @param string|null $cursor                 Cursor header value, or null.
     * @param bool        $allow_unsigned_payload True only for push endpoints.
     * @param float|null  $now                    Current time; tests pass a fixed value.
     */
    public function verify(
        array $headers,
        string $method,
        string $request_target,
        ?string $body,
        array $files = [],
        ?string $cursor = null,
        bool $allow_unsigned_payload = false,
        ?float $now = null
    ): ?string {
        $this->last_error_reason = null;
        $this->authenticated_key_id = null;

        if (!$this->key_auth_required) {
            return $this->fail(self::REASON_REQUIRES_TOKEN_AUTH, 'This host accepts connection-token authentication only');
        }
        if (!function_exists('openssl_verify')) {
            // Unreachable when the rule is honest, kept so a wrong seam fails closed.
            return $this->fail(self::REASON_REQUIRES_TOKEN_AUTH, 'This host cannot verify key signatures');
        }

        $key_id = self::requested_key_id($headers);
        $signature_b64 = $this->get_header($headers, 'X-Auth-Signature');
        $nonce = $this->get_header($headers, 'X-Auth-Nonce');
        $timestamp = $this->get_header($headers, 'X-Auth-Timestamp');
        $content_hash = $this->get_header($headers, 'X-Auth-Content-Hash');

        foreach ([
            'X-Auth-Key-Id' => $key_id,
            'X-Auth-Signature' => $signature_b64,
            'X-Auth-Nonce' => $nonce,
            'X-Auth-Timestamp' => $timestamp,
            'X-Auth-Content-Hash' => $content_hash,
        ] as $name => $value) {
            if ($value === null || $value === '') {
                return $this->fail(self::REASON_AUTH_FAILED, 'Missing ' . $name . ' header');
            }
        }

        if (!is_numeric($timestamp)) {
            return $this->fail(self::REASON_AUTH_FAILED, 'Invalid timestamp format');
        }
        $time_difference = abs(( $now === null ? microtime(true) : $now ) - (float) $timestamp);
        if ($time_difference > $this->timestamp_tolerance) {
            return $this->fail(
                self::REASON_AUTH_FAILED,
                sprintf('Request timestamp expired. Difference: %.2f seconds, max allowed: %d seconds', $time_difference, $this->timestamp_tolerance)
            );
        }
        if (strlen($nonce) < 16) {
            return $this->fail(self::REASON_AUTH_FAILED, 'Nonce must be at least 16 characters');
        }

        if (!isset($this->public_keys_by_id[$key_id])) {
            return $this->fail(self::REASON_UNKNOWN_KEY, 'Key ' . $key_id . ' is not enrolled on this site');
        }

        if ($content_hash === self::UNSIGNED_PAYLOAD) {
            if (!$allow_unsigned_payload) {
                return $this->fail(self::REASON_AUTH_FAILED, 'Unsigned payloads are accepted only for push endpoints');
            }
        } else {
            try {
                $actual_content_hash = $this->compute_received_content_hash($body, $files);
            } catch (RuntimeException $exception) {
                return $this->fail(self::REASON_AUTH_FAILED, $exception->getMessage());
            }
            if (!hash_equals($content_hash, $actual_content_hash)) {
                return $this->fail(self::REASON_AUTH_FAILED, 'Content hash mismatch: body was modified in transit');
            }
        }

        $signature = base64_decode($signature_b64, true);
        if ($signature === false || $signature === '') {
            return $this->fail(self::REASON_AUTH_FAILED, 'Malformed signature');
        }
        $public_key = @openssl_pkey_get_public(Utils::public_key_to_pem($this->public_keys_by_id[$key_id]));
        if ($public_key === false) {
            return $this->fail(self::REASON_AUTH_FAILED, 'Stored public key ' . $key_id . ' could not be parsed');
        }
        $message = PublicKeyClient::build_message($key_id, $nonce, $timestamp, $content_hash, $method, $request_target, $cursor);
        $result = openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256);
        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- Drains the error queue so a later message is not polluted by this one.
        while (openssl_error_string() !== false) {
        }
        if ($result !== 1) {
            return $this->fail(self::REASON_AUTH_FAILED, 'Signature verification failed');
        }

        $this->authenticated_key_id = $key_id;
        return null;
    }

    /**
     * Verifies the current PHP request from superglobals.
     *
     * Method and target come from $_SERVER, the body from php://input, uploads
     * from $_FILES, the cursor from the configured header, and the push
     * decision from the query-string endpoint, the same way HTTPServer makes it.
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
        $endpoint = isset($_GET['endpoint']) ? (string) $_GET['endpoint'] : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput
        $allow_unsigned_payload = HTTPServer::is_push_endpoint($endpoint);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request headers are covered by the signature, not a nonce field.
        return $this->verify($_SERVER, $method, $request_target, $body, $_FILES, $cursor, $allow_unsigned_payload, $now);
    }

    /**
     * Returns the key id header value, or null when absent. Used to pick the
     * enrolled key within key mode, and by an HMAC-only host to reject a
     * request that carries it. Not a scheme selector.
     */
    public static function requested_key_id(array $headers): ?string {
        $instance = new self([], 300, 'HTTP_X_EXPORT_CURSOR', false);
        $value = $instance->get_header($headers, 'X-Auth-Key-Id');
        return $value === '' ? null : $value;
    }

    public function last_error_reason(): ?string {
        return $this->last_error_reason;
    }

    public function authenticated_key_id(): ?string {
        return $this->authenticated_key_id;
    }

    /**
     * Validates a key at enrollment and returns its one-line form.
     *
     * @throws InvalidArgumentException With a message naming what was wrong.
     */
    public static function assert_valid_public_key(string $pem_or_one_line): string {
        if (strpos($pem_or_one_line, 'PRIVATE KEY') !== false) {
            throw new InvalidArgumentException('That is a private key. Paste the public key instead.');
        }
        $one_line = Utils::normalize_public_key($pem_or_one_line);
        if (!function_exists('openssl_pkey_get_public')) {
            throw new InvalidArgumentException('This host cannot parse public keys: the OpenSSL extension is missing.');
        }
        $public_key = @openssl_pkey_get_public(Utils::public_key_to_pem($one_line));
        if ($public_key === false) {
            throw new InvalidArgumentException('Not a parseable public key.');
        }
        $details = openssl_pkey_get_details($public_key);
        if (!is_array($details) || ( $details['type'] ?? null ) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('Public key must be RSA.');
        }
        if (( $details['bits'] ?? 0 ) < 2048) {
            throw new InvalidArgumentException('RSA key must be at least 2048 bits; got ' . (int) $details['bits'] . '.');
        }
        return $one_line;
    }

    private function fail(string $reason, string $message): string {
        $this->last_error_reason = $reason;
        return $message;
    }

    private function get_header(array $headers, string $name): ?string {
        $server_name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (strcasecmp((string) $key, $name) === 0 || strcasecmp((string) $key, $server_name) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function compute_received_content_hash(?string $body, array $files): string {
        if (empty($files)) {
            return hash('sha256', $body ?? '');
        }
        $context = hash_init('sha256');
        ksort($files);
        foreach ($files as $file_info) {
            if (!is_array($file_info)) {
                continue;
            }
            $this->append_tmp_name_hash($context, $file_info['tmp_name'] ?? null);
        }
        return hash_final($context);
    }

    /** @param \HashContext|resource $context */
    private function append_tmp_name_hash($context, $tmp_name): void {
        if (is_array($tmp_name)) {
            ksort($tmp_name);
            foreach ($tmp_name as $nested_tmp_name) {
                $this->append_tmp_name_hash($context, $nested_tmp_name);
            }
            return;
        }
        if (!is_string($tmp_name) || $tmp_name === '' || !is_readable($tmp_name)) {
            return;
        }
        if (!@hash_update_file($context, $tmp_name)) {
            throw new RuntimeException('Cannot hash uploaded file.');
        }
    }
}
