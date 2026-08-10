<?php
/**
 * Verify that the wp_mysql_parser PHP.wasm extension is loaded and actually
 * selected by the SQLite Integration parser bridge.
 *
 * Usage:
 *   php verify-wp-mysql-parser.php /path/to/reprint [lexer|parser]
 */

$project_root = $argv[1] ?? dirname(__DIR__, 3);
$mode = $argv[2] ?? 'parser';
if (!in_array($mode, array('lexer', 'parser'), true)) {
    fwrite(STDERR, "Invalid mode: {$mode}\n");
    exit(2);
}

$loader = rtrim($project_root, '/') . '/packages/reprint-client/src/lib/mysql-query-stream/load.php';
$grammar_path = rtrim($project_root, '/') . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/mysql/mysql-grammar.php';
if (!is_readable($loader)) {
    fwrite(STDERR, "Cannot read query-stream loader: {$loader}\n");
    exit(2);
}
if (!is_readable($grammar_path)) {
    fwrite(STDERR, "Cannot read MySQL grammar: {$grammar_path}\n");
    exit(2);
}

require $loader;

function reprint_native_parser_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function reprint_assert_native_delegate(WP_MySQL_Parser $parser, string $context): void
{
    $reflection = new ReflectionObject($parser);
    if (!$reflection->hasProperty('native')) {
        reprint_native_parser_fail($context . ': missing native delegate property');
    }

    $native_property = $reflection->getProperty('native');
    $native_property->setAccessible(true);
    if (!($native_property->getValue($parser) instanceof WP_MySQL_Native_Parser)) {
        reprint_native_parser_fail($context . ': delegate is not WP_MySQL_Native_Parser');
    }
}

$details = array(
    'mode' => $mode,
    'wp_mysql_parser' => extension_loaded('wp_mysql_parser') ? 'enabled' : 'missing',
);

if (!extension_loaded('wp_mysql_parser')) {
    reprint_native_parser_fail('wp_mysql_parser extension is not loaded');
}

foreach (
    array(
        'WP_MySQL_Native_Lexer',
        'WP_MySQL_Native_Parser',
        'WP_MySQL_Native_Parser_Node',
        'WP_MySQL_Native_Token_Stream',
    ) as $class
) {
    if (!class_exists($class, false)) {
        reprint_native_parser_fail($class . ' is not declared by the extension/loader');
    }
}

if (get_parent_class('WP_MySQL_Lexer') !== 'WP_MySQL_Native_Lexer') {
    reprint_native_parser_fail('WP_MySQL_Lexer did not resolve to the native lexer');
}

if (!in_array('WP_MySQL_Native_Parser_Impl', (array) class_uses('WP_MySQL_Parser'), true)) {
    reprint_native_parser_fail('WP_MySQL_Parser did not resolve to the native parser bridge');
}

$lexer = new WP_MySQL_Lexer('SELECT ID, post_title FROM wp_posts WHERE ID IN (1, 2, 3);');
if (!($lexer instanceof WP_MySQL_Native_Lexer)) {
    reprint_native_parser_fail('Native lexer instance check failed');
}

$tokens = $lexer->native_token_stream();
if (!($tokens instanceof WP_MySQL_Native_Token_Stream)) {
    reprint_native_parser_fail('native_token_stream() did not return WP_MySQL_Native_Token_Stream');
}

$details['native_lexer'] = 'verified';
$details['native_token_stream'] = get_class($tokens);
$details['native_token_count'] = method_exists($tokens, 'count') ? $tokens->count() : null;

if ($mode === 'parser') {
    $grammar = new WP_Parser_Grammar(require $grammar_path);
    $parser = new WP_MySQL_Parser($grammar, $tokens);
    reprint_assert_native_delegate($parser, 'WP_MySQL_Parser');

    $ast = $parser->parse();
    if (!($ast instanceof WP_MySQL_Native_Parser_Node) || $ast->rule_name !== 'query') {
        reprint_native_parser_fail('Native parser did not produce a query AST');
    }

    $driver = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wp;');
    $driver_parser = $driver->create_parser('SELECT 1;');
    reprint_assert_native_delegate($driver_parser, 'WP_PDO_MySQL_On_SQLite::create_parser');
    if (!$driver_parser->next_query()) {
        reprint_native_parser_fail('SQLite driver native parser did not parse a query');
    }

    $driver_ast = $driver_parser->get_query_ast();
    if (!($driver_ast instanceof WP_MySQL_Native_Parser_Node)) {
        reprint_native_parser_fail('SQLite driver did not expose a native-backed AST');
    }

    $details['native_parser'] = 'verified';
    $details['native_ast'] = get_class($ast);
    $details['sqlite_driver_parser'] = 'verified';
} else {
    $details['native_parser'] = 'selected';
}

echo json_encode($details, JSON_UNESCAPED_SLASHES) . "\n";
