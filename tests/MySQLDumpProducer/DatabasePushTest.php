<?php

use WordPress\Reprint\Server\DatabasePush;

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-source.php';

/** Exercises import and cutover with real InnoDB tables. */
class DatabasePushTest extends MySQLDumpProducerTestBase {
    private string $push_session_id = 'abcdef0123456789abcdef0123456789';

    public function testImportResumesAndOnlyExplicitCommitReplacesLiveTables(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longblob) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, 'production')");
        $this->pdo->exec('CREATE TABLE wp_orders (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE unrelated (id int PRIMARY KEY) ENGINE=InnoDB');
        $archive = tmpfile();
        foreach ([
            ['table' => 'wp_options', 'ddl' => 'CREATE TABLE `wp_options` (`id` int PRIMARY KEY, `value` longblob) ENGINE=InnoDB'],
            ['values' => ['id' => base64_encode('1'), 'value' => base64_encode("local\0bytes")]],
            ['end' => true],
        ] as $record) {
            fwrite($archive, json_encode($record) . "\n");
        }
        $local_absolute_path = stream_get_meta_data($archive)['uri'];
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $push->start();
        $this->importNextFixtureRecord($push, $local_absolute_path);
        self::assertSame('production', $this->pdo->query('SELECT value FROM wp_options')->fetchColumn());
        $push->close();
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $this->importNextFixtureRecord($push, $local_absolute_path);
        $this->importNextFixtureRecord($push, $local_absolute_path);
        self::assertSame('ready', $push->get_status()['phase']);
        self::assertSame(['wp_options', 'wp_orders'], $push->get_status()['replace_tables']);
        $push->commit();
        $push->close();
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        // Repeating commit must observe the marker renamed with the tables.
        $push->commit();
        self::assertSame("local\0bytes", $this->pdo->query('SELECT value FROM wp_options')->fetchColumn());
        self::assertSame('committed', $push->get_status()['phase']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wp_orders'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='unrelated'")->fetchColumn());
        $push->cleanup_next_table();
        self::assertSame('committed', $push->get_status()['phase']);
        while ($push->get_status()['phase'] !== 'complete') {
            $push->cleanup_next_table();
        }
        $push->close();
        fclose($archive);
    }

    public function testMysqliSourceAndTargetKeepBinaryValuesAndTransactions(): void {
        $this->pdo->exec("CREATE TABLE wp_values (id int PRIMARY KEY, value longblob, choice ENUM('', '0', 'one')) ENGINE=InnoDB");
        $this->pdo->exec("SET SESSION sql_mode=''");
        $this->pdo->exec("INSERT INTO wp_values VALUES (1, X'00FF3F', 'invalid')");
        $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName;
        $source = new \WordPress\Reprint\Server\MysqliDriverPDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
        $target = new \WordPress\Reprint\Server\MysqliDriverPDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
        $metadata = $target->prepare('SELECT ? AS name UNION ALL SELECT ? AS name');
        $metadata->execute(['first', 'second']);
        self::assertSame([['name' => 'first'], ['name' => 'second']], $metadata->fetchAll(PDO::FETCH_ASSOC));
        $target->beginTransaction();
        $target->exec("INSERT INTO wp_values VALUES (2, X'CAFE', 'one')");
        $target->rollBack();
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_values')->fetchColumn());
        $reader = new DatabasePushSource($source, 'wp_', [], $this->push_session_id);
        $push = new DatabasePush($target, 'wp_', $this->push_session_id);
        try {
            $push->start();
            $this->copySource($reader, $push);
            $push->commit();
            self::assertSame(['00FF3F', '0'], $this->pdo->query('SELECT HEX(value), CAST(choice+0 AS CHAR) FROM wp_values')->fetch(PDO::FETCH_NUM));
            while ($push->get_status()['phase'] !== 'complete') {
                $push->cleanup_next_table();
            }
        } finally {
            $reader->close();
            $push->close();
        }
    }

    public function testForeignKeyReplayAfterProgressWriteTimesOut(): void {
        $prefix = '__reprint_db_' . $this->push_session_id . '_';
        $archive = tmpfile();
        foreach ([
            ['table' => 'wp_parent', 'ddl' => 'CREATE TABLE `wp_parent` (`id` int PRIMARY KEY) ENGINE=InnoDB'],
            ['values' => ['id' => base64_encode('1')]],
            ['table' => 'wp_child', 'ddl' => 'CREATE TABLE `wp_child` (`id` int PRIMARY KEY, parent_id int) ENGINE=InnoDB'],
            ['values' => ['id' => base64_encode('2'), 'parent_id' => base64_encode('1')]],
            ['table' => 'wp_child', 'foreign_key' => $prefix . 't1_fk_1', 'definition' => 'FOREIGN KEY (parent_id) REFERENCES `' . $prefix . 't0` (id)'],
            ['end' => true],
        ] as $record) {
            fwrite($archive, json_encode($record) . "\n");
        }
        $local_absolute_path = stream_get_meta_data($archive)['uri'];
        $blocker = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start();
            for ($record = 0; $record < 4; ++$record) {
                $this->importNextFixtureRecord($push, $local_absolute_path);
            }
            $confirmed_records = $push->get_status()['records'];
            // Inject a real SQL lock timeout in the progress write, after
            // ALTER has committed. This does not modify private state.
            $blocker->beginTransaction();
            $blocker->query('SELECT state FROM `' . $prefix . 'state` WHERE id=1 FOR UPDATE')->fetchColumn();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
            try {
                $this->importNextFixtureRecord($push, $local_absolute_path);
                self::fail('The progress write must time out behind the held row lock.');
            } catch (PDOException $exception) {
                self::assertSame(1205, $exception->errorInfo[1]);
            }
            $push->close();
            $blocker->rollBack();
            $constraint_count = "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='" . $prefix . "t1_fk_1'";
            self::assertSame(1, (int) $this->pdo->query($constraint_count)->fetchColumn());
            $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
            self::assertSame($confirmed_records, $push->get_status()['records']);
            $this->importNextFixtureRecord($push, $local_absolute_path);
            self::assertGreaterThan($confirmed_records, $push->get_status()['records']);
            self::assertSame(1, (int) $this->pdo->query($constraint_count)->fetchColumn());
            $this->importNextFixtureRecord($push, $local_absolute_path);
            self::assertSame('ready', $push->get_status()['phase']);
            $push->commit();
            self::assertSame('wp_parent', $this->pdo->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_child' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn());
        } finally {
            if ($blocker->inTransaction()) {
                $blocker->rollBack();
            }
            $push->close();
            fclose($archive);
        }
    }

    public function testRowAndSourceCursorRollBackTogetherWhenProgressWriteTimesOut(): void {
        $prefix = '__reprint_db_' . $this->push_session_id . '_';
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $blocker = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $push->start();
            $push->import_record(['table' => 'wp_values', 'ddl' => 'CREATE TABLE `wp_values` (`id` int PRIMARY KEY) ENGINE=InnoDB', 'cursor' => ['record' => 1]]);
            $blocker->beginTransaction();
            $blocker->query('SELECT state FROM `' . $prefix . 'state` WHERE id=1 FOR UPDATE')->fetchColumn();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
            $row = ['values' => ['id' => base64_encode('1')], 'cursor' => ['record' => 2]];
            try {
                $push->import_record($row);
                self::fail('The progress write must time out behind the held row lock.');
            } catch (PDOException $exception) {
                self::assertSame(1205, $exception->errorInfo[1]);
            }
            $push->close();
            $blocker->rollBack();
            $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
            self::assertSame(1, $push->get_status()['records']);
            self::assertSame(['record' => 1], $push->get_status()['cursor']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $prefix . 't0`')->fetchColumn());
            $push->import_record($row);
            self::assertSame(['record' => 2], $push->get_status()['cursor']);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $prefix . 't0`')->fetchColumn());
        } finally {
            if ($blocker->inTransaction()) {
                $blocker->rollBack();
            }
            $push->close();
        }
    }

    public function testLongTableNamesKeepCheckAndSelfReferencingForeignKeyConstraints(): void {
        $table = 'wp_' . str_repeat('x', 61);
        $this->pdo->exec('CREATE TABLE `' . $table . '` (id int PRIMARY KEY, parent_id int NULL, CONSTRAINT named_fk FOREIGN KEY(parent_id) REFERENCES `' . $table . '`(id), CONSTRAINT named_check CHECK (id > 0)) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO `' . $table . '` VALUES (1, NULL), (2, 1)');
        $source = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('SET SESSION sql_quote_show_create=0');
        $reader = new DatabasePushSource($source, 'wp_', [], $this->push_session_id);
        $push = null;
        try {
            $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
            $push->start();
            $this->copySource($reader, $push);
            $push->commit();
            while ($push->get_status()['phase'] !== 'complete') {
                $push->cleanup_next_table();
            }
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn());
            $constraints = $this->pdo->query("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "' AND CONSTRAINT_TYPE IN ('CHECK', 'FOREIGN KEY')")->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $constraints);
            foreach ($constraints as $constraint) {
                self::assertLessThanOrEqual(64, strlen($constraint['CONSTRAINT_NAME']));
            }
            self::assertSame($table, $this->pdo->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn());
        } finally {
            $reader->close();
            if ($push !== null) {
                $push->close();
            }
        }
    }

    public function testSourceCanChangeAfterSchemaWasRead(): void {
        $this->pdo->exec('CREATE TABLE wp_values (id int PRIMARY KEY, value text) ENGINE=MyISAM');
        $this->pdo->exec("INSERT INTO wp_values VALUES (1, 'original')");
        $source = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $reader = new DatabasePushSource($source, 'wp_', [], $this->push_session_id);
        try {
            self::assertTrue($reader->next_step()); // Schema only; no long-lived read lock.
            $this->pdo->exec("UPDATE wp_values SET value='changed' WHERE id=1");
            self::assertTrue($reader->next_step());
            self::assertSame('changed', base64_decode($reader->get_record()['values']['value']));
            self::assertFalse($source->inTransaction());
            $reader->close();
            $reader->close();
        } finally {
            $reader->close();
        }
    }

    public function testCursorUsesRawCompositeKeysAndDoesNotRepeatConfirmedRows(): void {
        $this->pdo->exec("CREATE TABLE wp_values (name varchar(10) CHARACTER SET latin1, flag bit(16), choice ENUM('', '0', 'one'), value text, PRIMARY KEY (name, flag)) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_values VALUES (_latin1 0xE9, 257, 'one', 'https://local.test/first'), (_latin1 0xE9, 258, '0', 'https://local.test/second')");
        $source = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . ';charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $reader = new DatabasePushSource($source, 'wp_', ['https://local.test' => 'https://target.test'], $this->push_session_id);
        try {
            self::assertTrue($reader->next_step());
            self::assertTrue($reader->next_step());
            $record = $reader->get_record();
            self::assertSame('https://target.test/first', base64_decode($record['values']['value']));
            self::assertSame('é', base64_decode($record['values']['name']));
            $tables = $reader->get_tables();
            $reader->close();
            $this->pdo->exec("UPDATE wp_values SET value='https://local.test/changed' WHERE flag=258");
            $reader = new DatabasePushSource($source, 'wp_', ['https://local.test' => 'https://target.test'], $this->push_session_id, $record['cursor'], $tables);
            self::assertTrue($reader->next_step());
            $record = $reader->get_record();
            self::assertSame('https://target.test/changed', base64_decode($record['values']['value']));
            self::assertSame('0', base64_decode($record['values']['choice']));
            self::assertSame('0102', bin2hex(base64_decode($record['values']['flag'])));
        } finally {
            $reader->close();
        }
    }

    public function testForeignKeyFromAnotherPrefixPreventsStaging(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE another_site_refs (id int, FOREIGN KEY (id) REFERENCES wp_options(id)) ENGINE=InnoDB');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start();
            self::fail('An incoming foreign key must prevent an unsafe exchange.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('foreign key referencing this site', $exception->getMessage());
        } finally {
            $push->close();
        }
    }

    public function testExtraSelectionCannotChangeAfterCreate(): void {
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $push->start(['plugin_rows']);
        $push->close();
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('extra table selection changed');
            $push->start(['other_site']);
        } finally {
            $push->close();
        }
    }

    public function testForeignKeyFromUnselectedTableToExtraTablePreventsStaging(): void {
        $this->pdo->exec('CREATE TABLE plugin_rows (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE another_site (id int, FOREIGN KEY (id) REFERENCES plugin_rows(id)) ENGINE=InnoDB');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('foreign key referencing this site');
            $push->start(['plugin_rows']);
        } finally {
            $push->close();
        }
    }

    public function testMissingExplicitSourceTableIsReportedInsteadOfSkipped(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Explicitly included source table does not exist: plugin_typo.');
        new DatabasePushSource($this->pdo, 'wp_', [], $this->push_session_id, null, null, ['plugin_typo']);
    }

    public function testExtraTableDoesNotHideWrongSourcePrefix(): void {
        $this->pdo->exec('CREATE TABLE plugin_rows (id int) ENGINE=InnoDB');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The local source has no tables for prefix wp_.');
        new DatabasePushSource($this->pdo, 'wp_', [], $this->push_session_id, null, null, ['plugin_rows']);
    }

    public function testUnselectedTableRecordIsRejected(): void {
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start(['plugin_rows']);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('outside the selected tables');
            $push->import_record(['table' => 'another_site', 'ddl' => 'CREATE TABLE `another_site` (`id` int) ENGINE=InnoDB', 'cursor' => ['record' => 1]]);
        } finally {
            $push->close();
        }
    }

    public function testLiveTransactionBlocksRenameWithoutPublishingOldTableNames(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO wp_options VALUES (1)');
        $archive = tmpfile();
        fwrite($archive, json_encode(['table' => 'wp_options', 'ddl' => 'CREATE TABLE `wp_options` (`id` int PRIMARY KEY) ENGINE=InnoDB']) . "\n" . json_encode(['end' => true]) . "\n");
        $local_absolute_path = stream_get_meta_data($archive)['uri'];
        $writer = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start();
            $this->importNextFixtureRecord($push, $local_absolute_path);
            $this->importNextFixtureRecord($push, $local_absolute_path);
            $writer->beginTransaction();
            $writer->exec('UPDATE wp_options SET id=2 WHERE id=1');
            $this->pdo->exec('SET SESSION lock_wait_timeout=1');
            try {
                $push->commit();
                self::fail('The outstanding writer must block table exchange.');
            } catch (PDOException $exception) {
                self::assertSame(1205, $exception->errorInfo[1]);
            }
            self::assertSame('ready', $push->get_status()['phase']);
            self::assertSame([], $push->get_status()['old_tables']);
            self::assertSame(1, (int) $this->pdo->query('SELECT id FROM wp_options')->fetchColumn());
            $writer->rollBack();
            $push->commit();
            self::assertSame('committed', $push->get_status()['phase']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_options')->fetchColumn());
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            $push->close();
            fclose($archive);
        }
    }

    public function testHostEngineOverrideCannotCreateNontransactionalProgress(): void {
        if (stripos($this->pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') === false) {
            $this->markTestSkipped('enforce_storage_engine is a MariaDB host setting.');
        }
        $this->pdo->exec('SET SESSION enforce_storage_engine=MyISAM');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start();
            self::fail('The host must not substitute MyISAM for the InnoDB progress table.');
        } catch (PDOException $exception) {
            self::assertSame(1290, $exception->errorInfo[1]);
        } finally {
            $push->close();
        }
        self::assertSame([], $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testPrefixDoesNotSelectAnotherCaseSensitiveSite(): void {
        if ((int) $this->pdo->query('SELECT @@lower_case_table_names')->fetchColumn() !== 0) {
            $this->markTestSkipped('This server folds table name case.');
        }
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE WP_other_site (id int PRIMARY KEY) ENGINE=InnoDB');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            self::assertSame(['wp_options'], $push->assert_supported_target());
        } finally {
            $push->close();
        }
    }

    public function testRejectsUnsupportedTablesBeforeCreatingPushState(): void {
        $this->pdo->exec('CREATE TABLE wp_legacy (id int) ENGINE=MyISAM');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        try {
            $push->start();
            self::fail('MyISAM must be rejected before staging.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('InnoDB', $exception->getMessage());
        } finally {
            $push->close();
        }
        self::assertSame(['wp_legacy'], $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testCommitWithoutEndRecordCannotChangeProduction(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $push->start();
        try {
            $push->commit();
            self::fail('An incomplete stream must not be committed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ready', $exception->getMessage());
        } finally {
            $push->close();
        }
    }
    /** Small protocol fixtures use record number as their opaque source cursor. */
    private function importNextFixtureRecord(DatabasePush $push, string $local_absolute_path): void {
        $number = $push->get_status()['records'];
        $records = file($local_absolute_path);
        $record = json_decode($records[$number], true);
        $record['cursor'] = ['record' => $number + 1];
        $push->import_record($record);
    }

    private function copySource(DatabasePushSource $reader, DatabasePush $push): void {
        while ($reader->next_step()) {
            $record = $reader->get_record();
            if ($record !== null) {
                $push->import_record($record);
            }
        }
        self::assertSame('ready', $push->get_status()['phase']);
    }
}
