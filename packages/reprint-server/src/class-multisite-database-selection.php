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
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $base_prefix) || $site_id < 1 || $network_id < 1) {
            throw new \InvalidArgumentException(
                "A multisite selection requires a WordPress table prefix and positive site and network IDs."
            );
        }
        $this->base_prefix = $base_prefix;
        $this->site_prefix = $base_prefix . ( $site_id === 1 ? '' : $site_id . '_' );
        $this->site_id = $site_id;
        $this->network_id = $network_id;
    }

    /** Binds a database cursor to the same source site and selection rules. */
    public function get_identity(): string
    {
        return 'core-v1:' . $this->base_prefix . ':' . $this->network_id . ':' . $this->site_id;
    }

    /** Whether this table has a defined core selection rule. */
    public function includes_table(string $table): bool
    {
        return $this->get_row_condition($table) !== '0=1';
    }

    /** Returns a trusted SQL condition, including schema-only shared tables. */
    public function get_row_condition(string $table): string
    {
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
            return "`option_name` NOT IN ('reprint_server_connection_token', 'reprint_server_push_authorized_token_fingerprint', 'site_export_secret', 'site_export_push_authorized_token_fingerprint')";
        }
        if ($table === $this->base_prefix . 'blogs' || $table === $this->base_prefix . 'blogmeta') {
            return "`blog_id` = {$this->site_id}";
        }
        if ($table === $this->base_prefix . 'site') {
            return "`id` = {$this->network_id}";
        }
        if ($table === $this->base_prefix . 'sitemeta') {
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
            return '1=0';
        }
        return '0=1';
    }

    /** Selects members and core content references without collecting user IDs in PHP. */
    private function related_user_condition(string $user_expression): string
    {
        return '(' .
            "EXISTS (SELECT 1 FROM `{$this->base_prefix}usermeta` AS membership " .
                "WHERE membership.user_id = {$user_expression} " .
                "AND membership.meta_key = '{$this->site_prefix}capabilities') OR " .
            "EXISTS (SELECT 1 FROM `{$this->site_prefix}posts` AS authored_post " .
                "WHERE authored_post.post_author = {$user_expression}) OR " .
            "EXISTS (SELECT 1 FROM `{$this->site_prefix}comments` AS authored_comment " .
                "WHERE authored_comment.user_id = {$user_expression}) OR " .
            "EXISTS (SELECT 1 FROM `{$this->site_prefix}links` AS authored_link " .
                "WHERE authored_link.link_owner = {$user_expression})" .
        ')';
    }
}
