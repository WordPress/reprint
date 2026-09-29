<?php

namespace Reprint\Importer;

use ImportClient;
use InvalidArgumentException;
use ReprintProcessLock;
use RuntimeException;
use WordPress\Reprint\Server\Utils;

// phpcs:disable WordPress.Security.EscapeOutput -- This class prints CLI text and JSON, not HTML.

/** Local CLI settings. Loading this class never discovers or changes a config. */
final class SavedRemoteConfig {
    /** @var string|null Named remote state directory, or null for explicit URL commands. */
    public ?string $remote_state_directory = null;
    /** @var resource|null Config lock handle retained through command execution. */
    private $lock = null;

    /**
     * Handle config commands or supply defaults to the existing CLI parser.
     *
     * @param string[] $arguments Process arguments, including the executable.
     * @param array[]  $definitions CLI option definitions, including config_scope and config_path.
     * @return string[]|null Arguments for execution, or null after a config command.
     */
    public function prepare(array $arguments, array $definitions): ?array {
        $config_file = null;
        $no_config = false;
        $value_arguments = 0;
        foreach ($arguments as $index => $argument) {
            if ($index < 2 || $value_arguments > 0) {
                $value_arguments = max(0, $value_arguments - 1);
                continue;
            }
            foreach ($definitions as $definition) {
                if (in_array($argument, array_map(static function ($name) { return '--' . $name; }, array_merge([$definition['name']], $definition['aliases'] ?? [])), true)) {
                    $value_arguments = $definition['type'] === 'two-arguments' ? 2 : ( $definition['type'] === 'value-or-next' ? 1 : 0 );
                    break;
                }
            }
            if (strpos($argument, '--config=') === 0) {
                if ($config_file !== null || substr($argument, 9) === '') {
                    throw new InvalidArgumentException('Provide exactly one non-empty --config=FILE.');
                }
                $config_file = self::absolute_path(substr($argument, 9), getcwd());
                unset($arguments[$index]);
            } elseif ($argument === '--no-config') {
                $no_config = true;
                unset($arguments[$index]);
            }
        }
        $arguments = array_values($arguments);
        $command = $arguments[1];
        if ($no_config && $config_file !== null) {
            throw new InvalidArgumentException('--config and --no-config cannot be combined.');
        }
        $config_command = in_array($command, ['remote', 'config'], true);
        $explicit_url = !$config_command && isset($arguments[2]) && strpos($arguments[2], '-') !== 0;
        if ($explicit_url && $config_file !== null) {
            throw new InvalidArgumentException('Use either an explicit remote Reprint API URL or --config, not both.');
        }
        if ($no_config || $explicit_url || in_array($command, ['recover', 'post-process'], true)) {
            if ($config_command || $config_file !== null) {
                throw new InvalidArgumentException('This command cannot use the selected config options.');
            }
            return $arguments;
        }
        $explicit_config = $config_file !== null;
        $config_file = $config_file ?? self::absolute_path('.reprint/config.json', getcwd());
        if (!$config_command && !$explicit_config && !is_file($config_file)) {
            return $arguments;
        }
        if ($command === 'remote' && ( $arguments[2] ?? '' ) === 'add') {
            if (count($arguments) < 5) {
                throw new InvalidArgumentException('Usage: reprint remote add NAME URL --fs-root=DIR [settings]');
            }
            self::validate_remote($arguments[3], $arguments[4]);
            $provided = [];
            \_cli_parse_options($arguments, count($arguments), 5, $definitions, $provided);
            $config = ['remote' => ['name' => $arguments[3], 'remote_reprint_api_url' => $arguments[4], 'options' => []], 'local' => []];
            foreach ($provided as $name => $value) {
                $definition = self::definition($name, $value, $definitions);
                if (isset($definition['config_path'])) {
                    $value = self::absolute_path($value, getcwd());
                }
                if ($definition['config_scope'] === 'remote') {
                    $config['remote']['options'][$name] = $value;
                } else {
                    $config['local'][$name] = $value;
                }
            }
            if (empty($config['local']['fs-root'])) {
                throw new InvalidArgumentException('remote add requires --fs-root=DIR.');
            }
            $config['local']['state-dir'] = $config['local']['state-dir'] ?? 'state';
            $state_dir = self::absolute_path($config['local']['state-dir'], dirname($config_file));
            self::validate_paths($config_file, $state_dir, $config['local']['fs-root']);
            if (!is_dir(dirname($config_file)) && !mkdir(dirname($config_file), 0700, true)) {
                throw new RuntimeException('Cannot create config directory: ' . dirname($config_file) . '.');
            }
            $this->lock_config($config_file);
            if (is_file($config_file)) {
                throw new RuntimeException('This config already has remote settings. Only one remote is supported.');
            }
            // A new named remote must not silently adopt another migration's dumps or cursors.
            $allowed_entries = $state_dir === dirname($config_file) ? [basename($config_file) . '.lock'] : [];
            if (is_dir($state_dir) && array_diff(scandir($state_dir), array_merge(['.', '..'], $allowed_entries)) !== []) {
                throw new RuntimeException('remote add requires an empty state directory: ' . $state_dir . '.');
            }
            self::write_config($config_file, $config);
            fwrite(STDOUT, 'Saved remote ' . $arguments[3] . ' in ' . $config_file . ". No request was sent.\n");
            return null;
        }
        if (!is_file($config_file)) {
            throw new RuntimeException('No Reprint config at ' . $config_file . '. Use reprint remote add NAME URL --fs-root=DIR.');
        }
        // Retain the lock through execution: set-url must not change settings under an open command.
        $this->lock_config($config_file);
        $config = json_decode(file_get_contents($config_file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config) || array_diff(array_keys($config), ['remote', 'local']) !== []
            || !isset($config['remote']['name'], $config['remote']['remote_reprint_api_url'], $config['remote']['options'], $config['local'])
            || !is_array($config['remote']['options']) || !is_array($config['local'])
            || array_diff(array_keys($config['remote']), ['name', 'remote_reprint_api_url', 'options']) !== []) {
            throw new InvalidArgumentException('Invalid Reprint config structure in ' . $config_file . '.');
        }
        self::validate_remote($config['remote']['name'], $config['remote']['remote_reprint_api_url']);
        $saved = [];
        foreach (['local' => $config['local'], 'remote' => $config['remote']['options']] as $scope => $settings) {
            foreach ($settings as $name => $value) {
                $definition = self::definition($name, $value, $definitions);
                if ($definition['config_scope'] !== $scope) {
                    throw new InvalidArgumentException('Config option ' . $name . ' belongs in ' . $definition['config_scope'] . '.');
                }
                $saved[$name] = isset($definition['config_path']) ? self::absolute_path($value, dirname($config_file)) : $value;
            }
        }
        if (!isset($saved['state-dir'], $saved['fs-root'])) {
            throw new InvalidArgumentException('Config local settings require state-dir and fs-root.');
        }
        self::validate_paths($config_file, $saved['state-dir'], $saved['fs-root']);
        $this->remote_state_directory = $saved['state-dir'] . '/remotes/' . $config['remote']['name'];
        $pull_state_file = $this->remote_state_directory . '/pull/state.json';
        $pull_state = is_file($pull_state_file) ? json_decode(file_get_contents($pull_state_file), true, 512, JSON_THROW_ON_ERROR) : [];
        if ($command === 'remote') {
            if (( $arguments[2] ?? '' ) !== 'set-url' || !isset($arguments[3], $arguments[4])
                || array_diff(array_slice($arguments, 5), ['--same-remote']) !== []) {
                throw new InvalidArgumentException('Usage: reprint remote set-url NAME URL [--same-remote]');
            }
            if ($arguments[3] !== $config['remote']['name']) {
                throw new InvalidArgumentException('No saved remote named ' . $arguments[3] . '.');
            }
            self::validate_remote($arguments[3], $arguments[4]);
            $state_lock = new ReprintProcessLock($saved['state-dir']);
            $pull_state = is_file($pull_state_file) ? json_decode(file_get_contents($pull_state_file), true, 512, JSON_THROW_ON_ERROR) : [];
            self::assert_no_transfer($this->remote_state_directory, $pull_state);
            $has_history = is_file($this->remote_state_directory . '/local_index.jsonl')
                || is_file($this->remote_state_directory . '/pull/remote-index.jsonl') || is_file($saved['state-dir'] . '/db.sql');
            if ($has_history && !in_array('--same-remote', $arguments, true)) {
                throw new RuntimeException('Saved sync work exists. Pass --same-remote only if the new address serves the same remote; use a separate config for another site.');
            }
            $config['remote']['remote_reprint_api_url'] = $arguments[4];
            self::write_config($config_file, $config);
            $state_lock->close();
            fwrite(STDOUT, 'Saved remote Reprint API URL: ' . $arguments[4] . ". Mappings and indexes are unchanged. Run preflight before transferring.\n");
            return null;
        }
        $show = $command === 'config';
        if ($show) {
            if (( $arguments[2] ?? '' ) !== 'show') {
                throw new InvalidArgumentException('Usage: reprint config show --command=COMMAND [options]');
            }
            $command = 'pull';
            $overrides = [];
            foreach (array_slice($arguments, 3) as $argument) {
                if (strpos($argument, '--command=') === 0) {
                    $command = substr($argument, 10);
                } else {
                    $overrides[] = $argument;
                }
            }
        } else {
            $overrides = array_slice($arguments, 2);
        }
        if (!in_array($command, ImportClient::COMMANDS, true)) {
            throw new InvalidArgumentException('Unknown configured command: ' . $command . '.');
        }
        $provided = [];
        $override_arguments = array_merge([$arguments[0], $command], $overrides);
        \_cli_parse_options($override_arguments, count($override_arguments), 2, $definitions, $provided);
        foreach (['state-dir', 'fs-root'] as $name) {
            if (isset($provided[$name]) && self::absolute_path($provided[$name], getcwd()) !== $saved[$name]) {
                throw new InvalidArgumentException('Cannot override ' . $name . ' for a saved remote. Use a separate config or an explicit URL.');
            }
        }
        $overridden_targets = [];
        foreach ($definitions as $definition) {
            if (array_key_exists($definition['name'], $provided)) {
                $overridden_targets[] = $definition['target'];
            }
        }
        if (isset($provided['secret']) || isset($provided['private-key-path'])) {
            $overridden_targets[] = 'secret_file';
        }
        if ($command === 'apply-runtime' && isset($provided['flat-document-root'])) {
            $overridden_targets[] = 'filesystem_root';
        }
        $effective_arguments = [$arguments[0], $command, $config['remote']['remote_reprint_api_url']];
        $origins = [];
        foreach ($saved as $name => $value) {
            $definition = self::definition($name, $value, $definitions);
            $applicable = $name === 'state-dir'
                || ( $name === 'fs-root' && !in_array($command, ['db-push', 'db-rewrite-urls', 'pull-metadata'], true) )
                || ( $name !== 'fs-root' && in_array($command, $definition['commands'] ?? [], true) );
            // Saved rewrites describe the local copy. Database push needs explicit local-to-hosted pairs.
            if ($name === 'rewrite-url' && $command === 'db-push') {
                $applicable = false;
            }
            if (!$applicable || in_array($definition['target'], $overridden_targets, true)) {
                continue;
            }
            $effective_arguments = array_merge($effective_arguments, self::option_arguments($definition, $value));
            $origins[$name] = $config_file;
        }
        $effective_arguments = array_merge($effective_arguments, $overrides);
        foreach ($provided as $name => $value) {
            $origins[$name] = 'command line';
        }
        if ($show) {
            [$state_dir, $filesystem_root, $options] = \_cli_parse_options($effective_arguments, count($effective_arguments), 3, $definitions);
            foreach (['secret', 'target_pass', 'mysql_password', 'source_pass'] as $name) {
                if (isset($options[$name])) {
                    $options[$name] = '[redacted]';
                }
            }
            fwrite(STDOUT, json_encode([
                'config_file' => $config_file,
                'remote' => array_diff_key($config['remote'], ['options' => true]),
                'state_dir' => $state_dir,
                'filesystem_root' => $filesystem_root,
                'remote_state_directory' => $this->remote_state_directory,
                'options' => $options,
                'origins' => $origins,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            return null;
        }
        if (isset($pull_state['preflight']['url']) && $pull_state['preflight']['url'] !== $config['remote']['remote_reprint_api_url']) {
            self::assert_no_transfer($this->remote_state_directory, $pull_state);
            if ($command === 'db-push') {
                throw new RuntimeException('The remote Reprint API URL changed. Run preflight at the saved address before transferring.');
            }
        }
        fwrite(STDERR, 'Config: ' . $config_file . ' | Remote: ' . $config['remote']['name'] . ' (' . $config['remote']['remote_reprint_api_url'] . ")\n");
        return $effective_arguments;
    }

    /** Release the retained config lock after command execution. */
    public function __destruct() {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    /** Config and state may share a directory, so they need distinct lock files. */
    private function lock_config(string $config_file): void {
        $this->lock = fopen($config_file . '.lock', 'c+b');
        if ($this->lock === false) {
            throw new RuntimeException('Cannot open the Reprint config lock: ' . $config_file . '.lock.');
        }
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            fclose($this->lock);
            $this->lock = null;
            throw new RuntimeException('Another Reprint process is using the config: ' . $config_file . '.');
        }
    }

    /**
     * Find and validate a setting using the CLI's option definitions.
     *
     * @param string  $name Canonical CLI option name.
     * @param mixed   $value JSON setting value.
     * @param array[] $definitions CLI option definitions.
     * @return array CLI definition with its config scope and value grammar.
     */
    private static function definition(string $name, $value, array $definitions): array {
        foreach ($definitions as $definition) {
            if ($definition['name'] !== $name || !isset($definition['config_scope'])) {
                continue;
            }
            $valid = false;
            if ($definition['type'] === 'flag') {
                $valid = $value === ( $definition['flag_value'] ?? true );
            } elseif ($definition['type'] === 'two-arguments') {
                $valid = is_array($value) && array_values($value) === $value;
                foreach ($valid ? $value : [] as $pair) {
                    $valid = $valid && is_array($pair) && array_keys($pair) === [0, 1] && is_string($pair[0]) && is_string($pair[1]);
                }
            } elseif (!empty($definition['repeatable'])) {
                $valid = is_array($value) && array_values($value) === $value;
                foreach ($valid ? $value : [] as $entry) {
                    $valid = $valid && is_string($entry);
                }
            } else {
                $valid = ( $definition['placeholder'] ?? null ) === 'PORT'
                    ? ( is_int($value) || is_string($value) ) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) !== false
                    : is_string($value);
            }
            if ($valid && isset($definition['valid_values'])) {
                $valid = in_array($value, $definition['valid_values'], true);
            }
            if ($valid) {
                return $definition;
            }
        }
        throw new InvalidArgumentException('Cannot save option ' . $name . ' with this value. Use a supported reusable setting; use --rewrite-url pairs instead of --new-site-url, and --secret-file instead of --secret.');
    }

    /**
     * Encode one validated setting for the existing parser, without a shell.
     *
     * @param array $definition CLI definition with name, type, and optional repeatable.
     * @param mixed $value Validated setting value.
     * @return string[] CLI arguments.
     */
    private static function option_arguments(array $definition, $value): array {
        $name = '--' . $definition['name'];
        if ($definition['type'] === 'flag') {
            return [$name];
        }
        $arguments = [];
        if ($definition['type'] === 'two-arguments') {
            foreach ($value as $pair) {
                array_push($arguments, $name, $pair[0], $pair[1]);
            }
        } else {
            foreach (!empty($definition['repeatable']) ? $value : [$value] as $entry) {
                $arguments[] = $name . '=' . $entry;
            }
        }
        return $arguments;
    }

    /** Validate the saved name and address without contacting it. */
    private static function validate_remote($name, $remote_reprint_api_url): void {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('A remote name must start with a lowercase letter and contain at most 64 lowercase letters, digits, underscores, or hyphens.');
        }
        $parts = is_string($remote_reprint_api_url) ? parse_url($remote_reprint_api_url) : false;
        $query_parameters = [];
        parse_str($parts['query'] ?? '', $query_parameters);
        if (!$parts || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || array_key_exists('SECRET_KEY', $query_parameters)) {
            throw new InvalidArgumentException('The remote Reprint API URL must be an HTTP or HTTPS address without URL credentials, SECRET_KEY, or a fragment.');
        }
    }

    /** Resolve a local setting without requiring its final directory to exist. */
    private static function absolute_path(string $local_path, string $base_directory): string {
        if ($local_path === '') {
            throw new InvalidArgumentException('A configured local path must not be empty.');
        }
        return Utils::realpath_with_missing_tail(
            Utils::is_absolute_path($local_path, Utils::native_path_format()) ? $local_path : $base_directory . '/' . $local_path
        );
    }

    /** Config and state must not become site content during pull or push. */
    private static function validate_paths(string $config_file, string $state_dir, string $filesystem_root): void {
        if (Utils::path_is_same_as_or_descendant_of(dirname($config_file), $filesystem_root)
            || Utils::path_is_same_as_or_descendant_of($state_dir, $filesystem_root)) {
            throw new InvalidArgumentException('The config and state directory must be outside the filesystem root.');
        }
    }

    /**
     * A new address must never receive another address's unfinished work.
     *
     * @param string $remote_state_directory Named remote state directory.
     * @param array  $pull_state Saved pull state, with active_resumable_command when present.
     */
    private static function assert_no_transfer(string $remote_state_directory, array $pull_state): void {
        $active = $pull_state['active_resumable_command'] ?? [];
        if (( !empty($active['command_name']) && ( $active['completion_state'] ?? null ) !== 'complete' )
            || is_file($remote_state_directory . '/pull/index.wal')
            || is_file($remote_state_directory . '/push/sender.json')
            || is_file($remote_state_directory . '/push/database/state.json')) {
            throw new RuntimeException('Cannot change the remote Reprint API URL while a transfer is unfinished. Finish or explicitly abort that operation first.');
        }
    }

    /**
     * Publish settings atomically while the config lock is held.
     *
     * @param string $config_file Config file to publish.
     * @param array  $config Local settings and one named remote.
     */
    private static function write_config(string $config_file, array $config): void {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $config_file . '.swap';
        if (file_put_contents($temporary, $json) !== strlen($json) || !chmod($temporary, 0600) || !rename($temporary, $config_file)) {
            throw new RuntimeException('Cannot save Reprint config to ' . $config_file . '.');
        }
    }
}
