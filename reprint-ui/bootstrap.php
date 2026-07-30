<?php
/**
 * Shared bootstrap for the reprint-ui wizard and its OAuth callback.
 *
 * Loads WordPress just far enough to read WPCOM_CLIENT_ID / _SECRET from
 * the options table — we don't need the full WP HTTP stack, just the DB.
 * Falls back to environment variables when WordPress is absent (useful
 * for local `php -S` testing).
 */

if (!defined('REPRINT_UI_URL_BASE')) {
    // The path prefix under which the wizard is served.
    define('REPRINT_UI_URL_BASE', '/reprint.php');
}

/**
 * Returns a 32-byte symmetric key used to encrypt cookie payloads.
 * Derived from the WP.com client secret (already stored in wp_options
 * for OAuth) so it survives PHP-FPM restarts without any new server
 * state. The cookie is opaque to the client and unforgeable without
 * this key, so a leaked cookie can't be tampered with — but if the
 * client_secret rotates, all outstanding cookies become invalid
 * (which forces a re-login, the safe failure mode).
 */
function reprint_cookie_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    [, $secret] = reprint_client_credentials();
    if ($secret === '') {
        // No client_secret available — derive from a host-stable seed
        // so cookies at least survive within a request. Re-login on
        // restart in this degraded mode is acceptable.
        $secret = (string) ($_SERVER['HTTP_HOST'] ?? 'reprint') . '|fallback';
    }
    return $key = hash('sha256', 'reprint-ui-cookie-v1|' . $secret, true);
}

/** AES-256-GCM encrypt + base64url. Returns '' on failure. */
function reprint_cookie_encode(array $value): string {
    $key = reprint_cookie_key();
    $iv = random_bytes(12);
    $tag = '';
    $plain = json_encode($value, JSON_UNESCAPED_SLASHES);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
}

function reprint_cookie_decode(string $blob): ?array {
    if ($blob === '') return null;
    $raw = base64_decode(strtr($blob, '-_', '+/'), true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', reprint_cookie_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) return null;
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : null;
}

function reprint_cookie_get(string $name): ?array {
    if (!isset($_COOKIE[$name])) return null;
    return reprint_cookie_decode((string) $_COOKIE[$name]);
}

function reprint_cookie_set(string $name, array $value, int $ttl = 0): void {
    $blob = reprint_cookie_encode($value);
    if ($blob === '') return;
    $params = [
        'expires'  => $ttl > 0 ? time() + $ttl : 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie($name, $blob, $params);
    $_COOKIE[$name] = $blob;
}

function reprint_cookie_clear(string $name): void {
    setcookie($name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$name]);
}

/** Returns the WP.com access token, or '' if not authenticated. */
function reprint_wpcom_token(): string {
    $tok = reprint_cookie_get('rp_tok');
    return is_array($tok) ? (string) ($tok['token'] ?? '') : '';
}

/** Absolute redirect URI registered with the WP.com app. */
function reprint_redirect_uri(): string {
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host/oauth-redirect.php";
}

function reprint_site_origin(): string {
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host";
}

/**
 * Returns [client_id, client_secret]. Reads from wp_options when WP is
 * loadable, from env vars as a fallback.
 */
function reprint_client_credentials(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $id = getenv('WPCOM_CLIENT_ID') ?: '';
    $secret = getenv('WPCOM_CLIENT_SECRET') ?: '';

    if ($id === '' || $secret === '') {
        $wp_load = reprint_find_wp_load();
        if ($wp_load !== null) {
            if (!defined('SHORTINIT')) define('SHORTINIT', true);
            require_once $wp_load;
            if (function_exists('get_option')) {
                $id = $id !== '' ? $id : (string) get_option('WPCOM_CLIENT_ID', '');
                $secret = $secret !== '' ? $secret : (string) get_option('WPCOM_CLIENT_SECRET', '');
            }
        }
    }

    return $cached = [$id, $secret];
}

function reprint_find_wp_load(): ?string {
    $candidates = [
        $_SERVER['DOCUMENT_ROOT'] ?? '',
        __DIR__ . '/..',
        __DIR__ . '/../..',
        '/srv/htdocs',
    ];
    foreach ($candidates as $dir) {
        if (!$dir) continue;
        $path = rtrim((string) $dir, '/') . '/wp-load.php';
        if (is_readable($path)) return $path;
    }
    return null;
}
