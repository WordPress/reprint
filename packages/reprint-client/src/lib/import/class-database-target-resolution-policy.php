<?php
declare(strict_types=1);

namespace Reprint\Importer;

/**
 * Command-level rules for resolving a database target.
 */
final class DatabaseTargetResolutionPolicy {

    public ?string $default_engine;
    public bool $use_recorded_target_without_explicit_engine;
    public bool $use_matching_recorded_target_with_explicit_engine;
    public bool $require_explicit_engine_for_target_options;
    public bool $require_mysql_credentials_always;
    public bool $require_mysql_credentials_with_explicit_engine;
    public bool $require_sqlite_directory_with_explicit_engine;
    public string $mysql_credentials_error_style;

    public function __construct(
        ?string $default_engine,
        bool $use_recorded_target_without_explicit_engine,
        bool $use_matching_recorded_target_with_explicit_engine,
        bool $require_explicit_engine_for_target_options,
        bool $require_mysql_credentials_always,
        bool $require_mysql_credentials_with_explicit_engine,
        bool $require_sqlite_directory_with_explicit_engine,
        string $mysql_credentials_error_style
    ) {
        $this->default_engine = $default_engine;
        $this->use_recorded_target_without_explicit_engine = $use_recorded_target_without_explicit_engine;
        $this->use_matching_recorded_target_with_explicit_engine = $use_matching_recorded_target_with_explicit_engine;
        $this->require_explicit_engine_for_target_options = $require_explicit_engine_for_target_options;
        $this->require_mysql_credentials_always = $require_mysql_credentials_always;
        $this->require_mysql_credentials_with_explicit_engine = $require_mysql_credentials_with_explicit_engine;
        $this->require_sqlite_directory_with_explicit_engine = $require_sqlite_directory_with_explicit_engine;
        $this->mysql_credentials_error_style = $mysql_credentials_error_style;
    }

    public static function for_pull(): self
    {
        return new self('mysql', false, false, false, true, false, false, 'pull');
    }

    public static function for_direct_database_apply(): self
    {
        return new self('mysql', false, false, false, true, false, false, 'db-apply');
    }

    public static function for_runtime(): self
    {
        return new self(null, true, true, true, false, true, true, 'apply-runtime');
    }
}
