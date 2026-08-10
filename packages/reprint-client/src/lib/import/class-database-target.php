<?php
declare(strict_types=1);

namespace Reprint\Importer;

use Reprint\Importer\State\DatabaseApplyCommandState;

/**
 * Database connection details independent of a command's persisted progress.
 */
final class DatabaseTarget {

    public string $engine;
    public string $database_name;
    public ?string $sqlite_path;
    public ?string $host;
    public ?int $port;
    public ?string $user;
    public ?string $password;

    public function __construct(
        string $engine,
        string $database_name,
        ?string $sqlite_path = null,
        ?string $host = null,
        ?int $port = null,
        ?string $user = null,
        ?string $password = null
    ) {
        $this->engine = $engine;
        $this->database_name = $database_name;
        $this->sqlite_path = $sqlite_path;
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
    }

    public static function from_apply_state(DatabaseApplyCommandState $state): ?self
    {
        if ($state->target_engine === null) {
            return null;
        }

        return new self(
            $state->target_engine,
            $state->target_db ?? '',
            $state->target_sqlite_path,
            $state->target_host,
            $state->target_port,
            $state->target_user,
            $state->target_pass,
        );
    }

    public function with_sqlite_path(string $sqlite_path): self
    {
        return new self(
            $this->engine,
            $this->database_name,
            $sqlite_path,
            $this->host,
            $this->port,
            $this->user,
            $this->password,
        );
    }

    public function store_in_apply_state(DatabaseApplyCommandState $state): void
    {
        $state->target_engine = $this->engine;
        $state->target_db = $this->database_name;
        $state->target_sqlite_path = $this->sqlite_path;
        $state->target_host = $this->host;
        $state->target_port = $this->port;
        $state->target_user = $this->user;
        $state->target_pass = $this->password;
    }
}
