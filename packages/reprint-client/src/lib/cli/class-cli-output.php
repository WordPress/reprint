<?php

namespace Reprint\Importer\Cli;

use RuntimeException;

/**
 * Owns command-line output streams and terminal capability checks.
 */
class CliOutput {

	/** @var resource */
	private $standard_output;

	/** @var resource */
	private $standard_error;

	/**
	 * @param resource $standard_output Standard output stream.
	 * @param resource $standard_error Standard error stream.
	 */
	public function __construct( $standard_output, $standard_error ) {
		if ( ! is_resource( $standard_output ) || ! is_resource( $standard_error ) ) {
			throw new RuntimeException( 'CLI output requires open standard output and standard error streams.' );
		}
		$this->standard_output = $standard_output;
		$this->standard_error  = $standard_error;
	}

	public function write( string $message ): void {
		$this->write_to( $this->standard_output, $message, 'standard output' );
	}

	/**
	 * @param resource $stream Open output stream.
	 */
	private function write_to( $stream, string $message, string $stream_name ): void {
		$bytes_written = fwrite( $stream, $message );
		if ( $bytes_written === false || $bytes_written !== strlen( $message ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception describes a CLI stream failure, not HTML output.
			throw new RuntimeException( "Failed to write complete CLI output to {$stream_name}." );
		}
	}

	public function write_error( string $message ): void {
		$this->write_to( $this->standard_error, $message, 'standard error' );
	}

	public function standard_output_is_terminal(): bool {
		return function_exists( 'posix_isatty' ) && @posix_isatty( $this->standard_output );
	}

	public function standard_error_is_terminal(): bool {
		return function_exists( 'posix_isatty' ) && @posix_isatty( $this->standard_error );
	}
}
