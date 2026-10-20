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

    /**
     * Round-trips values through the quoting paths used by exports.
     *
     * @dataProvider parameterValues
     */
    public function testParametersKeepTheirBytes(string $value, string $binding): void
    {
        $adapter = $this->create_adapter();
        if ($binding === 'quote') {
            $statement = $adapter->query('SELECT ' . $adapter->quote($value) . ' AS actual_value');
        } elseif ($binding === 'named bind') {
            $statement = $adapter->prepare('SELECT :value AS actual_value');
            $statement->bindValue(':value', $value);
            $statement->execute();
        } else {
            $statement = $adapter->prepare('SELECT ? AS actual_value');
            $statement->execute([$value]);
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
            foreach (['named bind', 'positional execute', 'quote'] as $binding) {
                $cases[$name . ' / ' . $binding] = [$value, $binding];
            }
        }
        return $cases;
    }

    /** Quoted numeric cursors must still compare as numbers. */
    public function testNumericComparisonsKeepTheirMeaning(): void
    {
        $adapter = $this->create_adapter();
        $adapter->query('CREATE TABLE wp_numbers (id INT)');
        $adapter->query('INSERT INTO wp_numbers VALUES (1), (2)');
        $statement = $adapter->prepare('SELECT id FROM wp_numbers WHERE id > :last ORDER BY id');
        $statement->bindValue(':last', '1');
        $statement->execute();
        $this->assertSame(['2'], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Query values follow the active mode when backslashes are literal. */
    public function testQuoteAndParametersFollowNoBackslashEscapesMode(): void
    {
        $adapter = $this->create_adapter();
        $adapter->query("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
        $value = "folder\\new\\it's\0\n\r :value";
        $this->assertSame($value, $adapter->query('SELECT ' . $adapter->quote($value) . ' AS actual_value')->fetchColumn());
        $statement = $adapter->prepare('SELECT :value AS actual_value');
        $statement->bindValue(':value', $value);
        $statement->execute();
        $this->assertSame($value, $statement->fetchColumn());
        $statement = $adapter->prepare('SELECT ? AS actual_value');
        $statement->execute([$value]);
        $this->assertSame($value, $statement->fetchColumn());
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
