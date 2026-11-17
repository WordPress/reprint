<?php

namespace Reprint\Importer;

use PDO;
use RuntimeException;
use WordPress\Reprint\Server\RequestAuthenticator;

/**
 * Build the fixed WordPress admin Referer for a remote Reprint API URL.
 *
 * @param string $remote_reprint_api_url Remote Reprint API URL.
 * @return string|null Same-origin WordPress Media Library URL, or null when
 *                     the remote URL has no scheme or host.
 */
function wordpress_admin_referer(string $remote_reprint_api_url): ?string
{
	// Some WAFs reject automated requests when Referer is absent. A fixed,
	// query-free WordPress admin URL supplies request context only; this header
	// does not authenticate the request.
	$remote_url = parse_url($remote_reprint_api_url);
	if (
		! is_array($remote_url) ||
		! isset($remote_url['scheme'], $remote_url['host'])
	) {
		return null;
	}

	$site_path = rtrim( (string) ( $remote_url['path'] ?? '' ), '/' );
	if (substr($site_path, -10) === '/index.php') {
		$site_path = substr($site_path, 0, -10);
	}

	$referer = $remote_url['scheme'] . '://' . $remote_url['host'];
	if (isset($remote_url['port'])) {
		$referer .= ':' . $remote_url['port'];
	}

	return $referer . $site_path . '/wp-admin/upload.php';
}

/**
 * Explain HTTP 415 without assuming it proves a firewall greylist.
 *
 * Imunify360 uses the same status for non-text requests from a greylisted
 * source IP that an endpoint uses for a normal media-type rejection.
 *
 * @return string Actionable error detail for pull and push requests.
 */
function unsupported_media_type_error_detail(): string
{
	return 'The remote returned HTTP 415 Unsupported Media Type. This normally means the target rejected the ' .
		'request\'s media type. Check the target\'s logs for a media-type rejection. Some web application ' .
		'firewalls also return this status when the source IP address is greylisted and the request asks for a ' .
		'non-text response. In that case, changing Reprint\'s Accept header to text/html would only replace this ' .
		'response with a JavaScript challenge. Reprint cannot complete that challenge. If the firewall caused ' .
		'it, ask the host to allowlist this machine\'s source IP address.';
}

/**
 * Returns whether a signed request was refused by a plugin older than the client.
 *
 * Every authentication refusal from a plugin that speaks this protocol carries
 * `auth_version`. An older plugin refuses the signature with its error body,
 * which repeats the HTTP status as `code`, or with the push refusal body,
 * whose `status` is `rejected`. Its oldest error body has no reason. A
 * refusal for a reason outside RequestAuthenticator::AUTHENTICATION_REASONS
 * is not about the credential, so it is reported as the site sent it.
 *
 * For a token-signed request this means the plugin cannot verify the
 * signature. Older plugins verify key signatures, so for a key request it
 * only tells that the plugin is older, and the refusal is about the key.
 *
 * @param int   $http_code     HTTP status of the response.
 * @param mixed $decoded_body  Response body decoded as JSON.
 * @return bool Whether the response is an older plugin's authentication refusal.
 */
function is_older_plugin_authentication_refusal(int $http_code, $decoded_body): bool
{
	if (!is_array($decoded_body) || is_int($decoded_body['auth_version'] ?? null)) {
		return false;
	}
	$reason = $decoded_body['reason'] ?? null;
	if ($reason !== null && !in_array($reason, RequestAuthenticator::AUTHENTICATION_REASONS, true)) {
		return false;
	}
	$is_refusal_status = 401 === $http_code || 403 === $http_code
		|| ( 503 === $http_code && in_array($reason, ['not_configured', 'no_keys_enrolled'], true) );
	$is_reprint_body = ( $decoded_body['code'] ?? null ) === $http_code
		|| ( $decoded_body['status'] ?? null ) === 'rejected';
	return $is_refusal_status && $is_reprint_body;
}

/**
 * Explain that the site's plugin cannot verify this client's signatures.
 *
 * @return string Actionable error detail for pull and push requests.
 */
function older_plugin_authentication_error_detail(): string
{
	return "The site's Reprint Server plugin is older than this client and does not accept its signatures.\n\n" .
		'Update the Reprint Server plugin on the site.';
}

/**
 * If the ALL_PROXY environment variable is set, apply it to the cURL
 * handle via CURLOPT_PROXY.
 *
 * libcurl does inspect ALL_PROXY on its own, but only when curl is
 * built against a libc that exports the env var and when no one has
 * unset it in the PHP process. Some SAPIs and managed runtimes strip
 * the environment before PHP starts, so setting CURLOPT_PROXY
 * explicitly makes the behavior deterministic across hosts.
 *
 * Empty values are ignored — an explicit empty ALL_PROXY is the
 * shell idiom for "no proxy".
 *
 * @param resource $curl_handle cURL handle to configure.
 * @return string|null Applied proxy URL, or null when no proxy was configured.
 */
function apply_curl_proxy_from_environment($curl_handle): ?string
{
	$proxy = getenv('ALL_PROXY');
	if (! is_string($proxy) || '' === $proxy) {
		return null;
	}

	curl_setopt($curl_handle, CURLOPT_PROXY, $proxy);

	return $proxy;
}

/**
 * Send the cookie set by ZipWP's "continue with temporary site" button.
 *
 * @param resource|object $curl_handle            cURL handle to configure.
 * @param string          $remote_reprint_api_url Remote Reprint API URL.
 */
function apply_zipwp_access_cookie($curl_handle, string $remote_reprint_api_url): void
{
	$host = parse_url($remote_reprint_api_url, PHP_URL_HOST);
	if (!is_string($host) || substr(strtolower(rtrim($host, '.')), -9) !== '.zipwp.to') {
		return;
	}

	// ZipWP serves an HTML landing page until this cookie is present, even
	// for API requests. The button uses a random 12-character value. This
	// cookie does not replace Reprint authentication.
	curl_setopt($curl_handle, CURLOPT_COOKIE, 'zipwp_access=' . bin2hex(random_bytes(6)));
}

/**
 * Mirror PHP's `openssl.cafile` ini value onto the cURL handle as
 * `CURLOPT_CAINFO` — workaround for WordPress Playground, where the
 * WASM curl build doesn't honor `curl.cainfo` / `openssl.cafile`
 * (both are PHP_INI_SYSTEM, and curl can't see PHP-level ini values
 * anyway). Reading the ini value in PHP and passing the path via a
 * per-handle option is the only knob that works there.
 *
 * With verification enabled, no-op when `openssl.cafile` is empty (the typical Linux case —
 * curl uses its compile-time default). When it's set and points at
 * a readable file, we mirror it; if `curl.cainfo` was also set to
 * the same path PHP's curl extension already applied it to the
 * handle, so the per-handle setopt is a benign re-set.
 *
 * TODO: remove once https://github.com/WordPress/wordpress-playground
 * resolves `openssl.cafile` natively inside its WASM curl bundle.
 *
 * @param resource $curl_handle cURL handle to configure.
 * @param bool     $insecure Skip certificate and hostname verification.
 * @return string|null Applied CA path, "(insecure)", or null when unchanged.
 */
function apply_curl_ca_bundle($curl_handle, bool $insecure = false): ?string
{
	// Insecure-TLS escape hatch for environments where neither
	// CURLOPT_CAINFO nor any other knob persuades the TLS layer to
	// trust the source's cert — notably WordPress Playground in the
	// browser, where networking goes through a JS TLS library running
	// inside the page (not libcurl's TLS) and that library may have
	// a CA store that pre-dates the Let's Encrypt intermediate the
	// source's cert is signed by. The wizard can set this env when it
	// hands off; --insecure passes the same choice per client without
	// changing the process environment.
	if ($insecure || '1' === getenv('REPRINT_INSECURE_TLS')) {
		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);

		return '(insecure)';
	}

	$ca_file = (string) ini_get('openssl.cafile');
	if ('' === $ca_file || ! is_readable($ca_file)) {
		return null;
	}

	curl_setopt($curl_handle, CURLOPT_CAINFO, $ca_file);

	return $ca_file;
}

/**
 * Resolve a file in the bundled SQLite database integration.
 *
 * @param string $suffix Path suffix within the integration.
 * @return string Absolute path to the requested integration file.
 */
function resolve_sqlite_integration_path(string $suffix = ''): string
{
	$source_directory = dirname(__DIR__, 2);
	foreach (array(dirname($source_directory, 3), dirname($source_directory, 4)) as $project_root) {
		// The packaged CLI has a phar:// project root. Joining it with the
		// Unix filesystem helper would collapse the scheme's required `:///`.
		$candidate = $project_root . '/lib/sqlite-database-integration' . $suffix;
		if (file_exists($candidate)) {
			return $candidate;
		}
	}

	throw new RuntimeException(
		'SQLite target support requires lib/sqlite-database-integration to be initialized.'
	);
}

/**
 * Resolve the SQLite integration directory to copy as a runtime plugin.
 *
 * @return string Absolute path to the integration plugin directory.
 */
function resolve_sqlite_integration_plugin_path(): string
{
	$source_directory = dirname(__DIR__, 2);
	foreach (array(dirname($source_directory, 3), dirname($source_directory, 4)) as $project_root) {
		// Keep the phar:// scheme intact when this runs from the packaged CLI.
		$root    = $project_root . '/lib/sqlite-database-integration';
		$package = $root . '/packages/plugin-sqlite-database-integration';
		if (is_dir($package)) {
			return $package;
		}
	}

	throw new RuntimeException(
		'SQLite runtime support requires lib/sqlite-database-integration to be initialized.'
	);
}

/**
 * Register a user-defined SQL function on a SQLite PDO. PHP 8.4 introduced
 * Pdo\Sqlite::createFunction(); PDO::sqliteCreateFunction() serves earlier
 * supported PHP versions and is deprecated in 8.5.
 *
 * @param PDO      $sqlite_pdo SQLite PDO connection.
 * @param string   $name       SQL function name.
 * @param callable $callback   Function implementation.
 * @param int      $num_args   Number of SQL arguments accepted.
 */
function register_sqlite_function(PDO $sqlite_pdo, string $name, callable $callback, int $num_args = 1): void
{
	if ($sqlite_pdo instanceof PDO\SQLite) {
		$sqlite_pdo->createFunction($name, $callback, $num_args);
	} else {
		$sqlite_pdo->sqliteCreateFunction($name, $callback, $num_args);
	}
}

/**
 * Explain why Windows cannot store a path, or return null when it can.
 *
 * Every component is checked, so a path below an unstorable directory is
 * unstorable too. Only `/` separates components of a Unix path. A backslash
 * is part of the name, and Windows would read it as a separator instead.
 *
 * Windows rejects control characters 0x01 to 0x1F and `<>:"|?*\`, drops a
 * trailing dot or space, and opens a device instead of a file for CON, PRN,
 * AUX, NUL, COM1 to COM9 and LPT1 to LPT9, with or without an extension.
 * Microsoft also reserves COM and LPT followed by a superscript one, two or
 * three. Bytes which are not valid UTF-8 pass: Windows PHP still creates and
 * reopens such a name, which FilesPullLocalIndexTest checks on Windows.
 *
 * Examples:
 *
 *     windows_unstorable_path_reason('/srv/site/wp-content/uploads/photo.jpg'); // null
 *     windows_unstorable_path_reason('/srv/site/notes/aux.txt');
 *     // 'the name "aux.txt" uses the reserved device name AUX'
 *
 * @param string $path Unix source path, as raw bytes.
 * @return string|null Reason naming the first unstorable component, or null.
 */
function windows_unstorable_path_reason(string $path): ?string
{
	foreach (explode('/', $path) as $name) {
		if ($name === '') {
			continue;
		}
		$quoted_name = json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if (preg_match('/[\x01-\x1F]/', $name, $control_character) === 1) {
			return sprintf('the name %s contains the control character 0x%02X', $quoted_name, ord($control_character[0]));
		}
		if (preg_match('/[<>:"|?*\\\\]/', $name, $reserved_character) === 1) {
			return "the name {$quoted_name} contains the character " . json_encode($reserved_character[0]);
		}
		if (substr($name, -1) === '.') {
			return "the name {$quoted_name} ends in a dot";
		}
		if (substr($name, -1) === ' ') {
			return "the name {$quoted_name} ends in a space";
		}
		// Spaces before the extension are ignored too, so a name such as
		// "con .txt" is skipped rather than risk opening the device.
		$device_name = strtoupper(rtrim(explode('.', $name, 2)[0], ' '));
		if (preg_match('/^(?:CON|PRN|AUX|NUL|(?:COM|LPT)(?:[1-9]|\xC2[\xB9\xB2\xB3]))$/', $device_name) === 1) {
			return "the name {$quoted_name} uses the reserved device name {$device_name}";
		}
	}
	return null;
}
