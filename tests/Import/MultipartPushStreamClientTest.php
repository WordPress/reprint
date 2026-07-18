<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use MultipartPushStreamClient;
use PushRequestSizer;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php';

final class MultipartPushStreamClientTest extends TestCase {

    private const SECRET = 'multipart-stream-client-test-secret';

    public function testSendPartPutsMultipartBytesOnTheWireBeforeFinishRequest(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 4,
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('a', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $received = $this->read_available($connection);
        $this->assertStringContainsString('POST /?reprint-api=1&endpoint=push_upload&push_session_id=', $received);
        $this->assertStringNotContainsString('Expect: 100-continue', $received);

        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'streamed.bin',
            'total_bytes' => 3,
            'offset' => 0,
            'payload' => "a\0b",
        ]));
        $received .= $this->read_available($connection);
        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary=reprint-', $received);
        $this->assertStringContainsString('X-Chunk-Type: file', $received);
        $this->assertStringContainsString("a\0b", $received, 'The raw part payload is on the network before finish_request().');
        $this->assertTrue($client->send_part([
            'type' => 'delete-list',
            'offset' => 7,
            'payload' => "gone\0",
        ]));
        $received .= $this->read_available($connection);
        $this->assertStringContainsString('X-Chunk-Type: delete-list', $received);
        $this->assertStringContainsString('X-Delete-Offset: 7', $received);

        $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(2, $result['parts_sent']);
    }

    /**
     * Proves that two completed upload requests use one TCP connection.
     *
     * The child accepts exactly one socket and serves two complete chunked
     * requests from it. Opening another connection therefore cannot satisfy
     * the second request or make this test pass.
     */
    public function testBackToBackUploadRequestsReuseTheConnection(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Connection-reuse coverage requires PHP curl, pcntl, and CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_blocking($connection, false);
            $pending = '';
            for ($request_number = 1; $request_number <= 2; ++$request_number) {
                $deadline = microtime(true) + 8;
                $header_end = false;
                $closing_boundary = null;
                while (true) {
                    $piece = fread($connection, 64 * 1024);
                    if (is_string($piece) && $piece !== '') {
                        $pending .= $piece;
                    } elseif (microtime(true) > $deadline) {
                        fclose($connection);
                        fclose($listener);
                        exit(4);
                    } else {
                        usleep(1000);
                    }
                    if ($header_end === false) {
                        $header_end = strpos($pending, "\r\n\r\n");
                        if ($header_end !== false) {
                            $headers = substr($pending, 0, $header_end + 4);
                            if (
                                strpos($headers, 'POST /?reprint-api=1&endpoint=push_upload&push_session_id=') === false
                                || stripos($headers, "Transfer-Encoding: chunked\r\n") === false
                                || preg_match('/boundary=(reprint-[a-f0-9]+)/', $headers, $matches) !== 1
                            ) {
                                fclose($connection);
                                fclose($listener);
                                exit(7);
                            }
                            $closing_boundary = '--' . $matches[1] . "--\r\n";
                        }
                    }
                    if ($closing_boundary === null) {
                        continue;
                    }
                    $closing_at = strpos($pending, $closing_boundary, $header_end + 4);
                    if ($closing_at === false) {
                        continue;
                    }
                    $request_end = strpos($pending, "\r\n0\r\n\r\n", $closing_at + strlen($closing_boundary));
                    if ($request_end === false) {
                        continue;
                    }
                    $pending = substr($pending, $request_end + 7);
                    break;
                }

                $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
                $connection_header = $request_number === 1 ? 'keep-alive' : 'close';
                fwrite(
                    $connection,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response)
                        . "\r\nConnection: " . $connection_header . "\r\n\r\n" . $response
                );
            }
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);
        foreach (['first.bin', 'second.bin'] as $request_number => $path) {
            $this->assertTrue($client->start_upload_request(str_repeat( (string) ( $request_number + 4 ), 32)));
            $this->assertTrue($client->send_part([
                'type' => 'file',
                'path' => $path,
                'total_bytes' => 1,
                'offset' => 0,
                'payload' => 'x',
            ]));
            $result = $client->finish_request();
            $this->assertSame('complete', $result['status'], (string) json_encode($result));
        }
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status), 'The child must receive both requests on its one accepted connection.');
    }

    public function testTargetPartLimitBoundsTheNextLocalFileRead(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 64,
            'max_part_bytes' => 7,
            'connect_timeout' => 2,
        ]);
        $this->assertSame(7, $client->next_file_body_bytes('bounded.bin', 100, 0));
        $this->assertTrue($client->start_upload_request(str_repeat('b', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);
        $this->assertSame(7, $client->next_file_body_bytes('bounded.bin', 100, 0));

        fclose($connection);
        fclose($listener);
        $client->finish_request();
    }

    public function testNextLocalFileReadAndPartOmitModeTransport(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $request_sizer = new PushRequestSizer([
            'floor_bytes' => 512,
            'start_bytes' => 512,
            'max_bytes' => 512,
        ]);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'request_sizer' => $request_sizer,
            'chunk_bytes' => 1024,
            'connect_timeout' => 2,
        ]);
        $this->assertTrue($client->start_upload_request(str_repeat('d', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);

        $maximum = $client->next_file_body_bytes('mode.bin', 1000, 0);
        $this->assertGreaterThan(0, $maximum);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'mode.bin',
            'total_bytes' => 1000,
            'offset' => 0,
            'payload' => str_repeat('x', $maximum),
        ]));
        $received = $this->read_available($connection);
        $this->assertStringNotContainsString('X-File-Mode', $received);

        $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
        fclose($listener);
        $this->assertSame('complete', $client->finish_request()['status']);
    }

    public function testPlainHttpRequiresAnExplicitOptIn(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_http is true');
        new MultipartPushStreamClient([
            'base_url' => 'http://example.test/?reprint-api=1',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
        ]);
    }

    public function testRawHttp413LearnsASmallerRequestCeilingWithoutRequiringJson(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('c', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'too-large.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        fwrite($connection, "HTTP/1.1 413 Payload Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('retry', $result['status']);
        $this->assertSame('request_too_large', $result['reason']);
        $this->assertSame('HTTP 413 Request Entity Too Large.', $result['detail']);
        $this->assertLessThan(32 * 1024 * 1024, $client->get_request_sizer_state()['request_body_bytes']);
    }

    /**
     * Shows that an accepted empty upload does not establish a safe request size.
     *
     * The target has accepted only a MIME close, so the sender still knows
     * nothing about whether a larger decoded entity body would pass its stack.
     */
    public function testAcceptedEmptyUploadDoesNotGrowTheRequestBudget(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $request_sizer = new PushRequestSizer([
            'floor_bytes' => 512,
            'start_bytes' => 512,
            'max_bytes' => 2048,
        ]);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'request_sizer' => $request_sizer,
            'connect_timeout' => 2,
            'response_timeout' => 2,
        ]);
        $before = $client->get_request_sizer_state();
        $this->assertTrue($client->start_upload_request(str_repeat('7', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response)
                . "\r\nConnection: close\r\n\r\n" . $response
        );
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(0, $result['parts_sent']);
        $this->assertSame($before, $client->get_request_sizer_state());
    }

    public function testStructured413AppliesPostMaxBytesAndPreservesDetail(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('4', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'structured-too-large.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        $response = (string) json_encode([
            'status' => 'rejected',
            'reason' => 'request_too_large',
            'detail' => 'The decoded request body reached 16777217 bytes, exceeding the target post_max_size of 16777216 bytes.',
            'post_max_bytes' => 16 * 1024 * 1024,
        ]);
        fwrite($connection, "HTTP/1.1 413 Payload Too Large\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('retry', $result['status']);
        $this->assertSame('request_too_large', $result['reason']);
        $this->assertSame('The decoded request body reached 16777217 bytes, exceeding the target post_max_size of 16777216 bytes.', $result['detail']);
        $this->assertSame(16 * 1024 * 1024, $result['response']['post_max_bytes']);
        $this->assertLessThan(16 * 1024 * 1024, $client->get_request_sizer_state()['request_body_bytes']);
    }

    public function testMalformedNonemptyUploadResponseIsTerminal(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('e', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'malformed-response.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        $response = '<html>not json</html>';
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('failed', $result['status']);
        $this->assertSame('malformed_response', $result['reason']);
        $this->assertStringContainsString('Invalid JSON response', $result['detail']);
    }

    public function testPushRequestStopsReadingAnOversizedResponse(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Raw push-response coverage requires PHP curl and pcntl.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
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
            $response_bytes = 1024 * 1024 + 1;
            fwrite(
                $connection,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$response_bytes}\r\nConnection: close\r\n\r\n"
            );
            $remaining = $response_bytes;
            while ($remaining > 0) {
                $bytes_written = @fwrite($connection, str_repeat('x', min($remaining, 64 * 1024)));
                if (!is_int($bytes_written) || $bytes_written === 0) {
                    break;
                }
                $remaining -= $bytes_written;
            }
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'response_timeout' => 2,
        ]);
        $result = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => str_repeat('7', 32),
        ], ['created']);
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('failed', $result['status']);
        $this->assertSame('response_too_large', $result['reason']);
        $this->assertSame('The target response exceeded 1048576 bytes.', $result['detail']);
    }

    public function testUploadRequestStopsReadingAnOversizedResponse(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Raw upload-response coverage requires PHP curl, pcntl, and CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_timeout($connection, 3);
            $request = '';
            $closing_boundary = null;
            while (!feof($connection)) {
                $piece = fread($connection, 64 * 1024);
                if (!is_string($piece) || $piece === '') {
                    exit(3);
                }
                $request .= $piece;
                if (
                    $closing_boundary === null
                    && preg_match('/boundary=(reprint-[a-f0-9]+)/', $request, $matches) === 1
                ) {
                    $closing_boundary = '--' . $matches[1] . "--\r\n";
                }
                if ($closing_boundary !== null && strpos($request, $closing_boundary) !== false) {
                    break;
                }
            }
            $response_bytes = 1024 * 1024 + 1;
            fwrite(
                $connection,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$response_bytes}\r\nConnection: close\r\n\r\n"
            );
            $remaining = $response_bytes;
            while ($remaining > 0) {
                $bytes_written = @fwrite($connection, str_repeat('x', min($remaining, 64 * 1024)));
                if (!is_int($bytes_written) || $bytes_written === 0) {
                    break;
                }
                $remaining -= $bytes_written;
            }
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);
        $this->assertTrue($client->start_upload_request(str_repeat('6', 32)));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'oversized-response.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        $result = $client->finish_request();
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('failed', $result['status']);
        $this->assertSame('response_too_large', $result['reason']);
        $this->assertSame('The target response exceeded 1048576 bytes.', $result['detail']);
    }

    /**
     * Keeps redirects and unknown protocol failures terminal.
     *
     * Each case uses a real TCP response. Redirects must name the final target
     * required for a new signature, while an unrecognized rejection reason is
     * preserved for the caller instead of being guessed recoverable.
     */
    public function testPushRequestRedirectAndUnknownReasonAreTerminal(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Raw push-response coverage requires PHP curl and pcntl.');
        }
        foreach (['redirect', 'unknown-reason'] as $case) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $this->assertNotFalse($listener, (string) $error);
            $address = stream_socket_get_name($listener, false);
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
                if (strpos($request, 'POST /?reprint-api=1&endpoint=push_create&push_session_id=') === false) {
                    fclose($connection);
                    fclose($listener);
                    exit(3);
                }
                if ($case === 'redirect') {
                    fwrite(
                        $connection,
                        "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://example.test/final\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
                    );
                } else {
                    $body = (string) json_encode([
                        'status' => 'rejected',
                        'reason' => 'new_protocol_failure',
                        'detail' => 'The target returned a reason this client does not classify.',
                    ]);
                    fwrite(
                        $connection,
                        "HTTP/1.1 409 Conflict\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
                            . "\r\nConnection: close\r\n\r\n" . $body
                    );
                }
                fclose($connection);
                fclose($listener);
                exit(0);
            }

            $client = new MultipartPushStreamClient([
                'base_url' => 'http://' . $address . '/?reprint-api=1',
                'allow_http' => true,
                'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
                'connect_timeout' => 2,
                'response_timeout' => 2,
            ]);
            if ($case === 'redirect') {
                $result = $client->send_push_request('POST', 'push_create', [
                    'push_session_id' => str_repeat('8', 32),
                ], ['created']);
                $this->assertSame('failed', $result['status']);
                $this->assertSame('redirected', $result['reason']);
                $this->assertSame(
                    'The target redirected to http://example.test/final. Use that address as the push base_url.',
                    $result['detail']
                );
            } else {
                $result = $client->send_push_request('POST', 'push_create', [
                    'push_session_id' => str_repeat('9', 32),
                ], ['created']);
                $this->assertSame('failed', $result['status']);
                $this->assertSame('new_protocol_failure', $result['reason']);
                $this->assertSame('The target returned a reason this client does not classify.', $result['detail']);
            }
            pcntl_waitpid($child, $status);
            fclose($listener);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public function testZeroProgressUploadStallStopsWithoutATotalTransferTimeout(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 16 * 1024 * 1024,
            'connect_timeout' => 2,
            'stall_timeout' => 1,
            'response_timeout' => 2,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('f', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $started = microtime(true);
        $sent = $client->send_part([
            'type' => 'file',
            'path' => 'stalled.bin',
            'total_bytes' => 16 * 1024 * 1024,
            'offset' => 0,
            'payload' => str_repeat('x', 16 * 1024 * 1024),
        ]);
        $elapsed = microtime(true) - $started;
        $result = $client->finish_request();
        fclose($connection);
        fclose($listener);

        $this->assertFalse($sent);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(3.0, $elapsed);
        $this->assertSame('retry', $result['status']);
        $this->assertSame('request_failed', $result['reason']);
        $this->assertStringContainsString('no bytes moved for 1s', $result['detail']);
    }

    public function testResponseWaitTimeoutStartsAfterTheClosingBoundary(): void {
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Caller-driven multipart upload requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 1,
        ]);

        $this->assertTrue($client->start_upload_request(str_repeat('1', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'waiting.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        $started = microtime(true);
        $result = $client->finish_request();
        $elapsed = microtime(true) - $started;
        fclose($connection);
        fclose($listener);

        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(3.0, $elapsed);
        $this->assertSame('retry', $result['status']);
        $this->assertSame('request_failed', $result['reason']);
        $this->assertStringContainsString('no upload or response bytes moved for 1s', $result['detail']);
    }

    public function testSlowContinuousResponseProgressMayExceedTheResponseTimeout(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Slow wire coverage requires PHP curl, pcntl, and CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_blocking($connection, false);
            $deadline = microtime(true) + 10;
            $received = '';
            $closing = null;
            while (!feof($connection)) {
                $piece = fread($connection, 64 * 1024);
                if (is_string($piece) && $piece !== '') {
                    $received .= $piece;
                    if ($closing === null && preg_match('/boundary=(reprint-[a-f0-9]+)/', $received, $matches) === 1) {
                        $closing = '--' . $matches[1] . "--\r\n";
                    }
                    if ($closing !== null && strpos($received, $closing) !== false) {
                        break;
                    }
                } elseif (microtime(true) > $deadline) {
                    exit(3);
                } else {
                    usleep(1000);
                }
            }
            $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n");
            foreach (str_split($response, 8) as $piece) {
                if (@fwrite($connection, $piece) === false) {
                    fclose($connection);
                    fclose($listener);
                    exit(4);
                }
                usleep(350000);
            }
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 1,
        ]);
        $this->assertTrue($client->start_upload_request(str_repeat('3', 32)));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'slow-response.bin',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]));
        $started = microtime(true);
        $result = $client->finish_request();
        $elapsed = microtime(true) - $started;
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertGreaterThan(1.0, $elapsed);
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
    }

    public function testSlowContinuousUploadProgressMayExceedTheStallTimeout(): void {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Slow wire coverage requires PHP curl, pcntl, and CURL_READFUNC_PAUSE support.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_blocking($connection, false);
            $deadline = microtime(true) + 15;
            $received = '';
            $closing = null;
            $tail = '';
            while (!feof($connection)) {
                $piece = fread($connection, 64 * 1024);
                if (!is_string($piece) || $piece === '') {
                    if (microtime(true) > $deadline) {
                        exit(3);
                    }
                    usleep(1000);
                    continue;
                }
                if ($closing === null) {
                    $received .= $piece;
                    if (preg_match('/boundary=(reprint-[a-f0-9]+)/', $received, $matches) === 1) {
                        $closing = '--' . $matches[1] . "--\r\n";
                        $tail = substr($received, -strlen($closing));
                        $received = '';
                    }
                } else {
                    $tail .= $piece;
                }
                usleep(2000);
                if ($closing !== null) {
                    if (strpos($tail, $closing) !== false) {
                        break;
                    }
                    $tail = substr($tail, -strlen($closing));
                }
            }
            $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $client = new MultipartPushStreamClient([
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 8 * 1024 * 1024,
            'connect_timeout' => 2,
            'stall_timeout' => 1,
            'response_timeout' => 5,
        ]);
        $started = microtime(true);
        $this->assertTrue($client->start_upload_request(str_repeat('2', 32)));
        $sent = $client->send_part([
            'type' => 'file',
            'path' => 'slow-progress.bin',
            'total_bytes' => 8 * 1024 * 1024,
            'offset' => 0,
            'payload' => str_repeat('x', 8 * 1024 * 1024),
        ]);
        $result = $client->finish_request();
        $elapsed = microtime(true) - $started;
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertGreaterThan(1.0, $elapsed);
        $this->assertTrue($sent, (string) json_encode($result));
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
    }

    private function read_available($connection): string {
        stream_set_blocking($connection, false);
        $received = '';
        $deadline = microtime(true) + 2;
        do {
            $piece = fread($connection, 65536);
            if (is_string($piece) && $piece !== '') {
                $received .= $piece;
                $deadline = microtime(true) + 0.1;
            } elseif (microtime(true) < $deadline) {
                usleep(1000);
            }
        } while (microtime(true) < $deadline);
        return $received;
    }
}
