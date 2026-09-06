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
require_once __DIR__ . '/class-cautious-url-base-rewrite-mapping.php';
require_once __DIR__ . '/class-cautious-url-base-processor-in-text-with-mixed-unknown-escape-rules.php';
require_once __DIR__ . '/class-css-url-rewrite-stream.php';

// Use the php-toolkit implementation when the installed version provides it.
if (!class_exists(\WordPress\DataLiberation\Shortcode\ShortcodeProcessor::class)) {
    require_once __DIR__ . '/class-shortcode-processor.php';
}

// Scans structured block markup and uses the cautious processor for opaque tokens.
require_once __DIR__ . '/class-structured-block-markup-url-processor.php';
require_once __DIR__ . '/class-wpbakery-url-rewriter.php';

// Depend on the iterators above
require_once __DIR__ . '/class-structured-data-url-rewriter.php';

// Depends on StructuredDataUrlRewriter + Base64ValueScanner
require_once __DIR__ . '/class-sql-statement-rewriter.php';
require_once __DIR__ . '/class-database-url-rewrite-processor.php';
