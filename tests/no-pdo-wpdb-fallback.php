<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.Files.SeparateFunctionsFromOO,WordPress.WP.GlobalVariablesOverride,PSR2.Methods.MethodDeclaration.Underscore
// This standalone fixture must define the WordPress-shaped globals that the adapter consumes.

use WordPress\Reprint\Server\PdoConstants;
use WordPress\Reprint\Server\WpdbDriverPDO;

function no_pdo_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

foreach (['PDO', 'PDOStatement', 'PDOException'] as $class_name) {
    no_pdo_assert(!class_exists($class_name, false), "{$class_name} must remain unavailable.");
}

no_pdo_assert(PdoConstants::fetch_assoc() === 2, 'FETCH_ASSOC fallback must be 2.');
no_pdo_assert(PdoConstants::fetch_column() === 7, 'FETCH_COLUMN fallback must be 7.');
no_pdo_assert(PdoConstants::param_str() === 2, 'PARAM_STR fallback must be 2.');
foreach (['PDO', 'PDOStatement', 'PDOException'] as $class_name) {
    no_pdo_assert(!class_exists($class_name, false), "{$class_name} must remain unavailable after using PdoConstants.");
}

class NoPdoWpdbTestDouble {
    public $last_error = '';
    public $queries = [];
    public $executed = [];

    public function suppress_errors($suppress = true)
    {
    }

    public function hide_errors()
    {
    }

    public function _real_escape($value)
    {
        return addslashes($value);
    }

    public function get_results($query, $output)
    {
        $this->executed[] = [$query, $output];
        $this->queries[] = [$query, $output];
        if ($query === 'FAIL') {
            $this->last_error = 'wpdb query failed';
            return null;
        }
        if (strpos($query, 'SELECT') === 0) {
            return [
                ['id' => '1', 'name' => 'first'],
                ['id' => '2', 'name' => 'second'],
            ];
        }
        return [];
    }
}

require_once dirname(__DIR__) . '/packages/reprint-server/src/export.php';

$GLOBALS['wpdb'] = new NoPdoWpdbTestDouble();
$connection = create_db_connection([
    'db_engine' => 'mysql',
    'db_host' => 'unused',
    'db_name' => 'unused',
    'db_user' => 'unused',
    'db_password' => 'unused',
]);
no_pdo_assert($connection instanceof WpdbDriverPDO, 'MySQL must use the wpdb adapter without PDO.');

$statement = $connection->query('SELECT id, name FROM test');
no_pdo_assert(
    $statement->fetch() === ['id' => '1', 'name' => 'first'],
    'fetch() must return an associative row.'
);
no_pdo_assert(
    $statement->fetchAll(PdoConstants::fetch_column()) === ['2'],
    'fetchAll(FETCH_COLUMN) must return the first remaining column.'
);

$statement = $connection->prepare('SELECT :name');
$statement->bindValue(':name', "O'Reilly");
$statement->execute();
no_pdo_assert(
    strpos($GLOBALS['wpdb']->executed[2][0], "'O\\'Reilly'") !== false,
    'bindValue() must substitute escaped named parameters.'
);

try {
    $connection->query('FAIL');
    no_pdo_assert(false, 'wpdb query failure must throw.');
} catch (RuntimeException $error) {
    no_pdo_assert($error->getMessage() === 'wpdb query failed', 'wpdb failure message must be retained.');
}
no_pdo_assert(!class_exists('PDOException', false), 'wpdb failures must not construct PDOException.');

define('SQLITE_DB_DROPIN_VERSION', '3.0.0');
$GLOBALS['@pdo'] = new stdClass();
try {
    create_sqlite_pdo_adapter();
    no_pdo_assert(false, 'SQLite export must require PDO.');
} catch (RuntimeException $error) {
    no_pdo_assert(
        $error->getMessage() === 'SQLite export requires the PDO extension.',
        'SQLite PDO failure must be explicit.'
    );
}
