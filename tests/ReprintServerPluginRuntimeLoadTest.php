<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The settings page reads the host rule and the enrolled keys through server
 * classes, but only the API dispatch path loaded the server runtime. A real
 * install has no Composer autoloader registered when wp-admin renders the
 * page, so this test boots the plugin in a fresh PHP process without
 * vendor/autoload.php, the way WordPress does.
 *
 * The PHPUnit bootstrap registers the autoloader for every in-process test,
 * which is why the plugin tests never observed the fatal error.
 */
final class ReprintServerPluginRuntimeLoadTest extends TestCase {
    private const PLUGIN_DIRECTORY = __DIR__ . '/../reprint-server-wp/';

    public static function setUpBeforeClass(): void
    {
        $bundled_utils = self::PLUGIN_DIRECTORY . 'vendor/wp-php-toolkit/reprint-server/src/class-utils.php';
        if (!file_exists($bundled_utils)) {
            self::markTestSkipped(
                'reprint-server-wp/vendor/ is missing; run composer install --no-dev --working-dir=reprint-server-wp.'
            );
        }
    }

    public function testSettingsPageReadsAndEnrollsKeysWithoutAPreloadedAutoloader(): void
    {
        $result = $this->runPluginWithoutAutoloader();

        $this->assertStringNotContainsString('not found', $result['output'], $result['output']);
        $this->assertSame(0, $result['status'], $result['output']);

        $report = json_decode($result['output'], true);
        $this->assertIsArray($report, $result['output']);
        $this->assertSame('key', $report['required_scheme']);
        $this->assertSame([], $report['enrolled_keys_before']);
        $this->assertSame('saved', $report['enrollment']);
        $this->assertCount(1, $report['enrolled_keys_after']);
        $this->assertSame($report['last_enrolled_key_id'], $report['enrolled_keys_after'][0]['key_id']);
        $this->assertSame('settings page', $report['enrolled_keys_after'][0]['label']);
        $this->assertSame('saved', $report['push_grant']);
        // A key host refuses to remove its last key; the refusal needs the host rule from the runtime.
        $this->assertSame('last_key', $report['removal']);
        $this->assertCount(1, $report['enrolled_keys_after_removal']);
    }

    /**
     * @return array{output:string,status:int}
     */
    private function runPluginWithoutAutoloader(): array
    {
        $state_directory = sys_get_temp_dir() . '/reprint-server-runtime-load-' . uniqid();
        mkdir($state_directory, 0755, true);

        $php_code = <<<'PHP'
define('PLUGIN_DIRECTORY', (string) getenv('REPRINT_TEST_PLUGIN_DIRECTORY'));
define('PLUGIN_STATE_DIRECTORY', (string) getenv('REPRINT_TEST_STATE_DIRECTORY'));
define('ABSPATH', PLUGIN_STATE_DIRECTORY);
define('WordPress\Reprint\Server\Plugin\PLUGIN_DIR', PLUGIN_DIRECTORY);
define('WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_FILE', PLUGIN_STATE_DIRECTORY . 'secret.php');
define('WordPress\Reprint\Server\Plugin\PUBLIC_KEYS_FILE', PLUGIN_STATE_DIRECTORY . 'public-keys.php');
$GLOBALS['subprocess_options'] = [];
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function is_multisite(): bool { return false; }
function get_option(string $name, $default = false) { return $GLOBALS['subprocess_options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['subprocess_options'][$name] = $value; return true; }
function add_action(...$arguments): void {}
function add_filter(...$arguments): void {}
function apply_filters(string $hook_name, $value) { return $value; }
function register_setting(...$arguments): void {}
require PLUGIN_DIRECTORY . 'lib.php';
require PLUGIN_DIRECTORY . 'wordpress/configuration.php';
if (class_exists('WordPress\Reprint\Server\Utils', false)) {
    fwrite(STDERR, "Utils was loaded before the settings page asked for it\n");
    exit(2);
}
$before = WordPress\Reprint\Server\Plugin\get_configuration_state();
$private_key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$public_key_pem = openssl_pkey_get_details($private_key)['key'];
$enrollment = WordPress\Reprint\Server\Plugin\enroll_public_key($public_key_pem, 'settings page');
$last_enrolled_key_id = WordPress\Reprint\Server\Plugin\get_last_enrolled_key_id();
$after = WordPress\Reprint\Server\Plugin\get_configuration_state();
$push_grant = WordPress\Reprint\Server\Plugin\change_key_push_access((string) $last_enrolled_key_id, true);
$removal = WordPress\Reprint\Server\Plugin\remove_public_key((string) $last_enrolled_key_id);
echo json_encode([
    'required_scheme' => $before['required_scheme'],
    'enrolled_keys_before' => $before['enrolled_keys'],
    'enrollment' => $enrollment,
    'last_enrolled_key_id' => $last_enrolled_key_id,
    'enrolled_keys_after' => $after['enrolled_keys'],
    'push_grant' => $push_grant,
    'removal' => $removal,
    'enrolled_keys_after_removal' => WordPress\Reprint\Server\Plugin\get_enrolled_public_keys(),
]);
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'error_reporting=' . E_ALL,
            '-d', 'auto_prepend_file=',
            '-r', $php_code,
        ];
        $environment = array_merge(getenv(), [
            'REPRINT_TEST_PLUGIN_DIRECTORY' => realpath(self::PLUGIN_DIRECTORY) . '/',
            'REPRINT_TEST_STATE_DIRECTORY' => $state_directory . '/',
        ]);

        try {
            $process = proc_open($command, $descriptors, $pipes, $state_directory, $environment);
            if (!is_resource($process)) {
                $this->fail('Failed to spawn the PHP subprocess.');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            return [
                'output' => ( $stdout ?: '' ) . ( $stderr ?: '' ),
                'status' => $status,
            ];
        } finally {
            rmdir($state_directory);
        }
    }
}
