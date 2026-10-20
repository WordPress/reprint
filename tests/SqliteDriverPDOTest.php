<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\SqliteDriverPDO;

require_once __DIR__ . '/../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
require_once __DIR__ . '/../packages/reprint-server/src/class-sqlite-driver-pdo.php';

final class SqliteDriverPDOTest extends TestCase
{
    public function testFromBase64WorksThroughAdapterQueryPath(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $pdo = new PDO('sqlite::memory:');
        $connection = new WP_SQLite_Connection(['pdo' => $pdo]);
        $driver = new WP_SQLite_Driver($connection, 'wp_test');
        $adapter = new SqliteDriverPDO($driver, $pdo);

        $adapter->query('CREATE TABLE wp_postmeta (meta_id INTEGER, meta_key TEXT)');
        $adapter->query(
            "INSERT INTO wp_postmeta (meta_id, meta_key) VALUES " .
            "(1, '_edit_lock'), (2, '_thumbnail_id'), (3, NULL)"
        );

        $stmt = $adapter->query(
            "SELECT meta_id FROM wp_postmeta " .
            "WHERE meta_key IS NULL OR meta_key <> FROM_BASE64('" . base64_encode('_edit_lock') . "') " .
            "ORDER BY meta_id"
        );

        $this->assertSame(
            [['meta_id' => '2'], ['meta_id' => '3']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @dataProvider parameterValues */
    public function testParametersKeepTheirBytes(string $value, string $binding): void
    {
        $adapter = $this->create_adapter();
        if ($binding === 'quote') {
            $statement = $adapter->query('SELECT ' . $adapter->quote($value) . ' AS actual_value');
        } else {
            $positional = strpos($binding, 'positional') === 0;
            $statement = $adapter->prepare('SELECT ' . ( $positional ? '?' : ':value' ) . ' AS actual_value');
            if (strpos($binding, 'bind') !== false) {
                $statement->bindValue($positional ? 1 : ':value', $value);
                $statement->execute();
            } else {
                $statement->execute($positional ? [$value] : ['value' => $value]);
            }
        }
        $this->assertSame($value, $statement->fetchColumn());
    }

    /** @return array<string,array{string,string}> Values and the PDO-style binding used to send them. */
    public static function parameterValues(): array
    {
        $cases = [];
        foreach ([
            'backslashes' => 'folder\\new\\photo',
            'quote after backslash' => "\\' OR 1=1 -- ",
            'quotes' => "it's a \"quote\"",
            'NUL' => "before\0after",
            'line breaks' => "one\ntwo\rthree\tfour",
            'placeholder text' => ':other ? :value',
            'empty' => '',
            'Unicode' => 'żółw',
        ] as $name => $value) {
            foreach (['named execute', 'named bind', 'positional execute', 'positional bind', 'quote'] as $binding) {
                $cases[$name . ' / ' . $binding] = [$value, $binding];
            }
        }
        return $cases;
    }

    public function testPlaceholderReplacementDoesNotRevisitValuesOrQuotedSql(): void
    {
        $adapter = $this->create_adapter();
        $statement = $adapter->prepare(
            "SELECT :value AS first_result, :value_long AS second_result, ':value ?' AS literal_value, " .
            "'it\\'s :value' AS escaped_value, :value AS repeated_value /* :value ? */ -- :value ?\n"
        );
        $statement->execute([':value' => ':value_long', ':value_long' => 'second']);
        $this->assertSame([
            'first_result' => ':value_long',
            'second_result' => 'second',
            'literal_value' => ':value ?',
            'escaped_value' => "it's :value",
            'repeated_value' => ':value_long',
        ], $statement->fetch());
    }

    public function testNumericComparisonsAndNullParametersKeepTheirMeaning(): void
    {
        $adapter = $this->create_adapter();
        $adapter->query('CREATE TABLE wp_numbers (id INT)');
        $adapter->query('INSERT INTO wp_numbers VALUES (1), (2)');
        $statement = $adapter->prepare('SELECT id FROM wp_numbers WHERE id > :last AND :empty IS NULL ORDER BY id');
        $statement->execute([':last' => '1', ':empty' => null]);
        $this->assertSame(['2'], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testQuoteAndParametersFollowNoBackslashEscapesMode(): void
    {
        $adapter = $this->create_adapter();
        $adapter->query("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
        $value = "folder\\new\\it's :value";
        $statement = $adapter->prepare('SELECT :value AS actual_value, ' . $adapter->quote($value) . ' AS quoted_value');
        $statement->execute([':value' => $value]);
        $this->assertSame(['actual_value' => $value, 'quoted_value' => $value], $statement->fetch());
    }

    /** Opens the actual MySQL-on-SQLite translator, not a query-recording substitute. */
    private function create_adapter(): SqliteDriverPDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        $pdo = new PDO('sqlite::memory:');
        $driver = new WP_SQLite_Driver(new WP_SQLite_Connection(['pdo' => $pdo]), 'wp_test');
        return new SqliteDriverPDO($driver, $pdo);
    }
}
