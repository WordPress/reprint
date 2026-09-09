<?php

namespace WordPress\Reprint\Server;

require_once __DIR__ . '/utils.php';

/**
 * Selects one site's core tables and its related records in shared core tables.
 *
 * Constructed from trusted WordPress state, never from a client's SQL predicate.
 * Plugin-defined shared records need a separate, explicit migration contract.
 */
class MultisiteDatabaseSelection {

    /** @var string */
    private $base_prefix;
    /** @var string */
    private $site_prefix;
    /** @var int */
    private $site_id;
    /** @var int */
    private $network_id;

    /** @var mixed Source connection retained for this request's lock and ID writes. */
    private $db;
    /** @var string|null */
    private $lock_name;
    /** @var string|null Generation of the one saved set, not a source snapshot. */
    private $generation;

    /** Retains the selected site's IDs; promoting it does not rename its tables. */
    public function __construct(string $base_prefix, int $site_id, int $network_id)
    {
        /**
         * WordPress restricts prefixes to ASCII letters, digits and underscores
         * in wpdb::set_prefix(); MySQL table names allow more characters.
         * WordPress already checks the source prefix during normal bootstrap.
         * Repeat the check here because the queries below insert the prefix
         * into backtick-quoted table names and single-quoted meta keys without
         * escaping it. Removing this guard requires quoting both SQL contexts.
         * /D rejects a final newline that $ would otherwise allow.
         *
         * @see https://developer.wordpress.org/reference/classes/wpdb/set_prefix/
         */
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $base_prefix) || $site_id < 1 || $network_id < 1) {
            throw new \InvalidArgumentException(
                "A multisite selection requires a WordPress table prefix and positive site and network IDs."
            );
        }
        $this->base_prefix = $base_prefix;
        // WordPress leaves only site ID 1 unnumbered. A different network's
        // main site still uses its numeric site ID in the table prefix.
        $this->site_prefix = $base_prefix . ( $site_id === 1 ? '' : $site_id . '_' );
        $this->site_id = $site_id;
        $this->network_id = $network_id;
    }

    /**
     * Binds a database cursor to the same source site and selection rules.
     *
     * The row reader compares this value on resume. Change the version when
     * selection, value-replacement rules or the cursor layout change, not for
     * an equivalent query plan. Source rows may change without changing this version.
     * This identifies the rules, not a snapshot of the mutable source records.
     */
    public function get_identity(): string
    {
        return 'core-v5:' . $this->base_prefix . ':' . $this->network_id . ':' . $this->site_id;
    }

    /**
     * Starts or resumes the single saved user set for this site.
     *
     * The importer lock covers only its local state directory. Hold a source
     * lock for this request; compare the saved generation on the next request.
     * A fresh export replaces a paused one rather than retaining several sets.
     *
     * @param mixed $db Dedicated PDO MySQL connection from the SQL endpoint.
     * @param string|null $generation Saved cursor generation, or null for a fresh export.
     */
    public function open_user_set($db, ?string $generation): void
    {
        // wpdb may route reads and writes to different connections or retain
        // a plugin's transaction. Neither can protect these source-side writes
        // with one named lock and commit them before an export cursor leaves.
        if (!$db instanceof \PDO || $db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('Selected-site SQL export requires a direct PDO MySQL connection. Enable pdo_mysql and allow direct access using the source WordPress database credentials.');
        }
        if ($db->inTransaction() || (string) $db->query('SELECT @@autocommit')->fetchColumn() !== '1') {
            throw new \RuntimeException('Selected-site SQL export requires an autocommit connection without an open transaction. End the source transaction before starting the export.');
        }
        $this->db = $db;
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        // Named locks are server-wide and limited to 64 bytes. Include the
        // database as well as the site table. Fold case for servers with
        // case-insensitive table names. Locks survive commits, not connection death.
        $lock_name = 'reprint-users:' . sha1(strtolower($database . '.' . $this->get_user_table_name()));
        $result = $db->query("SELECT GET_LOCK('{$lock_name}', 0)")->fetchColumn();
        if ( (string) $result !== '1') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain source-side JSON error, never HTML.
            throw new \RuntimeException("Another SQL export request is using the saved users for site {$this->site_id}; try again after it finishes.");
        }
        $this->lock_name = $lock_name;
        try {
            $table = $this->get_user_table_name();
            $comment = $db->query("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchColumn();
            if ($generation !== null) {
                if (!preg_match('/^[a-f0-9]{32}$/D', $generation) || $comment !== 'reprint-users-v1:' . $generation) {
                    throw new \RuntimeException("The saved users for site {$this->site_id} were replaced or are missing. Run db-pull --abort and start again.");
                }
                $this->generation = $generation;
                return;
            }
            if ($comment !== false && !preg_match('/^reprint-users-v1:[a-f0-9]{32}$/D', $comment)) {
                throw new \RuntimeException("Cannot create the saved user set: table {$table} already exists without Reprint's schema marker.");
            }
            // Starting again replaces one site's set, including abandoned work.
            // Do not delete it at completion: the last HTTP response may be lost
            // and the importer may still need to replay an earlier cursor.
            $this->generation = bin2hex(generate_random_bytes(16));
            $db->exec("DROP TABLE IF EXISTS `{$table}`");
            $db->exec("CREATE TABLE `{$table}` (user_id bigint unsigned NOT NULL PRIMARY KEY, reference_kind tinyint unsigned NOT NULL, reference_id bigint unsigned NOT NULL) ENGINE=InnoDB COMMENT='reprint-users-v1:{$this->generation}'");
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Releases the request's lock; keeps the saved rows available for resume. */
    public function close(): void
    {
        if ($this->lock_name !== null) {
            $lock_name = $this->lock_name;
            $this->lock_name = null;
            $this->db->query("SELECT RELEASE_LOCK('{$lock_name}')")->fetchColumn();
        }
        $this->db = null;
    }

    public function get_generation(): ?string
    {
        return $this->generation;
    }

    public function get_user_table_name(): string
    {
        return $this->site_prefix . 'reprint_users';
    }

    /** These reserved tables are source state, including in unfiltered dumps. */
    public static function is_internal_table(string $table): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+reprint_users$/iD', $table);
    }

    /**
     * Tables visited before membership collection and user export.
     *
     * A users-only or profiles-only export must still read author IDs from
     * posts, comments and links. Add those tables to the content walk; the
     * reader omits their SQL when the caller did not select their contents.
     *
     * @param string[] $tables Tables selected for SQL export.
     * @return string[] Content tables, including any needed ID-only reads.
     */
    public function get_content_tables(array $tables): array
    {
        $user_tables = $this->get_user_tables($tables);
        $content_tables = array_values(array_diff($tables, $user_tables));
        if ($user_tables) {
            $content_tables = array_values(array_unique(array_merge($content_tables, [
                $this->site_prefix . 'posts', $this->site_prefix . 'comments', $this->site_prefix . 'links',
            ])));
        }
        return $content_tables;
    }

    /**
     * Users precede profiles even when the caller lists the tables backwards.
     *
     * @param string[] $tables Tables selected for SQL export.
     * @return string[] Selected users and usermeta tables, in that order.
     */
    public function get_user_tables(array $tables): array
    {
        return array_values(array_intersect([$this->base_prefix . 'users', $this->base_prefix . 'usermeta'], $tables));
    }

    public function get_usermeta_table_name(): string
    {
        return $this->base_prefix . 'usermeta';
    }

    /**
     * Collects one LIMIT-sized query before its matching content cursor can leave.
     *
     * @return string Last source primary key, or '0' when the query was empty.
     */
    public function collect_user_references(string $table, string $query): string
    {
        $columns = $this->get_reference_columns($table);
        $result = $this->db->query($query);
        $values = [];
        $last_id = '0';
        $row = $result->fetch(PdoConstants::fetch_assoc());
        while ($row !== false) {
            $last_id = (string) $row[$columns['primary_key']];
            $user_id = ltrim( (string) $row[$columns['user_column']], '0');
            if (ctype_digit($user_id) &&
                ( $columns['kind'] !== 4 || $row['meta_key'] === $this->site_prefix . 'capabilities' )) {
                $values[] = '(' . $user_id . ',' . $columns['kind'] . ',' . $last_id . ')';
            }
            $row = $result->fetch(PdoConstants::fetch_assoc());
        }
        // The result is fully consumed before writing, including with PDO's
        // unbuffered mode. Only IDs from this bounded query are held in PHP.
        $result = null;
        if ($values) {
            $this->db->exec("INSERT INTO `{$this->get_user_table_name()}` (user_id, reference_kind, reference_id) VALUES " . implode(',', $values) . ' ON DUPLICATE KEY UPDATE user_id=user_id');
        }
        return $last_id;
    }

    /**
     * Checks the saved source row by primary key, never by scanning its user column.
     *
     * One saved reference per user bounds storage by distinct users, not comments.
     * If that reference changes, stop rather than scan for another one. Another
     * valid relationship may still exist; a fresh export discovers it again.
     * New relationships behind the discovery cursor need a fresh export too.
     */
    public function get_user_reference_check(string $table): ?string
    {
        if (!$this->is_shared_user_table($table)) {
            $columns = $this->get_reference_columns($table);
            if ($columns === null) {
                return null;
            }
            // Discovery and the content SELECT are separate statements. Reject
            // a newly assigned author absent from the set rather than sending
            // content whose user could never be included by this export.
            $user_expression = "`{$table}`.`{$columns['user_column']}`";
            return "({$user_expression} = 0 OR " . $this->related_user_condition($user_expression) . ')';
        }
        $user_column = $table === $this->base_prefix . 'users' ? 'ID' : 'user_id';
        return "(SELECT CASE saved.reference_kind " .
            "WHEN 1 THEN EXISTS (SELECT 1 FROM `{$this->site_prefix}posts` WHERE ID=saved.reference_id AND post_author=saved.user_id) " .
            "WHEN 2 THEN EXISTS (SELECT 1 FROM `{$this->site_prefix}comments` WHERE comment_ID=saved.reference_id AND user_id=saved.user_id) " .
            "WHEN 3 THEN EXISTS (SELECT 1 FROM `{$this->site_prefix}links` WHERE link_id=saved.reference_id AND link_owner=saved.user_id) " .
            "WHEN 4 THEN EXISTS (SELECT 1 FROM `{$this->base_prefix}usermeta` WHERE umeta_id=saved.reference_id AND user_id=saved.user_id AND meta_key='{$this->site_prefix}capabilities') " .
            "ELSE 0 END FROM `{$this->get_user_table_name()}` saved WHERE saved.user_id=`{$table}`.`{$user_column}` LIMIT 1)";
    }

    /**
     * Whether this table has a defined core selection rule.
     *
     * '1=0' keeps a known table's schema without rows. '0=1' marks a table
     * without a rule for this selection, so the reader skips the whole table.
     * Keep these spellings distinct even though both SQL conditions are false.
     */
    public function includes_table(string $table): bool
    {
        return $this->get_row_condition($table) !== '0=1';
    }

    /** Returns a trusted SQL condition, including schema-only shared tables. */
    public function get_row_condition(string $table): string
    {
        // These exact core tables contain only the selected site's records.
        // A prefix match alone cannot establish what a plugin table contains.
        $site_tables = [
            'posts', 'postmeta', 'comments', 'commentmeta', 'terms',
            'termmeta', 'term_taxonomy', 'term_relationships', 'links',
        ];
        foreach ($site_tables as $suffix) {
            if ($table === $this->site_prefix . $suffix) {
                return '1=1';
            }
        }
        if ($table === $this->site_prefix . 'options') {
            // Remove Reprint's source connection and authorization state.
            // Other plugin settings in this site-specific table still travel;
            // this is not a general filter for plugin secrets.
            return "`option_name` NOT IN ('reprint_server_connection_token', 'reprint_server_push_authorized_token_fingerprint', 'site_export_secret', 'site_export_push_authorized_token_fingerprint')";
        }
        // WordPress calls sites "blogs" here; the singular "site" table holds
        // networks. Filter each shared table by the corresponding kind of ID.
        if ($table === $this->base_prefix . 'blogs' || $table === $this->base_prefix . 'blogmeta') {
            return "`blog_id` = {$this->site_id}";
        }
        if ($table === $this->base_prefix . 'site') {
            return "`id` = {$this->network_id}";
        }
        if ($table === $this->base_prefix . 'sitemeta') {
            /**
             * Network plugin/theme settings describe code available to the
             * site; WPLANG supplies its network language fallback. Retain the
             * upload policy and network name/contact metadata alongside the
             * selected network record. These remain network metadata in the
             * dump, not automatically converted single-site options.
             */
            // Counters, signups, source administrators, and unknown plugin settings
            // describe the old network. The target supplies its own network identity.
            return "`site_id` = {$this->network_id} AND `meta_key` IN (" .
                "'active_sitewide_plugins', 'allowedthemes', 'site_name', 'admin_email', " .
                "'upload_filetypes', 'fileupload_maxk', 'upload_space_check_disabled', " .
                "'blog_upload_space', 'WPLANG')";
        }
        if ($table === $this->base_prefix . 'users') {
            return $this->related_user_condition("`{$table}`.`ID`");
        }
        if ($table === $this->base_prefix . 'usermeta') {
            /**
             * A selected user's metadata can also contain data for other sites.
             * Filtering user IDs alone would copy those roles and credentials.
             * Keep this explicit subset, not every key attached to the user:
             *
             * - first_name, last_name, nickname and description retain the
             *   user's profile and author biography.
             * - rich_editing, syntax_highlighting and comment_shortcuts retain
             *   editor and comment-moderation preferences.
             * - admin_color, show_admin_bar_front, locale and use_ssl retain
             *   the color scheme, toolbar, language and admin HTTPS preference.
             * - The selected prefix's capabilities stores roles and direct
             *   grants/denials; user_level preserves the legacy numeric level.
             *   Keep their keys unchanged because the target adopts that prefix.
             *
             * Other-site roles, session_tokens, _application_passwords and
             * unlisted core/plugin metadata stay out. An unlisted field is not
             * necessarily secret, but adding it requires an export decision.
             * The user-ID condition also excludes unrelated users' profiles.
             *
             * @see https://developer.wordpress.org/reference/functions/wp_insert_user/
             * @see https://developer.wordpress.org/reference/classes/wp_user/for_site/
             * @see https://developer.wordpress.org/reference/classes/wp_user/update_user_level_from_caps/
             */
            $keys = [
                'first_name', 'last_name', 'nickname', 'description', 'rich_editing',
                'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl',
                'show_admin_bar_front', 'locale',
                $this->site_prefix . 'capabilities', $this->site_prefix . 'user_level',
            ];
            return $this->related_user_condition("`{$table}`.`user_id`") .
                " AND `meta_key` IN ('" . implode("', '", $keys) . "')";
        }
        if ($table === $this->base_prefix . 'signups' || $table === $this->base_prefix . 'registration_log') {
            // Pending registrations and registration history describe the
            // network, not this site's content. Retain empty core tables only.
            return '1=0';
        }
        return '0=1';
    }

    /**
     * Selects IDs saved from members and core content references.
     *
     * A capabilities row includes members who have no content. Post, registered
     * comment and link references also retain users who no longer have a role
     * on this site, so existing content keeps its original user IDs. Both users
     * and usermeta use this test; selecting a content author does not create a
     * capabilities row or grant that author a role.
     */
    private function related_user_condition(string $user_expression): string
    {
        // A scalar primary-key lookup cannot rebuild a materialized user set
        // for each batch. WordPress tables and their indexes stay unchanged.
        return "(SELECT saved.user_id FROM `{$this->get_user_table_name()}` saved WHERE saved.user_id={$user_expression} LIMIT 1) IS NOT NULL";
    }
    /**
     * Identifies the small fields needed to collect a user without its profile.
     *
     * @return array|null {
     *     @type string $primary_key Source row's primary key.
     *     @type string $user_column User ID in that row.
     *     @type int    $kind Source table discriminator stored with the ID.
     * }
     */
    public function get_reference_columns(string $table): ?array
    {
        $sources = [
            $this->site_prefix . 'posts' => ['primary_key' => 'ID', 'user_column' => 'post_author', 'kind' => 1],
            $this->site_prefix . 'comments' => ['primary_key' => 'comment_ID', 'user_column' => 'user_id', 'kind' => 2],
            $this->site_prefix . 'links' => ['primary_key' => 'link_id', 'user_column' => 'link_owner', 'kind' => 3],
            $this->base_prefix . 'usermeta' => ['primary_key' => 'umeta_id', 'user_column' => 'user_id', 'kind' => 4],
        ];
        return $sources[$table] ?? null;
    }

    public function is_shared_user_table(string $table): bool
    {
        return $table === $this->base_prefix . 'users' || $table === $this->base_prefix . 'usermeta';
    }
}
