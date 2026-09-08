<?php

namespace WordPress\Reprint\Server;

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
     * selection or value-replacement rules change, not for an equivalent query
     * plan. Source rows may change without changing this version.
     * This identifies the rules, not a snapshot of the mutable source records.
     */
    public function get_identity(): string
    {
        return 'core-v1:' . $this->base_prefix . ':' . $this->network_id . ':' . $this->site_id;
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
     * Selects members and core content references without collecting user IDs in PHP.
     *
     * A capabilities row includes members who have no content. Post, registered
     * comment and link references also retain users who no longer have a role
     * on this site, so existing content keeps its original user IDs. Both users
     * and usermeta use this test; selecting a content author does not create a
     * capabilities row or grant that author a role.
     */
    private function related_user_condition(string $user_expression): string
    {
        // Core does not index comments.user_id or links.link_owner. Build the
        // ID set inside MySQL instead of scanning those tables for each network
        // user or profile row. The derived UNION prevents MySQL from pushing
        // the outer user ID back into correlated, unindexed scans. Each query
        // rebuilds the set, so resumed and oversized reads still check current
        // membership without keeping user IDs in PHP or changing source tables.
        return "{$user_expression} IN (SELECT user_id FROM (" .
            "SELECT user_id FROM `{$this->base_prefix}usermeta` " .
                "WHERE meta_key = '{$this->site_prefix}capabilities' " .
            "UNION SELECT post_author FROM `{$this->site_prefix}posts` " .
            "UNION SELECT user_id FROM `{$this->site_prefix}comments` " .
            "UNION SELECT link_owner FROM `{$this->site_prefix}links`" .
        ') AS related_users)';
    }
}
