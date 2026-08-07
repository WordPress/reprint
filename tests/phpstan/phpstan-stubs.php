<?php
// phpcs:disable -- Static-analysis stubs must mirror third-party symbols and namespaces.
/**
 * PHPStan stubs for symbols whose runtime loaders cannot be followed during
 * static analysis. PDO\SQLite was introduced in PHP 8.4, while the public
 * HTML tag processor chooses its concrete parent at runtime.
 */

namespace PDO {
	class SQLite extends \PDO {
		public function createFunction( string $function_name, callable $callback, int $num_args = -1, int $flags = 0 ): bool {
			return true;
		}
	}
}

namespace {
	class WP_HTML_Span {
		/** @var int */
		public $start;

		/** @var int */
		public $length;
	}

	class WP_HTML_PHP_Tag_Processor {
		/** @var string */
		protected $html;

		/** @var array<string, WP_HTML_Span> */
		protected $bookmarks = array();

		public function __construct( string $html ) {}
		public function set_bookmark( string $name ): bool {}
		public function release_bookmark( string $name ): bool {}
		public function get_tag(): ?string {}
		public function is_tag_closer(): bool {}
		public function paused_at_incomplete_token(): bool {}
	}

	class WP_HTML_Tag_Processor extends WP_HTML_PHP_Tag_Processor {}
}

namespace WordPress\DataLiberation\BlockMarkup {
	/**
	 * @property string                      $html
	 * @property array<string, \WP_HTML_Span> $bookmarks
	 * @method bool set_bookmark(string $name)
	 * @method bool release_bookmark(string $name)
	 * @method string|null get_tag()
	 * @method bool is_tag_closer()
	 * @method bool paused_at_incomplete_token()
	 */
	class BlockMarkupProcessor extends \WP_HTML_Tag_Processor {}
}
