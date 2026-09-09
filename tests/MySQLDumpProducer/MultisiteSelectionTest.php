<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\Reprint\Server\DatabaseRowsReader;
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

    /** @dataProvider progress_resume_modes */
    public function test_progress_counts_only_sql_tables_in_export_order(bool $resume_each_fragment): void
    {
        $this->create_network();
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            // Profiles are listed first, but content and users must precede them.
            'tables_to_process' => ['network_usermeta', 'network_7_options', 'network_users', 'network_users'],
            'batch_size' => 2,
        ];
        $table_order = ['network_7_options', 'network_users', 'network_usermeta'];
        $producer = $this->createProducer($options);
        $previous_done = 0;
        $discovery_steps = 0;
        $seen_tables = [];
        $sql = '';
        while ($producer->next_sql_fragment()) {
            $sql .= $producer->get_sql_fragment() . "\n";
            $options['cursor'] = $producer->get_reentrancy_cursor();
            $cursor = json_decode($options['cursor'], true);
            $progress = $cursor['progress'];
            $this->assertSame(3, $progress['tables']['total']);
            $this->assertGreaterThanOrEqual($previous_done, $progress['tables']['done']);
            $this->assertLessThanOrEqual(3, $progress['tables']['done']);
            $this->assertArrayNotHasKey('tables_before_current', $cursor);
            $previous_done = $progress['tables']['done'];
            if ($progress['current_table'] !== null) {
                $table = $progress['current_table']['name'];
                $this->assertContains($table, $table_order);
                $this->assertSame(array_search($table, $table_order, true), $previous_done);
                $seen_tables[$table] = true;
            }
            if (in_array($cursor['state'], ['collect_content_user_ids', 'collect_site_members'], true)) {
                ++$discovery_steps;
                $this->assertSame(1, $previous_done, 'ID-only reads must retain the completed content-table count.');
                $this->assertNull($progress['current_table']);
            }
            if ($resume_each_fragment) {
                $producer->close();
                $producer = $this->createProducer($options);
                $resumed_cursor = json_decode($producer->get_reentrancy_cursor(), true);
                $this->assertSame($progress, $resumed_cursor['progress'], 'Resume must not change the table or row counts.');
            }
        }
        $producer->close();
        $this->assertGreaterThan(0, $discovery_steps);
        $this->assertSame($table_order, array_keys($seen_tables));
        $this->assertSame(3, $previous_done);
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertEqualsCanonicalizing($table_order, $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function progress_resume_modes(): array
    {
        return [[false], [true]];
    }

    /** Shared login secrets stay at the source, including after every fragment. */
    public function test_login_credentials_are_replaced_before_export_and_resume(): void
    {
        $this->create_network();
        $display_name = str_repeat('😀', 250);
        $this->pdo->prepare('UPDATE network_users SET display_name = ? WHERE ID = 1')->execute([$display_name]);
        $source_users = $this->pdo->query('SELECT * FROM network_users ORDER BY ID')->fetchAll();
        foreach ([false, true] as $resume) {
            $options = [
                'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
                'max_statement_size' => 1450,
                'batch_size' => 2,
            ];
            $producer = $this->createProducer($options);
            $sql = '';
            $steps = 0;
            while ($producer->next_sql_fragment()) {
                $sql .= $producer->get_sql_fragment() . "\n";
                $cursor = $producer->get_reentrancy_cursor();
                foreach ($source_users as $user) {
                    foreach (['user_pass', 'user_activation_key'] as $column) {
                        $this->assertStringNotContainsString($user[$column], $sql . $cursor);
                        $this->assertStringNotContainsString(bin2hex($user[$column]), strtolower($sql));
                        $this->assertStringNotContainsString(base64_encode($user[$column]), $sql . $cursor);
                    }
                }
                if ($resume) {
                    $producer = $this->createProducer($options + ['cursor' => $cursor]);
                }
                $this->assertLessThan(500, ++$steps);
            }
            $target = $this->executeDumpInNewDatabase($sql);
            $this->assertSame(['*'], $target->query('SELECT DISTINCT user_pass FROM network_users')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame([''], $target->query('SELECT DISTINCT user_activation_key FROM network_users')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame($display_name, $target->query('SELECT display_name FROM network_users WHERE ID = 1')->fetchColumn());
            $this->assertStringContainsString('UPDATE `network_users`', $sql, 'A permitted profile field must exercise oversized-row reloads.');
            $this->assertSame($source_users, $this->pdo->query('SELECT * FROM network_users ORDER BY ID')->fetchAll());
            $target = null;
        }
    }

    /** The preceding rule version may already have sent credentials to the target. */
    public function test_previous_selection_rules_require_a_fresh_export(): void
    {
        $this->create_network();
        $options = ['multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1)];
        $producer = $this->createProducer($options);
        $producer->next_sql_fragment();
        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        // v3 and v5 save user IDs but still send credentials in the earlier
        // stack layer. v4 also has the previous table-walk cursor layout.
        foreach (['core-v1', 'core-v2', 'core-v3', 'core-v4', 'core-v5'] as $version) {
            $cursor['multisite_selection'] = $version . ':network_:1:7';
            try {
                $this->createProducer($options + ['cursor' => json_encode($cursor)]);
                $this->fail('A cursor from ' . $version . ' must not resume under the current rules');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('export rules changed', $error->getMessage());
            }
        }
    }

    /** A client-supplied cursor cannot turn a profile chunk into a credential read. */
    public function test_tampered_oversized_cursor_cannot_read_login_credentials(): void
    {
        $this->create_network();
        $this->pdo->prepare('UPDATE network_users SET display_name = ? WHERE ID = 1')
            ->execute([str_repeat('😀', 250)]);
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'max_statement_size' => 1450,
        ];
        $producer = $this->createProducer($options);
        $cursor = null;
        while ($producer->next_sql_fragment()) {
            $candidate = json_decode($producer->get_reentrancy_cursor(), true);
            if ($candidate['current_table'] === 'network_users' && !empty($candidate['oversized_queue'])) {
                $cursor = $candidate;
                break;
            }
        }
        $this->assertNotNull($cursor, 'The profile must leave an unfinished oversized read');
        foreach (['user_pass' => 'source-password-hash-1', 'user_activation_key' => 'source-reset-key-1'] as $column => $secret) {
            $cursor['oversized_queue'][0]['column'] = $column;
            $cursor['oversized_queue'][0]['total_length'] = strlen($secret);
            $producer = $this->createProducer($options + ['cursor' => json_encode($cursor)]);
            $read_error = null;
            while (true) {
                try {
                    if (!$producer->next_sql_fragment()) {
                        break;
                    }
                } catch (RuntimeException $error) {
                    $read_error = $error;
                    break;
                }
                $fragment = $producer->get_sql_fragment();
                $this->assertStringNotContainsString($secret, $fragment);
                $this->assertStringNotContainsString(base64_encode($secret), $fragment);
            }
            $this->assertInstanceOf(RuntimeException::class, $read_error, 'A credential read must not satisfy the forged profile length');
            $this->assertStringContainsString('changed during export', $read_error->getMessage());
        }
    }

    /** Ordinary database exports keep their existing login behavior. */
    public function test_unselected_dump_preserves_login_credentials(): void
    {
        $this->create_network();
        $target = $this->executeDumpInNewDatabase($this->getDumpSQL());
        $query = 'SELECT ID, user_pass, user_activation_key FROM network_users ORDER BY ID';
        $this->assertSame($this->pdo->query($query)->fetchAll(), $target->query($query)->fetchAll());
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
                if ($cursor['state'] === 'collect_site_members' || strpos($fragment, '-- Begin user and profile export') === 0) {
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

    /** Sparse users must cost saved-ID lookups, not a scan of unrelated accounts. */
    public function test_sparse_users_bound_source_index_reads(): void
    {
        $this->create_network();
        $values = [];
        for ($id = 10; $id <= 2010; ++$id) {
            $values[] = "({$id},'unrelated')";
        }
        $this->pdo->exec('INSERT INTO network_users (ID, user_login) VALUES ' . implode(',', $values));
        $this->pdo->exec("INSERT INTO network_users (ID, user_login) VALUES (9000,'last-selected')");
        $this->pdo->exec('INSERT INTO network_7_comments VALUES (2,9000)');
        $reader = $this->open_shared_table_reader('network_users', 2);
        $users = [];
        do {
            $before = $this->count_source_index_reads();
            $result = $reader->next_record();
            $reads = $this->count_source_index_reads() - $before;
            $this->assertLessThan(60, $reads, 'One step must not scan unrelated network users.');
            if ($result === true) {
                $users[] = (string) $reader->get_current_record()['ID'];
                $reader->clear_current_record();
            }
        } while ($result !== false);
        $this->assertSame(['1', '2', '3', '4', '5', '9000'], $users);
        $reader->close();
    }

    /** Rejected metadata still produces a bounded step and a durable row position. */
    public function test_rejected_metadata_batches_resume_on_both_storage_engines(): void
    {
        $this->create_network();
        $this->pdo->exec('DELETE FROM network_usermeta');
        $values = [];
        for ($id = 1; $id <= 1000; ++$id) {
            $values[] = "({$id},3,'session_tokens','never-export')";
        }
        $this->pdo->exec('INSERT INTO network_usermeta VALUES ' . implode(',', $values));
        // A sparse final key also checks that we page through actual IDs,
        // rather than walking every integer between the first and last ID.
        $this->pdo->exec("INSERT INTO network_usermeta VALUES (9000000000000000000,3,'nickname','selected')");
        foreach (['InnoDB', 'MyISAM'] as $engine) {
            $this->pdo->exec("ALTER TABLE network_usermeta ENGINE={$engine}");
            $reader = $this->open_shared_table_reader('network_usermeta', 250);
            $before = $this->count_source_index_reads();
            $this->assertNull($reader->next_record(), 'An empty filtered batch must return control, not scan ahead.');
            $this->assertLessThan(800, $this->count_source_index_reads() - $before);
            $cursor = $reader->get_cursor_state();
            $this->assertSame(['umeta_id' => 250], $cursor['last_pk_values']);
            $reader->close();

            // Resume a fresh reader after every empty batch. The ID is saved
            // even though no row from that batch will appear in the dump.
            for ($last_id = 500; $last_id <= 1000; $last_id += 250) {
                $reader = new DatabaseRowsReader($this->pdo, [
                    'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
                    'tables_to_process' => ['network_usermeta'], 'batch_size' => 250,
                    'cursor' => $cursor,
                ]);
                $this->assertTrue($reader->restore_cursor_state($cursor));
                $this->assertNull($reader->next_record());
                $cursor = $reader->get_cursor_state();
                $this->assertSame(['umeta_id' => $last_id], $cursor['last_pk_values']);
                $reader->close();
            }
            $reader = new DatabaseRowsReader($this->pdo, [
                'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
                'tables_to_process' => ['network_usermeta'], 'batch_size' => 250,
                'cursor' => $cursor,
            ]);
            $reader->restore_cursor_state($cursor);
            $this->assertTrue($reader->next_record());
            $this->assertSame('selected', $reader->get_current_record()['meta_value']);
            $reader->clear_current_record();
            // Completing the final batch can yield once before table EOF.
            $result = $reader->next_record();
            if ($result === null) {
                $result = $reader->next_record();
            }
            $this->assertFalse($result);
            $reader->close();
        }
    }

    /** Missing accounts and rejected tails must not end either table early. */
    public function test_resume_each_fragment_keeps_rows_after_empty_user_batches(): void
    {
        $this->create_network();
        $this->pdo->exec("INSERT INTO network_7_comments VALUES (2,8),(3,9),(4,10),(5,11),(6,12)");
        $this->pdo->exec("INSERT INTO network_users (ID, user_login) VALUES (12,'last-user')");
        $this->pdo->exec("INSERT INTO network_usermeta VALUES
            (9,6,'session_tokens','private'),(10,12,'nickname','Last'),
            (11,12,'session_tokens','private'),(12,12,'session_tokens','private')");
        foreach ([true, false] as $buffered) {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            $options = [
                'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
                'tables_to_process' => ['network_users', 'network_usermeta'], 'batch_size' => 2,
            ];
            $producer = $this->createProducer($options);
            $sql = '';
            $steps = 0;
            while ($producer->next_sql_fragment()) {
                $sql .= $producer->get_sql_fragment() . "\n";
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $producer->close();
                $producer = $this->createProducer($options);
                $this->assertLessThan(100, ++$steps, 'Empty batches must make progress after resume.');
            }
            $producer->close();
            $target = $this->executeDumpInNewDatabase($sql);
            $this->assertSame(['1','2','3','4','5','12'], array_map('strval',
                $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertSame(['1','2','4','10'], array_map('strval',
                $target->query('SELECT umeta_id FROM network_usermeta ORDER BY umeta_id')->fetchAll(PDO::FETCH_COLUMN)));
            $target = null;
        }
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    /** Candidate queries retain the existing rejection of non-numeric cursor IDs. */
    public function test_tampered_shared_user_cursor_rejects_non_numeric_id(): void
    {
        $this->create_network();
        foreach (['network_users' => 'ID', 'network_usermeta' => 'umeta_id'] as $table => $column) {
            $reader = $this->open_shared_table_reader($table, 2);
            $cursor = $reader->get_cursor_state();
            $cursor['last_pk_values'] = $reader->encode_database_values_for_cursor([$column => '0 OR 1=1']);
            $reader->restore_cursor_state($cursor);
            try {
                $reader->next_record();
                $this->fail('A tampered shared-table ID must be rejected before reading candidates.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('non-numeric value', $error->getMessage());
            } finally {
                $reader->close();
            }
        }
    }

    /** Starts an actual selected-site reader after content and membership collection. */
    private function open_shared_table_reader(string $table, int $batch_size): DatabaseRowsReader
    {
        $reader = new DatabaseRowsReader($this->pdo, [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'tables_to_process' => [$table], 'batch_size' => $batch_size,
        ]);
        while ($reader->move_to_next_table()) {
            while ($reader->collect_content_user_ids_step()) {
                $this->assertNotNull($reader->get_cursor_state()['last_pk_values']);
            }
        }
        $this->assertTrue($reader->start_user_tables());
        while ($reader->collect_site_members_step()) {
            $this->assertNotSame('0', $reader->get_cursor_state()['last_scanned_usermeta_id']);
        }
        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame($table, $reader->get_current_table());
        return $reader;
    }

    /** Counts storage-engine row reads on this connection without a fake transport. */
    private function count_source_index_reads(): int
    {
        return array_sum($this->pdo->query("SHOW SESSION STATUS LIKE 'Handler_read_%'")->fetchAll(PDO::FETCH_KEY_PAIR));
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
        $this->pdo->exec("ALTER TABLE network_users
            ADD user_pass varchar(255) NOT NULL DEFAULT '',
            ADD user_activation_key varchar(255) NOT NULL DEFAULT '',
            ADD display_name varchar(250) NOT NULL DEFAULT ''");
        $this->pdo->exec("UPDATE network_users SET user_pass = CONCAT('source-password-hash-', ID),
            user_activation_key = CONCAT('source-reset-key-', ID), display_name = user_login");
    }
}
