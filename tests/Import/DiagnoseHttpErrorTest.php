<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify that diagnose_http_error() maps HTTP status codes and response
 * bodies to the correct error codes and actionable messages.
 */
class DiagnoseHttpErrorTest extends TestCase
{
    private function diagnose(int $http_code, ?string $body = null, ?string $redirect_url = null, bool $has_secret = true): array
    {
        $client = new \ImportClient(
            'http://example.com',
            sys_get_temp_dir(),
            sys_get_temp_dir(),
        );

        $ref = new \ReflectionClass(\ImportClient::class);

        if ($has_secret) {
            $hmac = $ref->getProperty('hmac_client');
            // Any truthy object — we just need it non-null.
            $hmac->setValue($client, new \stdClass());
        }

        $method = $ref->getMethod('diagnose_http_error');
        return $method->invoke($client, $http_code, $body, $redirect_url);
    }

    private function isPotentiallyTransientHttpError(
        int $http_code,
        string $body,
        bool $has_secret = true
    ): bool {
        $client = new \ImportClient(
            'http://example.com',
            sys_get_temp_dir(),
            sys_get_temp_dir(),
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        if ($has_secret) {
            $reflection->getProperty('hmac_client')->setValue(
                $client,
                new \stdClass(),
            );
        }

        return $reflection->getMethod('is_potentially_transient_http_error')->invoke(
            $client,
            $http_code,
            $body,
        );
    }

    // ── Redirects ────────────────────────────────────────────────

    public function testRedirectWithTargetUrl()
    {
        $result = $this->diagnose(301, '', 'https://example.com/');
        $this->assertSame('REDIRECT', $result['code']);
        $this->assertStringContainsString('https://example.com/', $result['message']);
    }

    public function testRedirectWithoutTargetUrl()
    {
        $result = $this->diagnose(302, '');
        $this->assertSame('REDIRECT', $result['code']);
        $this->assertStringContainsString('http vs https', $result['message']);
    }

    public function test307Redirect()
    {
        $result = $this->diagnose(307, '', 'https://other.com/');
        $this->assertSame('REDIRECT', $result['code']);
    }

    // ── Auth: no secret provided ─────────────────────────────────

    public function testAuthNoSecretProvided()
    {
        $result = $this->diagnose(403, '{"error":"Missing X-Auth-Signature header"}', null, false);
        $this->assertSame('AUTH_NO_SECRET', $result['code']);
        $this->assertStringContainsString('--secret', $result['message']);
    }

    public function test401NoSecretProvided()
    {
        $result = $this->diagnose(401, '', null, false);
        $this->assertSame('AUTH_NO_SECRET', $result['code']);
    }

    // ── Auth: secret mismatch ────────────────────────────────────

    public function testAuthSecretMismatch()
    {
        $result = $this->diagnose(403, '{"error":"HMAC signature verification failed"}');
        $this->assertSame('AUTH_SECRET_MISMATCH', $result['code']);
        $this->assertStringContainsString('does not match', $result['message']);
    }

    // ── Auth: clock skew ─────────────────────────────────────────

    public function testAuthClockSkew()
    {
        $server_msg = 'Request timestamp expired. Difference: 400.00 seconds, max allowed: 300 seconds';
        $result = $this->diagnose(403, json_encode(['error' => $server_msg]));
        $this->assertSame('AUTH_CLOCK_SKEW', $result['code']);
        $this->assertStringContainsString('400.00 seconds', $result['message']);
    }

    // ── Auth: content tampered ───────────────────────────────────

    public function testAuthContentTampered()
    {
        $result = $this->diagnose(403, '{"error":"Content hash mismatch: body was modified in transit"}');
        $this->assertSame('AUTH_CONTENT_TAMPERED', $result['code']);
        $this->assertStringContainsString('modified in transit', $result['message']);
    }

    // ── Auth: headers stripped ───────────────────────────────────

    public function testAuthHeadersStripped()
    {
        $result = $this->diagnose(403, '{"error":"Missing X-Auth-Signature header"}');
        $this->assertSame('AUTH_HEADERS_STRIPPED', $result['code']);
        $this->assertStringContainsString('Missing X-Auth-Signature', $result['message']);
    }

    public function testAuthMissingNonceHeader()
    {
        $result = $this->diagnose(403, '{"error":"Missing X-Auth-Nonce header"}');
        $this->assertSame('AUTH_HEADERS_STRIPPED', $result['code']);
        $this->assertStringContainsString('Missing X-Auth-Nonce', $result['message']);
    }

    // ── Auth: unexplained 403 ────────────────────────────────────

    public function testAuthUnexplained403NoBody()
    {
        $result = $this->diagnose(403, '');
        $this->assertSame('AUTH_FAILED', $result['code']);
        $this->assertStringContainsString('firewall', $result['message']);
    }

    public function testAuthUnexplained403HtmlBody()
    {
        $result = $this->diagnose(403, '<html><body>Forbidden</body></html>');
        $this->assertSame('AUTH_FAILED', $result['code']);
    }

    // ── Auth: unknown server message ─────────────────────────────

    public function testAuthUnknownServerMessage()
    {
        $result = $this->diagnose(403, '{"error":"Some future error we haven\'t seen"}');
        $this->assertSame('AUTH_FAILED', $result['code']);
        $this->assertStringContainsString("Some future error", $result['message']);
    }

    // ── Export not configured (503) ──────────────────────────────

    public function testExportNotConfigured503()
    {
        $body = '{"error":"Export not configured. Please configure the shared secret in WordPress admin under Tools > Site Export."}';
        $result = $this->diagnose(503, $body);
        $this->assertSame('EXPORT_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    // ── Not found (404) ──────────────────────────────────────────

    public function testNotFound404WithHtml()
    {
        $result = $this->diagnose(404, '<html><head><title>404 Not Found</title></head></html>');
        $this->assertSame('NOT_FOUND', $result['code']);
        $this->assertStringContainsString('not installed', $result['message']);
    }

    public function testNotFound404WithEmptyBody()
    {
        $result = $this->diagnose(404, '');
        $this->assertSame('NOT_FOUND', $result['code']);
        $this->assertStringContainsString('install-server', $result['message']);
    }

    // ── Server errors (500+) ─────────────────────────────────────

    public function testServerError500WithMessage()
    {
        $result = $this->diagnose(500, '{"error":"Allowed memory size exhausted"}');
        $this->assertSame('SERVER_ERROR', $result['code']);
        $this->assertStringContainsString('memory size exhausted', $result['message']);
        $this->assertStringContainsString('error log', $result['message']);
    }

    public function testServerError500NoBody()
    {
        $result = $this->diagnose(500, '');
        $this->assertSame('SERVER_ERROR', $result['code']);
        $this->assertStringContainsString('500', $result['message']);
    }

    public function testServerError502()
    {
        $result = $this->diagnose(502, '<html>Bad Gateway</html>');
        $this->assertSame('SERVER_ERROR', $result['code']);
    }

    public function testPotentiallyTransientHttpErrorClassification()
    {
        $this->assertTrue($this->isPotentiallyTransientHttpError(
            418,
            '<!doctype html><title>Temporary bot response</title>',
        ));
        $this->assertTrue($this->isPotentiallyTransientHttpError(
            403,
            '<!doctype html><title>Temporary firewall response</title>',
        ));
        $this->assertTrue($this->isPotentiallyTransientHttpError(
            403,
            '{"error":"Bot protection is temporarily unavailable"}',
        ));
        $this->assertTrue($this->isPotentiallyTransientHttpError(
            503,
            '{"error":"Upstream service is temporarily unavailable"}',
        ));
        $this->assertFalse($this->isPotentiallyTransientHttpError(
            403,
            '{"error":"Invalid timestamp format","code":403}',
        ));
        $this->assertFalse($this->isPotentiallyTransientHttpError(
            503,
            '{"error":"Invalid secret.php configuration","code":503}',
        ));
        $this->assertFalse($this->isPotentiallyTransientHttpError(
            500,
            '{"error":"Reprint Server runtime is incomplete","code":500}',
        ));
        $this->assertFalse($this->isPotentiallyTransientHttpError(
            415,
            '<!doctype html><title>Unsupported Media Type</title>',
        ));
    }

    public function testPullJsonRequestReportsHttp415AsPossibleFirewallGreylist()
    {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('HTTP 415 wire coverage requires PHP curl and pcntl.');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertNotFalse($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);

        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_timeout($connection, 3);
            $request = '';
            while (strpos($request, "\r\n\r\n") === false && !feof($connection)) {
                $piece = fread($connection, 64 * 1024);
                if (!is_string($piece) || $piece === '') {
                    break;
                }
                $request .= $piece;
            }
            if (strpos($request, 'GET /?reprint-api=1') !== 0) {
                exit(3);
            }
            $body = '<!doctype html><title>Unsupported Media Type</title>';
            fwrite(
                $connection,
                "HTTP/1.1 415 Unsupported Media Type\r\nContent-Type: text/html\r\nContent-Length: "
                    . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body
            );
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new \ImportClient(
            'http://' . $address . '/?reprint-api=1',
            sys_get_temp_dir(),
            sys_get_temp_dir(),
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        $method = $reflection->getMethod('fetch_json');
        $result = $method->invoke($client, 'http://' . $address . '/?reprint-api=1&endpoint=preflight');
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(415, $result['http_code']);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $result['error_code']);
        $this->assertStringContainsString('Unsupported Media Type', $result['error']);
        $this->assertStringContainsString('normally means', $result['error']);
        $this->assertStringContainsString('greylist', $result['error']);
        $this->assertStringContainsString('allowlist', $result['error']);
    }

    // ── HTML response on any status ──────────────────────────────

    public function testHtmlResponseOn200()
    {
        $result = $this->diagnose(200, '<html><body>Welcome to WordPress</body></html>');
        $this->assertSame('HTML_RESPONSE', $result['code']);
        $this->assertSame(200, $result['http_code']);
        $this->assertStringContainsString('not installed', $result['message']);
    }

    public function testHtmlResponseDetectsDoctype()
    {
        $result = $this->diagnose(200, '<!DOCTYPE html><html><body>Hello</body></html>');
        $this->assertSame('HTML_RESPONSE', $result['code']);
    }

    public function testHtmlResponseDetectsLeadingTag()
    {
        // XML-like response that starts with < but isn't JSON
        $result = $this->diagnose(200, '<?xml version="1.0"?><error>oops</error>');
        $this->assertSame('HTML_RESPONSE', $result['code']);
    }

    // ── JSON body takes precedence over HTML heuristics ──────────

    public function testJsonBodyNotMistakenForHtml()
    {
        // Valid JSON that happens to contain HTML-like strings
        $result = $this->diagnose(200, '{"error":"<html> is not allowed"}');
        // Should NOT be HTML_RESPONSE — the body parsed as valid JSON
        $this->assertNotSame('HTML_RESPONSE', $result['code']);
    }

    // ── Fallback ─────────────────────────────────────────────────

    public function testFallbackWithServerMessage()
    {
        $result = $this->diagnose(418, '{"error":"I am a teapot"}');
        $this->assertSame('HTTP_ERROR', $result['code']);
        $this->assertStringContainsString('teapot', $result['message']);
        $this->assertStringContainsString('418', $result['message']);
    }

    public function testFallbackNoBody()
    {
        $result = $this->diagnose(418, '');
        $this->assertSame('HTTP_ERROR', $result['code']);
        $this->assertStringContainsString('418', $result['message']);
    }

    // ── Null and false body handling ─────────────────────────────

    public function testNullBody()
    {
        $result = $this->diagnose(500, null);
        $this->assertSame('SERVER_ERROR', $result['code']);
    }

    // ── error_code is stored on instance ─────────────────────────

    public function testFormatDiagnosedErrorStoresCodeOnInstance()
    {
        $client = new \ImportClient(
            'http://example.com',
            sys_get_temp_dir(),
            sys_get_temp_dir(),
        );

        $ref = new \ReflectionClass(\ImportClient::class);
        $diagnose = $ref->getMethod('diagnose_http_error');
        $format = $ref->getMethod('format_diagnosed_error');

        $diagnosis = $diagnose->invoke($client, 404, '');
        $format->invoke($client, $diagnosis);

        $this->assertSame('NOT_FOUND', $client->last_error_code);
    }
}
