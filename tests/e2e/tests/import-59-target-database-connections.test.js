/**
 * The target database classes run the same query, prepared statement, and
 * transaction flow through mysqli and the MySQL-on-SQLite PDO driver.
 */
import { describe, it } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Target database connection classes', () => {
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const phpBinary = process.env.PHP_BINARY || 'php';

    it('uses the same operations through mysqli and SQLite', () => {
        const script = String.raw`
            require $argv[1] . '/packages/reprint-client/src/lib/database/load.php';
            require $argv[1] . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';

            use Reprint\Importer\Database\MysqliDatabaseConnection;
            use Reprint\Importer\Database\PdoDatabaseConnection;

            function run_contract($database) {
                $database->exec('DROP TABLE IF EXISTS reprint_connection_contract');
                $database->exec(
                    'CREATE TABLE reprint_connection_contract (' .
                    'id BIGINT NOT NULL PRIMARY KEY, value TEXT NOT NULL)'
                );
                $quoted = $database->quote("a'b");
                $quoted_result = $database->query("SELECT {$quoted}");
                $quoted_value = $quoted_result->fetchColumn();
                $quoted_result->closeCursor();
                if ($quoted_value !== "a'b") {
                    throw new RuntimeException('The target quoted an SQL value incorrectly.');
                }
                $null_result = $database->query('SELECT NULL');
                if ($null_result->fetchColumn() !== null) {
                    throw new RuntimeException('The target changed SQL NULL into another value.');
                }
                $null_result->closeCursor();

                $duplicate_columns = $database->query(
                    'SELECT 1 AS duplicate_value, 2 AS duplicate_value'
                );
                $duplicate_values = $duplicate_columns->fetch(PDO::FETCH_NUM);
                if (array_map('intval', $duplicate_values) !== array(1, 2)) {
                    throw new RuntimeException('Numeric results lost a duplicate-named column.');
                }
                $duplicate_columns->closeCursor();
                $database->execute(
                    'INSERT INTO reprint_connection_contract (id, value) VALUES (?, ?)',
                    array(1, 'first')
                );
                $row = $database->query(
                    'SELECT id, value FROM reprint_connection_contract WHERE id = 1'
                )->fetch(PDO::FETCH_ASSOC);
                if ((int) $row['id'] !== 1 || $row['value'] !== 'first') {
                    throw new RuntimeException('The target query returned the wrong row.');
                }

                $database->beginTransaction();
                $database->execute(
                    'UPDATE reprint_connection_contract SET value = ? WHERE id = ?',
                    array('changed', 1)
                );
                $database->rollBack();
                $value = $database->query(
                    'SELECT value FROM reprint_connection_contract WHERE id = 1'
                )->fetchColumn();
                if ($value !== 'first') {
                    throw new RuntimeException('The target rollback did not restore the row.');
                }

                $database->execute(
                    'UPDATE reprint_connection_contract SET value = ? WHERE id = ?',
                    array('second', 1)
                );
                $value = $database->query(
                    'SELECT value FROM reprint_connection_contract WHERE id = 1'
                )->fetchColumn();
                if ($value !== 'second') {
                    throw new RuntimeException('The target could not reuse a prepared statement.');
                }

                try {
                    $database->exec('SELECT * FROM reprint_table_which_does_not_exist');
                    throw new RuntimeException('The target accepted invalid SQL.');
                } catch (PDOException $error) {
                    // Both native drivers expose SQL failures through the same type.
                }

                $database->exec('DROP TABLE reprint_connection_contract');
                $database->close();
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new mysqli('127.0.0.1', 'e2e_admin', 'e2e_password', 'mysql');
            $mysqli->set_charset('utf8mb4');
            $mysql_database = new MysqliDatabaseConnection($mysqli);
            $mysql_database->exec('SELECT 1; SELECT 2');
            $drained_result = $mysql_database->query('SELECT 3');
            if ((int) $drained_result->fetchColumn() !== 3) {
                throw new RuntimeException('The MySQL adapter did not drain its SQL group.');
            }
            $drained_result->closeCursor();
            run_contract($mysql_database);

            $sqlite = new WP_PDO_MySQL_On_SQLite(
                'mysql-on-sqlite:path=:memory:;dbname=wp',
                null,
                null,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            run_contract(new PdoDatabaseConnection(
                $sqlite,
                $sqlite->get_connection()->get_pdo()
            ));
        `;

        const output = execFileSync(phpBinary, ['-r', script, projectRoot], {
            encoding: 'utf8',
            env: { ...process.env },
        });
        assert.equal(output, '');
    });
});
