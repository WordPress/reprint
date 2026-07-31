<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Transport errors are CLI/API values, never HTML output.
/**
 * Remote export API HTTP transport.
 */

namespace Reprint\Importer\Remote;

use Reprint\Importer\CurlTimeoutException;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\TransientInterruptionException;
use RuntimeException;

use function Reprint\Importer\apply_curl_ca_bundle;
use function Reprint\Importer\apply_curl_proxy_from_environment;

/**
 * Sends remote export API requests and parses their responses.
 */
class RemoteExportApiClient {

	/**
	 * User-Agent strings to try during preflight, in order of preference.
	 * Some WAFs block browser UAs that carry custom auth headers, so we
	 * start with an honest non-browser identity and fall back to common
	 * browser strings.
	 */
	public const USER_AGENTS = array(
		'Reprint/1.0',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
	);

	/** @var string Remote Reprint API base URL. */
	private $base_url;

	/** @var object|null HMAC client used to sign requests. */
	private $hmac_client;

	/** @var callable(string,bool):void Audit log writer. */
	private $audit_logger;

	/** @var callable(array):void Transport progress reporter. */
	private $progress_reporter;

	/** @var int|null Last cURL error number. */
	private $last_curl_errno = null;

	/** @var bool Whether the last cURL request timed out. */
	private $last_curl_timeout = false;

	/** @var int|null Last HTTP response code. */
	private $last_http_code = null;

	/** @var string|null Machine-readable code from the last diagnosed response. */
	private $last_error_code = null;

	/**
	 * @param string                     $base_url          Remote Reprint API base URL.
	 * @param object|null                $hmac_client       HMAC client used to sign requests.
	 * @param callable(string,bool):void $audit_logger      Audit log writer.
	 * @param callable(array):void       $progress_reporter Transport progress reporter.
	 */
	public function __construct(
		string $base_url,
		$hmac_client,
		callable $audit_logger,
		callable $progress_reporter
	) {
		$this->base_url          = rtrim( $base_url, '?&' );
		$this->hmac_client       = $hmac_client;
		$this->audit_logger      = $audit_logger;
		$this->progress_reporter = $progress_reporter;
	}

	/**
	 * Build a request URL with an endpoint and optional cursor.
	 *
	 * @param string      $endpoint Endpoint name.
	 * @param string|null $cursor   Durable remote cursor.
	 * @param array       $params {
	 *     Additional query parameters.
	 * }
	 */
	public function build_url(
		string $endpoint,
		?string $cursor,
		array $params = array()
	): string {
		$separator = strpos( $this->base_url, '?' ) === false ? '?' : '&';

		$params['endpoint'] = $endpoint;
		if ( $cursor ) {
			// Also include cursor in query params as a fallback when headers are stripped.
			$params['cursor'] = $cursor;
		}
		$params['_cache_bust'] = time() . '-' . rand( 0, 999999 );

		return $this->base_url . $separator . http_build_query( $params );
	}

	/** Return the last cURL error number. */
	public function get_last_curl_errno(): ?int {
		return $this->last_curl_errno;
	}

	/** Whether the last request timed out. */
	public function did_last_request_timeout(): bool {
		return $this->last_curl_timeout;
	}

	/** Return the last HTTP response code. */
	public function get_last_http_code(): ?int {
		return $this->last_http_code;
	}

	/** Return the last machine-readable response error code. */
	public function get_last_error_code(): ?string {
		return $this->last_error_code;
	}

	/**
	 * Diagnose an HTTP error and return a user-friendly message with
	 * actionable advice. Used by fetch_json() and fetch_streaming() to
	 * turn opaque "HTTP 403" messages into something a non-expert can
	 * act on.
	 *
	 * Returns ['message' => ..., 'code' => ...].
	 *
	 * @param int         $http_code    HTTP status code (0 for connection failures).
	 * @param string|null $body         Response body (may be HTML, JSON, or empty).
	 * @param string|null $redirect_url The Location header / CURLINFO_REDIRECT_URL for 3xx responses.
	 * @return array {
	 *     Diagnosed response error.
	 *
	 *     @type string $code      Machine-readable error code.
	 *     @type string $message   Actionable error message.
	 *     @type int    $http_code HTTP status for an unexpected HTML response, when applicable.
	 * }
	 */
	public function diagnose_http_error(
		int $http_code,
		?string $body,
		?string $redirect_url = null
	): array {
		$body = ( $body !== null && $body !== false ) ? $body : '';

		$decoded    = json_decode( $body, true );
		$server_msg = is_array( $decoded ) ? ( $decoded['error'] ?? null ) : null;

		$looks_like_html = ! is_array( $decoded ) && $body !== '' && (
			stripos( $body, '<html' ) !== false ||
			stripos( $body, '<!doctype' ) !== false ||
			str_starts_with( $body, '<' )
		);

		// ── Redirects ────────────────────────────────────────────
		if ( $http_code >= 300 && $http_code < 400 ) {
			$msg = $redirect_url
				? "Wrong URL. The server redirected to {$redirect_url} " .
				  "(HTTP {$http_code}).\n\n" .
				  'Reprint does not follow redirects to avoid silently ' .
				  'connecting to the wrong server. Retry with the target ' .
				  'URL above.'
				: "Wrong URL. The server returned a redirect (HTTP {$http_code}) " .
				  "instead of the export API.\n\n" .
				  'Reprint does not follow redirects. Check whether the site ' .
				  'uses http vs https or www vs non-www and retry with the ' .
				  'canonical URL.';
			return array(
				'code'    => 'REDIRECT',
				'message' => $msg,
			);
		}

		// ── Authentication / authorization ───────────────────────
		if ( $http_code === 401 || $http_code === 403 ) {
			if ( $this->hmac_client === null ) {
				return array(
					'code'    => 'AUTH_NO_SECRET',
					'message' =>
						'No --secret was provided. The remote site requires ' .
						"authentication.\n\n" .
						'Pass --secret=YOUR_SECRET using the same secret ' .
						'configured in the Site Export plugin on the remote site.',
				);
			}

			if ( $server_msg === null ) {
				return array(
					'code'    => 'AUTH_FAILED',
					'message' =>
						"The request was blocked (HTTP {$http_code}) but the " .
						'server did not say why. The Reprint Server plugin always ' .
						'explains authentication failures, so something ' .
						'upstream is blocking the request — a server-level ' .
						'firewall, .htaccess rule, or security plugin.',
				);
			}

			// The server tells us exactly what went wrong. Map each known
			// HMAC error to a targeted message.

			if ( str_contains( $server_msg, 'HMAC signature verification failed' ) ) {
				return array(
					'code'    => 'AUTH_SECRET_MISMATCH',
					'message' =>
						'Wrong shared secret. The --secret value does not match ' .
						'the one configured in the Site Export plugin settings ' .
						'(wp-admin → Site Export).',
				);
			}

			if ( str_contains( $server_msg, 'timestamp expired' ) ) {
				return array(
					'code'    => 'AUTH_CLOCK_SKEW',
					'message' =>
						"Clock out of sync. {$server_msg}\n\n" .
						"Check this machine's clock (run `date`) and compare " .
						"it with the server's time.",
				);
			}

			if ( str_contains( $server_msg, 'Content hash mismatch' ) ) {
				return array(
					'code'    => 'AUTH_CONTENT_TAMPERED',
					'message' =>
						'Request body was modified in transit. A proxy, CDN, ' .
						'or firewall between this machine and the server is ' .
						'altering the request content.',
				);
			}

			if ( str_contains( $server_msg, 'Missing X-Auth-' ) ) {
				return array(
					'code'    => 'AUTH_HEADERS_STRIPPED',
					'message' =>
						'Authentication headers were stripped. The server ' .
						"reported: {$server_msg}\n\n" .
						'A proxy, CDN, or security plugin is removing custom ' .
						'HTTP headers before they reach WordPress.',
				);
			}

			return array(
				'code'    => 'AUTH_FAILED',
				'message' => "Authentication failed: {$server_msg}",
			);
		}

		// ── Export not configured (503 from exporter) ────────────
		if ( $http_code === 503 && $server_msg !== null ) {
			return array(
				'code'    => 'EXPORT_NOT_CONFIGURED',
				'message' =>
					'The Reprint Server plugin is installed but not configured. ' .
					"The server reported: {$server_msg}",
			);
		}

		// ── Not found ────────────────────────────────────────────
		if ( $http_code === 404 ) {
			$msg = 'The Reprint Server plugin is not installed on the remote site.';
			if ( $looks_like_html ) {
				$msg .= ' The server returned an HTML 404 page instead of ' .
						 'the export API.';
			} else {
				$msg .= ' The server returned HTTP 404.';
			}
			$msg .= "\n\nRun `php reprint.phar install-server` for setup " .
					 'instructions.';
			return array(
				'code'    => 'NOT_FOUND',
				'message' => $msg,
			);
		}

		// ── Server errors ────────────────────────────────────────
		if ( $http_code >= 500 ) {
			$msg  = $server_msg
				? "The remote server crashed: {$server_msg}"
				: "The remote server crashed (HTTP {$http_code}).";
			$msg .= "\n\nThis is a problem on the remote server. " .
					 'Check its PHP error log for details.';
			return array(
				'code'    => 'SERVER_ERROR',
				'message' => $msg,
			);
		}

		// ── HTML response (plugin not installed / wrong URL) ─────
		if ( $looks_like_html ) {
			return array(
				'code'      => 'HTML_RESPONSE',
				'http_code' => $http_code,
				'message'   =>
					'The Reprint Server plugin is not installed on the remote site. ' .
					"The server returned an HTML page (HTTP {$http_code}) " .
					"instead of a JSON API response.\n\n" .
					'Run `php reprint.phar install-server` for setup ' .
					'instructions.',
			);
		}

		// ── Fallback ─────────────────────────────────────────────
		return array(
			'code'    => 'HTTP_ERROR',
			'message' => $server_msg
				? "HTTP error {$http_code}: {$server_msg}"
				: "Unexpected HTTP status {$http_code}.",
		);
	}

	/**
	 * Fetch a JSON response for a lightweight request (non-streaming).
	 *
	 * @param string $url        Request URL.
	 * @param string $user_agent User-Agent header value.
	 * @return array {
	 *     Request result.
	 *
	 *     @type bool        $ok          Whether a valid JSON response was received.
	 *     @type int         $http_code   HTTP status code, or zero for a transport failure.
	 *     @type float       $elapsed     Request duration in seconds.
	 *     @type string|null $body        Raw response body.
	 *     @type array|null  $json        Decoded JSON response.
	 *     @type string|null $error       Failure message.
	 *     @type string|null $error_code  Machine-readable response error code.
	 *     @type int|null    $curl_errno  cURL error number for a transport failure.
	 *     @type bool|null   $timeout     Whether a transport failure was a timeout.
	 * }
	 */
	public function fetch_json( string $url, string $user_agent ): array {
		$this->reset_request_state();

		$this->audit_log( "HTTP_REQUEST | GET | {$url}", false );

		$ch = curl_init( $url );
		apply_curl_proxy_from_environment( $ch );
		apply_curl_ca_bundle( $ch );

		$headers = array_merge(
			$this->get_base_headers( 'application/json', $user_agent ),
			$this->get_hmac_headers()
		);

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_FOLLOWLOCATION   => false,
				// Bound the connect phase separately from the total timeout: a
				// stalled TCP connect would otherwise consume the whole 30s
				// budget with no connection ever established. No server
				// legitimately takes 10s just to accept a connection, so a
				// connect failure here is fast and retryable.
				CURLOPT_CONNECTTIMEOUT    => 10,
				CURLOPT_TIMEOUT           => 30,
				CURLOPT_ENCODING          => 'gzip, deflate',
				CURLOPT_HTTPHEADER        => $headers,
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_NOPROGRESS        => false,
				CURLOPT_PROGRESSFUNCTION  => function () {
					$this->report_progress( array( 'type' => 'tick' ) );
					return 0;
				},
			)
		);

		$start   = microtime( true );
		$body    = curl_exec( $ch );
		$elapsed = microtime( true ) - $start;

		try {
			$this->check_curl_error( $ch );
		} catch ( RuntimeException $error ) {
			@curl_close( $ch );
			return array(
				'ok'          => false,
				'http_code'   => 0,
				'elapsed'     => $elapsed,
				'body'        => null,
				'json'        => null,
				'error'       => $error->getMessage(),
				'curl_errno'  => $this->last_curl_errno,
				'timeout'     => $this->last_curl_timeout,
			);
		}

		$http_code           = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$this->last_http_code = $http_code;
		$redirect_url        = curl_getinfo( $ch, CURLINFO_REDIRECT_URL ) ?: null;
		@curl_close( $ch );

		if ( $http_code !== 200 ) {
			$diagnosis = $this->diagnose_http_error( $http_code, $body, $redirect_url );
			return array(
				'ok'         => false,
				'http_code'  => $http_code,
				'elapsed'    => $elapsed,
				'body'       => $body,
				'json'       => null,
				'error'      => $this->format_diagnosed_error( $diagnosis ),
				'error_code' => $diagnosis['code'],
			);
		}

		$json       = null;
		$json_error = null;
		$error_code = null;
		if ( $body !== false && $body !== '' ) {
			$json = json_decode( $body, true );
			if ( $json === null && json_last_error() !== JSON_ERROR_NONE ) {
				// HTTP 200 but body isn't valid JSON — likely an HTML page
				// from a site that doesn't have the exporter installed.
				$diagnosis = $this->diagnose_http_error( 200, $body );
				if ( $diagnosis['code'] === 'HTML_RESPONSE' ) {
					$json_error = $this->format_diagnosed_error( $diagnosis );
					$error_code = $diagnosis['code'];
				} else {
					$json_error = 'Invalid JSON: ' . json_last_error_msg();
					$error_code = 'INVALID_JSON';
				}
			}
		}

		return array(
			'ok'         => $json_error === null,
			'http_code'  => $http_code,
			'elapsed'    => $elapsed,
			'body'       => $body,
			'json'       => $json,
			'error'      => $json_error,
			'error_code' => $error_code,
		);
	}

	/**
	 * Fetch and incrementally parse a streaming multipart response.
	 *
	 * @param string        $url        Request URL.
	 * @param string|null   $cursor     Durable remote cursor.
	 * @param callable      $on_chunk   Handler for each parsed multipart part or file body event.
	 * @param string        $user_agent User-Agent header value.
	 * @param array|null    $post_data {
	 *     Optional POST fields.
	 * }
	 * @return array {
	 *     Response timing statistics.
	 *
	 *     @type float $ttfb       Time to first response byte in seconds.
	 *     @type float $total_time Total request time in seconds.
	 * }
	 */
	public function fetch_streaming(
		string $url,
		?string $cursor,
		callable $on_chunk,
		string $user_agent,
		?array $post_data = null
	): array {
		$this->reset_request_state();

		// Log HTTP request details
		$log_parts = array( 'HTTP_REQUEST', $post_data ? 'POST' : 'GET', $url );

		if ( $post_data && isset( $post_data['file_list'] ) ) {
			$file_list_part = $post_data['file_list'];
			if ( $file_list_part instanceof \CURLFile ) {
				$upload_path = $file_list_part->getFilename();
				$upload_size = is_string( $upload_path )
					? filesize( $upload_path )
					: false;
				$upload_size = $upload_size === false ? 0 : $upload_size;
				$log_parts[] = 'file_list_file=' . $upload_size . 'b';
			} else {
				$log_parts[] = 'file_list=' . strlen( (string) $file_list_part ) . 'b';
			}
		}

		$this->audit_log( implode( ' | ', $log_parts ), false );

		$ch = curl_init( $url );
		apply_curl_proxy_from_environment( $ch );
		apply_curl_ca_bundle( $ch );

		$parser              = null;
		$current_chunk       = null;
		$bytes_received      = 0;
		$last_heartbeat      = microtime( true );
		$last_progress_check = microtime( true );
		$last_bytes_received = 0;
		$error_body          = '';
		$saw_completion      = false;

		// Build headers to look like a real browser
		$headers = array_merge(
			$this->get_base_headers(
				'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
				$user_agent
			),
			array(
				'Upgrade-Insecure-Requests: 1',
				'Sec-Fetch-Dest: document',
				'Sec-Fetch-Mode: navigate',
				'Sec-Fetch-Site: none',
				'Sec-Fetch-User: ?1',
			)
		);

		if ( $cursor ) {
			$headers[] = "X-Export-Cursor: {$cursor}";
		}

		// Configure POST data if provided.  We need to know the body
		// content BEFORE generating HMAC headers so the content hash
		// can be included in the signature.
		$body_for_signing = '';
		if ( $post_data !== null ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			$has_file = false;
			foreach ( $post_data as $value ) {
				if ( $value instanceof \CURLFile ) {
					$has_file = true;
					break;
				}
			}
			if ( $has_file ) {
				// For CURLFile uploads, sign the raw file content — this
				// is the logical payload the server will receive, even
				// though curl wraps it in multipart framing.
				foreach ( $post_data as $value ) {
					if ( $value instanceof \CURLFile ) {
						$body_for_signing .= file_get_contents( $value->getFilename() );
					}
				}
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
			} else {
				$body_for_signing = http_build_query( $post_data );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body_for_signing );
			}
		}

		// Append HMAC auth headers now that we know the body content
		array_push( $headers, ...$this->get_hmac_headers( $body_for_signing ) );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_FOLLOWLOCATION  => false,
				// Don't cap total transfer time — streaming responses can
				// legitimately run for 20+ minutes. Instead, detect stalled
				// connections: timeout only when fewer than 1 byte/sec is
				// received for 300 consecutive seconds.
				CURLOPT_LOW_SPEED_LIMIT  => 1,
				CURLOPT_LOW_SPEED_TIME   => 300,
				CURLOPT_ENCODING         => 'gzip, deflate',
				// Tick the spinner during transfers. curl calls this roughly
				// once per second even when no data is flowing, which keeps
				// the Braille spinner rotating so it looks alive.
				CURLOPT_NOPROGRESS       => false,
				CURLOPT_PROGRESSFUNCTION => function () {
					$this->report_progress( array( 'type' => 'tick' ) );
					return 0; // 0 = continue, non-zero = abort
				},
				CURLOPT_HTTPHEADER       => $headers,
				CURLOPT_HEADERFUNCTION   => function ( $curl_handle, $header_line ) use (
					&$parser,
					&$current_chunk,
					&$saw_completion,
					$on_chunk
				) {
					$length = strlen( $header_line );

					// Parse Content-Type to extract boundary
					if ( stripos( $header_line, 'Content-Type:' ) === 0 ) {
						// Find boundary parameter
						$position = stripos( $header_line, 'boundary=' );
						if ( $position !== false ) {
							$boundary_start = $position + 9; // length of 'boundary='
							$boundary_value = trim( substr( $header_line, $boundary_start ) );

							// Remove quotes if present
							if ( $boundary_value[0] === '"' ) {
								$quote_end = strpos( $boundary_value, '"', 1 );
								if ( $quote_end !== false ) {
									$boundary_value = substr( $boundary_value, 1, $quote_end - 1 );
								}
							} else {
								// Find end (semicolon, comma, or whitespace)
								$end_position   = strcspn( $boundary_value, ";,\r\n \t" );
								$boundary_value = substr( $boundary_value, 0, $end_position );
							}

							if ( $boundary_value !== '' ) {
								$this->audit_log(
									"Creating multipart parser with boundary: $boundary_value",
									false
								);
								$parser = new MultipartStreamParser(
									$boundary_value,
									$this->make_chunk_handler(
										$on_chunk,
										$current_chunk,
										$saw_completion
									)
								);
							}
						}
					}

					return $length;
				},
				CURLOPT_WRITEFUNCTION    => function ( $curl_handle, $data ) use (
					&$parser,
					&$current_chunk,
					&$saw_completion,
					&$bytes_received,
					&$last_heartbeat,
					&$last_progress_check,
					&$last_bytes_received,
					&$error_body,
					$on_chunk
				) {
					// If no parser yet, we might be receiving an error response
					if ( ! $parser ) {
						$error_body .= $data;
						if ( strlen( $error_body ) > 65536 ) {
							$error_body = substr( $error_body, -65536 );
						}

						// Strict fallback: if body starts with a boundary line, parse it.
						if ( strncmp( $error_body, '--boundary-', 11 ) === 0 ) {
							$line_end = strpos( $error_body, "\n" );
							if ( $line_end !== false ) {
								$line = rtrim( substr( $error_body, 0, $line_end ), "\r\n" );
								if ( strncmp( $line, '--boundary-', 11 ) === 0 ) {
									$boundary = substr( $line, 2 );
									if ( $boundary !== '' ) {
										$this->audit_log(
											"Detected boundary in body (no Content-Type): {$boundary}",
											false
										);
										$parser = new MultipartStreamParser(
											$boundary,
											$this->make_chunk_handler(
												$on_chunk,
												$current_chunk,
												$saw_completion
											)
										);
										$parser->feed( $error_body );
										$error_body = '';
									}
								}
							}
						}

						static $logged_no_parser = false;
						if ( ! $logged_no_parser && strlen( $error_body ) > 0 ) {
							$this->audit_log(
								'No parser, accumulating error body (first 500 chars): ' .
									substr( $error_body, 0, 500 ),
								false
							);
							$logged_no_parser = true;
						}
					}

					if ( $parser ) {
						$parser->feed( $data );
					}

					$bytes_received += strlen( $data );

					// Check for stuck/slow transfer every 5 seconds
					$now = microtime( true );
					if ( $now - $last_progress_check >= 5.0 ) {
						$bytes_since_check = $bytes_received - $last_bytes_received;
						$rate              = $bytes_since_check / 5.0; // bytes per second

						$this->report_progress(
							array(
								'type'            => 'progress_check',
								'bytes_received'  => $bytes_received,
								'bytes_last_5s'   => $bytes_since_check,
								'rate_bps'        => round( $rate ),
							)
						);

						// If we're receiving less than 1KB/s for 5 seconds, something is wrong
						if ( $bytes_since_check < 1024 && $bytes_received > 0 ) {
							$this->audit_log(
								"Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
								false
							);
						}

						$last_progress_check = $now;
						$last_bytes_received = $bytes_received;
					}

					// Report a heartbeat every second. The caller decides whether and how to display it.
					if ( $now - $last_heartbeat >= 1.0 ) {
						$this->report_progress(
							array(
								'type'           => 'heartbeat',
								'bytes_received' => $bytes_received,
							)
						);
						$last_heartbeat = $now;
					}

					return strlen( $data );
				},
			)
		);

		$this->audit_log( 'Executing curl request...', false );
		$this->report_progress( array( 'type' => 'waiting_for_response' ) );
		$result = curl_exec( $ch );
		$this->audit_log(
			'curl_exec completed, result=' . ( $result === false ? 'false' : 'true' ),
			false
		);

		try {
			$this->check_curl_error( $ch );

			$http_code           = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$this->last_http_code = $http_code;
			$redirect_url        = curl_getinfo( $ch, CURLINFO_REDIRECT_URL ) ?: null;
			$ttfb                = (float) curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME );
			$total_time          = (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
		} finally {
			@curl_close( $ch );
		}

		if ( $http_code !== 200 ) {
			// Log what we received
			$this->audit_log(
				"HTTP error {$http_code} | error_body length: " . strlen( $error_body ),
				true
			);

			$diagnosis = $this->diagnose_http_error( $http_code, $error_body, $redirect_url );
			$error_msg = $this->format_diagnosed_error( $diagnosis );

			// Append stack trace from the server if available.
			if ( $error_body ) {
				$error_data = json_decode( $error_body, true );
				if ( is_array( $error_data ) && isset( $error_data['trace'] ) ) {
					$error_msg .= "\n\nServer stack trace:\n" . $error_data['trace'];
				}
			}

			throw new RuntimeException( $error_msg );
		}

		if ( ! $parser ) {
			$snippet = $error_body ? substr( $error_body, 0, 500 ) : '';
			throw new TransientInterruptionException(
				'Invalid response: missing multipart boundary. ' .
					( $snippet !== '' ? "Body: {$snippet}" : '' )
			);
		}

		if ( ! $saw_completion ) {
			throw new TransientInterruptionException(
				'Invalid response: missing completion chunk from server.'
			);
		}

		return array(
			'ttfb'       => $ttfb,
			'total_time' => $total_time,
		);
	}

	/**
	 * Build the multipart chunk handler callback shared by both parser
	 * creation sites inside fetch_streaming.
	 *
	 * File parts are forwarded as body data arrives so large files are written
	 * to disk incrementally. Non-file parts are still accumulated until
	 * complete because they are small metadata/progress JSON payloads.
	 */
	private function make_chunk_handler(
		callable $on_chunk,
		&$current_chunk,
		bool &$saw_completion
	): callable {
		return function ( $event ) use ( $on_chunk, &$current_chunk, &$saw_completion ) {
			if ( $event['type'] === 'body' ) {
				$headers    = $event['headers'];
				$chunk_type = $headers['x-chunk-type'] ?? '';
				if ( $chunk_type === 'file' ) {
					if ( ! $current_chunk ) {
						$current_chunk = array(
							'headers'       => $headers,
							'body_streamed' => true,
							'started'       => false,
						);
					}

					$stream_headers = $headers;
					if ( ! empty( $current_chunk['started'] ) ) {
						$stream_headers['x-first-chunk'] = '0';
					}
					// The parser emits a separate complete event after the
					// last body bytes, so close/index the file from there.
					$stream_headers['x-last-chunk'] = '0';
					$on_chunk(
						array(
							'headers' => $stream_headers,
							'body'    => $event['data'],
							// Suppresses state saves while a streamed file
							// part body is still being written.
							'is_streaming_body' => true,
						)
					);
					$current_chunk['started'] = true;
					return;
				}

				if ( ! $current_chunk ) {
					$current_chunk = array(
						'headers' => $headers,
						'body'    => $event['data'],
					);
				} else {
					$current_chunk['body'] = ( $current_chunk['body'] ?? '' ) . $event['data'];
				}
			} elseif ( $event['type'] === 'complete' ) {
				$headers    = $event['headers'];
				$chunk_type = $headers['x-chunk-type'] ?? '';
				if ( $chunk_type === 'completion' ) {
					$saw_completion = true;
				}
				if ( $chunk_type === 'file' && ! empty( $current_chunk['body_streamed'] ) ) {
					$close_headers                  = $headers;
					$close_headers['x-first-chunk'] = '0';
					$on_chunk(
						array(
							'headers' => $close_headers,
							'body'    => '',
							// Forces a save at every streamed file-part
							// boundary, even if the periodic counter has not
							// reached SAVE_STATE_EVERY_N_CHUNKS.
							'is_streaming_close' => true,
						)
					);
				} elseif ( $current_chunk ) {
					// Chunk complete - emit to handler
					$on_chunk( $current_chunk );
				} elseif ( $headers ) {
					// No body data - emit just headers
					$on_chunk(
						array(
							'headers' => $headers,
							'body'    => '',
						)
					);
				}
				$current_chunk = null;
			}
		};
	}

	/**
	 * Return HMAC authentication headers formatted for curl ("Name: value"),
	 * or an empty array if no secret was configured.
	 *
	 * @param string $body The request body content whose SHA-256 hash will
	 *                     be included in the HMAC signature. For CURLFile
	 *                     uploads, pass the raw file content (not the
	 *                     multipart envelope); for form-encoded POST, pass
	 *                     the http_build_query() output; for GET, omit or
	 *                     pass empty string.
	 */
	private function get_hmac_headers( string $body = '' ): array {
		if ( $this->hmac_client === null ) {
			return array();
		}
		return $this->hmac_client->get_curl_headers( $body );
	}

	/** Build common request headers. */
	private function get_base_headers( string $accept, string $user_agent ): array {
		return array(
			"User-Agent: {$user_agent}",
			"Accept: {$accept}",
			'Accept-Language: en-US,en;q=0.9',
			'Accept-Encoding: gzip, deflate',
			'Cache-Control: no-cache',
			'Pragma: no-cache',
			'Connection: keep-alive',
		);
	}

	/**
	 * Reset curl-related state at the start of each HTTP request.
	 */
	private function reset_request_state(): void {
		$this->last_curl_errno   = null;
		$this->last_curl_timeout = false;
		$this->last_http_code    = null;
	}

	/**
	 * Check for cURL errors after curl_exec and record timeout state.
	 *
	 * @param mixed $curl_handle cURL handle.
	 * @throws CurlTimeoutException           When the request times out.
	 * @throws TransientInterruptionException When the response ends early.
	 * @throws RuntimeException               For every other cURL error.
	 */
	private function check_curl_error( $curl_handle ): void {
		if ( ! curl_errno( $curl_handle ) ) {
			return;
		}
		$errno         = curl_errno( $curl_handle );
		$error         = curl_error( $curl_handle );
		$timeout_errno = defined( 'CURLE_OPERATION_TIMEDOUT' )
			? CURLE_OPERATION_TIMEDOUT
			: 28;
		$this->last_curl_errno   = $errno;
		$this->last_curl_timeout = $errno === $timeout_errno;
		if ( $this->last_curl_timeout ) {
			throw new CurlTimeoutException( "cURL error: {$error}" );
		}
		// These errors mean the response ended before cURL could finish
		// receiving it. Content-decoding failures such as
		// CURLE_BAD_CONTENT_ENCODING (61) remain fatal because the same bytes
		// will fail again after resumption.
		//   18 = CURLE_PARTIAL_FILE (transfer closed mid-stream)
		//   52 = CURLE_GOT_NOTHING (empty response)
		//   56 = CURLE_RECV_ERROR (connection reset / receive failure)
		if ( in_array( $errno, array( 18, 52, 56 ), true ) ) {
			throw new TransientInterruptionException(
				"cURL error ({$errno}): {$error}"
			);
		}
		throw new RuntimeException( "cURL error ($errno): {$error}" );
	}

	/**
	 * Format a diagnosed error as a single string for display.
	 * Also stores the error code on the instance for output_progress
	 * and write_progress_file to pick up.
	 */
	private function format_diagnosed_error( array $diagnosis ): string {
		$this->last_error_code = $diagnosis['code'];
		return $diagnosis['message'];
	}

	/** Write one transport audit entry. */
	private function audit_log( string $message, bool $to_console = true ): void {
		call_user_func( $this->audit_logger, $message, $to_console );
	}

	/** Report one transport progress observation. */
	private function report_progress( array $progress ): void {
		call_user_func( $this->progress_reporter, $progress );
	}
}
