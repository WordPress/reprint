<?php
/**
 * Loader for MySQL lexer, parser, and query stream classes.
 *
 * The lexer, parser, and grammar come from the WordPress/sqlite-database-integration
 * submodule at lib/sqlite-database-integration/. The mysql-on-sqlite package
 * loader selects the optional native Rust parser classes when the
 * wp_mysql_parser extension is loaded and falls back to the pure-PHP parser
 * otherwise. The query streams are local to this project.
 *
 * If you see "failed to open stream" errors, run:
 *   git submodule update --init
 */

$sdi_loader = null;
foreach ([
    dirname(__DIR__, 5) . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php',
    dirname(__DIR__, 6) . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php',
] as $candidate) {
    if (file_exists($candidate)) {
        $sdi_loader = $candidate;
        break;
    }
}

if ($sdi_loader === null) {
    throw new RuntimeException(
        'sqlite-database-integration is missing. Run: git submodule update --init'
    );
}

require_once $sdi_loader;

// Local: naive query stream (not part of the submodule)
require_once __DIR__ . '/class-wp-mysql-naive-query-stream.php';
// Local: fast query stream — strcspn-driven scanner that falls back to
// the naive parser on any unrecoverable state. Loaded after the naive
// parser because it instantiates one as a fallback.
require_once __DIR__ . '/class-wp-mysql-fast-query-stream.php';
