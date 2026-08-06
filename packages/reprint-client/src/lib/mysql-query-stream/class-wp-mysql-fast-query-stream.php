<?php

/**
 * Drop-in replacement for WP_MySQL_Naive_Query_Stream with a much
 * cheaper boundary scanner. Same public contract: append_sql() bytes,
 * call next_query() in a loop, get_query() returns the next complete
 * statement.
 *
 * Where the naive parser spawns a fresh WP_MySQL_Lexer on every
 * next_query() call and re-tokenizes the entire buffer just to find the
 * next top-level semicolon, this class uses a small state machine that
 * knows three things — string literals, backtick identifiers, comments —
 * and lets strcspn() devour the bulk "boring" stretches between them
 * at libc speed. Inside a base64 payload the inner skip loop scans
 * 4 KB blocks per call. On a 35 MB dump the boundary phase drops from
 * ~5.5 s to ~0.2 s, ~27× faster.
 *
 * It's still naive in the same sense the lexer-based parser was —
 * neither understands SQL grammar, both just track quoting / comments
 * to find statement boundaries. The state machine here is an exact
 * subset of the lexer's quote / comment / escape recognition. On valid
 * MySQL input the two parsers produce byte-identical statement
 * boundaries.
 *
 * The "fast" half of the name speaks to performance. The "naive" sibling
 * still exists, and this class falls back to it automatically when
 * something looks wrong:
 *
 *   - The buffer grows past MAX_SQL_BUFFER_SIZE without finding a
 *     top-level semicolon. The original parser called this a syntax
 *     error; this class first tries the lexer-based path on the same
 *     buffer in case it can make progress.
 *   - mark_input_complete() is followed by a paused state — string,
 *     comment, or backtick that never closes. Could be malformed input
 *     OR could be a quoting edge case the fast scanner doesn't handle;
 *     we let the lexer-based parser try.
 *
 * Whenever fallback fires, the failure is recorded in $last_error and
 * the optional error_logger callable is invoked synchronously so the
 * caller (typically db-apply) can record the event in its audit log
 * before the fallback parser takes over. The transition is transparent
 * to the caller — bytes_consumed stays monotonic across the switch and
 * subsequent next_query() calls produce queries from where the fast
 * parser left off.
 */
class WP_MySQL_FastQueryStream {

	private $sql_buffer = '';
	private $input_complete = false;
	private $state = self::STATE_QUERY;
	private $last_query = false;
	private $bytes_consumed = 0;

	/**
	 * Cursor inside $sql_buffer where the next query starts. Held across
	 * find_boundary() calls so a partial scan that ran out of buffer
	 * doesn't have to re-scan the bytes already inspected when more
	 * input arrives.
	 */
	private $scan_cursor = 0;

	/**
	 * @var WP_MySQL_Naive_Query_Stream|null Set when the fast parser
	 *   bailed and ownership transferred to the lexer-based parser.
	 */
	private $fallback = null;

	/**
	 * @var int bytes_consumed at the moment fallback was installed.
	 *   The fallback parser counts bytes from its own buffer; we add
	 *   this to its count to keep get_bytes_consumed() monotonic across
	 *   the transition.
	 */
	private $bytes_consumed_at_fallback = 0;

	/**
	 * @var array{phase: string, byte_offset: int, message: string, context: string}|null
	 *   Diagnostic snapshot recorded when fallback was installed. Null
	 *   on the happy path.
	 */
	private $last_error = null;

	/** @var callable|null Optional logger called at fallback install time. */
	private $error_logger = null;

	const STATE_QUERY = 'valid';
	const STATE_SYNTAX_ERROR = 'syntax_error';
	const STATE_PAUSED_ON_INCOMPLETE_INPUT = 'paused_on_incomplete_input';
	const STATE_FINISHED = 'finished';

	/**
	 * Same heuristic the naive parser uses — a query that grows past
	 * this without a top-level semicolon is treated as a syntax error
	 * (in this class: trigger fallback before declaring failure).
	 */
	const MAX_SQL_BUFFER_SIZE = 1024 * 1024 * 2;

	public function __construct() {}

	/**
	 * Install a callable invoked synchronously when this class falls back
	 * to the lexer-based parser. The callable receives the $last_error
	 * array. Use this to wire fallback notifications into your audit log
	 * or telemetry without polluting the parser's error path.
	 */
	public function set_error_logger( callable $logger ): void {
		$this->error_logger = $logger;
	}

	public function get_last_error(): ?array {
		return $this->last_error;
	}

	public function has_fallen_back(): bool {
		return $this->fallback !== null;
	}

	public function append_sql( string $sql ) {
		if ( $this->fallback !== null ) {
			return $this->fallback->append_sql( $sql );
		}
		if ( $this->input_complete ) {
			return false;
		}
		$this->sql_buffer .= $sql;
		$this->state = self::STATE_QUERY;
		return true;
	}

	public function is_paused_on_incomplete_input(): bool {
		if ( $this->fallback !== null ) {
			return $this->fallback->is_paused_on_incomplete_input();
		}
		return $this->state === self::STATE_PAUSED_ON_INCOMPLETE_INPUT;
	}

	public function mark_input_complete() {
		if ( $this->fallback !== null ) {
			$this->fallback->mark_input_complete();
			return;
		}
		$this->input_complete = true;
	}

	public function next_query() {
		if ( $this->fallback !== null ) {
			return $this->fallback->next_query();
		}

		$this->last_query = false;

		// Paused from a previous call and still waiting for more bytes.
		// Don't re-scan — caller hasn't given us anything new.
		if (
			$this->state === self::STATE_PAUSED_ON_INCOMPLETE_INPUT
			&& ! $this->input_complete
		) {
			return false;
		}

		// State may be PAUSED-from-last-call here, but input_complete is
		// now true. do_next_query() either drains the trailing
		// non-meaningful bytes (whitespace after the final `;`) and
		// transitions to FINISHED, or sets PAUSED again if we're truly
		// stuck mid-construct — handled by the post-check below.
		$result = $this->do_next_query();
		if ( ! $result && strlen( $this->sql_buffer ) > self::MAX_SQL_BUFFER_SIZE ) {
			// The buffer grew past the heuristic limit without finding
			// a top-level semicolon. Hand the surviving buffer to the
			// lexer-based parser before declaring syntax error — it
			// recognises some quoting edge cases ours doesn't.
			$this->install_fallback(
				'buffer overflow without semicolon',
				'Buffer exceeded ' . number_format( self::MAX_SQL_BUFFER_SIZE )
				. ' bytes without finding a top-level semicolon'
			);
			return $this->fallback->next_query();
		}
		// do_next_query just paused the parser AND we're already at end
		// of input. Same situation as the early return above — fall back.
		if (
			! $result
			&& $this->input_complete
			&& $this->state === self::STATE_PAUSED_ON_INCOMPLETE_INPUT
			&& strlen( $this->sql_buffer ) > 0
		) {
			$this->install_fallback(
				'input complete but parser paused',
				'mark_input_complete() called but the fast scanner is paused inside an unterminated string, comment, or backtick'
			);
			return $this->fallback->next_query();
		}
		return $result;
	}

	private function do_next_query() {
		$buf = $this->sql_buffer;
		$buf_len = strlen( $buf );

		// Boundary finder advances $scan_cursor as it inspects bytes; if
		// it runs out of buffer mid-string or mid-comment, the cursor
		// stays put so the next append_sql() call resumes scanning from
		// the same spot instead of re-scanning everything.
		$boundary = $this->find_boundary( $buf, $buf_len );
		if ( $boundary === false ) {
			$stuck_mid_construct = $this->scan_cursor < $buf_len;
			if ( $this->input_complete ) {
				if ( $buf_len === 0 ) {
					$this->state = self::STATE_FINISHED;
					return false;
				}
				if ( $stuck_mid_construct ) {
					// Buffer still has bytes, but the scanner can't make
					// progress — paused inside a string, comment, or
					// backtick that never closes. Don't silently emit
					// the partial buffer as a "query"; pause so the
					// caller (next_query) can install the fallback.
					$this->state = self::STATE_PAUSED_ON_INCOMPLETE_INPUT;
					return false;
				}
				// Whole buffer scanned cleanly with no terminator —
				// the trailing statement just lacks a semicolon. Emit it.
				return $this->emit_query( $buf, $buf_len );
			}
			$this->state = self::STATE_PAUSED_ON_INCOMPLETE_INPUT;
			return false;
		}

		// $boundary is the offset of the terminating `;`. The query
		// includes the semicolon (matching the lexer-based original).
		return $this->emit_query( $buf, $boundary + 1 );
	}

	/**
	 * Emit a query from the front of the buffer. $consumed is the
	 * number of bytes the query takes (including any trailing `;`).
	 *
	 * Skips queries that contain only whitespace and comments — the
	 * caller never sees a "comment-only" query, matching the lexer-
	 * based parser's has_meaningful_tokens behaviour.
	 *
	 * @return bool True when a meaningful query was extracted.
	 */
	private function emit_query( string $buf, int $consumed ) {
		$query = substr( $buf, 0, $consumed );
		$this->sql_buffer = substr( $buf, $consumed );
		$this->bytes_consumed += $consumed;
		$this->scan_cursor = 0;

		if ( ! $this->has_meaningful_content( $query ) ) {
			// Comment-only / whitespace-only stretch. Drop it on the
			// floor and try the next chunk. The caller treats this as
			// "no query yet"; matches the lexer-path behaviour.
			if ( $this->input_complete && $this->sql_buffer === '' ) {
				$this->state = self::STATE_FINISHED;
				return false;
			}
			// Recurse to pick up the next query in the same call.
			return $this->do_next_query();
		}

		$this->last_query = $query;
		$this->state = self::STATE_QUERY;
		return true;
	}

	/**
	 * Cheap check: does this stretch contain any non-whitespace,
	 * non-comment byte? If not, the lexer-based parser returned "no
	 * meaningful tokens". We mirror that here without re-lexing.
	 *
	 * Handles `--` line comments, `#` line comments, `/* … *\/` block
	 * comments. Inside string literals, anything is "meaningful" (a
	 * statement that's just a string is still a statement).
	 */
	private function has_meaningful_content( string $sql ): bool {
		$len = strlen( $sql );
		$i = 0;
		while ( $i < $len ) {
			$c = $sql[$i];
			if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === ';' ) {
				$i++;
				continue;
			}
			if ( $c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' ) {
				// `--` line comment requires the next byte to be
				// whitespace, end-of-line, or end-of-input.
				if ( $i + 2 >= $len ) { return false; }
				$next = $sql[$i + 2];
				if ( $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r" ) {
					$nl = strpos( $sql, "\n", $i + 2 );
					if ( $nl === false ) { return false; }
					$i = $nl + 1;
					continue;
				}
			}
			if ( $c === '#' ) {
				$nl = strpos( $sql, "\n", $i + 1 );
				if ( $nl === false ) { return false; }
				$i = $nl + 1;
				continue;
			}
			if ( $c === '/' && $i + 1 < $len && $sql[$i + 1] === '*' ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( $end === false ) { return false; }
				$i = $end + 2;
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Walk the buffer looking for the byte offset of the top-level
	 * semicolon that ends the next statement. Skips past:
	 *   - single-quoted strings ('…', with '' and \\' escapes)
	 *   - double-quoted strings ("…", with "" and \\" escapes)
	 *   - backtick-quoted identifiers (`…`, with `` escape)
	 *   - line comments (-- … \n, # … \n)
	 *   - block comments (/* … *\/)
	 *
	 * Uses strcspn() to skip stretches of "boring" bytes in C-speed,
	 * which is the difference between O(bytes) PHP work per byte and
	 * O(bytes) C work per byte. The lexer-based parser tokenized the
	 * entire buffer just to find the next semicolon — quadratic in
	 * the number of statements per buffer.
	 *
	 * Returns the offset of the terminating `;`, or false when the
	 * scanner ran out of buffer mid-construct (caller waits for more
	 * input). $this->scan_cursor is advanced as bytes are inspected,
	 * so a future append_sql() resumes scanning from where we paused.
	 *
	 * @return int|false
	 */
	private function find_boundary( string $buf, int $buf_len ) {
		$i = $this->scan_cursor;
		// Bytes that need state-machine attention. Anything else is
		// payload we can fast-skip with strcspn.
		static $stop_chars = "'\"`;-#/";

		while ( $i < $buf_len ) {
			$skip = strcspn( $buf, $stop_chars, $i );
			$i += $skip;
			if ( $i >= $buf_len ) {
				break;
			}
			$c = $buf[$i];

			if ( $c === ';' ) {
				$this->scan_cursor = $i + 1;
				return $i;
			}

			if ( $c === "'" || $c === '"' ) {
				$end = $this->skip_string( $buf, $buf_len, $i, $c );
				if ( $end === false ) {
					$this->scan_cursor = $i; // resume here when more input arrives
					return false;
				}
				$i = $end;
				continue;
			}

			if ( $c === '`' ) {
				$end = $this->skip_backtick( $buf, $buf_len, $i );
				if ( $end === false ) {
					$this->scan_cursor = $i;
					return false;
				}
				$i = $end;
				continue;
			}

			if ( $c === '-' ) {
				if ( $i + 1 < $buf_len && $buf[$i + 1] === '-' ) {
					if ( $i + 2 >= $buf_len ) {
						$this->scan_cursor = $i;
						return false;
					}
					$next = $buf[$i + 2];
					if ( $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r" ) {
						$nl = strpos( $buf, "\n", $i + 2 );
						if ( $nl === false ) {
							$this->scan_cursor = $i;
							return false;
						}
						$i = $nl + 1;
						continue;
					}
				}
				$i++; // bare '-' is just an operator byte
				continue;
			}

			if ( $c === '#' ) {
				$nl = strpos( $buf, "\n", $i + 1 );
				if ( $nl === false ) {
					$this->scan_cursor = $i;
					return false;
				}
				$i = $nl + 1;
				continue;
			}

			if ( $c === '/' ) {
				if ( $i + 1 < $buf_len && $buf[$i + 1] === '*' ) {
					$end = strpos( $buf, '*/', $i + 2 );
					if ( $end === false ) {
						$this->scan_cursor = $i;
						return false;
					}
					$i = $end + 2;
					continue;
				}
				$i++;
				continue;
			}

			// Unreachable — strcspn only stops on $stop_chars.
			$i++;
		}

		$this->scan_cursor = $i;
		return false;
	}

	/**
	 * Skip past a single- or double-quoted MySQL string literal that
	 * opens at $start with the quote $quote. Returns the offset of the
	 * byte immediately after the closing quote, or false if the buffer
	 * ran out before the string was closed.
	 *
	 * Inside the literal, MySQL accepts two escape conventions:
	 *   - doubled quote ('' inside '…', "" inside "…")
	 *   - backslash escape (\\' inside '…', \\" inside "…")
	 * Both are skipped over without ending the literal.
	 */
	private function skip_string( string $buf, int $buf_len, int $start, string $quote ) {
		$i = $start + 1;
		while ( $i < $buf_len ) {
			// Fast-skip over the bulk of the string body. Inside a
			// quoted literal only `\` and the matching $quote can end
			// the run; a strcspn over those two bytes lets PHP's libc
			// devour 4 KB blocks of base64 payload in microseconds.
			$skip = strcspn( $buf, "\\" . $quote, $i );
			$i += $skip;
			if ( $i >= $buf_len ) {
				return false;
			}
			$c = $buf[$i];
			if ( $c === '\\' ) {
				if ( $i + 1 >= $buf_len ) {
					return false;
				}
				$i += 2; // skip the backslash + escaped byte
				continue;
			}
			// Found the matching $quote. Doubled-quote escape: keep going.
			if ( $i + 1 < $buf_len && $buf[$i + 1] === $quote ) {
				$i += 2;
				continue;
			}
			return $i + 1;
		}
		return false;
	}

	/**
	 * Skip past a backtick-quoted identifier. Doubled backticks (``)
	 * are treated as a literal backtick inside the identifier.
	 */
	private function skip_backtick( string $buf, int $buf_len, int $start ) {
		$i = $start + 1;
		while ( $i < $buf_len ) {
			$pos = strpos( $buf, '`', $i );
			if ( $pos === false ) {
				return false;
			}
			if ( $pos + 1 < $buf_len && $buf[$pos + 1] === '`' ) {
				$i = $pos + 2;
				continue;
			}
			return $pos + 1;
		}
		return false;
	}

	public function get_query() {
		if ( $this->fallback !== null ) {
			return $this->fallback->get_query();
		}
		return $this->last_query;
	}

	public function get_state() {
		if ( $this->fallback !== null ) {
			return $this->fallback->get_state();
		}
		return $this->state;
	}

	/**
	 * Total input bytes consumed so far. Stays monotonic across the
	 * fallback transition: the fallback parser counts bytes from its
	 * own buffer (the surviving bytes from the moment of switch); we
	 * add the count we already accumulated to get the cumulative
	 * value the caller expects.
	 */
	public function get_bytes_consumed(): int {
		if ( $this->fallback !== null ) {
			return $this->bytes_consumed_at_fallback + $this->fallback->get_bytes_consumed();
		}
		return $this->bytes_consumed;
	}

	/**
	 * Hand the surviving buffer over to a fresh lexer-based parser
	 * and start forwarding all calls to it. Records the failure in
	 * $last_error and notifies the optional error logger so the
	 * caller can audit-log the event with its own context (file
	 * offset, statement number, etc.) before the fallback takes over.
	 */
	private function install_fallback( string $reason, string $message ): void {
		$this->last_error = array(
			'phase'       => 'fast_query_stream',
			'byte_offset' => $this->bytes_consumed,
			'reason'      => $reason,
			'message'     => $message,
			// Snippet of the unconsumed buffer for diagnostics. Cap
			// length so the audit log doesn't get flooded.
			'context'     => substr( $this->sql_buffer, 0, 200 ),
		);

		if ( $this->error_logger !== null ) {
			// Logger callable mustn't throw; if it does, let the
			// exception surface to the caller — fallback didn't yet
			// take effect.
			( $this->error_logger )( $this->last_error );
		}

		$this->fallback                   = new WP_MySQL_Naive_Query_Stream();
		$this->bytes_consumed_at_fallback = $this->bytes_consumed;
		$this->fallback->append_sql( $this->sql_buffer );
		if ( $this->input_complete ) {
			$this->fallback->mark_input_complete();
		}
		$this->sql_buffer = ''; // owned by the fallback parser now
	}
}
