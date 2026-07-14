<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use MultipartPushStreamClient;
use PushRequestSizer;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

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
        $this->assertStringContainsString('POST /?reprint-api=1&endpoint=staged_session_upload&session_id=', $received);
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

        $response = (string) json_encode(['status' => 'accepted', 'accepted' => []]);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(1, $result['parts_sent']);
    }

    public function testTargetPartLimitBoundsTheNextSourceRead(): void {
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
        $this->assertTrue($client->start_upload_request(str_repeat('b', 32)));
        $connection = stream_socket_accept($listener, 3);
        $this->assertNotFalse($connection);
        $this->read_available($connection);
        $this->assertSame(7, $client->next_file_body_bytes('bounded.bin', 100, 0));

        fclose($connection);
        fclose($listener);
        $client->finish_request();
    }

    public function testNextSourceReadReservesTheFileModeHeaderItWillSend(): void {
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

        $maximum = $client->next_file_body_bytes('mode.bin', 1000, 0, 0001);
        $this->assertGreaterThan(0, $maximum);
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'mode.bin',
            'total_bytes' => 1000,
            'offset' => 0,
            'mode' => 0001,
            'payload' => str_repeat('x', $maximum),
        ]), 'The read budget omitted bytes that send_part() adds for X-File-Mode.');
        $received = $this->read_available($connection);
        $this->assertStringContainsString('X-File-Mode: 0001', $received);

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
