<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

/**
 * db-apply state, including target database configuration retained so
 * apply-runtime can generate DB_* constants.
 */
class DatabaseApplyCommandState {

    /** @var int SQL statements successfully executed. */
    public int $statements_executed = 0;

    /** @var int Bytes read from db.sql. */
    public int $bytes_read = 0;

    /** @var array<string,string>|null URL rewrite map selected for db-apply. */
    public ?array $rewrite_url = null;

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

    /** @var string|null Runtime database password. */
    public ?string $target_pass = null;

    /** @var string|null Runtime SQLite database path. */
    public ?string $target_sqlite_path = null;

    /** @var string[] Remote paths intentionally removed while applying runtime state. */
    public array $remote_paths_removed_from_local_site = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->statements_executed = $data['statements_executed'];
        $state->bytes_read = $data['bytes_read'];
        $state->rewrite_url = $data['rewrite_url'];
        $state->target_engine = $data['target_engine'];
        $state->target_db = $data['target_db'];
        $state->target_host = $data['target_host'];
        $state->target_port = $data['target_port'];
        $state->target_user = $data['target_user'];
        $state->target_pass = $data['target_pass'];
        $state->target_sqlite_path = $data['target_sqlite_path'];
        $state->remote_paths_removed_from_local_site = array_values($data['remote_paths_removed_from_local_site']);
        return $state;
    }

    public function to_array(): array
    {
        return [
            'statements_executed' => $this->statements_executed,
            'bytes_read' => $this->bytes_read,
            'rewrite_url' => $this->rewrite_url,
            'target_engine' => $this->target_engine,
            'target_db' => $this->target_db,
            'target_host' => $this->target_host,
            'target_port' => $this->target_port,
            'target_user' => $this->target_user,
            'target_pass' => $this->target_pass,
            'target_sqlite_path' => $this->target_sqlite_path,
            'remote_paths_removed_from_local_site' => $this->remote_paths_removed_from_local_site,
        ];
    }
}
