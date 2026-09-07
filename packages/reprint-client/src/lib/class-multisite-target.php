<?php

namespace Reprint\Importer;

use InvalidArgumentException;
use Reprint\Importer\Database\DatabaseConnection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI errors, never HTML.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals written to wp-config.php, not diagnostic output.

/** Turns the selected core records into a single site without renaming tables. */
class MultisiteTarget {

    /** @var array Trusted source metadata captured during preflight. */
    private $source;
    /** @var string */
    private $target_url;
    /** @var string */
    private $site_admin;

    /**
     * @param array $source {
     *     Selected source site.
     *
     *     @type int    $site_id Selected site ID.
     *     @type int    $network_id Source network ID used to read its settings.
     *     @type string $base_prefix Network table prefix.
     *     @type string $home_url Selected home URL.
     *     @type string $site_url Selected WordPress URL.
     *     @type string $content_url Shared content URL.
     *     @type string $uploads_url Selected media URL.
     *     @type string $network_content_url Shared network content URL.
     *     @type int[] $sibling_site_ids Other site IDs whose media stays remote.
     *     @type string[] $sibling_urls Other site URLs which must remain remote.
     * }
     */
    public function __construct(array $source, string $target_url, string $site_admin)
    {
        if (!isset($source['base_prefix'], $source['site_id'], $source['network_id'])
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $source['base_prefix'])
            || !is_int($source['site_id']) || $source['site_id'] < 1
            || !is_int($source['network_id']) || $source['network_id'] < 1) {
            throw new InvalidArgumentException('The source did not provide valid multisite IDs and a base table prefix.');
        }
        $url = parse_url($target_url);
        if (!$url || !isset($url['host'], $url['scheme']) || !in_array($url['scheme'], ['http', 'https'], true)
            || ( isset($url['user']) || isset($url['pass']) ) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('A multisite pull requires --new-site-url with an HTTP(S) host and no path, query, or fragment.');
        }
        if (filter_var(trim($url['host'], '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Use a DNS host name such as localhost for --new-site-url; plain-text URL rewriting does not support numeric target hosts.');
        }
        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $url['host'])) {
            throw new InvalidArgumentException('Use an ASCII DNS host name without a trailing dot for --new-site-url; convert international names to their xn-- form. Received host: ' . $url['host'] . '.');
        }
        if ($site_admin === '') {
            throw new InvalidArgumentException('A multisite pull requires --site-admin=LOGIN naming an imported user who will administer the new site.');
        }
        $this->source = $source;
        $this->target_url = rtrim($target_url, '/');
        $this->site_admin = $site_admin;
    }

    /** Map selected content and shared assets, not the old network's entire origin. */
    public function get_url_mapping(): array
    {
        $source = $this->source;
        // The first mapping supplies the base for relative links.
        $mapping = [rtrim($source['home_url'], '/') => $this->target_url];
        foreach ($source['sibling_urls'] ?? [] as $url) {
            foreach ($this->get_http_url_variants($url) as $sibling_url) {
                $mapping[$sibling_url] = $sibling_url;
            }
        }
        foreach (array_unique([$source['content_url'], $source['network_content_url'] ?? $source['content_url']]) as $content_url) {
            foreach ($this->get_http_url_variants($content_url) as $content_url_variant) {
                $mapping[$content_url_variant] = $this->target_url . '/wp-content';
                // Shared code moves, but unselected media under that same content
                // URL stays remote. The selected media root is the more specific rule.
                $mapping[$content_url_variant . '/uploads'] = $content_url_variant . '/uploads';
                foreach ($source['sibling_site_ids'] ?? [] as $site_id) {
                    if ($site_id !== 1) {
                        $sibling_uploads = $content_url_variant . '/uploads/sites/' . $site_id;
                        $mapping[$sibling_uploads] = $sibling_uploads;
                    }
                }
                $selected_uploads = $content_url_variant . '/uploads' . ( $source['site_id'] === 1 ? '' : '/sites/' . $source['site_id'] );
                $mapping[$selected_uploads] = $this->target_url . '/' . $this->get_upload_path();
            }
        }
        foreach ([
            $source['uploads_url'] => $this->target_url . '/' . $this->get_upload_path(),
            $source['site_url'] => $this->target_url,
            $source['home_url'] => $this->target_url,
        ] as $source_url => $target_url) {
            foreach ($this->get_http_url_variants($source_url) as $source_url_variant) {
                $mapping[$source_url_variant] = $target_url;
            }
        }
        return $mapping;
    }

    /** The target listener must use this URL, not a preserved sibling URL. */
    public function get_site_url(): string
    {
        return $this->target_url;
    }

    /**
     * Reject existing target tables before any imported DROP TABLE can run.
     *
     * @param string|null $empty_progress_table Reprint's empty progress table,
     *     allowed only when resuming before any SQL group was recorded.
     */
    public function assert_empty_database(DatabaseConnection $database, ?string $empty_progress_table = null): void
    {
        $tables = $database->query('SHOW TABLES');
        $row = $tables->fetch(\PDO::FETCH_NUM);
        if ($row !== false && $row[0] === $empty_progress_table) {
            $quoted_table = '`' . str_replace('`', '``', $row[0]) . '`';
            if ($database->query("SELECT 1 FROM {$quoted_table} LIMIT 1")->fetchColumn() === false) {
                $row = $tables->fetch(\PDO::FETCH_NUM);
            }
        }
        if ($row !== false) {
            throw new InvalidArgumentException('A selected multisite pull requires an empty target database; found table ' . $row[0] . '.');
        }
    }

    /** Idempotent cleanup: SQL import has completed, but WordPress has not booted yet. */
    public function configure_database(DatabaseConnection $database): void
    {
        $source = $this->source;
        $base_prefix = $source['base_prefix'];
        $site_prefix = $this->get_table_prefix();
        $user = $database->query(
            "SELECT ID FROM `{$base_prefix}users` WHERE user_login = ?",
            [$this->site_admin]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('The requested site administrator was not imported: ' . $this->site_admin . '. Choose a member or content author of the selected site.');
        }

        // Capability keys already use the selected table prefix. Add the explicit
        // administrator grant without removing this user's roles or direct caps.
        $row = $database->query("SELECT meta_value FROM `{$base_prefix}usermeta` WHERE user_id = ? AND meta_key = ?", [
            $user['ID'], $site_prefix . 'capabilities',
        ])->fetch(\PDO::FETCH_NUM);
        $capabilities = $row ? @unserialize($row[0], ['allowed_classes' => false]) : [];
        if (!is_array($capabilities)) {
            throw new InvalidArgumentException('The imported site administrator capabilities are not a serialized array: ' . $this->site_admin . '.');
        }
        $capabilities['administrator'] = true;
        foreach (['capabilities' => serialize($capabilities), 'user_level' => '10'] as $suffix => $value) {
            $key = $site_prefix . $suffix;
            $exists = $database->query("SELECT 1 FROM `{$base_prefix}usermeta` WHERE user_id = ? AND meta_key = ?", [$user['ID'], $key])->fetchColumn();
            if ($exists !== false) {
                $database->execute("UPDATE `{$base_prefix}usermeta` SET meta_value = ? WHERE user_id = ? AND meta_key = ?", [$value, $user['ID'], $key]);
            } else {
                $database->execute("INSERT INTO `{$base_prefix}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$user['ID'], $key, $value]);
            }
        }
        foreach ([
            'home' => $this->target_url,
            'siteurl' => $this->target_url,
            // Single-site defaults otherwise drop the source /sites/7 media suffix.
            'upload_path' => $this->get_upload_path(),
            'upload_url_path' => $this->target_url . '/' . $this->get_upload_path(),
        ] as $name => $value) {
            $database->execute("DELETE FROM `{$site_prefix}options` WHERE option_name = ?", [$name]);
            $database->execute("INSERT INTO `{$site_prefix}options` (option_name, option_value, autoload) VALUES (?, ?, 'yes')", [$name, $value]);
        }
        // get_locale() falls back to the network only when the site has no
        // WPLANG row. An existing empty string is an explicit English choice.
        $database->execute("INSERT INTO `{$site_prefix}options` (option_name, option_value, autoload)
            SELECT 'WPLANG', meta_value, 'yes' FROM `{$base_prefix}sitemeta`
            WHERE site_id = ? AND meta_key = 'WPLANG' LIMIT 1
            ON DUPLICATE KEY UPDATE option_name = VALUES(option_name)", [$source['network_id']]);
        // The Reprint plugin and its credentials never enter the selected file
        // tree. Merge network activation keys and ordinary activation values into
        // the single-site list, excluding Reprint from both. Keep source network
        // rows available so cleanup can be replayed after process death.
        $active_plugins = [];
        foreach ([
            ["{$base_prefix}sitemeta", 'meta_value', "site_id = ? AND meta_key = 'active_sitewide_plugins'", [$source['network_id']], true],
            ["{$site_prefix}options", 'option_value', "option_name = 'active_plugins'", [], false],
        ] as [$table, $column, $where, $params, $network]) {
            $row = $database->query("SELECT `{$column}` FROM `{$table}` WHERE {$where}", $params)->fetch(\PDO::FETCH_NUM);
            $plugins = $row ? @unserialize($row[0], ['allowed_classes' => false]) : [];
            if (!is_array($plugins)) {
                throw new InvalidArgumentException('The imported plugin activation list is not a serialized array: ' . $table . '.');
            }
            $plugins = $network ? array_keys($plugins) : array_values($plugins);
            // WordPress sorts network plugins and loads them before site plugins.
            if ($network) {
                sort($plugins);
            }
            foreach ($plugins as $basename) {
                if (is_string($basename) && !in_array(strtok($basename, '/'), ['reprint-server', 'reprint-exporter', 'reprint-server-wp'], true)) {
                    $active_plugins[] = $basename;
                }
            }
        }
        $active_plugins = array_values(array_unique($active_plugins));
        // Replace the list atomically: deleting it first loses site-only plugins
        // if the process stops before the insert and cleanup runs again.
        $database->execute("INSERT INTO `{$site_prefix}options` (option_name, option_value, autoload) VALUES ('active_plugins', ?, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", [serialize($active_plugins)]);
        if ($database->inTransaction()) {
            $database->commit();
        }
    }

    /**
     * Build a standalone target configuration with new login salts.
     *
     * @param array $target {
     *     MySQL target connection.
     *
     *     @type string $db Database name.
     *     @type string $user Database user.
     *     @type string $pass Database password.
     *     @type string $host Database host.
     *     @type int $port Database port.
     * }
     */
    public function get_wp_config(array $target): string
    {
        $constants = [
            'DB_NAME' => $target['db'], 'DB_USER' => $target['user'], 'DB_PASSWORD' => $target['pass'],
            'DB_HOST' => $target['host'] . ( $target['port'] === 3306 ? '' : ':' . $target['port'] ),
            'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
            // User tables use the source network prefix; keep their names while
            // ordinary tables and capability keys use the selected site's prefix.
            'CUSTOM_USER_TABLE' => $this->source['base_prefix'] . 'users',
            'CUSTOM_USER_META_TABLE' => $this->source['base_prefix'] . 'usermeta',
        ];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $constants[$name] = bin2hex(random_bytes(32));
        }
        $php = "<?php\n// One selected site, retaining its original table names.\n";
        foreach ($constants as $name => $value) {
            $php .= "if (!defined(" . var_export($name, true) . ")) { define(" . var_export($name, true) . ", " . var_export($value, true) . "); }\n";
        }
        return $php . '$table_prefix = ' . var_export($this->get_table_prefix(), true) . ";\n"
            . "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }

    /** Match WordPress's source table names and its saved role and capability keys. */
    private function get_table_prefix(): string
    {
        return $this->source['base_prefix'] . ( $this->source['site_id'] === 1 ? '' : $this->source['site_id'] . '_' );
    }

    /**
     * Stored content may use either scheme after a source switches to HTTPS.
     *
     * @return string[] Both schemes with the source's scheme first.
     */
    private function get_http_url_variants(string $url): array
    {
        $url = rtrim($url, '/');
        $alternate_url = strpos($url, 'https://') === 0
            ? 'http://' . substr($url, 8)
            : 'https://' . substr($url, 7);
        return [$url, $alternate_url];
    }

    /** Keep the source media layout after leaving the network. */
    private function get_upload_path(): string
    {
        return 'wp-content/uploads' . ( $this->source['site_id'] === 1 ? '' : '/sites/' . $this->source['site_id'] );
    }
}
