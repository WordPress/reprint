<?php
/**
 * PDO polyfill for hosts without the PDO extension.
 *
 * The reprint-exporter codebase references PDO::* constants, \PDOException,
 * and \PDOStatement at multiple call sites (see the design spec for an audit).
 * On hosts without ext-pdo, those references would fatal at runtime even
 * though the wpdb adapter is the chosen connection.
 *
 * This file conditionally defines those names in the global namespace so
 * existing code can resolve them without modification. Constant values
 * match the real PDO extension exactly so behavior is identical regardless
 * of which is loaded.
 *
 * Side-effect note: on PDO-less hosts, class_exists('PDO') with the default
 * autoload-true argument now returns true. Co-resident code that uses
 * class_exists('PDO') as an optional-feature gate (skip PDO path if false)
 * will see the polyfill and try to use it, fataling where it previously
 * skipped cleanly. This is acceptable for the exporter's deployment surface
 * but documented here so a reader can grep for it.
 */

if (!class_exists('PDO', false)) {
    eval(<<<'PHP'
class PDO
{
    const FETCH_ASSOC                   = 2;
    const FETCH_COLUMN                  = 7;
    const PARAM_STR                     = 2;
    const ATTR_ERRMODE                  = 3;
    const ERRMODE_EXCEPTION             = 2;
    const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;
}
PHP
    );
}

if (!class_exists('PDOStatement', false)) {
    eval(<<<'PHP'
class PDOStatement
{
}
PHP
    );
}

if (!class_exists('PDOException', false)) {
    // Real PDOException extends \Exception (not \RuntimeException). Match
    // upstream so `catch (\RuntimeException $e)` does not accidentally catch
    // the polyfilled exception while missing the real one.
    eval(<<<'PHP'
class PDOException extends \Exception
{
}
PHP
    );
}
