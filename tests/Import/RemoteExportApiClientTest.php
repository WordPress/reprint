<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Remote\RemoteExportApiClient;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class RemoteExportApiClientTest extends TestCase {

	public function testStreamingResponseTravelsOverHttpAndEmitsParts(): void {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'Remote export API wire coverage requires PHP curl and pcntl.' );
		}

		$saved_all_proxy = getenv( 'ALL_PROXY' );
		putenv( 'ALL_PROXY' );
		$listener = stream_socket_server( 'tcp://127.0.0.1:0', $error_number, $error_message );
		$this->assertNotFalse( $listener, (string) $error_message );
		$address      = stream_socket_get_name( $listener, false );
		$request_path = tempnam( sys_get_temp_dir(), 'remote-export-request-' );
		$child        = pcntl_fork();
		$this->assertNotSame( -1, $child );

		if ( $child === 0 ) {
			$connection = stream_socket_accept( $listener, 3 );
			if ( $connection === false ) {
				exit( 2 );
			}
			$request = '';
			while ( strpos( $request, "\r\n\r\n" ) === false ) {
				$piece = fread( $connection, 8192 );
				if ( ! is_string( $piece ) || $piece === '' ) {
					fclose( $connection );
					exit( 3 );
				}
				$request .= $piece;
			}
			file_put_contents( $request_path, $request );

			$boundary = 'remote-export-api-test';
			$body     = "--{$boundary}\r\n"
				. "X-Chunk-Type: metadata\r\n"
				. "Content-Length: 11\r\n\r\n"
				. '{"ok":true}' . "\r\n"
				. "--{$boundary}\r\n"
				. "X-Chunk-Type: completion\r\n"
				. "Content-Length: 0\r\n\r\n\r\n"
				. "--{$boundary}--\r\n";
			$response = "HTTP/1.1 200 OK\r\n"
				. "Content-Type: multipart/mixed; boundary={$boundary}\r\n"
				. 'Content-Length: ' . strlen( $body ) . "\r\n"
				. "Connection: close\r\n\r\n"
				. $body;
			fwrite( $connection, $response );
			fclose( $connection );
			fclose( $listener );
			exit( 0 );
		}

		$chunks = array();
		$client = new RemoteExportApiClient(
			'http://' . $address . '/?reprint-api=1',
			null,
			static function ( string $message, bool $to_console = true ): void {},
			static function ( array $progress ): void {},
		);
		$url    = $client->build_url( 'file_index', 'cursor-123' );
		$timing = $client->fetch_streaming(
			$url,
			'cursor-123',
			static function ( array $chunk ) use ( &$chunks ): void {
				$chunks[] = $chunk;
			},
			RemoteExportApiClient::USER_AGENTS[0]
		);

		pcntl_waitpid( $child, $status );
		fclose( $listener );
		$request = file_get_contents( $request_path );
		unlink( $request_path );
		if ( $saved_all_proxy === false ) {
			putenv( 'ALL_PROXY' );
		} else {
			putenv( 'ALL_PROXY=' . $saved_all_proxy );
		}

		$this->assertTrue( pcntl_wifexited( $status ) );
		$this->assertSame( 0, pcntl_wexitstatus( $status ) );
		$this->assertStringContainsString( 'endpoint=file_index', (string) $request );
		$this->assertStringContainsString( "X-Export-Cursor: cursor-123\r\n", (string) $request );
		$this->assertSame( 'metadata', $chunks[0]['headers']['x-chunk-type'] );
		$this->assertSame( '{"ok":true}', $chunks[0]['body'] );
		$this->assertSame( 'completion', $chunks[1]['headers']['x-chunk-type'] );
		$this->assertArrayHasKey( 'ttfb', $timing );
		$this->assertArrayHasKey( 'total_time', $timing );
	}
}
