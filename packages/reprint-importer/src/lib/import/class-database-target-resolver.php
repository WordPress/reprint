<?php
declare(strict_types=1);

namespace Reprint\Importer;

use InvalidArgumentException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions contain CLI option values and filesystem paths, never HTML output.

/**
 * Resolves database target options against optional saved db-apply state.
 */
final class DatabaseTargetResolver {

    /** @var array<string,string> */
    private const TARGET_OPTION_FLAGS = [
        'target_db' => '--target-db',
        'target_sqlite_path' => '--target-sqlite-path',
        'target_host' => '--target-host',
        'target_port' => '--target-port',
        'target_user' => '--target-user',
        'target_pass' => '--target-pass',
    ];

    /**
     * @param array<string,mixed> $options
     */
    public static function resolve(
        array $options,
        ?DatabaseTarget $recorded_target,
        DatabaseTargetResolutionPolicy $policy
    ): ?DatabaseTarget {
        $stated_engine = $options['target_engine'] ?? null;
        $engine_was_stated = $stated_engine !== null && $stated_engine !== '';

        if ($engine_was_stated) {
            $engine = normalize_database_target_engine( (string) $stated_engine);
            if (
                !$policy->use_matching_recorded_target_with_explicit_engine ||
                $recorded_target === null ||
                $recorded_target->engine !== $engine
            ) {
                $recorded_target = null;
            }
        } else {
            if ($policy->require_explicit_engine_for_target_options) {
                self::assert_target_options_name_an_engine($options);
            }
            $engine = $policy->use_recorded_target_without_explicit_engine && $recorded_target !== null
                ? $recorded_target->engine
                : $policy->default_engine;
            if (!$policy->use_recorded_target_without_explicit_engine) {
                $recorded_target = null;
            }
        }

        if ($engine === null) {
            return null;
        }

        if ($engine === 'sqlite') {
            $target = new DatabaseTarget(
                'sqlite',
                (string) self::option_then_recorded_or_default(
                    $options['target_db'] ?? null,
                    $recorded_target === null ? null : $recorded_target->database_name,
                    'sqlite_database',
                ),
                self::nullable_string(self::option_then_recorded_or_default(
                    $options['target_sqlite_path'] ?? null,
                    $recorded_target === null ? null : $recorded_target->sqlite_path,
                    null,
                )),
            );

            if ($engine_was_stated && $policy->require_sqlite_directory_with_explicit_engine) {
                self::assert_sqlite_target_directory_exists($target->sqlite_path);
            }

            return $target;
        }

        $target = new DatabaseTarget(
            'mysql',
            (string) self::option_then_recorded_or_default(
                $options['target_db'] ?? null,
                $recorded_target === null ? null : $recorded_target->database_name,
                '',
            ),
            null,
            (string) self::option_then_recorded_or_default(
                $options['target_host'] ?? null,
                $recorded_target === null ? null : $recorded_target->host,
                '127.0.0.1',
            ),
            (int) self::option_then_recorded_or_default(
                $options['target_port'] ?? null,
                $recorded_target === null ? null : $recorded_target->port,
                3306,
            ),
            (string) self::option_then_recorded_or_default(
                $options['target_user'] ?? null,
                $recorded_target === null ? null : $recorded_target->user,
                '',
            ),
            (string) self::option_then_recorded_or_default(
                $options['target_pass'] ?? null,
                $recorded_target === null ? null : $recorded_target->password,
                '',
            ),
        );

        if (
            $policy->require_mysql_credentials_always ||
            ( $engine_was_stated && $policy->require_mysql_credentials_with_explicit_engine )
        ) {
            self::assert_mysql_credentials($target, $policy->mysql_credentials_error_style);
        }

        return $target;
    }

    /** @param array<string,mixed> $options */
    private static function assert_target_options_name_an_engine(array $options): void
    {
        foreach (self::TARGET_OPTION_FLAGS as $option_key => $flag) {
            $value = $options[$option_key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            throw new InvalidArgumentException(
                "apply-runtime received {$flag} without --target-engine. " .
                'Add --target-engine=mysql or --target-engine=sqlite to state the database target.'
            );
        }
    }

    private static function assert_mysql_credentials(DatabaseTarget $target, string $command): void
    {
        foreach (['user' => '--target-user', 'database_name' => '--target-db'] as $field => $flag) {
            if ($target->{$field} !== '') {
                continue;
            }

            if ($command === 'apply-runtime') {
                throw new InvalidArgumentException(
                    "apply-runtime with --target-engine=mysql requires {$flag}: " .
                    'neither the command line nor the recorded db-apply target supplied one.'
                );
            }

            if ($command === 'pull') {
                throw new InvalidArgumentException(
                    "{$flag} is required when db-apply targets MySQL."
                );
            }

            throw new InvalidArgumentException(
                'db-apply with --target-engine=mysql requires --target-user and --target-db.'
            );
        }
    }

    private static function assert_sqlite_target_directory_exists(?string $sqlite_path): void
    {
        if ($sqlite_path === null || is_dir(dirname($sqlite_path))) {
            return;
        }

        $directory = dirname($sqlite_path);
        throw new InvalidArgumentException(
            "The directory for --target-sqlite-path={$sqlite_path} does not exist: {$directory}. " .
            'Create it first; the database file itself is created on the first request.'
        );
    }

    /**
     * @param mixed $option_value
     * @param mixed $recorded_value
     * @param mixed $default_value
     * @return mixed
     */
    private static function option_then_recorded_or_default($option_value, $recorded_value, $default_value)
    {
        foreach ([$option_value, $recorded_value] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }
        return $default_value;
    }

    /** @param mixed $value */
    private static function nullable_string($value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
