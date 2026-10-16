<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

/**
 * db-apply state, including target connection fields retained so
 * apply-runtime can generate DB_* constants. The password stays in memory
 * for the current invocation and is not part of the checkpoint.
 */
class DatabaseApplyCommandState {

    /** @var string|null Explicit imported user selected to administer a single site. */
    public ?string $site_admin = null;

    /** @var int SQL statements successfully executed. */
    public int $statements_executed = 0;

    /** @var int Bytes read from db.sql. */
    public int $bytes_read = 0;

    /** @var array<string,string>|null URL rewrite map selected for db-apply. */
    public ?array $rewrite_url = null;

    /**
     * Immutable child-site path file selected when apply starts. A separate
     * preflight may find new sites, but must not change an unfinished SQL apply.
     *
     * @var string|null
     */
    public ?string $nested_site_paths_file = null;

    /** @var string|null Runtime target database engine: mysql or sqlite. */
    public ?string $target_engine = null;

    /** @var string|null Runtime database name. */
    public ?string $target_db = null;

    /** @var string|null Runtime database host. */
    public ?string $target_host = null;

    /** @var int|null Runtime database port. */
    public ?int $target_port = null;

    /** @var string|null Runtime database user. */
    public ?string $target_user = null;

    /** @var string|null Current invocation's runtime database password; not saved to disk. */
    public ?string $target_pass = null;

    /** @var string|null Runtime SQLite database path. */
    public ?string $target_sqlite_path = null;

    /**
     * @var string[] Document-root-relative paths selected for local runtime removal.
     * Saved before removal and retained across command resets. Diff and push
     * exclude these paths, including when setup stopped partway through cleanup.
     */
    public array $remote_paths_removed_from_local_site = [];

    /**
     * Load checkpoint fields without reusing a password from an earlier schema.
     *
     * @param array $data {
     *     Saved db-apply fields; older schemas may also contain target_pass, which is ignored.
     *     @type string|null                    $site_admin                           Explicit imported administrator.
     *     @type int                            $statements_executed                  Completed SQL statements.
     *     @type int                            $bytes_read                           Completed SQL bytes.
     *     @type array<string,string>|null      $rewrite_url                          Selected URL rewrite map.
     *     @type string|null                    $nested_site_paths_file               Selected child-site path file.
     *     @type string|null                    $target_engine                        Runtime database engine.
     *     @type string|null                    $target_db                            Runtime database name.
     *     @type string|null                    $target_host                          Runtime MySQL host.
     *     @type int|null                       $target_port                          Runtime MySQL port.
     *     @type string|null                    $target_user                          Runtime MySQL user.
     *     @type string|null                    $target_sqlite_path                   Runtime SQLite path.
     *     @type string[]                       $remote_paths_removed_from_local_site Local cleanup paths excluded from diff and push.
     * }
     * @return self State with no current-invocation password.
     */
    public static function from_array(array $data): self
    {
        $state = new self();
        $data += ['site_admin' => null, 'nested_site_paths_file' => null];
        unset($data['target_pass']);
        $state->site_admin = $data['site_admin'];
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->statements_executed = $data['statements_executed'];
        $state->bytes_read = $data['bytes_read'];
        $state->rewrite_url = $data['rewrite_url'];
        $state->nested_site_paths_file = $data['nested_site_paths_file'];
        $state->target_engine = $data['target_engine'];
        $state->target_db = $data['target_db'];
        $state->target_host = $data['target_host'];
        $state->target_port = $data['target_port'];
        $state->target_user = $data['target_user'];
        $state->target_sqlite_path = $data['target_sqlite_path'];
        $state->remote_paths_removed_from_local_site = array_values($data['remote_paths_removed_from_local_site']);
        return $state;
    }

    /**
     * Serialize durable db-apply fields, leaving the current password in memory.
     *
     * @return array {
     *     Saved db-apply fields.
     *     @type string|null                    $site_admin                           Explicit imported administrator.
     *     @type int                            $statements_executed                  Completed SQL statements.
     *     @type int                            $bytes_read                           Completed SQL bytes.
     *     @type array<string,string>|null      $rewrite_url                          Selected URL rewrite map.
     *     @type string|null                    $nested_site_paths_file               Selected child-site path file.
     *     @type string|null                    $target_engine                        Runtime database engine.
     *     @type string|null                    $target_db                            Runtime database name.
     *     @type string|null                    $target_host                          Runtime MySQL host.
     *     @type int|null                       $target_port                          Runtime MySQL port.
     *     @type string|null                    $target_user                          Runtime MySQL user.
     *     @type string|null                    $target_sqlite_path                   Runtime SQLite path.
     *     @type string[]                       $remote_paths_removed_from_local_site Local cleanup paths excluded from diff and push.
     * }
     */
    public function to_array(): array
    {
        return [
            'site_admin' => $this->site_admin,
            'statements_executed' => $this->statements_executed,
            'bytes_read' => $this->bytes_read,
            'rewrite_url' => $this->rewrite_url,
            'nested_site_paths_file' => $this->nested_site_paths_file,
            'target_engine' => $this->target_engine,
            'target_db' => $this->target_db,
            'target_host' => $this->target_host,
            'target_port' => $this->target_port,
            'target_user' => $this->target_user,
            'target_sqlite_path' => $this->target_sqlite_path,
            'remote_paths_removed_from_local_site' => $this->remote_paths_removed_from_local_site,
        ];
    }
}
