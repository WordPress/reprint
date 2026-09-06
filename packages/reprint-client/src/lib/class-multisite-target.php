<?php

namespace Reprint\Importer;

use InvalidArgumentException;
use Reprint\Importer\Database\DatabaseConnection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI errors, never HTML.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP literals written to wp-config.php, not diagnostic output.

/** Turns the selected core records into a fresh network without renumbering the site. */
class MultisiteTarget {

    /** @var array Trusted source metadata captured during preflight. */
    private $source;
    /** @var string */
    private $target_url;
    /** @var string */
    private $network_admin;
    /** @var string */
    private $domain;

    /**
     * @param array $source {
     *     Selected source site.
     *
     *     @type int    $site_id Selected site ID.
     *     @type int    $network_id Source network ID retained at the target.
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
    public function __construct(array $source, string $target_url, string $network_admin)
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
        if ($network_admin === '') {
            throw new InvalidArgumentException('A multisite pull requires --network-admin=LOGIN naming an imported user who will administer the new network.');
        }
        $this->source = $source;
        $this->target_url = rtrim($target_url, '/');
        $this->network_admin = $network_admin;
        $this->domain = strtolower($url['host']);
        $default_port = $url['scheme'] === 'https' ? 443 : 80;
        if (isset($url['port']) && $url['port'] !== $default_port) {
            $this->domain .= ':' . $url['port'];
        }
    }

    /** Map selected content and shared assets, not the old network's entire origin. */
    public function get_url_mapping(): array
    {
        $source = $this->source;
        $mapping = [rtrim($source['home_url'], '/') => $this->target_url];
        foreach ($source['sibling_urls'] ?? [] as $url) {
            $url = rtrim($url, '/');
            $mapping[$url] = $url;
        }
        foreach (array_unique([$source['content_url'], $source['network_content_url'] ?? $source['content_url']]) as $content_url) {
            $content_url = rtrim($content_url, '/');
            $mapping[$content_url] = $this->target_url . '/wp-content';
            // Shared code moves, but unselected media under that same content
            // URL stays remote. The selected media root is the more specific rule.
            $mapping[$content_url . '/uploads'] = $content_url . '/uploads';
            foreach ($source['sibling_site_ids'] ?? [] as $site_id) {
                if ($site_id !== 1) {
                    $sibling_uploads = $content_url . '/uploads/sites/' . $site_id;
                    $mapping[$sibling_uploads] = $sibling_uploads;
                }
            }
            $selected_uploads = $content_url . '/uploads' . ( $source['site_id'] === 1 ? '' : '/sites/' . $source['site_id'] );
            $mapping[$selected_uploads] = $this->target_url . '/' . $this->get_upload_path();
        }
        $mapping[rtrim($source['uploads_url'], '/')] = $this->target_url . '/' . $this->get_upload_path();
        $mapping[rtrim($source['site_url'], '/')] = $this->target_url;
        $mapping[rtrim($source['home_url'], '/')] = $this->target_url;
        return $mapping;
    }

    /** The target listener must use this URL, not a preserved sibling URL. */
    public function get_site_url(): string
    {
        return $this->target_url;
    }

    /** Reject existing target tables before any imported DROP TABLE can run. */
    public function assert_empty_database(DatabaseConnection $database): void
    {
        $row = $database->query('SHOW TABLES')->fetch(\PDO::FETCH_NUM);
        if ($row !== false) {
            throw new InvalidArgumentException('A selected multisite pull requires an empty target database; found table ' . $row[0] . '.');
        }
    }

    /** Idempotent cleanup: SQL import has completed, but WordPress has not booted yet. */
    public function configure_database(DatabaseConnection $database): void
    {
        $source = $this->source;
        $base_prefix = $source['base_prefix'];
        $site_prefix = $base_prefix . ( $source['site_id'] === 1 ? '' : $source['site_id'] . '_' );
        $user = $database->query(
            "SELECT ID FROM `{$base_prefix}users` WHERE user_login = ?",
            [$this->network_admin]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('The requested network administrator was not imported: ' . $this->network_admin . '. Choose a member or content author of the selected site.');
        }

        $database->execute("UPDATE `{$base_prefix}site` SET domain = ?, path = '/' WHERE id = ?", [$this->domain, $source['network_id']]);
        $database->execute("UPDATE `{$base_prefix}blogs` SET domain = ?, path = '/', site_id = ? WHERE blog_id = ?", [
            $this->domain, $source['network_id'], $source['site_id'],
        ]);
        foreach ([
            'home' => $this->target_url,
            'siteurl' => $this->target_url,
            // A promoted main site otherwise drops the /sites/7 media suffix.
            'upload_path' => $this->get_upload_path(),
            'upload_url_path' => $this->target_url . '/' . $this->get_upload_path(),
        ] as $name => $value) {
            $database->execute("DELETE FROM `{$site_prefix}options` WHERE option_name = ?", [$name]);
            $database->execute("INSERT INTO `{$site_prefix}options` (option_name, option_value, autoload) VALUES (?, ?, 'yes')", [$name, $value]);
        }
        foreach ([
            'siteurl' => $this->target_url . '/',
            'main_site' => (string) $source['site_id'],
            'blog_count' => '1',
            'registration' => 'none',
            'ms_files_rewriting' => '0',
            'site_admins' => serialize([$this->network_admin]),
        ] as $name => $value) {
            $database->execute("DELETE FROM `{$base_prefix}sitemeta` WHERE site_id = ? AND meta_key = ?", [$source['network_id'], $name]);
            $database->execute("INSERT INTO `{$base_prefix}sitemeta` (site_id, meta_key, meta_value) VALUES (?, ?, ?)", [$source['network_id'], $name, $value]);
        }

        // The Reprint plugin and its credentials never enter the selected file
        // tree. Remove both network activation keys and ordinary activation values.
        foreach ([
            ["{$base_prefix}sitemeta", 'meta_value', "site_id = ? AND meta_key = 'active_sitewide_plugins'", [$source['network_id']], true],
            ["{$site_prefix}options", 'option_value', "option_name = 'active_plugins'", [], false],
        ] as [$table, $column, $where, $params, $network]) {
            $row = $database->query("SELECT `{$column}` FROM `{$table}` WHERE {$where}", $params)->fetch(\PDO::FETCH_NUM);
            $plugins = $row ? @unserialize($row[0], ['allowed_classes' => false]) : [];
            if (!is_array($plugins)) {
                throw new InvalidArgumentException('The imported plugin activation list is not a serialized array: ' . $table . '.');
            }
            foreach ($plugins as $key => $value) {
                $basename = $network ? $key : $value;
                if (is_string($basename) && in_array(strtok($basename, '/'), ['reprint-server', 'reprint-exporter', 'reprint-server-wp'], true)) {
                    unset($plugins[$key]);
                }
            }
            $database->execute("UPDATE `{$table}` SET `{$column}` = ? WHERE {$where}", array_merge([
                serialize($network ? $plugins : array_values($plugins)),
            ], $params));
        }
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
            'MULTISITE' => true, 'SUBDOMAIN_INSTALL' => false,
            'DOMAIN_CURRENT_SITE' => $this->domain, 'PATH_CURRENT_SITE' => '/',
            'SITE_ID_CURRENT_SITE' => $this->source['network_id'],
            'BLOG_ID_CURRENT_SITE' => $this->source['site_id'],
        ];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $constants[$name] = bin2hex(random_bytes(32));
        }
        $php = "<?php\n// One selected site, retaining its original site ID and table names.\n";
        foreach ($constants as $name => $value) {
            $php .= "if (!defined(" . var_export($name, true) . ")) { define(" . var_export($name, true) . ", " . var_export($value, true) . "); }\n";
        }
        return $php . '$table_prefix = ' . var_export($this->source['base_prefix'], true) . ";\n"
            . "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }

    /** Keep the source media layout even though the selected site becomes main. */
    private function get_upload_path(): string
    {
        return 'wp-content/uploads' . ( $this->source['site_id'] === 1 ? '' : '/sites/' . $this->source['site_id'] );
    }
}
