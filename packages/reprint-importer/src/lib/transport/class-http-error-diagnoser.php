<?php

namespace Reprint\Importer\Transport;

final class HttpErrorDiagnoser
{
    /**
     * Diagnose an HTTP error and return a user-friendly message with
     * actionable advice.
     *
     * Returns ['message' => ..., 'code' => ...].
     */
    public static function diagnose(
        int $http_code,
        ?string $body,
        ?string $redirect_url = null,
        bool $has_secret = true
    ): array {
        $body = ($body !== null && $body !== false) ? $body : '';

        $decoded = json_decode($body, true);
        $server_msg = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        $looks_like_html = !is_array($decoded) && $body !== '' && (
            stripos($body, '<html') !== false ||
            stripos($body, '<!doctype') !== false ||
            str_starts_with($body, '<')
        );

        if ($http_code >= 300 && $http_code < 400) {
            $msg = $redirect_url
                ? "Wrong URL. The server redirected to {$redirect_url} " .
                  "(HTTP {$http_code}).\n\n" .
                  "Reprint does not follow redirects to avoid silently " .
                  "connecting to the wrong server. Retry with the target " .
                  "URL above."
                : "Wrong URL. The server returned a redirect (HTTP {$http_code}) " .
                  "instead of the export API.\n\n" .
                  "Reprint does not follow redirects. Check whether the site " .
                  "uses http vs https or www vs non-www and retry with the " .
                  "canonical URL.";
            return ['code' => 'REDIRECT', 'message' => $msg];
        }

        if ($http_code === 401 || $http_code === 403) {
            if (!$has_secret) {
                return [
                    'code' => 'AUTH_NO_SECRET',
                    'message' =>
                        "No --secret was provided. The remote site requires " .
                        "authentication.\n\n" .
                        "Pass --secret=YOUR_SECRET using the same secret " .
                        "configured in the Site Export plugin on the remote site.",
                ];
            }

            if ($server_msg === null) {
                return [
                    'code' => 'AUTH_FAILED',
                    'message' =>
                        "The request was blocked (HTTP {$http_code}) but the " .
                        "server did not say why. The exporter plugin always " .
                        "explains authentication failures, so something " .
                        "upstream is blocking the request — a server-level " .
                        "firewall, .htaccess rule, or security plugin.",
                ];
            }

            if (str_contains($server_msg, 'HMAC signature verification failed')) {
                return [
                    'code' => 'AUTH_SECRET_MISMATCH',
                    'message' =>
                        "Wrong shared secret. The --secret value does not match " .
                        "the one configured in the Site Export plugin settings " .
                        "(wp-admin → Site Export).",
                ];
            }

            if (str_contains($server_msg, 'timestamp expired')) {
                return [
                    'code' => 'AUTH_CLOCK_SKEW',
                    'message' =>
                        "Clock out of sync. {$server_msg}\n\n" .
                        "Check this machine's clock (run `date`) and compare " .
                        "it with the server's time.",
                ];
            }

            if (str_contains($server_msg, 'Content hash mismatch')) {
                return [
                    'code' => 'AUTH_CONTENT_TAMPERED',
                    'message' =>
                        "Request body was modified in transit. A proxy, CDN, " .
                        "or firewall between this machine and the server is " .
                        "altering the request content.",
                ];
            }

            if (str_contains($server_msg, 'Missing X-Auth-')) {
                return [
                    'code' => 'AUTH_HEADERS_STRIPPED',
                    'message' =>
                        "Authentication headers were stripped. The server " .
                        "reported: {$server_msg}\n\n" .
                        "A proxy, CDN, or security plugin is removing custom " .
                        "HTTP headers before they reach WordPress.",
                ];
            }

            return [
                'code' => 'AUTH_FAILED',
                'message' => "Authentication failed: {$server_msg}",
            ];
        }

        if ($http_code === 503 && $server_msg !== null) {
            return [
                'code' => 'EXPORT_NOT_CONFIGURED',
                'message' =>
                    "The exporter plugin is installed but not configured. " .
                    "The server reported: {$server_msg}",
            ];
        }

        if ($http_code === 404) {
            $msg = "The exporter plugin is not installed on the remote site.";
            if ($looks_like_html) {
                $msg .= " The server returned an HTML 404 page instead of " .
                         "the export API.";
            } else {
                $msg .= " The server returned HTTP 404.";
            }
            $msg .= "\n\nRun `php reprint.phar install-exporter` for setup " .
                     "instructions.";
            return ['code' => 'NOT_FOUND', 'message' => $msg];
        }

        if ($http_code >= 500) {
            $msg = $server_msg
                ? "The remote server crashed: {$server_msg}"
                : "The remote server crashed (HTTP {$http_code}).";
            $msg .= "\n\nThis is a problem on the remote server. " .
                     "Check its PHP error log for details.";
            return ['code' => 'SERVER_ERROR', 'message' => $msg];
        }

        if ($looks_like_html) {
            return [
                'code' => 'HTML_RESPONSE',
                'http_code' => $http_code,
                'message' =>
                    "The exporter plugin is not installed on the remote site. " .
                    "The server returned an HTML page (HTTP {$http_code}) " .
                    "instead of a JSON API response.\n\n" .
                    "Run `php reprint.phar install-exporter` for setup " .
                    "instructions.",
            ];
        }

        return [
            'code' => 'HTTP_ERROR',
            'message' => $server_msg
                ? "HTTP error {$http_code}: {$server_msg}"
                : "Unexpected HTTP status {$http_code}.",
        ];
    }
}
