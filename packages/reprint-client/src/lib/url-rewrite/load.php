<?php
/**
 * Loader for URL rewriting classes.
 *
 * Classes are loaded in dependency order: leaf classes first,
 * then classes that depend on them.
 */

require_once __DIR__ . '/../wp-stubs.php';
require_once __DIR__ . '/../mysql-query-stream/load.php';

// Leaf classes (no internal dependencies)
require_once __DIR__ . '/class-php-serialization-processor.php';
require_once __DIR__ . '/class-json-string-iterator.php';
require_once __DIR__ . '/class-base64-value-scanner.php';
require_once __DIR__ . '/class-fast-insert-scanner.php';
require_once __DIR__ . '/class-sqlite-prepared-insert-builder.php';

// Depend on the iterators above
require_once __DIR__ . '/class-structured-data-url-rewriter.php';
require_once __DIR__ . '/class-domain-collector.php';

// Depends on StructuredDataUrlRewriter + Base64ValueScanner
require_once __DIR__ . '/class-sql-statement-rewriter.php';
