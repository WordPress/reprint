<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\Reprint\Server\MultisiteDatabaseSelection;

/** Exercises site selection against MySQL, including resumable oversized reads. */
class MultisiteSelectionTest extends MySQLDumpProducerTestBase
{
    /** Only selected content, related users, and permitted shared settings travel. */
    public function test_selected_site_dump_excludes_sibling_data(): void
    {
        $this->create_network();
        $sql = $this->getDumpSQL([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'batch_size' => 2,
        ]);
        $target = $this->executeDumpInNewDatabase($sql);
        $tables = $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('network_7_posts', $tables);
        $this->assertNotContains('network_posts', $tables);
        $this->assertNotContains('network_8_posts', $tables);
        $this->assertNotContains('network_shared_plugin', $tables);
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['7'], array_map('strval',
            $target->query('SELECT blog_id FROM network_blogs')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['1'], array_map('strval',
            $target->query('SELECT id FROM network_site')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['active_sitewide_plugins', 'allowedthemes'], $target->query(
            'SELECT meta_key FROM network_sitemeta ORDER BY meta_key')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['first_name', 'network_7_capabilities', 'network_7_capabilities'], $target->query(
            'SELECT meta_key FROM network_usermeta ORDER BY umeta_id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['blogname'], $target->query(
            'SELECT option_name FROM network_7_options')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM network_signups')->fetchColumn());
        $this->assertSame('selected', $target->query('SELECT meta_value FROM network_blogmeta')->fetchColumn());
        $this->assertSame('shop', $target->query('SELECT post_title FROM network_7_posts')->fetchColumn());
    }

    /** Site 1 uses the base prefix without selecting the rest of the database. */
    public function test_main_site_does_not_select_numbered_site_tables(): void
    {
        $this->create_network();
        $target = $this->executeDumpInNewDatabase($this->getDumpSQL([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 1, 1),
        ]));
        $tables = $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('network_posts', $tables);
        $this->assertNotContains('network_7_posts', $tables);
        $this->assertSame('main', $target->query('SELECT post_title FROM network_posts')->fetchColumn());
    }

    /** Every fragment boundary can resume without losing a large selected value. */
    public function test_resume_every_fragment_keeps_selection_and_oversized_values(): void
    {
        $this->create_network();
        $value = str_repeat('selected text ', 800);
        $this->pdo->prepare('UPDATE network_7_posts SET post_title = ?')->execute([$value]);
        $this->pdo->prepare("UPDATE network_usermeta SET meta_value = ? WHERE umeta_id = 1")->execute([$value]);
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'max_statement_size' => 2048,
            'batch_size' => 2,
        ];
        $producer = $this->createProducer($options);
        $sql = '';
        $steps = 0;
        while ($producer->next_sql_fragment()) {
            $sql .= $producer->get_sql_fragment() . "\n";
            $options['cursor'] = $producer->get_reentrancy_cursor();
            $producer = $this->createProducer($options);
            $this->assertLessThan(500, ++$steps);
        }
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame($value, $target->query('SELECT post_title FROM network_7_posts')->fetchColumn());
        $this->assertSame($value, $target->query('SELECT meta_value FROM network_usermeta WHERE umeta_id = 1')->fetchColumn());
        $this->assertGreaterThan(20, $steps);
        $this->assertNotContains('network_8_posts', $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Removing a content-free member must also stop subsequent source value reads. */
    public function test_membership_removed_during_oversized_reads_stops_export(): void
    {
        $this->create_network();
        $this->pdo->prepare('UPDATE network_usermeta SET meta_value = ? WHERE umeta_id = 1')
            ->execute([str_repeat('selected text ', 800)]);
        $producer = $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'max_statement_size' => 2048,
        ]);
        $started_profile = false;
        while ($producer->next_sql_fragment()) {
            if (strpos($producer->get_sql_fragment(), 'UPDATE `network_usermeta`') === 0) {
                $started_profile = true;
                break;
            }
        }
        $this->assertTrue($started_profile);
        $this->pdo->exec("DELETE FROM network_usermeta WHERE user_id = 1 AND meta_key = 'network_7_capabilities'");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch column substring for oversized row: meta_value');
        $producer->next_sql_fragment();
    }

    /** A client cannot carry its database cursor from site 7 to site 8. */
    public function test_resume_with_a_different_site_is_rejected(): void
    {
        $this->create_network();
        $producer = $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
        ]);
        $producer->next_sql_fragment();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected multisite site');
        $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 8, 1),
            'cursor' => $producer->get_reentrancy_cursor(),
        ]);
    }

    /** A selected cursor cannot resume as an unfiltered database dump. */
    public function test_resume_without_selection_is_rejected(): void
    {
        $this->create_network();
        $producer = $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
        ]);
        $producer->next_sql_fragment();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected multisite site');
        $this->createProducer(['cursor' => $producer->get_reentrancy_cursor()]);
    }

    /** A normal restart replaces only this site's saved IDs, never a sibling's. */
    public function test_site_tables_are_separate_and_replaced_cursors_stop(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)];
        $producer = $this->createProducer($options);
        $producer->next_sql_fragment();
        $cursor = $producer->get_reentrancy_cursor();
        unset($producer);
        $this->getDumpSQL(['multisite_selection' => new MultisiteDatabaseSelection('network_', 8, 2)]);
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('network_7_reprint_users', $tables);
        $this->assertContains('network_8_reprint_users', $tables);
        $resumed = $this->createProducer($options + ['cursor' => $cursor]);
        $this->assertTrue($resumed->next_sql_fragment());
        unset($resumed);
        $this->getDumpSQL($options);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replaced');
        $this->createProducer($options + ['cursor' => $cursor]);
    }

    /** A users-only request still discovers content authors and content-free members. */
    public function test_skipped_content_is_discovered_in_bounded_resumable_steps(): void
    {
        $this->create_network();
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_users'], 'batch_size' => 2,
        ];
        $sql = '';
        $discovery_steps = 0;
        do {
            $producer = $this->createProducer($options);
            $more = $producer->next_sql_fragment();
            if ($more) {
                $sql .= $producer->get_sql_fragment() . "\n";
                $options['cursor'] = $producer->get_reentrancy_cursor();
                if (strpos($producer->get_sql_fragment(), 'DO 0;') !== false) {
                    ++$discovery_steps;
                    $cursor = json_decode($options['cursor'], true);
                    if ($cursor['current_table'] !== null) {
                        $this->assertContains($cursor['current_table'], ['network_7_posts', 'network_7_comments', 'network_7_links']);
                        $this->assertSame('collect_content_user_ids', $cursor['state']);
                    }
                }
            }
            unset($producer);
        } while ($more);
        $this->assertGreaterThan(4, $discovery_steps, 'Discovery must return between small primary-key batches');
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Content, membership scanning and user export have separate resume boundaries. */
    public function test_content_then_members_then_users_resume_without_reentering_members(): void
    {
        $this->create_network();
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_usermeta', 'network_users', 'network_7_links', 'network_7_comments', 'network_7_posts'],
            'batch_size' => 2,
        ];
        $sql = '';
        $exported_tables = [];
        $member_positions = [];
        do {
            $producer = $this->createProducer($options);
            $more = $producer->next_sql_fragment();
            if ($more) {
                $fragment = $producer->get_sql_fragment();
                $sql .= $fragment . "\n";
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $cursor = json_decode($options['cursor'], true);
                if (preg_match('/CREATE TABLE `([^`]+)`/', $fragment, $match)) {
                    $exported_tables[] = $match[1];
                }
                if (strpos($fragment, 'DO 0;') !== false) {
                    $this->assertNull($cursor['current_table'], 'Membership work happens between table groups, not inside a user table');
                    $this->assertSame(['network_7_links', 'network_7_comments', 'network_7_posts'], $exported_tables);
                    $this->assertSame(['3', '4', '5'], array_map('strval', $this->pdo->query(
                        'SELECT user_id FROM network_7_reprint_users WHERE reference_kind IN (1,2,3) ORDER BY user_id'
                    )->fetchAll(PDO::FETCH_COLUMN)));
                    if ($cursor['state'] === 'collect_site_members') {
                        $member_positions[] = $cursor['last_scanned_usermeta_id'];
                    }
                }
            }
            unset($producer);
        } while ($more);
        $this->assertSame(['0', '2', '4', '6', '8'], $member_positions,
            'Checkpoint before membership scanning and after each bounded batch; never scan completed metadata twice');
        $this->assertSame(['network_7_links', 'network_7_comments', 'network_7_posts', 'network_users', 'network_usermeta'], $exported_tables);
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Row exclusions omit content, not the users related to that source site. */
    public function test_excluded_content_rows_collect_ids_before_exporting_that_table(): void
    {
        $this->create_network();
        $this->pdo->exec("INSERT INTO network_7_posts VALUES (2,4,'omit'),(3,5,'keep'),(4,3,'omit')");
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_users', 'network_7_posts'], 'batch_size' => 2,
            'exclude_rows' => [['table' => 'network_7_posts', 'column' => 'post_title', 'value' => 'omit']],
        ];
        $sql = '';
        $post_positions = [];
        do {
            $producer = $this->createProducer($options);
            $more = $producer->next_sql_fragment();
            if ($more) {
                $fragment = $producer->get_sql_fragment();
                $sql .= $fragment . "\n";
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $cursor = json_decode($options['cursor'], true);
                if ($cursor['state'] === 'collect_content_user_ids' && $cursor['current_table'] === 'network_7_posts') {
                    $post_positions[] = base64_decode($cursor['last_pk_values']['ID']['__binary__']);
                    $this->assertStringNotContainsString('CREATE TABLE `network_7_posts`', $sql);
                }
            }
            unset($producer);
        } while ($more);
        $this->assertSame(['2', '4'], $post_positions);
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(['1', '3'], array_map('strval', $target->query('SELECT ID FROM network_7_posts ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Cursor values remain SQL values, including during an omitted table's ID reads. */
    public function test_tampered_content_id_cursor_cannot_remove_the_batch_limit(): void
    {
        $this->create_network();
        $this->pdo->exec("INSERT INTO network_7_posts VALUES (2,4,'two'),(3,5,'three'),(4,3,'four')");
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_users'], 'batch_size' => 2,
        ];
        $producer = $this->createProducer($options);
        while ($producer->next_sql_fragment()) {
            $cursor = json_decode($producer->get_reentrancy_cursor(), true);
            if ($cursor['state'] === 'collect_content_user_ids') {
                break;
            }
        }
        $producer->close();
        $cursor['last_pk_values']['ID'] = ['__binary__' => base64_encode('0 OR 1=1 -- ')];
        $resumed = $this->createProducer($options + ['cursor' => json_encode($cursor)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-numeric value');
        $resumed->next_sql_fragment();
    }

    /** Content-only exports have no reason to scan network memberships. */
    public function test_content_only_export_has_no_membership_phase(): void
    {
        $this->create_network();
        $sql = $this->getDumpSQL([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_7_posts'], 'batch_size' => 2,
        ]);
        $this->assertStringNotContainsString('DO 0;', $sql);
        $this->assertSame(['3'], array_map('strval', $this->pdo->query('SELECT user_id FROM network_7_reprint_users')->fetchAll(PDO::FETCH_COLUMN)));
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(['network_7_posts'], $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A profiles-only request still needs content authors and content-free members. */
    public function test_profiles_only_export_resumes_user_discovery_without_exporting_content(): void
    {
        $this->create_network();
        $this->pdo->exec("INSERT INTO network_usermeta VALUES (20,3,'first_name','Author'),(21,4,'first_name','Commenter'),(22,5,'first_name','Link author')");
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => ['network_usermeta'], 'batch_size' => 2,
        ];
        $sql = '';
        $discovery_steps = 0;
        do {
            $producer = $this->createProducer($options);
            $more = $producer->next_sql_fragment();
            if ($more) {
                $fragment = $producer->get_sql_fragment();
                $sql .= $fragment . "\n";
                $options['cursor'] = $producer->get_reentrancy_cursor();
                if (strpos($fragment, 'DO 0;') !== false) {
                    ++$discovery_steps;
                }
            }
            unset($producer);
        } while ($more);
        $this->assertGreaterThan(4, $discovery_steps);
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(['network_usermeta'], $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT DISTINCT user_id FROM network_usermeta ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** The saved set precedes each content cursor; replay does not duplicate IDs. */
    public function test_content_cursor_has_durable_ids_and_users_are_last(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1), 'batch_size' => 2];
        $producer = $this->createProducer($options);
        $sql = '';
        while ($producer->next_sql_fragment()) {
            $sql .= $producer->get_sql_fragment() . "\n";
            if (strpos($producer->get_sql_fragment(), 'INSERT INTO `network_7_posts`') !== false) {
                break;
            }
        }
        $this->assertStringNotContainsString('CREATE TABLE `network_users`', $sql);
        $this->assertContains('network_7_reprint_users', $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('3', (string) $this->pdo->query('SELECT user_id FROM network_7_reprint_users WHERE user_id = 3')->fetchColumn());
        $cursor = $producer->get_reentrancy_cursor();
        unset($producer);
        $resumed_sql = $this->getDumpSQL($options + ['cursor' => $cursor]);
        $target = $this->executeDumpInNewDatabase($sql . $resumed_sql);
        $this->assertSame(5, (int) $target->query('SELECT COUNT(*) FROM network_users')->fetchColumn());
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM network_7_reprint_users')->fetchColumn());
        $this->getDumpSQL($options + ['cursor' => $cursor]);
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM network_7_reprint_users')->fetchColumn());
        $this->assertNotContains('network_7_reprint_users', $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $target = null;
        $unselected = $this->executeDumpInNewDatabase($this->getDumpSQL());
        $this->assertNotContains('network_7_reprint_users', $unselected->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A live author edit must not turn a saved ID into permission to read a profile. */
    public function test_removed_saved_reference_stops_before_exporting_the_user(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)];
        $producer = $this->createProducer($options);
        while ($producer->next_sql_fragment()) {
            if (strpos($producer->get_sql_fragment(), 'INSERT INTO `network_7_posts`') !== false) {
                break;
            }
        }
        $cursor = $producer->get_reentrancy_cursor();
        unset($producer);
        $this->pdo->exec('UPDATE network_7_posts SET post_author = 0 WHERE ID = 1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('saved user reference');
        $this->getDumpSQL($options + ['cursor' => $cursor]);
    }

    /** Unbuffered source reads must release their result before saving the next batch. */
    public function test_unbuffered_export_resumes_after_partial_content_query(): void
    {
        $this->create_network();
        $this->pdo->exec("INSERT INTO network_7_posts VALUES (2,4,'second'),(3,5,'third'),(4,3,'fourth')");
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1), 'batch_size' => 3];
        $producer = $this->createProducer($options);
        $sql = '';
        while ($producer->next_sql_fragment()) {
            $sql .= $producer->get_sql_fragment() . "\n";
            if (strpos($producer->get_sql_fragment(), 'INSERT INTO `network_7_posts`') !== false) {
                break;
            }
        }
        $cursor = $producer->get_reentrancy_cursor();
        unset($producer);
        $target = $this->executeDumpInNewDatabase($sql . $this->getDumpSQL($options + ['cursor' => $cursor]));
        $this->assertSame(4, (int) $target->query('SELECT COUNT(*) FROM network_7_posts')->fetchColumn());
        $this->assertSame(5, (int) $target->query('SELECT COUNT(*) FROM network_users')->fetchColumn());
    }

    /** Two real source connections cannot replace the same set inside an open request. */
    public function test_source_lock_is_released_by_idempotent_close(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)];
        $producer = $this->createProducer($options);
        $producer->next_sql_fragment();
        $cursor = $producer->get_reentrancy_cursor();
        $other_connection = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName,
            getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            new \WordPress\Reprint\Server\MySQLDumpProducer($other_connection, $options);
            $this->fail('An open source request must prevent replacing its user set');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Another SQL export request', $error->getMessage());
        }
        $producer->close();
        $producer->close();
        $resumed = new \WordPress\Reprint\Server\MySQLDumpProducer($other_connection, $options + ['cursor' => $cursor]);
        $this->assertTrue($resumed->next_sql_fragment());
        $resumed->close();
    }

    /** An embedding caller must not leave ID writes inside its own transaction. */
    public function test_open_source_transaction_is_rejected_without_committing_it(): void
    {
        $this->create_network();
        $this->pdo->beginTransaction();
        try {
            $this->createProducer(['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)]);
            $this->fail('The saved user set needs a dedicated autocommit connection');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('autocommit connection', $error->getMessage());
            $this->assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    /**
     * Process death after content or membership checkpoints must leave saved IDs usable.
     *
     * @dataProvider process_death_boundaries
     */
    public function test_process_death_releases_lock_and_preserves_collected_ids(string $stop_after): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('This process-death test needs the POSIX extension.');
        }
        $this->create_network();
        $script = tempnam(sys_get_temp_dir(), 'reprint-user-set-child-');
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote a filesystem path in the child PHP script.
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($script, '<?php require ' . $autoload . ';' . <<<'CHILD'
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$producer = new \WordPress\Reprint\Server\MySQLDumpProducer($pdo, [
    'multisite_selection' => new \WordPress\Reprint\Server\MultisiteDatabaseSelection('network_', 7, 1),
    'batch_size' => 2,
]);
$sql = '';
while ($producer->next_sql_fragment()) {
    $sql .= $producer->get_sql_fragment() . "\n";
    if (strpos($producer->get_sql_fragment(), $argv[1]) !== false) {
        echo json_encode(['sql' => $sql, 'cursor' => $producer->get_reentrancy_cursor()]);
        fflush(STDOUT);
        // SIGKILL skips destructors, matching a host terminating the PHP worker.
        posix_kill(getmypid(), SIGKILL);
    }
}
CHILD
        );
        try {
            // The PHP 5.6 artifact also needs to create this set without the native random_bytes function.
            $process = proc_open([PHP_BINARY, '-d', 'disable_functions=random_bytes', $script, $stop_after], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertNotSame(0, proc_close($process), $error);
            $saved = json_decode($output, true);
            $this->assertIsArray($saved, $error . $output);
            $this->assertContains('network_7_reprint_users', $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame('3', (string) $this->pdo->query('SELECT user_id FROM network_7_reprint_users WHERE user_id=3')->fetchColumn());
            $target = $this->executeDumpInNewDatabase($saved['sql'] . $this->getDumpSQL([
                'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
                'cursor' => $saved['cursor'], 'batch_size' => 2,
            ]));
            $this->assertSame(5, (int) $target->query('SELECT COUNT(*) FROM network_users')->fetchColumn());
            $this->assertSame('shop', $target->query('SELECT post_title FROM network_7_posts')->fetchColumn());
        } finally {
            unlink($script);
        }
    }

    /** @return array<string,string[]> SQL fragments at the durable phase boundaries. */
    public static function process_death_boundaries(): array
    {
        return [
            'content batch' => ['INSERT INTO `network_7_posts`'],
            'before memberships' => ['-- Begin site membership collection'],
            'membership batch' => ['-- Collect site members'],
            'before users' => ['-- Begin user and profile export'],
        ];
    }

    /** Earlier cursors describe a different table walk and cannot safely resume. */
    public function test_previous_table_walk_cursor_requires_a_fresh_export(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)];
        $producer = $this->createProducer($options);
        $producer->next_sql_fragment();
        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        $producer->close();
        $cursor['multisite_selection'] = 'core-v3:network_:1:7';
        $cursor['user_discovery_source'] = 0;
        $cursor['user_discovery_last_id'] = '0';
        unset($cursor['table_group'], $cursor['last_scanned_usermeta_id']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected multisite site changed');
        $this->createProducer($options + ['cursor' => json_encode($cursor)]);
    }

    /** Builds overlapping IDs, memberships, authors, and network records. */
    private function create_network(): void
    {
        foreach (['network_', 'network_7_', 'network_8_'] as $prefix) {
            $this->pdo->exec("CREATE TABLE {$prefix}posts (ID bigint PRIMARY KEY, post_author bigint, post_title longtext);
                CREATE TABLE {$prefix}comments (comment_ID bigint PRIMARY KEY, user_id bigint);
                CREATE TABLE {$prefix}links (link_id bigint PRIMARY KEY, link_owner bigint);
                CREATE TABLE {$prefix}options (option_id bigint PRIMARY KEY, option_name varchar(191), option_value longtext)");
        }
        $this->pdo->exec("CREATE TABLE network_users (ID bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO network_users VALUES (1,'member'),(2,'empty-member'),(3,'former-author'),(4,'commenter'),(5,'link-author'),(6,'sibling');
            CREATE TABLE network_usermeta (umeta_id bigint PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_usermeta VALUES
                (1,1,'first_name','Shared'),(2,1,'network_7_capabilities','a:1:{s:6:\"editor\";b:1;}'),
                (3,1,'network_8_capabilities','sibling-role'),(4,2,'network_7_capabilities','member'),
                (5,6,'network_8_capabilities','sibling'),(6,6,'first_name','Private'),
                (7,1,'session_tokens','private-session'),(8,1,'_application_passwords','private-password');
            CREATE TABLE network_blogs (blog_id bigint PRIMARY KEY, site_id bigint, domain varchar(200), path varchar(100));
            INSERT INTO network_blogs VALUES (1,1,'main.test','/'),(7,1,'shop.test','/'),(8,2,'other.test','/');
            CREATE TABLE network_blogmeta (meta_id bigint PRIMARY KEY, blog_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_blogmeta VALUES (1,7,'test','selected'),(2,8,'test','sibling');
            CREATE TABLE network_site (id bigint PRIMARY KEY, domain varchar(200), path varchar(100));
            INSERT INTO network_site VALUES (1,'main.test','/'),(2,'other.test','/');
            CREATE TABLE network_sitemeta (meta_id bigint PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_sitemeta VALUES (1,1,'active_sitewide_plugins','a:0:{}'),(2,1,'allowedthemes','a:0:{}'),
                (3,2,'active_sitewide_plugins','private'),(4,1,'site_admins','private'),(5,1,'plugin_secret','private');
            CREATE TABLE network_signups (signup_id bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO network_signups VALUES (1,'pending-private');
            CREATE TABLE network_shared_plugin (id bigint PRIMARY KEY, value text);
            INSERT INTO network_shared_plugin VALUES (1,'private');
            INSERT INTO network_posts VALUES (1,6,'main');
            INSERT INTO network_7_posts VALUES (1,3,'shop');
            INSERT INTO network_8_posts VALUES (1,6,'sibling');
            INSERT INTO network_7_comments VALUES (1,4);
            INSERT INTO network_7_links VALUES (1,5);
            INSERT INTO network_7_options VALUES (1,'blogname','Shop'),(2,'reprint_server_connection_token','private'),(3,'reprint_server_push_authorized_token_fingerprint','private'),(4,'site_export_secret','private')");
    }
}
