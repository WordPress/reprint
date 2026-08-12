<?php

/**
 * Reprint client's command-line entry point.
 *
 * import.php requires this file only after it has loaded the symbols used
 * by command parsing and execution, and only for a CLI entry point.
 */

// ============================================================================
// CLI Entry Point
// ============================================================================

// Returns the importer version string. Inside the phar, reads the baked-in
// VERSION file. In development, falls back to `git describe`.
function get_importer_version(): string {
    // When running from the phar, the VERSION file is baked in at build time.
    $version_file = __DIR__ . '/../VERSION';
    if (file_exists($version_file)) {
        return trim(file_get_contents($version_file));
    }

    // Development fallback: derive from git.
    $tag = trim(shell_exec('git describe --exact-match --tags HEAD 2>/dev/null') ?: '');
    if ($tag !== '') {
        return $tag;
    }
    $latest = trim(shell_exec("git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1") ?: '');
    return ($latest !== '' ? $latest : 'v0.0.0') . '-trunk';
}

// Handle --version before anything else.
if (isset($argv[1]) && in_array($argv[1], ["--version", "-V"])) {
    echo get_importer_version() . "\n";
    exit(0);
}

// ================================================================
// CLI option definitions — single source of truth.
//
// The argument parser and help renderer both read from this array.
// Adding a new option here automatically includes it in --help;
// removing it here removes it from both parsing and help.
//
// Fields:
//   name           --name without the dashes (required)
//   type           'value'         --name=VAL
//                  'flag'          --name (sets a boolean)
//                  'value-or-next' --name=VAL or --name VAL
//                  'two-arguments' --name A B (repeatable, takes 2 arguments)
//   target         Where to store the parsed value:
//                  'state_dir' | 'filesystem_root' → special local variables
//                  'key'                   → $options['key']
//                  'tuning_config.key'     → $options['tuning_config']['key']
//   help           Description for --help output (null = hidden)
//   help_section   'required' | 'global' → controls main --help grouping
//                  null → not shown in main --help
//   commands       Array of command names for per-command --help display
//   placeholder    Value placeholder in help, e.g. 'DIR' (value types)
//   short          Single-char alias, e.g. 'v' for -v (flag types)
//   aliases        Array of alternative --names (hidden from help)
//   repeatable     Append each value to target instead of replacing it
//   cast           'int' | 'float' | 'size' (default: string)
//   flag_value     What to store for flag types (default: true)
//   valid_values   Array of allowed values (enforced at parse time)
//   argument_labels Labels for two-argument type help, e.g. 'FROM TO'
// ================================================================
$option_defs = [
    // ── Required options ─────────────────────────────────────
    [
        'name' => 'state-dir',
        'type' => 'value',
        'target' => 'state_dir',
        'placeholder' => 'DIR',
        'help' => 'Directory for pull state files and SQL dumps',
        'help_section' => 'required',
        'commands' => [],
    ],
    [
        'name' => 'fs-root',
        'type' => 'value',
        'target' => 'filesystem_root',
        'placeholder' => 'DIR',
        'help' => 'Local directory read from or written to for site files',
        'help_section' => 'required',
        'commands' => ['apply-runtime'],
        'aliases' => ['docroot'],
    ],

    // ── Global options ───────────────────────────────────────
    [
        'name' => 'secret',
        'type' => 'value',
        'target' => 'secret',
        'placeholder' => 'TOKEN',
        'help' => 'HMAC shared secret for export API authentication',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-push', 'files-index', 'db-pull', 'db-index', 'preflight', 'preflight-assert'],
    ],
    [
        'name' => 'force-http',
        'type' => 'flag',
        'target' => 'force_http',
        'help' => 'Allow a trusted plain-HTTP target; anyone able to observe or alter the connection can read or modify transferred content',
        'commands' => ['files-push'],
    ],
    [
        'name' => 'progress',
        'type' => 'value',
        'target' => 'progress',
        'placeholder' => 'MODE',
        'help' => 'Progress output: auto, tty, or jsonl (default: auto)',
        'help_section' => 'global',
        'commands' => ImportClient::COMMANDS,
        'valid_values' => ImportClient::PROGRESS_OUTPUT_MODES,
    ],
    [
        'name' => 'abort',
        'type' => 'flag',
        'target' => 'abort',
        'help' => 'Abort current sync and exit (preserves downloaded files)',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-index', 'db-pull', 'db-index', 'db-apply'],
    ],
    [
        'name' => 'verbose',
        'type' => 'flag',
        'target' => 'verbose',
        'short' => 'v',
        'help' => 'Show detailed request/response logs',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-push', 'files-index', 'db-pull', 'db-index', 'db-apply', 'flat-docroot', 'apply-runtime'],
    ],
    [
        'name' => 'no-follow-symlinks',
        'type' => 'flag',
        'target' => 'follow_symlinks',
        'flag_value' => false,
        'help' => 'Do not follow symlinks pointing outside root directories',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'files-pull'],
    ],
    [
        'name' => 'follow-symlinks',
        'type' => 'flag',
        'target' => 'follow_symlinks',
        'flag_value' => true,
        'help' => null,
        'commands' => [],
    ],
    [
        'name' => 'follow-symlinks',
        'type' => 'value',
        'target' => 'local_followed_symlinks_root',
        'placeholder' => 'DIR',
        'help' => 'Follow symlinks, consolidating escaping (out-of-scope) targets into DIR ' .
            '(a :fs-root: path or an absolute path within --fs-root), nested by source path. ' .
            'Bare --follow-symlinks is equivalent to --follow-symlinks=:fs-root:.',
        'commands' => ['pull', 'pull-files', 'files-pull'],
    ],
    [
        'name' => 'on-fs-root-nonempty',
        'type' => 'value',
        'target' => 'fs_root_nonempty_behavior',
        'placeholder' => 'MODE',
        'help' => 'What to do when filesystem root is non-empty (error|preserve-local)',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'files-pull'],
        'aliases' => ['on-docroot-nonempty'],
    ],
    [
        'name' => 'include-caches',
        'type' => 'flag',
        'target' => 'include_caches',
        'flag_value' => true,
        'help' => 'Include generated caches, VCS metadata, OS junk and editor scratch files (skipped by default)',
        'help_section' => 'global',
        'commands' => ['pull', 'pull-files', 'files-pull', 'files-index'],
    ],
    [
        'name' => 'adaptive',
        'type' => 'flag',
        'target' => 'tuning_config.enabled',
        'flag_value' => true,
        'help' => 'Enable adaptive request tuning (default: on)',
        'help_section' => 'global',
        'commands' => [],
    ],
    [
        'name' => 'no-adaptive',
        'type' => 'flag',
        'target' => 'tuning_config.enabled',
        'flag_value' => false,
        'help' => null,
        'commands' => [],
    ],
    [
        'name' => 'step',
        'type' => 'value',
        'target' => 'pipeline_step',
        'placeholder' => 'N',
        'cast' => 'int',
        'help' => 'Current pipeline step (1-indexed, for progress file)',
        'help_section' => 'global',
        'commands' => [],
    ],
    [
        'name' => 'steps',
        'type' => 'value',
        'target' => 'pipeline_steps',
        'placeholder' => 'N',
        'cast' => 'int',
        'help' => 'Total pipeline steps (for progress file)',
        'help_section' => 'global',
        'commands' => [],
    ],

    // ── files-pull options ───────────────────────────────────
    [
        'name' => 'filter',
        'type' => 'value',
        'target' => 'filter',
        'placeholder' => 'MODE',
        'valid_values' => ['none', 'essential-files', 'skipped-earlier'],
        'help' => null,
        'commands' => ['pull', 'pull-files', 'files-pull'],
    ],
    [
        'name' => 'extra-directory',
        'type' => 'value',
        'target' => 'extra_directory',
        'placeholder' => 'DIR',
        'help' => 'Additional remote directory to include in the export',
        'commands' => ['pull-files', 'files-pull', 'files-index'],
    ],

    // ── db-pull options ──────────────────────────────────────
    [
        'name' => 'max-allowed-packet',
        'type' => 'value',
        'target' => 'max_allowed_packet',
        'placeholder' => 'SIZE',
        'cast' => 'size',
        'help' => 'Client max_allowed_packet (e.g. 16M, 64M)',
        'commands' => ['pull-db', 'db-pull'],
    ],
    [
        'name' => 'sql-output',
        'type' => 'value',
        'target' => 'sql_output',
        'placeholder' => 'MODE',
        'help' => 'Output mode: file (default), stdout, mysql',
        'commands' => ['db-pull'],
    ],
    [
        'name' => 'mysql-host',
        'type' => 'value',
        'target' => 'mysql_host',
        'placeholder' => 'HOST',
        'help' => 'MySQL host (default: 127.0.0.1, for --sql-output=mysql)',
        'commands' => ['db-pull'],
    ],
    [
        'name' => 'mysql-port',
        'type' => 'value',
        'target' => 'mysql_port',
        'placeholder' => 'PORT',
        'help' => 'MySQL port (default: 3306, for --sql-output=mysql)',
        'commands' => ['db-pull'],
    ],
    [
        'name' => 'mysql-user',
        'type' => 'value',
        'target' => 'mysql_user',
        'placeholder' => 'USER',
        'help' => 'MySQL user (default: root, for --sql-output=mysql)',
        'commands' => ['db-pull'],
    ],
    [
        'name' => 'mysql-password',
        'type' => 'value',
        'target' => 'mysql_password',
        'placeholder' => 'PASS',
        'help' => 'MySQL password (or set MYSQL_PASSWORD env)',
        'commands' => ['db-pull'],
    ],
    [
        'name' => 'mysql-database',
        'type' => 'value',
        'target' => 'mysql_database',
        'placeholder' => 'DB',
        'help' => 'MySQL database (required for --sql-output=mysql)',
        'commands' => ['db-pull'],
    ],

    // ── db-apply options ─────────────────────────────────────
    [
        'name' => 'target-engine',
        'type' => 'value',
        'target' => 'target_engine',
        'placeholder' => 'ENGINE',
        'help' => 'Target database engine: mysql or sqlite',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-host',
        'type' => 'value',
        'target' => 'target_host',
        'placeholder' => 'HOST',
        'help' => 'Target MySQL host (default: 127.0.0.1)',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-port',
        'type' => 'value',
        'target' => 'target_port',
        'placeholder' => 'PORT',
        'cast' => 'int',
        'help' => 'Target MySQL port (default: 3306)',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-user',
        'type' => 'value',
        'target' => 'target_user',
        'placeholder' => 'USER',
        'help' => 'Target MySQL user (required for mysql)',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-pass',
        'type' => 'value',
        'target' => 'target_pass',
        'placeholder' => 'PASS',
        'help' => 'Target MySQL password',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-db',
        'type' => 'value',
        'target' => 'target_db',
        'placeholder' => 'NAME',
        'help' => 'Target DB name (required for mysql, optional for sqlite)',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'target-sqlite-path',
        'type' => 'value',
        'target' => 'target_sqlite_path',
        'placeholder' => 'PATH',
        'help' => 'Target SQLite database file (default: <wp-content>/database/.ht.sqlite)',
        'commands' => ['pull', 'pull-db', 'db-apply', 'apply-runtime'],
    ],
    [
        'name' => 'rewrite-url',
        'type' => 'two-arguments',
        'target' => 'rewrite_url',
        'argument_labels' => 'FROM TO',
        'help' => 'Rewrite FROM to TO (repeatable)',
        'commands' => ['pull', 'pull-db', 'db-apply'],
    ],
    [
        'name' => 'new-site-url',
        'type' => 'value-or-next',
        'target' => 'new_site_url',
        'placeholder' => 'URL',
        'help' => 'New site URL (auto-creates --rewrite-url from export URL origin)',
        'commands' => ['pull', 'pull-db', 'db-apply'],
    ],
    [
        'name' => 'remap',
        'type' => 'two-arguments',
        'target' => 'remap',
        'argument_labels' => 'SOURCE TARGET',
        'help' => 'Place SOURCE (a :token: like :wp-uploads: or an absolute path) at TARGET ' .
            '(a :fs-root: path or an absolute path within --fs-root); repeatable',
        'commands' => ['pull-files', 'files-pull'],
    ],
    [
        'name' => 'include',
        'type' => 'value-or-next',
        'target' => 'include',
        'placeholder' => 'SOURCE',
        'repeatable' => true,
        'help' => 'Restrict the file pull to SOURCE (a :token: like :wp-content: or :wp-uploads:, or an absolute path); ' .
            'repeat for several. Default pulls everything',
        'commands' => ['pull-files', 'files-pull'],
        'aliases' => ['only'],
    ],
    [
        'name' => 'exclude',
        'type' => 'value-or-next',
        'target' => 'exclude',
        'placeholder' => 'SOURCE',
        'repeatable' => true,
        'help' => 'Omit SOURCE (a :token: like :wp-content: or :wp-uploads:, or an absolute path) from the file pull; ' .
            'repeat for several',
        'commands' => ['pull-files', 'files-pull'],
    ],

    // ── flat-docroot options ────────────────────────────────
    [
        'name' => 'flatten-to',
        'type' => 'value',
        'target' => 'flatten_to',
        'placeholder' => 'PATH',
        'help' => 'Target directory for the flattened layout',
        'commands' => ['pull', 'flat-docroot'],
    ],
    [
        'name' => 'force',
        'type' => 'flag',
        'target' => 'force',
        'help' => 'Remove conflicting non-symlink files and replace with symlinks',
        'commands' => ['pull', 'flat-docroot'],
    ],

    // ── apply-runtime options ────────────────────────────────
    [
        'name' => 'runtime',
        'type' => 'value',
        'target' => 'runtime',
        'placeholder' => 'RUNTIME',
        'valid_values' => VALID_TARGET_RUNTIMES,
        'help' => 'Target server runtime: php-builtin, playground-cli, nginx-fpm, or none',
        'commands' => ['pull', 'apply-runtime'],
    ],
    [
        'name' => 'start-runtime',
        'type' => 'value',
        'target' => 'start_runtime',
        'placeholder' => 'RUNTIME',
        'valid_values' => VALID_TARGET_RUNTIMES,
        'help' => 'Runtime to launch after pull (php-builtin|playground-cli|nginx-fpm|none)',
        'commands' => ['pull'],
    ],
    [
        'name' => 'output-dir',
        'type' => 'value',
        'target' => 'output_dir',
        'placeholder' => 'DIR',
        'help' => 'Directory for generated runtime files',
        'commands' => ['pull', 'apply-runtime'],
    ],
    [
        'name' => 'flat-document-root',
        'type' => 'value',
        'target' => 'flat_document_root',
        'placeholder' => 'DIR',
        'help' => 'Flattened layout directory (used as-is)',
        'commands' => ['apply-runtime'],
        'aliases' => ['flattened-docroot'],
    ],
    [
        'name' => 'host',
        'type' => 'value',
        'target' => 'host',
        'placeholder' => 'HOST',
        'help' => 'Listen address (default: from rewrite URL, or localhost)',
        'commands' => ['apply-runtime'],
    ],
    [
        'name' => 'port',
        'type' => 'value',
        'target' => 'port',
        'placeholder' => 'PORT',
        'cast' => 'int',
        'help' => 'Listen port (default: from rewrite URL, or 8881)',
        'commands' => ['apply-runtime'],
    ],

    // ── Tuning options (accepted but hidden from help) ───────
    ['name' => 'duty', 'type' => 'value', 'target' => 'tuning_config.duty', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'duty-min', 'type' => 'value', 'target' => 'tuning_config.duty_min', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'duty-max', 'type' => 'value', 'target' => 'tuning_config.duty_max', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'throughput-alpha', 'type' => 'value', 'target' => 'tuning_config.throughput_ema_alpha', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'aimd-drop-ratio', 'type' => 'value', 'target' => 'tuning_config.aimd_drop_ratio', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'aimd-decrease-factor', 'type' => 'value', 'target' => 'tuning_config.aimd_decrease_factor', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'error-decrease-factor', 'type' => 'value', 'target' => 'tuning_config.error_decrease_factor', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'aimd-increase-file', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_file_bytes', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'aimd-increase-index', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_index_entries', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'aimd-increase-sql', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_sql_fragments', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'error-backoff', 'type' => 'value', 'target' => 'tuning_config.error_backoff_requests', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'max-exec', 'type' => 'value', 'target' => 'tuning_config.max_execution_time', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'memory-threshold', 'type' => 'value', 'target' => 'tuning_config.memory_threshold', 'cast' => 'float', 'help' => null, 'commands' => []],
    ['name' => 'file-chunk-start', 'type' => 'value', 'target' => 'tuning_config.file_chunk_start', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'file-chunk-min', 'type' => 'value', 'target' => 'tuning_config.file_chunk_min', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'file-chunk-max', 'type' => 'value', 'target' => 'tuning_config.file_chunk_max', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'index-batch-start', 'type' => 'value', 'target' => 'tuning_config.index_batch_start', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'index-batch-min', 'type' => 'value', 'target' => 'tuning_config.index_batch_min', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'index-batch-max', 'type' => 'value', 'target' => 'tuning_config.index_batch_max', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'sql-fragments-start', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_start', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'sql-fragments-min', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_min', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'sql-fragments-max', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_max', 'cast' => 'int', 'help' => null, 'commands' => []],
    ['name' => 'db-unbuffered', 'type' => 'flag', 'target' => 'tuning_config.db_unbuffered', 'help' => null, 'commands' => []],
    ['name' => 'db-query-time-limit', 'type' => 'value', 'target' => 'tuning_config.db_query_time_limit', 'cast' => 'int', 'help' => null, 'commands' => []],
];

// ── CLI helper functions ─────────────────────────────────

/**
 * Parse CLI options using the declarative option definitions.
 *
 * @return array {
 *     Parsed CLI option tuple.
 *
 *     @type string|null $0 State directory path.
 *     @type string|null $1 Filesystem root path.
 *     @type array       $2 Parsed options.
 * }
 * @phpstan-return array{0: ?string, 1: ?string, 2: array}
 */
function _cli_parse_options(array $argv, int $argc, int $start, array $option_defs): array
{
    $state_dir = null;
    $filesystem_root = null;
    $options = [
        "abort" => false,
        "verbose" => false,
        "secret" => null,
        "tuning_config" => [],
    ];

    for ($i = $start; $i < $argc; $i++) {
        $arg = $argv[$i];
        $matched = false;

        foreach ($option_defs as $def) {
            $names = [$def['name']];
            if (isset($def['aliases'])) {
                $names = array_merge($names, $def['aliases']);
            }

            foreach ($names as $cli_name) {
                switch ($def['type']) {
                    case 'value':
                        $prefix = "--{$cli_name}=";
                        if (strpos($arg, $prefix) === 0) {
                            $raw = substr($arg, strlen($prefix));
                            $value = _cli_cast($raw, $def['cast'] ?? null);
                            if (isset($def['valid_values']) && !in_array($value, $def['valid_values'], true)) {
                                fwrite(STDERR, "Invalid --{$def['name']} value: {$raw}. Valid values: " . implode(", ", $def['valid_values']) . "\n");
                                exit(1);
                            }
                            _cli_store($def, $value, $state_dir, $filesystem_root, $options);
                            $matched = true;
                            break 3;
                        }
                        break;

                    case 'flag':
                        if ($arg === "--{$cli_name}" || (isset($def['short']) && $arg === "-{$def['short']}")) {
                            _cli_store($def, $def['flag_value'] ?? true, $state_dir, $filesystem_root, $options);
                            $matched = true;
                            break 3;
                        }
                        break;

                    case 'value-or-next':
                        $prefix = "--{$cli_name}=";
                        if (strpos($arg, $prefix) === 0) {
                            $raw = substr($arg, strlen($prefix));
                            _cli_store($def, $raw, $state_dir, $filesystem_root, $options);
                            $matched = true;
                            break 3;
                        }
                        if ($arg === "--{$cli_name}") {
                            if (!isset($argv[$i + 1])) {
                                fwrite(STDERR, "--{$def['name']} requires one argument: " . ($def['placeholder'] ?? 'VALUE') . "\n");
                                exit(1);
                            }
                            _cli_store($def, $argv[$i + 1], $state_dir, $filesystem_root, $options);
                            $i += 1;
                            $matched = true;
                            break 3;
                        }
                        break;

                    case 'two-arguments':
                        if ($arg === "--{$cli_name}") {
                            if (!isset($argv[$i + 1]) || !isset($argv[$i + 2])) {
                                fwrite(STDERR, "--{$def['name']} requires two arguments: " . ($def['argument_labels'] ?? 'ARG1 ARG2') . "\n");
                                exit(1);
                            }
                            $target = $def['target'];
                            if (!isset($options[$target])) {
                                $options[$target] = [];
                            }
                            $options[$target][] = [$argv[$i + 1], $argv[$i + 2]];
                            $i += 2;
                            $matched = true;
                            break 3;
                        }
                        break;
                }
            }
        }

        if (!$matched) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(1);
        }
    }

    return [$state_dir, $filesystem_root, $options];
}

/** @internal */
function _cli_cast(string $raw, ?string $cast)
{
    switch ($cast) {
        case 'int':   return (int) $raw;
        case 'float': return (float) $raw;
        case 'size':  return parse_size($raw);
        default:      return $raw;
    }
}

/** @internal */
function _cli_store(array $def, $value, ?string &$state_dir, ?string &$filesystem_root, array &$options): void
{
    $target = $def['target'];
    if ($target === 'state_dir') { $state_dir = $value; return; }
    if ($target === 'filesystem_root')      { $filesystem_root = $value; return; }
    if (strpos($target, 'tuning_config.') === 0) {
        $options['tuning_config'][substr($target, strlen('tuning_config.'))] = $value;
        return;
    }
    if (!empty($def['repeatable'])) {
        if (!isset($options[$target])) {
            $options[$target] = [];
        }
        $options[$target][] = $value;
        return;
    }
    $options[$target] = $value;
}

/**
 * Render the main --help output.
 */
function _cli_render_main_help(array $option_defs, array $command_info): void
{
    $is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
    $re = $is_tty ? "\033[35m" : "";              // magenta (Re)
    $pr = $is_tty ? "\033[38;5;63m" : "";         // WP Blueberry ~#3858E9 (Print)
    $r  = $is_tty ? "\033[0m" : "";
    echo "{$re} ___         {$pr}___         _          _   {$r}\n";
    echo "{$re}| _ \\  ___  {$pr}| _ \\  _ _  (_)  _ _   | |_ {$r}\n";
    echo "{$re}|   / / -_) {$pr}|  _/ | '_| | | | ' \\  |  _|{$r}\n";
    echo "{$re}|_|_\\ \\___| {$pr}|_|   |_|   |_| |_||_|  \\__|{$r}\n";
    echo "\n";
    echo "Mirror any WordPress site over HTTP.\n";
    echo "Version " . get_importer_version() . "\n";
    echo "\n";
    echo "Usage: reprint <command> <remote-reprint-api-url> [options]\n";
    echo "\n";

    $high = array_filter($command_info, fn($i) => ($i['level'] ?? 'low') === 'high');
    $low = array_filter($command_info, fn($i) => ($i['level'] ?? 'low') === 'low');
    $max_len = max(array_map('strlen', array_keys($command_info)));

    echo "Commands:\n";
    foreach ($high as $name => $info) {
        echo "  " . str_pad($name, $max_len + 2) . $info["short"] . "\n";
    }
    echo "\n";
    echo "Low-level commands:\n";
    foreach ($low as $name => $info) {
        echo "  " . str_pad($name, $max_len + 2) . $info["short"] . "\n";
    }
    echo "\n";
    echo "Run 'reprint <command> --help' for command-specific help.\n";
    echo "\n";

    $required = array_filter($option_defs, fn($d) => ($d['help_section'] ?? null) === 'required');
    if ($required) {
        echo "Required options:\n";
        _cli_render_option_list($required);
        echo "\n";
    }

    echo "Shared options (see command help for availability):\n";
    $global = array_filter($option_defs, fn($d) => ($d['help_section'] ?? null) === 'global');
    // --version/-V is handled before option parsing, so inject it manually.
    _cli_render_option_list($global, ['--version, -V' => 'Print version and exit']);
    echo "\n";

    echo "Exit codes:\n";
    echo "  0  Command completed successfully\n";
    echo "  2  Partial progress — run the same command again to continue\n";
    echo "  1  Error\n";
    echo "\n";
    echo "Resumable commands keep their command-specific work under --state-dir.\n";
    echo "Run command-specific help for continuation and cancellation behavior.\n";
}

/**
 * Render per-command --help output.
 *
 * The "Options:" section is auto-generated from $option_defs so that
 * every declared option automatically appears in the right command's
 * help.  The hand-written $command_info provides the prose description
 * and any extra sections (examples, output-file lists, etc.).
 */
function _cli_render_command_help(string $command, array $option_defs, array $command_info): void
{
    if (!isset($command_info[$command])) {
        fwrite(STDERR, "Unknown command: {$command}\n");
        return;
    }

    $info = $command_info[$command];
    $usage = $info["usage"] ?? "reprint {$command} <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [options]";
    echo "Usage: {$usage}\n";
    echo "\n";
    echo $info["description"];

    // Collect options tagged for this command. Required options are also
    // shown when the command usage names them, so command-specific help
    // matches what the CLI requires without duplicating every command name
    // in the option definition.
    $cmd_options = array_filter($option_defs, function ($d) use ($command, $usage) {
        if (($d['help'] ?? null) === null) {
            return false;
        }
        if (isset($d['commands']) && in_array($command, $d['commands'], true)) {
            return true;
        }
        return
            ($d['help_section'] ?? null) === 'required' &&
            strpos($usage, "--{$d['name']}") !== false;
    });

    // Show command-specific options first, then global ones.
    if ($cmd_options) {
        usort($cmd_options, function ($a, $b) {
            $a_global = in_array($a['help_section'] ?? null, ['required', 'global'], true) ? 1 : 0;
            $b_global = in_array($b['help_section'] ?? null, ['required', 'global'], true) ? 1 : 0;
            return $a_global - $b_global;
        });
        echo "\n";
        echo "Options:\n";
        _cli_render_option_list($cmd_options);
    }

    if (!empty($info["extra"])) {
        echo "\n";
        echo $info["extra"];
    }
    echo "\n";
}

/**
 * Render the install-server guide.
 *
 * Shows the download URL for the Reprint Server plugin matching this
 * version of reprint, and step-by-step installation instructions.
 */
function _cli_render_install_exporter(): void
{
    $version = get_importer_version();
    $is_dev = str_contains($version, '-trunk') || $version === 'v0.0.0';
    $is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
    $bold  = $is_tty ? "\033[1m" : "";
    $dim   = $is_tty ? "\033[2m" : "";
    $cyan  = $is_tty ? "\033[36m" : "";
    $reset = $is_tty ? "\033[0m" : "";

    $repo = "WordPress/reprint";
    $zip_url = "https://github.com/{$repo}/releases/download/{$version}/reprint-exporter-wp.zip";

    echo "{$bold}Install the Reprint Server Plugin{$reset}\n";
    echo "\n";
    echo "The Reprint Server plugin must be installed on the WordPress site you\n";
    echo "want to mirror. It exposes the HTTP API that reprint connects to.\n";
    echo "\n";

    echo "{$bold}Step 1: Download the plugin{$reset}\n";
    echo "\n";
    if ($is_dev) {
        echo "  You are running an unreleased development build ({$version}).\n";
        echo "  Install the Reprint Server plugin from the same branch:\n";
        echo "\n";
        echo "  {$dim}composer build:server-plugin{$reset}\n";
        echo "\n";
        echo "  Then upload reprint-exporter-wp.zip through wp-admin,\n";
        echo "  or symlink reprint-server-wp/ into wp-content/plugins/.\n";
    } else {
        echo "  {$cyan}{$zip_url}{$reset}\n";
    }

    echo "\n";
    echo "{$bold}Step 2: Install on your WordPress site{$reset}\n";
    echo "\n";
    echo "  1. Log in to wp-admin\n";
    echo "  2. Go to Plugins → Add New Plugin → Upload Plugin\n";
    echo "  3. Upload reprint-exporter-wp.zip and activate it\n";
    echo "\n";
    echo "{$bold}Step 3: Configure the shared secret{$reset}\n";
    echo "\n";
    echo "  1. In wp-admin, go to Reprint Server (in the sidebar)\n";
    echo "  2. Enter a shared secret and save\n";
    echo "  3. Use the same secret with reprint:\n";
    echo "\n";
    echo "     {$dim}php reprint.phar preflight https://your-site.com \\\n";
    echo "       --secret=YOUR_SECRET \\\n";
    echo "       --state-dir=./state --fs-root=./files{$reset}\n";
    echo "\n";
}

/**
 * Render a list of options with aligned descriptions.
 *
 * @param array $defs   Option definition entries (only those with non-null help are rendered).
 * @param array $extra  Additional entries as ['--usage-string' => 'description'].
 */
function _cli_render_option_list(array $defs, array $extra = []): void
{
    $lines = [];
    foreach ($defs as $def) {
        if (($def['help'] ?? null) === null) {
            continue;
        }
        $lines[] = [_cli_option_usage($def), $def['help']];
    }
    foreach ($extra as $usage => $help) {
        $lines[] = [$usage, $help];
    }

    // Compute alignment: at least 2 spaces after the longest option.
    $max_usage = 0;
    foreach ($lines as [$usage, $_]) {
        $max_usage = max($max_usage, strlen($usage));
    }
    $col = max($max_usage + 2, 21);

    foreach ($lines as [$usage, $help]) {
        if (strlen($usage) >= $col) {
            // Option too long for the column — wrap description to next line.
            echo "  {$usage}\n";
            echo str_repeat(' ', $col + 2) . "{$help}\n";
        } else {
            echo "  " . str_pad($usage, $col) . "{$help}\n";
        }
    }
}

/** @internal Build the display string for one option, e.g. "--name=DIR" or "--name, -v". */
function _cli_option_usage(array $def): string
{
    $name = "--{$def['name']}";
    if (isset($def['short'])) {
        $name .= ", -{$def['short']}";
    }
    switch ($def['type']) {
        case 'value':
        case 'value-or-next':
            return "{$name}=" . ($def['placeholder'] ?? 'VALUE');
        case 'two-arguments':
            return "{$name} " . ($def['argument_labels'] ?? 'ARG1 ARG2');
        case 'flag':
        default:
            return $name;
    }
}

// ── Per-command help definitions ─────────────────────────────
//
// "short"       — one-line summary shown in the main help listing.
// "description" — prose shown above the auto-generated Options section.
// "extra"       — text shown below the Options section (examples,
//                 output-file lists, mode explanations, etc.).
//
// The Options: section itself is generated from $option_defs so that
// every declared option for a command is guaranteed to appear.
// High-level commands are the ones most users will use. Low-level
// commands expose focused workflows useful for scripting and hosting
// platform integrations; pull composes the relevant pull-side commands.
$command_info = [
    "pull" => [
        "level" => "high",
        "short" => "Clone a remote site (preflight + files + database + apply)",
        "description" =>
            "Full site clone in a single command. Composes lower-level commands into\n" .
            "a resumable pipeline:\n" .
            "\n" .
            "  1. Preflight — probe the remote site environment\n" .
            "  2. Files     — download all remote files into --fs-root\n" .
            "  3. Database  — download the SQL dump\n" .
            "  4. Apply     — apply SQL to a local database (if --target-db)\n" .
            "  5. Flatten   — reassemble into standard WP layout (if --flatten-to)\n" .
            "  6. Runtime   — generate server config (default: php-builtin)\n" .
            "  7. Start     — launch the selected runtime when supported\n" .
            "\n" .
            "Each step resumes automatically after an interrupted response. If the process is\n" .
            "interrupted, re-run the same command to resume from where it left off.\n" .
            "Running pull again after completion performs a delta sync.\n" .
            "\n" .
            "The ?site-export-api query parameter is added automatically if missing,\n" .
            "so you can pass just the site URL.\n",
        "extra" =>
            "Examples:\n" .
            "  # Download files and database without applying SQL:\n" .
            "  reprint pull https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files\n" .
            "\n" .
            "  # Full clone with MySQL database apply and URL rewriting:\n" .
            "  reprint pull https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --target-user=root --target-db=wp_local \\\n" .
            "    --new-site-url=http://localhost:8881\n" .
            "\n" .
            "  # Full clone with SQLite, flattened layout, and PHP built-in server:\n" .
            "  reprint pull https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --target-engine=sqlite \\\n" .
            "    --new-site-url=http://localhost:8881 \\\n" .
            "    --flatten-to=./site --runtime=php-builtin --output-dir=./runtime\n" .
            "\n" .
            "  # Prepare a Playground runtime but let another process start it:\n" .
            "  reprint pull https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --runtime=playground-cli --start-runtime=none --output-dir=./runtime\n",
    ],
    "pull-files" => [
        "level" => "high",
        "short" => "Pull files through the high-level pull pipeline",
        "description" =>
            "Runs the file side of the pull pipeline:\n" .
            "\n" .
            "  1. Preflight — probe the remote site environment\n" .
            "  2. files-pull — download all files, or a selected subset\n" .
            "\n" .
            "This gives files the same retry and resume behavior as pull,\n" .
            "without running the database stages.\n",
        "extra" =>
            "Examples:\n" .
            "  reprint pull-files https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files\n" .
            "\n" .
            "  reprint pull-files https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --include=:wp-content: --exclude=:wp-uploads:\n",
    ],
    "pull-db" => [
        "level" => "high",
        "short" => "Pull and apply the database through the high-level pull pipeline",
        "description" =>
            "Runs the database side of the pull pipeline:\n" .
            "\n" .
            "  1. Preflight — probe the remote site environment\n" .
            "  2. db-pull — download the SQL dump into --state-dir/db.sql\n" .
            "  3. db-apply — apply the dump to a local database\n" .
            "\n" .
            "This gives the database the same retry and resume behavior as pull,\n" .
            "without running the file or runtime stages. With no MySQL target\n" .
            "options, pull-db applies the dump to SQLite by default.\n",
        "extra" =>
            "Examples:\n" .
            "  reprint pull-db https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --target-engine=sqlite\n" .
            "\n" .
            "  reprint pull-db https://example.com \\\n" .
            "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
            "    --target-user=root --target-db=wp_local \\\n" .
            "    --new-site-url=http://localhost:8881\n",
    ],
    "install-server" => [
        "level" => "high",
        "short" => "Show how to install the Reprint Server plugin on your site",
        "description" =>
            "Prints the download URL for the Reprint Server WordPress plugin that\n" .
            "matches this version of reprint, and step-by-step installation\n" .
            "instructions.\n" .
            "\n" .
            "The Reprint Server plugin must be installed on the remote site before\n" .
            "any other reprint command can connect to it.\n",
        "extra" => null,
    ],
    "preflight" => [
        "level" => "low",
        "short" => "Probe the remote site and cache its environment",
        "description" =>
            "Contacts the remote site and collects environment details:\n" .
            "PHP/MySQL versions, memory limits, filesystem access, database\n" .
            "connectivity, WordPress version, plugins, themes, directory layout,\n" .
            "and runtime scripts (auto_prepend_file, auto_append_file).\n" .
            "\n" .
            "Results are saved to state for use by later commands.\n" .
            "Prints the full response as pretty-printed JSON.\n" .
            "Exits 0 if the site reported OK, 1 otherwise.\n",
        "extra" => null,
    ],
    "preflight-assert" => [
        "level" => "low",
        "short" => "Verify the remote site can be mirrored (exits 0 or 1)",
        "description" =>
            "Runs the same check as the preflight command, then evaluates\n" .
            "key assertions:\n" .
            "\n" .
            "  - Remote site responded with HTTP 200\n" .
            "  - Preflight OK flag is set\n" .
            "  - Filesystem directories are accessible\n" .
            "  - Database connection works\n" .
            "\n" .
            "Prints a PASS/FAIL summary and exits 0 if all checks pass, 1 if not.\n",
        "extra" => null,
    ],
    "files-pull" => [
        "level" => "low",
        "short" => "Pull all files (initial) or only changes (delta)",
        "description" =>
            "Downloads files from the remote site into --fs-root.\n" .
            "\n" .
            "On the first run, indexes the full remote directory tree and then\n" .
            "downloads every file. On subsequent runs, writes the next remote index,\n" .
            "compares it with the remote index, and downloads only what changed.\n" .
            "Interrupted pulls resume from the last saved cursor.\n" .
            "\n" .
            "Runs files-index internally to write the next remote index.\n",
        "extra" =>
            "Path selection:\n" .
            "  --include=SOURCE   Include only this source path prefix; repeatable.\n" .
            "  --exclude=SOURCE   Exclude this source path prefix; repeatable.\n" .
            "  Exclusions win when include and exclude prefixes overlap.\n" .
            "\n" .
            "Output files:\n" .
            "  (filesystem root)/                       Downloaded files\n" .
            "  remotes/<md5-of-trimmed-remote-reprint-api-url>/local_index.jsonl\n" .
            "                                           Local index advanced by completed pull mutations\n" .
            "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/remote-index.jsonl\n" .
            "                                           Remote index\n" .
            "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/remote-index.next.jsonl\n" .
            "                                           Next remote index\n" .
            "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/fetch-list.jsonl\n" .
            "                                           Files pending download\n" .
            "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/state.json\n" .
            "                                           Resumable pull state\n" .
            "  audit.log                       Audit log\n",
    ],
    "files-diff" => [
        "level" => "low",
        "short" => "Compare local files with the local index",
        "usage" => "reprint files-diff <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [--progress=auto|tty|jsonl]",
        "description" =>
            "Shows which local paths a files-push would send or delete, comparing\n" .
            "the filesystem root at --fs-root with the local index for this remote\n" .
            "Reprint API URL. files-pull advances that index after completed local\n" .
            "mutations, and files-push writes it after the target confirms commit.\n" .
            "Use the same remote Reprint API URL, state directory, and filesystem\n" .
            "root for these commands.\n" .
            "The output is a local minimized push operation plan before target\n" .
            "exclusions, not a path-for-path filesystem log. Like files-push, its\n" .
            "default-skipped paths include generated wp-content caches, version-\n" .
            "control data, node_modules, package-manager caches, OS metadata, and\n" .
            "editor scratch files.\n" .
            "With --progress=auto (the default), a terminal gets red status lines\n" .
            "that label paths to push as modified and paths to delete as deleted;\n" .
            "redirected stdout gets JSONL. --progress=tty forces status lines and\n" .
            "--progress=jsonl forces JSONL. JSONL paths remain base64 text so\n" .
            "arbitrary filesystem names are preserved. No network calls are made,\n" .
            "and no secret is required.\n",
        "extra" =>
            "Every run reports the complete diff from the beginning; there is\n" .
            "no partial resume to continue.\n",
    ],
    "files-push" => [
        "level" => "low",
        "short" => "Push one local file tree without database work",
        "usage" => "reprint files-push <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR --secret=TOKEN [--force-http] [--progress=MODE] [--verbose]",
        "description" =>
            "Sends the remote document root's local tree beneath --fs-root.\n" .
            "This is a low-level, files-only command: it performs no database work,\n" .
            "plan display, confirmation prompt, automatic retry, or automatic restart.\n" .
            "It requires saved preflight data for the remote document root.\n" .
            "\n" .
            "Each process runs one sender until it completes, reaches a caller time or\n" .
            "memory boundary, or receives a signal handled by this PHP runtime.\n" .
            "Re-run the same command after exit 2.\n" .
            "After a restart result, the next run starts a fresh plan.\n",
        "extra" =>
            "Progress output:\n" .
            "  auto   Use tty on a terminal and jsonl otherwise (default)\n" .
            "  tty    Force the single interactive progress bar\n" .
            "  jsonl  Force one JSON object per line\n" .
            "Explicit tty and jsonl modes cannot be combined with --verbose.\n" .
            "\n" .
            "Exit outcomes:\n" .
            "  0  File push complete\n" .
            "  2  Partial, interrupted, or restart; run the command again\n" .
            "  1  Failed request or command error\n",
    ],
    "files-index" => [
        "level" => "low",
        "short" => "Index all remote files (initial) or detect changes (delta)",
        "description" =>
            "Streams the full remote directory tree over HTTP and writes each\n" .
            "entry (path, size, ctime, type, and directory emptiness) to\n" .
            "<remote-state-directory>/pull/remote-index.next.jsonl.\n" .
            "\n" .
            "On the first run, builds the complete index. On subsequent runs,\n" .
            "re-indexes and diffs against the prior snapshot to produce a\n" .
            "fetch list of changed files.\n" .
            "\n" .
            "When symlink-following is enabled, recursively discovers and indexes\n" .
            "additional directories outside the primary roots.\n" .
            "\n" .
            "Does not download any file contents.\n",
        "extra" => null,
    ],
    "files-stats" => [
        "level" => "low",
        "short" => "Show file counts and sizes from the next remote index",
        "description" =>
            "Reads the next remote index and fetch lists to report (no network calls):\n" .
            "\n" .
            "  - Total indexed files and their combined size\n" .
            "  - Files not yet downloaded and their combined size\n" .
            "\n" .
            "Output is JSON with 'indexed' and 'pending' sections.\n" .
            "Requires a prior files-index or files-pull run.\n",
        "extra" => null,
    ],
    "db-pull" => [
        "level" => "low",
        "short" => "Pull the database as a SQL dump (index + download)",
        "description" =>
            "Indexes remote tables, then streams the full SQL dump into\n" .
            "--state-dir/db.sql (default), to stdout, or directly into a\n" .
            "MySQL connection. Resumes from the last cursor if interrupted.\n" .
            "Discovered domains are cached for later use by db-apply.\n",
        "extra" =>
            "Output modes:\n" .
            "  file    Write to --state-dir/db.sql (default)\n" .
            "  stdout  Write raw SQL to stdout; progress goes to stderr\n" .
            "  mysql   Stream directly into a MySQL connection\n",
    ],
    "db-index" => [
        "level" => "low",
        "short" => "Pull table metadata from the remote database",
        "description" =>
            "Fetches table metadata (name, estimated rows, data size) from\n" .
            "the remote server and writes it to --state-dir/db-tables.jsonl.\n" .
            "Useful for planning before a full db-pull.\n",
        "extra" =>
            "Output files:\n" .
            "  db-tables.jsonl  One JSON object per table\n",
    ],
    "db-domains" => [
        "level" => "low",
        "short" => "Extract domains from the pulled SQL dump",
        "description" =>
            "Prints domains found in the SQL dump, one per line.\n" .
            "\n" .
            "If <remote-state-directory>/pull/domains.json exists (cached by db-pull), it is read\n" .
            "directly. Otherwise, db.sql is scanned and the result is cached\n" .
            "for future calls. No network calls.\n" .
            "\n" .
            "Example:\n" .
            "  reprint db-domains https://example.com --state-dir=/path/to/state\n",
        "extra" => null,
    ],
    "pull-metadata" => [
        "level" => "low",
        "short" => "Print local pull metadata for host integrations as JSON",
        "usage" => "reprint pull-metadata <remote-reprint-api-url> --state-dir=DIR",
        "description" =>
            "Prints pull lifecycle, artifact availability, and source-site\n" .
            "metadata for host integrations. The remote Reprint API URL selects\n" .
            "the state; no network calls are made.\n",
        "extra" =>
            "Example:\n" .
            "  reprint pull-metadata https://example.com --state-dir=./state | jq '.hasCompletedOnce'\n",
    ],
    "db-apply" => [
        "level" => "low",
        "short" => "Apply the SQL dump to a local MySQL or SQLite database",
        "description" =>
            "Reads db.sql from --state-dir, optionally rewrites URLs, and executes\n" .
            "all statements against a target database. Resumable. Saves target\n" .
            "database credentials to state for use by apply-runtime.\n",
        "extra" =>
            "MySQL example:\n" .
            "  reprint db-apply https://example.com --state-dir=./state --fs-root=./files \\\n" .
            "    --target-user=root --target-db=wp_new \\\n" .
            "    --rewrite-url https://old.com https://new.com\n" .
            "\n" .
            "SQLite example:\n" .
            "  reprint db-apply https://example.com --state-dir=./state --fs-root=./files \\\n" .
            "    --target-engine=sqlite --target-sqlite-path=/path/to/db.sqlite \\\n" .
            "    --rewrite-url https://old.com https://new.com\n",
    ],
    "flat-docroot" => [
        "level" => "low",
        "short" => "Reassemble pulled files into a standard WordPress layout",
        "description" =>
            "Creates a directory at --flatten-to with symlinks that map the\n" .
            "pulled files back into a vanilla WordPress directory structure.\n" .
            "\n" .
            "Uses preflight paths (ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR,\n" .
            "WPMU_PLUGIN_DIR, uploads basedir) to locate each component\n" .
            "within --fs-root, even when they reside in different parent\n" .
            "directories on the source server (e.g. WP Cloud with ABSPATH at\n" .
            "/srv/htdocs and WP_CONTENT_DIR at /tmp/__wp__/wp-content).\n" .
            "\n" .
            "No files are copied — only symlinks are created. Idempotent.\n" .
            "If a path that should be a symlink is a regular file or directory,\n" .
            "the command stops with an error unless --force is specified.\n",
        "extra" => null,
    ],
    "apply-runtime" => [
        "level" => "low",
        "short" => "Generate server config and prepare the site to run locally",
        "usage" =>
            "reprint apply-runtime <remote-reprint-api-url> --state-dir=DIR " .
            "(--fs-root=DIR|--flat-document-root=DIR) [options]",
        "description" =>
            "Generates server configuration (runtime.php, nginx.conf or start.sh)\n" .
            "from preflight data and removes production-only drop-ins and mu-plugins\n" .
            "that would crash outside the original host.\n" .
            "\n" .
            "Embeds the target database in runtime.php: the one named by the\n" .
            "--target-* options, or the one db-apply connected to.\n" .
            "\n" .
            "The remote Reprint API URL selects the state used to generate the\n" .
            "runtime configuration; no network calls are made.\n" .
            "\n" .
            "Pass --fs-root for the raw download directory (the remote document_root\n" .
            "path is appended automatically), or --flat-document-root for a directory\n" .
            "created by flat-docroot (used as-is). These are mutually exclusive.\n",
        "extra" =>
            "Runtime modes:\n" .
            "  nginx-fpm      — writes runtime.php + nginx.conf\n" .
            "  php-builtin    — writes runtime.php + start.sh\n" .
            "  playground-cli — writes runtime.php + blueprint.json\n" .
            "\n" .
            "Database configuration:\n" .
            "  The target database is included in runtime.php as DB_* constants.\n" .
            "  Name it with --target-engine and its companion options, or leave\n" .
            "  those out and apply-runtime uses the target db-apply connected to.\n" .
            "  Options win field by field; a --target-engine that differs from the\n" .
            "  one db-apply used replaces the recorded target completely.\n" .
            "  For MySQL targets the constants are DB_HOST, DB_NAME, DB_USER, and\n" .
            "  DB_PASSWORD. Every field you leave out falls back to the recorded\n" .
            "  target, so name the whole connection when you point at a different\n" .
            "  database — otherwise you inherit db-apply's host, port or password.\n" .
            "  For SQLite targets, the sqlite-database-integration plugin is copied\n" .
            "  into the output directory and a lazy-loading \$wpdb proxy is generated\n" .
            "  in runtime.php (Playground-style, no files placed in the filesystem\n" .
            "  root). The SQLite file may be absent — the plugin creates it on the\n" .
            "  first request — but its directory must exist.\n" .
            "  apply-runtime does not write these options to state: they configure\n" .
            "  one run, unlike db-apply's record of a database it connected to.\n" .
            "\n" .
            "Output files (nginx-fpm):\n" .
            "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n" .
            "  (output-dir)/nginx.conf              Nginx server block\n" .
            "\n" .
            "Output files (php-builtin):\n" .
            "  (output-dir)/runtime.php             PHP runtime (constants, routing, handlers)\n" .
            "  (output-dir)/start.sh                Shell script to launch the server\n" .
            "\n" .
            "Output files (playground-cli):\n" .
            "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n" .
            "  (output-dir)/blueprint.json          Playground Blueprint\n" .
            "\n" .
            "Output files (sqlite target, additional):\n" .
            "  (output-dir)/sqlite-database-integration/   Plugin copy\n" .
            "\n" .
            "Examples:\n" .
            "  # From raw download directory:\n" .
            "  reprint apply-runtime https://example.com --state-dir=./state \\\n" .
            "    --fs-root=./files --output-dir=./runtime --runtime=php-builtin\n" .
            "\n" .
            "  # From flattened layout:\n" .
            "  reprint apply-runtime https://example.com --state-dir=./state \\\n" .
            "    --flat-document-root=./flat --output-dir=./runtime --runtime=php-builtin\n" .
            "\n" .
            "  # Point the runtime at a database db-apply did not create:\n" .
            "  reprint apply-runtime https://example.com --state-dir=./state \\\n" .
            "    --flat-document-root=./flat --output-dir=./runtime --runtime=php-builtin \\\n" .
            "    --target-engine=sqlite --target-sqlite-path=./flat/wp-content/database/.ht.sqlite\n" .
            "\n" .
            "  bash ./runtime/start.sh\n",
    ],
];

// Show main help when invoked with no arguments or just --help
if ($argc < 2 || (isset($argv[1]) && in_array($argv[1], ["--help", "-h", "help"]))) {
    _cli_render_main_help($option_defs, $command_info);
    exit(1);
}

$command = $argv[1];

// Map accepted command aliases to the canonical command names.
$command_aliases = [
    "files-sync" => "files-pull",
    "db-sync" => "db-pull",
    "flat-document-root" => "flat-docroot",
    "flatten-docroot" => "flat-docroot",
    "import-metadata" => "pull-metadata",
    "install-exporter" => "install-server",
];
if (isset($command_aliases[$command])) {
    $command = $command_aliases[$command];
}

// install-server is a standalone guide — no URL, state-dir, or filesystem root needed.
// Handle it before per-command --help so it always shows the full guide.
if ($command === "install-server") {
    _cli_render_install_exporter();
    exit(0);
}

// Per-command --help (can be requested before providing url/path)
if (in_array("--help", array_slice($argv, 2)) || in_array("-h", array_slice($argv, 2))) {
    _cli_render_command_help($command, $option_defs, $command_info);
    exit(0);
}

// Every command which reads or writes state names the remote Reprint API
// URL whose remote state directory it uses. Local commands use the URL to
// select state without making a network request.
$remote_reprint_api_url = $argv[2] ?? null;
if (
    !$remote_reprint_api_url
    || strpos($remote_reprint_api_url, '-') === 0
) {
    fwrite(STDERR, "Error: <remote-reprint-api-url> is required\n");
    fwrite(STDERR, "Usage: reprint {$command} <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [options]\n");
    exit(1);
}
$option_start_index = 3;

[$state_dir, $filesystem_root, $options] = _cli_parse_options(
    $argv, $argc, $option_start_index, $option_defs
);
$options["command"] = $command;

$reprint_files_command_arguments = array_slice($argv, $option_start_index);
if ($command === 'files-push') {
    foreach ($reprint_files_command_arguments as $reprint_files_push_command_argument) {
        $reprint_files_push_option_allowed = in_array(
            $reprint_files_push_command_argument,
            ['--force-http', '--verbose', '-v'],
            true
        )
            || strpos($reprint_files_push_command_argument, '--state-dir=') === 0
            || strpos($reprint_files_push_command_argument, '--fs-root=') === 0
            || strpos($reprint_files_push_command_argument, '--secret=') === 0
            || strpos($reprint_files_push_command_argument, '--progress=') === 0;
        if (!$reprint_files_push_option_allowed) {
            $reprint_files_push_option_name = explode('=', $reprint_files_push_command_argument, 2)[0];
            fwrite(STDERR, "Error: files-push does not accept {$reprint_files_push_option_name}.\n");
            exit(1);
        }
    }
} elseif ($command === 'files-diff') {
    foreach ($reprint_files_command_arguments as $reprint_files_diff_command_argument) {
        $reprint_files_diff_option_allowed =
            strpos($reprint_files_diff_command_argument, '--progress=') === 0
            || strpos($reprint_files_diff_command_argument, '--state-dir=') === 0
            || strpos($reprint_files_diff_command_argument, '--fs-root=') === 0;
        if (!$reprint_files_diff_option_allowed) {
            $reprint_files_diff_option_name = explode('=', $reprint_files_diff_command_argument, 2)[0];
            fwrite(STDERR, "Error: files-diff does not accept {$reprint_files_diff_option_name}.\n");
            exit(1);
        }
    }
} elseif (!empty($options['force_http'])) {
    fwrite(STDERR, "Error: --force-http is accepted only by files-push.\n");
    exit(1);
}

if (!$state_dir) {
    fwrite(STDERR, "Error: --state-dir=DIR is required\n");
    fwrite(STDERR, "Usage: reprint {$command} <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [options]\n");
    exit(1);
}

// apply-runtime accepts --flat-document-root as an alternative to --fs-root.
$flat_document_root = $options["flat_document_root"] ?? null;
if ($filesystem_root && $flat_document_root) {
    fwrite(STDERR, "Error: --fs-root and --flat-document-root are mutually exclusive.\n");
    fwrite(STDERR, "Use --fs-root for the raw download directory, or --flat-document-root for a flattened layout.\n");
    exit(1);
}
if (!$filesystem_root && !$flat_document_root && $command !== "pull-metadata") {
    fwrite(STDERR, "Error: --fs-root=DIR is required\n");
    fwrite(STDERR, "Usage: reprint {$command} <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [options]\n");
    exit(1);
}
if (!$filesystem_root) {
    // For commands that need a filesystem root in the constructor, use the
    // flattened filesystem root. run_apply_runtime will resolve it properly.
    // pull-metadata reads only state, but ImportClient still expects
    // a filesystem root path. Point it at state-dir rather than requiring an
    // otherwise-unused CLI option.
    $filesystem_root = $flat_document_root ?: $state_dir;
}

try {
    // Acquire the lock before local push state setup and audit writes so
    // each command owns every local state transition for its complete invocation.
    $reprint_process_lock = new ReprintProcessLock($state_dir);
    $reprint_files_push_context = null;
    $reprint_files_diff_push_state_directory = null;
    if ($command === 'files-push') {
        $reprint_files_push_context = ImportClient::prepare_files_push_context(
            $remote_reprint_api_url,
            $state_dir,
            $filesystem_root,
            $options
        );
    } elseif ($command === 'files-diff') {
        $reprint_files_diff_push_state_directory = ImportClient::resolve_push_state_directory(
            $remote_reprint_api_url,
            $state_dir,
            $filesystem_root,
            'files-diff'
        );
    }
    $client = new ImportClient($remote_reprint_api_url, $state_dir, $filesystem_root, $command);
    $client->audit_log_argv($command, $argv);
    $client->run(
        $options
            + ( $reprint_files_push_context === null
                ? []
                : ['files_push_context' => $reprint_files_push_context] )
            + ( $reprint_files_diff_push_state_directory === null
                ? []
                : ['files_diff_push_state_directory' => $reprint_files_diff_push_state_directory] ),
        $reprint_process_lock
    );
    // EXIT_AFTER_PULL controls whether we hand control back to
    // the caller after pull returns. Default true: standard CLI
    // invocations (reprint pull, the phar bin, e2e tests) get the
    // exit() they expect. Embedders that include the phar from a
    // web SAPI — the Playground wizard in reprint-import.php is
    // the live case — define EXIT_AFTER_PULL=false so cleanup
    // logic can run AFTER pull, in the same try/catch scope as
    // the include. Without that knob the bare exit() jumps the
    // embedder's stack and forces it to wire activation through
    // register_shutdown_function, where exceptions have no
    // channel to surface as ndjson events. Stash the exit code on
    // a global so the embedder can read it.
    $GLOBALS['REPRINT_PULL_EXIT_CODE'] = (int) $client->exit_code;
    if (!defined('EXIT_AFTER_PULL') || EXIT_AFTER_PULL) {
        exit($client->exit_code);
    }
    return;
} catch (\Throwable $e) {
    $reprint_progress_output_mode = $options['progress'] ?? 'auto';
    $reprint_progress_stream = ( $options['sql_output'] ?? null ) === 'stdout' ? STDERR : STDOUT;
    $reprint_progress_stream_is_tty =
        function_exists("posix_isatty") && posix_isatty($reprint_progress_stream);
    $reprint_uses_terminal_progress = $reprint_progress_output_mode === 'tty'
        || ( $reprint_progress_output_mode === 'auto' && $reprint_progress_stream_is_tty );
    $error_code = isset($client) ? $client->last_error_code : null;
    if ($reprint_uses_terminal_progress && empty($options['verbose'])) {
        fwrite(STDERR, ( $command === 'files-diff' ? '' : "\n" ) . "Error: " . $e->getMessage() . "\n");
    } else {
        $error = [
            "error" => $e->getMessage(),
            "error_code" => $error_code,
            "exception" => get_class($e),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ];
        $json = json_encode($error);
        if ($json === false) {
            $json = '{"error":"' . addslashes($e->getMessage()) . '","exception":"' . get_class($e) . '"}';
        }
        fwrite(STDERR, $json . "\n");
    }
    $GLOBALS['REPRINT_PULL_EXIT_CODE'] = 1;
    if (!defined('EXIT_AFTER_PULL') || EXIT_AFTER_PULL) {
        exit(1);
    }
    // When EXIT_AFTER_PULL is false we still want the embedder
    // to see the failure — re-throw so its try/catch around
    // `include $phar` can surface a proper `{type:'error'}` event.
    throw $e;
} finally {
    if (isset($reprint_process_lock)) {
        $reprint_process_lock->close();
    }
}
