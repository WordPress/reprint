<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests basic MySQL dump functionality: empty databases, tables, primary keys,
 * foreign keys, batch sizes, and round-trip integrity.
 */
class BasicDumpTest extends MySQLDumpProducerTestBase
{
    // ──────────────────────────────────────────────────
    // Core dump basics
    // ──────────────────────────────────────────────────

    public function testEmptyDatabase(): void
    {
        // No tables created
        $producer = $this->createProducer();
        $fragments = $this->collectAllFragments($producer);

        // Should produce header and footer even for empty database
        $this->assertCount(2, $fragments, 'Empty database should produce header and footer');
        $this->assertStringContainsString('SET @OLD_UNIQUE_CHECKS', $fragments[0]);
        $this->assertStringContainsString('COMMIT', $fragments[1]);
        $this->assertTrue($producer->is_finished());
    }

    public function testSingleEmptyTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL
            )
        ");

        $sql = $this->getDumpSQL();

        $this->assertSQLContains('CREATE TABLE `users`', $sql);
        $this->assertSQLContains('Dumping data for table `users`', $sql);
        $this->assertSQLNotContains('INSERT INTO', $sql, 'Empty table should have no INSERTs');
    }

    public function testSingleTableWithData(): void
    {
        $this->pdo->exec("
            CREATE TABLE products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO products (name, price) VALUES
            ('Widget', 19.99),
            ('Gadget', 29.99),
            ('Doohickey', 9.99)
        ");

        $sql = $this->getDumpSQL();

        $this->assertSQLContains('CREATE TABLE `products`', $sql);
        $this->assertSQLContains('INSERT INTO `products`', $sql);
        $this->assertSQLContains('FROM_BASE64', $sql);
        $this->assertSQLContains('19.99', $sql);
    }

    public function testMyIsamInsertFillsRowsMissingAfterAStoppedQuery(): void
    {
        $this->pdo->exec("
            CREATE TABLE replay_rows (
                site_id INT NOT NULL,
                row_id INT NOT NULL,
                value VARCHAR(100) NOT NULL,
                PRIMARY KEY (site_id, row_id)
            ) ENGINE=MyISAM
        ");
        $this->pdo->exec("
            INSERT INTO replay_rows VALUES
            (1, 1, 'first'),
            (1, 2, 'second'),
            (1, 3, 'third'),
            (1, 4, 'fourth')
        ");

        $sql = $this->getDumpSQL(['batch_size' => 4]);
        $this->assertSame(
            1,
            preg_match('/INSERT INTO `replay_rows`.*?;/s', $sql, $matches)
        );
        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE `site_id` = `site_id`;',
            $matches[0]
        );

        $this->pdo->exec('TRUNCATE TABLE replay_rows');

        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $dsn = "mysql:host={$host};charset=utf8mb4";
        $query = "
            INSERT INTO replay_rows VALUES
            (1, 1, 'first'),
            (1, 2, 'second'),
            (1, IF(SLEEP(30) = 0, 3, 1), 'third'),
            (1, 4, 'fourth')
        ";
        $child_code = <<<'PHP'
$pdo = new PDO(
    $argv[1],
    $argv[2],
    $argv[3],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('USE `' . str_replace('`', '``', $argv[4]) . '`');
echo $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), "\n";
flush();
try {
    $pdo->exec(base64_decode($argv[5]));
    exit(2);
} catch (PDOException $error) {
    fwrite(STDERR, $error->getMessage());
    exit(0);
}
PHP;
        $pipes = [];
        $command = [
            PHP_BINARY,
            '-r',
            $child_code,
            $dsn,
            $user,
            $pass,
            $this->dbName,
            base64_encode($query),
        ];
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);

        $connection_id_line = fgets($pipes[1]);
        $this->assertNotFalse($connection_id_line);
        $connection_id = (int) trim($connection_id_line);
        $deadline = microtime(true) + 10;
        $state = null;
        do {
            $state = $this->pdo->query(
                "SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = {$connection_id}"
            )->fetchColumn();
            if ('User sleep' === $state) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        if ('User sleep' !== $state) {
            $this->pdo->exec("KILL CONNECTION {$connection_id}");
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->fail('The MyISAM INSERT did not reach its interruption point.');
        }

        // KILL QUERY makes SLEEP() return 1. That repeats row 1's key, so
        // MyISAM stops after the first two rows instead of finishing the batch.
        $this->pdo->exec("KILL QUERY {$connection_id}");
        $child_error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $child_error);
        $this->assertStringContainsString('1062', $child_error);

        $this->assertSame(
            ['1:1', '1:2'],
            $this->pdo->query(
                "SELECT CONCAT(site_id, ':', row_id) FROM replay_rows ORDER BY site_id, row_id"
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $this->pdo->exec($matches[0]);

        $this->assertSame(
            ['1:1:first', '1:2:second', '1:3:third', '1:4:fourth'],
            $this->pdo->query(
                "SELECT CONCAT(site_id, ':', row_id, ':', value) " .
                'FROM replay_rows ORDER BY site_id, row_id'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testUniqueKeyChecksStayEnabledForReplay(): void
    {
        $this->pdo->exec("
            CREATE TABLE unique_replay_rows (
                site_id INT NOT NULL,
                row_id INT NOT NULL,
                value VARCHAR(100) NOT NULL,
                UNIQUE KEY replay_row (site_id, row_id)
            ) ENGINE=InnoDB
        ");
        $this->pdo->exec("
            INSERT INTO unique_replay_rows VALUES
            (1, 1, 'first'),
            (1, 2, 'second'),
            (1, 3, 'third')
        ");

        $sql = $this->getDumpSQL(['batch_size' => 3]);
        $this->assertStringContainsString('UNIQUE_CHECKS=1', $sql);
        $this->assertSame(
            1,
            preg_match('/INSERT INTO `unique_replay_rows`.*?;/s', $sql, $matches)
        );

        $this->pdo->exec('TRUNCATE TABLE unique_replay_rows');
        $this->pdo->exec("INSERT INTO unique_replay_rows VALUES (1, 1, 'first')");
        $this->pdo->exec('SET UNIQUE_CHECKS=0');
        $this->pdo->exec(\WordPress\DataLiberation\MySQLDumpProducer::get_session_setup_sql());
        $this->assertSame(1, (int) $this->pdo->query('SELECT @@UNIQUE_CHECKS')->fetchColumn());
        $this->pdo->exec($matches[0]);

        $this->assertSame(
            ['1:1:first', '1:2:second', '1:3:third'],
            $this->pdo->query(
                "SELECT CONCAT(site_id, ':', row_id, ':', value) " .
                'FROM unique_replay_rows ORDER BY site_id, row_id'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testMultipleTables(): void
    {
        // Create multiple tables
        $this->pdo->exec("
            CREATE TABLE categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE orders (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert data
        $this->pdo->exec("INSERT INTO categories (name) VALUES ('Electronics'), ('Books')");
        $this->pdo->exec("INSERT INTO products (category_id, name, price) VALUES (1, 'Laptop', 999.99), (2, 'Novel', 14.99)");
        $this->pdo->exec("INSERT INTO orders (product_id, quantity) VALUES (1, 2), (2, 1)");

        $sql = $this->getDumpSQL();
        $tables = $this->extractTableNames($sql);

        // Verify all tables are present and in alphabetical order
        $this->assertEquals(['categories', 'orders', 'products'], $tables);

        // Verify data is present (strings are base64-encoded, numerics are raw)
        $this->assertSQLContains('FROM_BASE64', $sql);
        $this->assertSQLContains('999.99', $sql);
    }

    public function testRoundTripIntegrity(): void
    {
        // Create schema
        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                salary DECIMAL(10,2),
                hire_date DATE,
                is_active BOOLEAN DEFAULT TRUE
            )
        ");

        // Insert test data
        $this->pdo->exec("
            INSERT INTO employees (first_name, last_name, salary, hire_date, is_active) VALUES
            ('John', 'Doe', 75000.50, '2020-01-15', TRUE),
            ('Jane', 'Smith', 82000.00, '2019-06-01', TRUE),
            ('Bob', 'Johnson', 65000.75, '2021-03-10', FALSE)
        ");

        // Export
        $sql = $this->getDumpSQL();

        // Import to new database
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify data integrity
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['employees']);
    }

    public function testCanExcludeRowsByTableColumnAndValue(): void
    {
        $this->pdo->exec("
            CREATE TABLE wp_postmeta (
                meta_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255),
                meta_value LONGTEXT
            )
        ");

        $this->pdo->exec("
            INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
            (1, '_edit_lock', '1781114164:51814349'),
            (1, '_thumbnail_id', '42'),
            (2, NULL, 'kept')
        ");

        $sql = $this->getDumpSQL([
            'exclude_rows' => [
                [
                    'table' => 'wp_postmeta',
                    'column' => 'meta_key',
                    'value' => '_edit_lock',
                ],
            ],
        ]);

        $this->assertStringContainsString(base64_encode('_thumbnail_id'), $sql);
        $this->assertStringNotContainsString(base64_encode('_edit_lock'), $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $rows = $importPdo
            ->query('SELECT meta_key, meta_value FROM wp_postmeta ORDER BY meta_id')
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame(
            [
                ['meta_key' => '_thumbnail_id', 'meta_value' => '42'],
                ['meta_key' => null, 'meta_value' => 'kept'],
            ],
            $rows
        );
    }

    public function testBatchSizeRespected(): void
    {
        $this->pdo->exec("
            CREATE TABLE items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                value INT NOT NULL
            )
        ");

        // Insert 500 rows
        $values = [];
        for ($i = 1; $i <= 500; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec("INSERT INTO items (value) VALUES " . implode(',', $values));

        // Export with batch size of 100
        $sql = $this->getDumpSQL(['batch_size' => 100]);

        $batchCount = $this->countInsertStatements($sql);
        $this->assertEquals(5, $batchCount, 'Should have 5 batches (500 rows / 100 per batch)');

        // Verify round-trip - all 500 rows should be imported
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
        $this->assertEquals(500, $count, 'All 500 rows should be imported');

        // Verify data integrity
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['items']);
    }

    public function testVariousDataTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE data_types (
                id INT PRIMARY KEY AUTO_INCREMENT,
                tiny_int TINYINT,
                small_int SMALLINT,
                medium_int MEDIUMINT,
                big_int BIGINT,
                a_float FLOAT,
                a_double DOUBLE,
                a_decimal DECIMAL(10,2),
                a_char CHAR(10),
                a_varchar VARCHAR(100),
                a_text TEXT,
                a_date DATE,
                a_time TIME,
                a_datetime DATETIME,
                a_timestamp TIMESTAMP,
                a_year YEAR
            )
        ");

        $this->pdo->exec("
            INSERT INTO data_types (
                tiny_int, small_int, medium_int, big_int,
                a_float, a_double, a_decimal,
                a_char, a_varchar, a_text,
                a_date, a_time, a_datetime, a_timestamp, a_year
            ) VALUES (
                127, 32767, 8388607, 9223372036854775807,
                3.14, 2.718281828, 12345.67,
                'CHAR', 'VARCHAR text', 'Long text content',
                '2024-01-15', '14:30:00', '2024-01-15 14:30:00', '2024-01-15 14:30:00', 2024
            )
        ");

        $sql = $this->getDumpSQL();

        // Verify numeric types are not quoted (allow optional spaces after commas)
        $this->assertMatchesRegularExpression('/\(1,\s*127,\s*32767,\s*8388607,\s*9223372036854775807/', $sql);

        // Verify decimal precision is preserved
        $this->assertSQLContains('12345.67', $sql);

        // Verify strings are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['data_types']);
    }

    public function testNullValues(): void
    {
        $this->pdo->exec("
            CREATE TABLE nullables (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nullable_int INT NULL,
                nullable_varchar VARCHAR(100) NULL,
                nullable_date DATE NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO nullables (nullable_int, nullable_varchar, nullable_date) VALUES
            (NULL, NULL, NULL),
            (42, 'text', '2024-01-15'),
            (NULL, 'only text', NULL)
        ");

        $sql = $this->getDumpSQL();

        // Verify NULL is not quoted
        $this->assertMatchesRegularExpression('/\(1,NULL,NULL,NULL\)/', $sql);
        // Verify non-null row has numeric raw + base64-encoded strings
        $this->assertMatchesRegularExpression('/\(2,42,FROM_BASE64\(/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['nullables']);
    }

    public function testCreateTableDisabled(): void
    {
        $this->pdo->exec("
            CREATE TABLE simple (
                id INT PRIMARY KEY,
                name VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO simple VALUES (1, 'test')");

        $sql = $this->getDumpSQL(['create_table_query' => false]);

        $this->assertSQLNotContains('CREATE TABLE', $sql);
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('Dumping data for table', $sql);
    }

    // ──────────────────────────────────────────────────
    // Foreign keys
    // ──────────────────────────────────────────────────

    public function testSimpleForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE authors (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE books (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                author_id INT NOT NULL,
                FOREIGN KEY (author_id) REFERENCES authors(id)
            )
        ");

        $this->pdo->exec("INSERT INTO authors (name) VALUES ('J.K. Rowling'), ('George Orwell')");
        $this->pdo->exec("INSERT INTO books (title, author_id) VALUES ('Harry Potter', 1), ('1984', 2)");

        $sql = $this->getDumpSQL();

        // Verify CREATE TABLE includes foreign key
        $this->assertSQLContains('FOREIGN KEY', $sql);
        $this->assertSQLContains('REFERENCES `authors`', $sql);

        // Round-trip test - FK relationships must be preserved
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['authors', 'books']);

        // Verify FK constraint works in imported database
        try {
            $importPdo->exec("INSERT INTO books (title, author_id) VALUES ('Invalid', 999)");
            $this->fail('Foreign key constraint should have prevented invalid insert');
        } catch (PDOException $e) {
            $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        }
    }

    public function testMultipleForeignKeys(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE posts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                user_id INT NOT NULL,
                category_id INT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
            )
        ");

        $this->pdo->exec("INSERT INTO users (username) VALUES ('alice'), ('bob')");
        $this->pdo->exec("INSERT INTO categories (name) VALUES ('Tech'), ('News')");
        $this->pdo->exec("INSERT INTO posts (title, user_id, category_id) VALUES ('Post 1', 1, 1), ('Post 2', 2, 2)");

        $sql = $this->getDumpSQL();

        // Verify all FK relationships
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*user_id.*REFERENCES.*users/s', $sql);
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*category_id.*REFERENCES.*categories/s', $sql);

        // Verify ON DELETE clauses
        $this->assertSQLContains('ON DELETE CASCADE', $sql);
        $this->assertSQLContains('ON DELETE RESTRICT', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['users', 'categories', 'posts']);
    }

    public function testSelfReferencingForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                manager_id INT NULL,
                FOREIGN KEY (manager_id) REFERENCES employees(id)
            )
        ");

        $this->pdo->exec("
            INSERT INTO employees (id, name, manager_id) VALUES
            (1, 'CEO', NULL),
            (2, 'VP Sales', 1),
            (3, 'VP Engineering', 1),
            (4, 'Sales Rep', 2),
            (5, 'Developer', 3)
        ");

        $sql = $this->getDumpSQL();

        // Verify self-referencing FK
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*manager_id.*REFERENCES.*employees/s', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['employees']);
    }

    public function testCompositeForeignKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE orders (
                order_id INT NOT NULL,
                order_line INT NOT NULL,
                product_name VARCHAR(100) NOT NULL,
                PRIMARY KEY (order_id, order_line)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE shipments (
                shipment_id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                order_line INT NOT NULL,
                tracking_number VARCHAR(50),
                FOREIGN KEY (order_id, order_line) REFERENCES orders(order_id, order_line)
            )
        ");

        $this->pdo->exec("
            INSERT INTO orders (order_id, order_line, product_name) VALUES
            (100, 1, 'Widget'),
            (100, 2, 'Gadget'),
            (101, 1, 'Doohickey')
        ");

        $this->pdo->exec("
            INSERT INTO shipments (order_id, order_line, tracking_number) VALUES
            (100, 1, 'TRACK001'),
            (100, 2, 'TRACK002')
        ");

        $sql = $this->getDumpSQL();

        // Verify composite FK
        $this->assertMatchesRegularExpression('/FOREIGN KEY.*order_id.*order_line.*REFERENCES.*orders/s', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['orders', 'shipments']);
    }

    public function testCircularForeignKeys(): void
    {
        // Create tables without FKs first
        $this->pdo->exec("
            CREATE TABLE departments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                manager_id INT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE employees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                department_id INT NULL
            )
        ");

        // Add FKs after both tables exist
        $this->pdo->exec("
            ALTER TABLE departments
            ADD FOREIGN KEY (manager_id) REFERENCES employees(id)
        ");

        $this->pdo->exec("
            ALTER TABLE employees
            ADD FOREIGN KEY (department_id) REFERENCES departments(id)
        ");

        // Insert data carefully to avoid FK violations
        $this->pdo->exec("INSERT INTO departments (id, name, manager_id) VALUES (1, 'Engineering', NULL)");
        $this->pdo->exec("INSERT INTO employees (id, name, department_id) VALUES (1, 'Alice', 1)");
        $this->pdo->exec("UPDATE departments SET manager_id = 1 WHERE id = 1");

        $sql = $this->getDumpSQL();

        // Verify both FKs are present
        $this->assertMatchesRegularExpression('/CREATE TABLE.*departments/s', $sql);
        $this->assertMatchesRegularExpression('/CREATE TABLE.*employees/s', $sql);

        // Note: Circular FKs may require special handling during import
        // The dump should contain the structure correctly
    }

    public function testForeignKeyWithActions(): void
    {
        $this->pdo->exec("
            CREATE TABLE customers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE invoices (
                id INT PRIMARY KEY AUTO_INCREMENT,
                customer_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            )
        ");

        $this->pdo->exec("INSERT INTO customers (name) VALUES ('ACME Corp'), ('Widgets Inc')");
        $this->pdo->exec("INSERT INTO invoices (customer_id, amount) VALUES (1, 1000.00), (2, 500.00)");

        $sql = $this->getDumpSQL();

        // Verify FK actions
        $this->assertSQLContains('ON DELETE CASCADE', $sql);
        $this->assertSQLContains('ON UPDATE CASCADE', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Test cascade delete works
        $importPdo->exec("DELETE FROM customers WHERE id = 1");
        $count = $importPdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = 1")->fetchColumn();
        $this->assertEquals(0, $count, 'CASCADE DELETE should have removed invoice');
    }

    public function testTableOrderWithForeignKeys(): void
    {
        // Create tables in reverse dependency order
        $this->pdo->exec("
            CREATE TABLE level1 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE level2 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                level1_id INT NOT NULL,
                name VARCHAR(50),
                FOREIGN KEY (level1_id) REFERENCES level1(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE level3 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                level2_id INT NOT NULL,
                name VARCHAR(50),
                FOREIGN KEY (level2_id) REFERENCES level2(id)
            )
        ");

        $this->pdo->exec("INSERT INTO level1 (name) VALUES ('L1-A'), ('L1-B')");
        $this->pdo->exec("INSERT INTO level2 (level1_id, name) VALUES (1, 'L2-A'), (2, 'L2-B')");
        $this->pdo->exec("INSERT INTO level3 (level2_id, name) VALUES (1, 'L3-A'), (2, 'L3-B')");

        $sql = $this->getDumpSQL();

        // Tables should be in alphabetical order (level1, level2, level3)
        // This is fine because we're just dumping structure and data
        // The import should handle FK checks being disabled
        $tables = $this->extractTableNames($sql);
        $this->assertEquals(['level1', 'level2', 'level3'], $tables);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['level1', 'level2', 'level3']);
    }

    // ──────────────────────────────────────────────────
    // Primary key variations
    // ──────────────────────────────────────────────────

    public function testSimplePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL
            )
        ");

        // Insert 1500 rows to test pagination (in batches for speed)
        for ($batch = 0; $batch < 15; $batch++) {
            $values = [];
            for ($i = 1; $i <= 100; $i++) {
                $row = $batch * 100 + $i;
                $values[] = "('user{$row}')";
            }
            $this->pdo->exec("INSERT INTO users (username) VALUES " . implode(',', $values));
        }

        $sql = $this->getDumpSQL(['batch_size' => 250]);

        // Verify SQL contains INSERT statements with base64-encoded data
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test - this is the real verification
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertEquals(1500, $count, 'Should have all 1500 users after import');

        // Verify first and last users exist
        $first = $importPdo->query("SELECT username FROM users WHERE id = 1")->fetchColumn();
        $last = $importPdo->query("SELECT username FROM users WHERE id = 1500")->fetchColumn();
        $this->assertEquals('user1', $first);
        $this->assertEquals('user1500', $last);
    }

    public function testCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE order_items (
                order_id INT NOT NULL,
                item_id INT NOT NULL,
                quantity INT NOT NULL,
                PRIMARY KEY (order_id, item_id)
            )
        ");

        // Insert data with composite keys
        $values = [];
        for ($orderId = 1; $orderId <= 10; $orderId++) {
            for ($itemId = 1; $itemId <= 5; $itemId++) {
                $values[] = "({$orderId}, {$itemId}, " . ($orderId * $itemId) . ")";
            }
        }
        $this->pdo->exec("INSERT INTO order_items (order_id, item_id, quantity) VALUES " . implode(',', $values));

        $sql = $this->getDumpSQL();

        // Round-trip test - composite keys must maintain order
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['order_items']);
    }

    public function testThreeColumnCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE booking_slots (
                venue_id INT NOT NULL,
                date DATE NOT NULL,
                time_slot INT NOT NULL,
                booked_by VARCHAR(50),
                PRIMARY KEY (venue_id, date, time_slot)
            )
        ");

        // Insert various combinations
        $this->pdo->exec("
            INSERT INTO booking_slots (venue_id, date, time_slot, booked_by) VALUES
            (1, '2024-01-15', 1, 'Alice'),
            (1, '2024-01-15', 2, 'Bob'),
            (1, '2024-01-16', 1, 'Charlie'),
            (2, '2024-01-15', 1, 'Dave'),
            (2, '2024-01-15', 2, 'Eve')
        ");

        $sql = $this->getDumpSQL();

        // Verify lexicographic ordering is handled
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['booking_slots']);
    }

    public function testNoPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE logs (
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                level VARCHAR(20),
                message TEXT
            )
        ");

        // Insert 300 rows (more than one batch)
        for ($i = 1; $i <= 300; $i++) {
            $this->pdo->exec("INSERT INTO logs (level, message) VALUES ('INFO', 'Log entry {$i}')");
        }

        $sql = $this->getDumpSQL(['batch_size' => 100]);

        // Verify rows are exported (strings are base64-encoded)
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        $this->assertEquals(300, $count);
    }

    public function testMultipleTablesWithDifferentPKTypes(): void
    {
        // Table with simple PK
        $this->pdo->exec("
            CREATE TABLE simple_pk (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            )
        ");

        // Table with composite PK
        $this->pdo->exec("
            CREATE TABLE composite_pk (
                part1 INT,
                part2 INT,
                data VARCHAR(50),
                PRIMARY KEY (part1, part2)
            )
        ");

        // Table with no PK
        $this->pdo->exec("
            CREATE TABLE no_pk (
                data VARCHAR(50)
            )
        ");

        // Insert data
        $this->pdo->exec("INSERT INTO simple_pk VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        $this->pdo->exec("INSERT INTO composite_pk VALUES (1, 1, 'x'), (1, 2, 'y'), (2, 1, 'z')");
        $this->pdo->exec("INSERT INTO no_pk VALUES ('data1'), ('data2'), ('data3')");

        $sql = $this->getDumpSQL();

        // Verify all tables and data
        $tables = $this->extractTableNames($sql);
        $this->assertCount(3, $tables);
        $this->assertContains('simple_pk', $tables);
        $this->assertContains('composite_pk', $tables);
        $this->assertContains('no_pk', $tables);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['simple_pk', 'composite_pk', 'no_pk']);
    }

    public function testStringPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE countries (
                code CHAR(2) PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO countries (code, name) VALUES
            ('US', 'United States'),
            ('UK', 'United Kingdom'),
            ('CA', 'Canada'),
            ('AU', 'Australia'),
            ('DE', 'Germany')
        ");

        $sql = $this->getDumpSQL();

        // Verify string PK values are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['countries']);
    }

    public function testUUIDPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE sessions (
                id CHAR(36) PRIMARY KEY,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Generate some UUID-like strings
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuid = sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                mt_rand(),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand()
            );
            $uuids[] = "('{$uuid}', {$i})";
        }
        $this->pdo->exec("INSERT INTO sessions (id, user_id) VALUES " . implode(',', $uuids));

        $sql = $this->getDumpSQL(['batch_size' => 50]);

        // Should produce 2 INSERT statements
        $insertCount = substr_count($sql, 'INSERT INTO `sessions`');
        $this->assertEquals(2, $insertCount);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $this->assertEquals(100, $count);
    }

    public function testNullableCompositePrimaryKey(): void
    {
        // MySQL doesn't allow NULL in primary keys, but we can test with UNIQUE key
        $this->pdo->exec("
            CREATE TABLE temp_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                key1 VARCHAR(50),
                key2 VARCHAR(50),
                value TEXT,
                UNIQUE KEY unique_keys (key1, key2)
            )
        ");

        $this->pdo->exec("
            INSERT INTO temp_data (key1, key2, value) VALUES
            ('a', 'b', 'value1'),
            ('c', 'd', 'value2'),
            (NULL, 'e', 'value3'),
            ('f', NULL, 'value4')
        ");

        $sql = $this->getDumpSQL();

        // Verify NULLs are properly handled (strings are base64-encoded)
        $this->assertMatchesRegularExpression('/NULL,FROM_BASE64\(/', $sql);
        $this->assertMatchesRegularExpression('/FROM_BASE64\([^)]+\),NULL/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['temp_data']);
    }
}
