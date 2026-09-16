<?php

namespace Reprint\Importer;

use InvalidArgumentException;
use Reprint\Importer\Database\DatabaseConnection;
use WordPress\DataLiberation\URL\WPURL;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI errors, never HTML.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals written to wp-config.php, not diagnostic output.

/** Turns the selected core records into a single site without renaming tables. */
class MultisiteTarget {

    /**
     * Source fields checked when the preflight response is received.
     * Site 7 in network 1 with base_prefix=network_ uses network_7_posts.
     *
     * @var array
     */
    private $source;
    /** @var string */
    private $target_url;

    /**
     * The importer checks source metadata at preflight and target choices at
     * the command boundary. This object does not parse or validate user input.
     *
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
     * }
     * @param string $target_url Parsed HTTP(S) origin, without a trailing slash.
     */
    public function __construct(array $source, string $target_url)
    {
        $this->source = $source;
        $this->target_url = $target_url;
    }

    /**
     * Map selected pages, media and shared code to the target.
     *
     * The map uses only the selected site's URL fields, never a list of sites.
     * A link to news.network.test does not match shop.network.test. The rewriter
     * separately checks the saved child-site path set before replacing a base.
     * Selecting network.test/ can then keep /news remote when it is another
     * site, without adding a full rewrite rule for every child site.
     */
    public function get_url_mapping(): array
    {
        $source = $this->source;
        // The first mapping supplies the base for relative links.
        $mapping = [rtrim($source['home_url'], '/') => $this->target_url];
        // For a /shop source, /sibling must become an absolute source link;
        // leaving it relative would point at the target host after migration.
        // The more specific /shop rule still moves selected pages. A root
        // source needs no fallback. Adding one could hide its replacement when
        // parsing changes the host spelling (café.test becomes xn--caf-dma.test).
        $source_home_url = WPURL::parse($source['home_url']);
        if ($source_home_url->pathname !== '/') {
            foreach ($this->get_http_url_variants($source_home_url->origin) as $origin) {
                $mapping[$origin] = $origin;
            }
        }
        foreach (array_unique([$source['content_url'], $source['network_content_url']]) as $content_url) {
            foreach ($this->get_http_url_variants($content_url) as $content_url_variant) {
                $mapping[$content_url_variant] = $this->target_url . '/wp-content';
                // Shared code moves, but unselected media below it stays remote.
                // Site 7: keep uploads/, then move only uploads/sites/7 below.
                // Site 1: move uploads/, but keep its nested sites/ directory.
                // These two prefixes cover every site without listing site IDs.
                $preserved_uploads = $content_url_variant . '/uploads' . ( $source['site_id'] === 1 ? '/sites' : '' );
                $mapping[$preserved_uploads] = $preserved_uploads;
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

    /**
     * Idempotent cleanup: SQL import has completed, but WordPress has not booted yet.
     *
     * @param string $site_admin Login chosen at the command boundary. Its existence
     *     can only be checked now, against the users actually imported.
     */
    public function configure_database(DatabaseConnection $database, string $site_admin): void
    {
        $source = $this->source;
        $base_prefix = $source['base_prefix'];
        $site_prefix = $this->get_table_prefix();
        $user = $database->query(
            "SELECT ID FROM `{$base_prefix}users` WHERE user_login = ?",
            [$site_admin]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('The requested site administrator was not imported: ' . $site_admin . '. Choose a member or content author of the selected site.');
        }

        // Capability keys already use the selected table prefix. Add the explicit
        // administrator grant without removing this user's roles or direct caps.
        $row = $database->query("SELECT meta_value FROM `{$base_prefix}usermeta` WHERE user_id = ? AND meta_key = ?", [
            $user['ID'], $site_prefix . 'capabilities',
        ])->fetch(\PDO::FETCH_NUM);
        $capabilities = $row ? @unserialize($row[0], ['allowed_classes' => false]) : [];
        if (!is_array($capabilities)) {
            throw new InvalidArgumentException('The imported site administrator capabilities are not a serialized array: ' . $site_admin . '.');
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
        $alternate_url = strncasecmp($url, 'https://', 8) === 0
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
