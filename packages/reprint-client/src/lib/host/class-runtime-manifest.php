<?php
/**
 * Runtime manifest — the intermediate representation between host analyzers
 * and runtime appliers.
 *
 * A host analyzer reads preflight data from the source site and produces a
 * RuntimeManifest describing what that site needs to run. A runtime applier
 * reads the manifest and writes the configuration files appropriate for the
 * target server.
 *
 * The manifest is pure data — no executable code, no file paths to scripts.
 * It captures:
 *
 * - php_ini:      INI directives the source site had (memory_limit, etc.)
 * - constants:    PHP constants to define before WordPress boots.
 *                 Values may contain {fs-root} resolved at apply-time.
 * - server_vars:  $_SERVER entries to set before WordPress boots.
 *                 Values may contain {fs-root}.
 * - routes:       Declarative request routes the target runtime must
 *                 implement. Each describes a URL path pattern, a handler
 *                 name, and an optional condition (e.g. "file_not_found").
 *                 The target runtime decides how to implement the handler.
 * - sqlite:       When non-null, the target uses SQLite instead of MySQL.
 *                 Contains the plugin source path and database file location.
 */
class RuntimeManifest
{
    /** @var string Source host identifier (e.g. "wpcloud", "siteground") */
    public string $source;

    /** @var array<string, string> PHP INI directives */
    public array $php_ini = [];

    /** @var array<string, string> PHP constants to define (values may use {fs-root}) */
    public array $constants = [];

    /** @var array<string, string> $_SERVER entries (values may use {fs-root}) */
    public array $server_vars = [];

    /**
     * @var array<int, array{handler: string, path_pattern: string, condition?: string, description: string}>
     * Declarative request routes. Each entry describes a URL path pattern,
     * the handler to invoke, and an optional condition under which it fires.
     *
     * The handler name maps 1:1 to an implementation file in
     * target-runtime/route-handlers/ (e.g. "wpcloud-thumbnail-generator"
     * maps to wpcloud-thumbnail-generator.php).
     *
     * Example:
     *   [
     *     'handler' => 'wpcloud-thumbnail-generator',
     *     'path_pattern' => '/wp-content/uploads/.*-\d+x\d+\.\w+$',
     *     'condition' => 'file_not_found',
     *     'description' => 'Generate missing WordPress thumbnails from originals'
     *   ]
     */
    public array $routes = [];

    /**
     * Whether the manifest includes DB_* constants that will collide
     * with definitions in wp-config.php.  When true, the generated
     * runtime.php installs a lightweight error handler that silences
     * the "Constant already defined" warnings that occur when
     * wp-config.php tries to redefine the same constants.
     */
    public bool $has_db_constants = false;

    /**
     * Paths relative to the fs-root that should be removed after
     * flattening because they depend on production infrastructure not
     * available locally.  Examples: Memcached-backed object-cache
     * drop-ins, hosting-specific mu-plugins, hosting-specific plugins.
     *
     * Each entry is a relative path like 'wp-content/object-cache.php'
     * or 'wp-content/mu-plugins/wpcomsh'.  Directories are removed
     * recursively.  The audit log records every removal.
     *
     * Entries under 'wp-content/plugins/' also trigger automatic
     * deactivation: any active plugin whose basename starts with the
     * removed directory name is stripped from the active_plugins option
     * in the target database, preventing "plugin file does not exist"
     * warnings in wp-admin.
     *
     * @var string[]
     */
    public array $paths_to_remove = [];

    /**
     * Directories outside the WordPress root that must be mounted into
     * the virtual filesystem at their original absolute paths.  Each
     * entry maps an absolute remote path (e.g. '/scripts') to the
     * corresponding host-side directory under fs-root/raw.
     *
     * Populated from auto_prepend_file / auto_append_file INI values
     * during host analysis.  The target runtime applier decides how to
     * surface these (e.g. --mount-before-install in Playground CLI).
     *
     * @var string[]  Absolute remote directory paths (e.g. ['/scripts'])
     */
    public array $extra_directories = [];

    /**
     * SQLite database configuration.  When non-null, the target uses
     * SQLite instead of MySQL.  The runtime layer copies the plugin
     * into the output directory and generates a lazy-loading $wpdb
     * proxy in runtime.php — no files are placed in the fs-root.
     *
     * Keys:
     *   'plugin_source'  string  Absolute path to the sqlite-database-
     *                            integration source directory (e.g. lib/).
     *   'plugin_dir'     string  Absolute path to the copied plugin in
     *                            the output directory (set after copying).
     *   'db_dir'         string  Directory containing the .sqlite file.
     *   'db_file'        string  SQLite database file name.
     *
     * @var array{plugin_source: string, plugin_dir: string, db_dir: string, db_file: string}|null
     */
    public ?array $sqlite = null;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

}
