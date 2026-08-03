<?php
/**
 * Remote upload proxy route handler.
 *
 * Returns PHP code that proxies requests for missing uploads to the source
 * site while file synchronization is still incomplete.
 */
function remote_upload_proxy_code(): string
{
    return <<<'PHP'
/**
 * Proxy missing uploads from the source site until files-pull completes.
 *
 * This runs before WordPress boots. If a request under /wp-content/uploads/
 * is missing locally, it fetches the corresponding source URL with cURL and
 * relays the response status, headers, and body to the visitor.
 */
(function() {
	if (!defined('REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL')) return;
	if (!defined('REPRINT_PULL_STATE_FILE')) return;
	if (!function_exists('curl_init')) return;

	$proxy_enabled = false;
	$pull_state_file = REPRINT_PULL_STATE_FILE;
	if (
		is_string($pull_state_file) &&
		$pull_state_file !== '' &&
		is_readable($pull_state_file)
	) {
		$pull_state = json_decode((string) file_get_contents($pull_state_file), true);
		if (is_array($pull_state)) {
			$filter = $pull_state['filter'] ?? 'none';
			$command = $pull_state['active_resumable_command']['command_name'] ?? null;
			$status = $pull_state['active_resumable_command']['completion_state'] ?? null;
			$proxy_enabled =
				$filter === 'essential-files' ||
				(
					$command === 'files-pull' &&
					$status !== null &&
					$status !== 'complete'
				);
		}
	}

	if (!$proxy_enabled) return;

	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	if ($method !== 'GET' && $method !== 'HEAD') return;

	$uri = $_SERVER['REQUEST_URI'] ?? '';
	$path = parse_url($uri, PHP_URL_PATH);
	if (!is_string($path) || $path === '') return;

	if (!preg_match('#/wp-content/uploads/(.+)$#', $path, $matches)) return;

	$content_dir = defined('WP_CONTENT_DIR')
		? rtrim(WP_CONTENT_DIR, '/')
		: rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/wp-content';
	$local_path = $content_dir . '/uploads/' . ltrim($matches[1], '/');
	if (file_exists($local_path)) return;

	$target_url = rtrim(REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL, '/') . '/' . ltrim($matches[1], '/');
	$query = parse_url($uri, PHP_URL_QUERY);
	if (is_string($query) && $query !== '') {
		$target_url .= '?' . $query;
	}

	$request_headers = ['Accept-Encoding: identity'];
	$forwarded_headers = [
		'HTTP_ACCEPT' => 'Accept',
		'HTTP_IF_MODIFIED_SINCE' => 'If-Modified-Since',
		'HTTP_IF_NONE_MATCH' => 'If-None-Match',
		'HTTP_IF_RANGE' => 'If-Range',
		'HTTP_RANGE' => 'Range',
		'HTTP_USER_AGENT' => 'User-Agent',
	];
	foreach ($forwarded_headers as $server_key => $header_name) {
		$value = $_SERVER[$server_key] ?? null;
		if (is_string($value) && $value !== '') {
			$request_headers[] = $header_name . ': ' . $value;
		}
	}

	$status_code = 502;
	$response_headers = [];
	$current_headers = [];
	$headers_sent_to_client = false;
	$send_headers = static function() use (&$headers_sent_to_client, &$status_code, &$response_headers) {
		if ($headers_sent_to_client) {
			return;
		}
		$headers_sent_to_client = true;

		http_response_code($status_code > 0 ? $status_code : 502);
		foreach ($response_headers as $header_line) {
			if (preg_match('/^(Connection|Keep-Alive|Proxy-Authenticate|Proxy-Authorization|TE|Trailer|Transfer-Encoding|Upgrade):/i', $header_line)) {
				continue;
			}
			header($header_line, false);
		}
	};

	$curl = curl_init($target_url);
	if ($curl === false) {
		return;
	}

	// Respect ALL_PROXY for environments that route egress through an
	// outbound proxy. libcurl sometimes picks this up on its own, but
	// some SAPIs strip env vars before PHP starts — set it explicitly.
	$all_proxy = getenv('ALL_PROXY');
	if (is_string($all_proxy) && $all_proxy !== '') {
		curl_setopt($curl, CURLOPT_PROXY, $all_proxy);
	}

	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($curl, CURLOPT_FAILONERROR, false);
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($curl, CURLOPT_TIMEOUT, 0);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $request_headers);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
	curl_setopt($curl, CURLOPT_HEADER, false);

	if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
		curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
	}
	if ($method === 'HEAD') {
		curl_setopt($curl, CURLOPT_NOBODY, true);
	}

	curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function($ch, $line) use (&$status_code, &$response_headers, &$current_headers) {
		$length = strlen($line);
		$trimmed = trim($line);

		if ($trimmed === '') {
			$response_headers = $current_headers;
			return $length;
		}

		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches)) {
			$status_code = (int) $matches[1];
			$current_headers = [];
			return $length;
		}

		$current_headers[] = $trimmed;
		return $length;
	});

	curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function($ch, $chunk) use ($send_headers) {
		$send_headers();
		echo $chunk;
		flush();
		return strlen($chunk);
	});

	$ok = curl_exec($curl);
	if ($ok === false) {
		if (!headers_sent()) {
			http_response_code(502);
			header('Content-Type: text/plain; charset=UTF-8');
		}
		echo "Remote upload proxy failed.";
		curl_close($curl);
		exit;
	}

	curl_close($curl);
	$send_headers();
	exit;
})();
PHP;
}
