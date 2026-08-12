<?php

namespace Reprint\Importer;

use PDO;
use RuntimeException;

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
 * Mirror PHP's `openssl.cafile` ini value onto the cURL handle as
 * `CURLOPT_CAINFO` — workaround for WordPress Playground, where the
 * WASM curl build doesn't honor `curl.cainfo` / `openssl.cafile`
 * (both are PHP_INI_SYSTEM, and curl can't see PHP-level ini values
 * anyway). Reading the ini value in PHP and passing the path via a
 * per-handle option is the only knob that works there.
 *
 * No-op when `openssl.cafile` is empty (the typical Linux case —
 * curl uses its compile-time default). When it's set and points at
 * a readable file, we mirror it; if `curl.cainfo` was also set to
 * the same path PHP's curl extension already applied it to the
 * handle, so the per-handle setopt is a benign re-set.
 *
 * TODO: remove once https://github.com/WordPress/wordpress-playground
 * resolves `openssl.cafile` natively inside its WASM curl bundle.
 *
 * @param resource $curl_handle cURL handle to configure.
 * @return string|null Applied CA path, "(insecure)", or null when unchanged.
 */
function apply_curl_ca_bundle($curl_handle): ?string
{
	// Insecure-TLS escape hatch for environments where neither
	// CURLOPT_CAINFO nor any other knob persuades the TLS layer to
	// trust the source's cert — notably WordPress Playground in the
	// browser, where networking goes through a JS TLS library running
	// inside the page (not libcurl's TLS) and that library may have
	// a CA store that pre-dates the Let's Encrypt intermediate the
	// source's cert is signed by. The wizard sets this env when it
	// hands off; we never set it for any other caller.
	if ('1' === getenv('REPRINT_INSECURE_TLS')) {
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
