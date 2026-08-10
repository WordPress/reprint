<?php
/**
 * PHPStan stub – PDO\SQLite was introduced in PHP 8.4. CI runs PHPStan on
 * PHP 8.2, so we stub the class to avoid "not found" errors.
 */

namespace PDO;

class SQLite extends \PDO {
	public function createFunction( string $function_name, callable $callback, int $num_args = -1, int $flags = 0 ): bool {
		return true;
	}
}
