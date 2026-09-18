<?php

use WordPress\Reprint\Server\DatabasePush;

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

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
        $push->import_next_record($local_absolute_path);
        self::assertSame('production', $this->pdo->query('SELECT value FROM wp_options')->fetchColumn());
        $push->close();
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $push->import_next_record($local_absolute_path);
        $push->import_next_record($local_absolute_path);
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

    public function testSourceSnapshotIsStableAndSourceRowsRemainUntouched(): void {
        require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-archive.php';
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY, value longtext) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_options VALUES (1, 'https://local.test/old')");
        $source = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName . ';charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $local_absolute_path = sys_get_temp_dir() . '/db-archive-' . bin2hex(random_bytes(8));
        $archive = new DatabasePushArchive($source, $local_absolute_path, 'wp_', ['https://local.test' => 'https://production.test']);
        try {
            $this->pdo->exec("UPDATE wp_options SET value='https://local.test/new' WHERE id=1");
            while ($archive->next_step()) {
            }
            $records = array_map(static fn ($line) => json_decode($line, true), file($local_absolute_path));
            self::assertSame('https://production.test/old', base64_decode($records[1]['values']['value']));
            self::assertSame('https://local.test/new', $this->pdo->query('SELECT value FROM wp_options')->fetchColumn());
        } finally {
            $archive->close();
            if (is_file($local_absolute_path)) {
                unlink($local_absolute_path);
            }
            if (is_file($local_absolute_path . '.building')) {
                unlink($local_absolute_path . '.building');
            }
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
            $push->import_next_record($local_absolute_path);
            $push->import_next_record($local_absolute_path);
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

    public function testCommitWithoutCompletedArchiveCannotChangeProduction(): void {
        $this->pdo->exec('CREATE TABLE wp_options (id int PRIMARY KEY) ENGINE=InnoDB');
        $push = new DatabasePush($this->pdo, 'wp_', $this->push_session_id);
        $push->start();
        try {
            $push->commit();
            self::fail('An incomplete archive must not be committed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ready', $exception->getMessage());
        } finally {
            $push->close();
        }
    }
}
