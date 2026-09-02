<?php

declare(strict_types=1);

/**
 * Backwards compatibility for the released Site Export names.
 *
 * Every test here exists only because compat.php exists: the released
 * SITE_EXPORT_* constants, the released _site_export_*() functions, the
 * legacy option migration, and the legacy push-policy environment variable.
 * Nothing in the canonical suite depends on any of these names.
 *
 * TODO: Delete this file together with reprint-server-wp/compat.php after
 * September 2026, as it should no longer be relevant by then.
 */

use WordPress\Reprint\Server\Plugin\SettingsPage;

use const WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_OPTION;
use const WordPress\Reprint\Server\Plugin\PUSH_AUTHORIZATION_OPTION;

require_once __DIR__ . '/lib/ReprintServerPluginTestCase.php';

final class ReprintServerCompatTest extends ReprintServerPluginTestCase
{
    /** @var string|false */
    private $original_legacy_push_enabled_environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_legacy_push_enabled_environment = getenv('SITE_EXPORT_PUSH_ENABLED');
        putenv('SITE_EXPORT_PUSH_ENABLED');
    }

    protected function tearDown(): void
    {
        if ($this->original_legacy_push_enabled_environment === false) {
            putenv('SITE_EXPORT_PUSH_ENABLED');
        } else {
            putenv('SITE_EXPORT_PUSH_ENABLED=' . $this->original_legacy_push_enabled_environment);
        }

        parent::tearDown();
    }

    public function testMigrationCreatesNoOptionsWhenNoLegacyOptionExists(): void
    {
        reprint_server_compat_migrate_legacy_options();

        $this->assertSame([], $GLOBALS['reprint_server_test_options']);
    }

    public function testMigrationKeepsPushAuthorizationGrantedUnderTheLegacyOptionNames(): void
    {
        // The plugin migrates before the settings page registers its
        // listeners. Registering them first models a project which embeds
        // lib.php later in the request, where the listener revoking
        // authorization for the appearing connection token runs during the
        // migration.
        \WordPress\Reprint\Server\Plugin\SettingsPage::get_instance();
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] =
            hash('sha256', 'legacy-token');

        reprint_server_compat_migrate_legacy_options();

        $this->assertSame(
            hash('sha256', 'legacy-token'),
            $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertArrayNotHasKey(
            'site_export_push_authorized_token_fingerprint',
            $GLOBALS['reprint_server_test_options']
        );
        $this->assertTrue(_site_export_is_push_authorized());
    }

    public function testCanonicalPluginSymbolsArePrimaryAndReleasedCompatibilityNamesRemainAvailable(): void
    {
        $this->assertTrue(defined('WordPress\\Reprint\\Server\\Plugin\\VERSION'));
        $this->assertTrue(defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE'));
        $this->assertTrue(defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION'));
        $this->assertSame(
            'reprint_server_connection_token',
            constant('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION')
        );
        $this->assertSame(
            constant('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION'),
            SITE_EXPORT_SECRET_OPTION
        );
        $this->assertSame(
            constant('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE'),
            REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE
        );
        $this->assertFalse(defined('WordPress\\Reprint\\Server\\Plugin\\SECRET_OPTION'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\handle_api_request'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\get_connection_token'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\update_connection_token'));
        $this->assertFalse(function_exists('WordPress\\Reprint\\Server\\Plugin\\get_shared_secret'));
        $this->assertTrue(function_exists('_site_export_handle_api_request'));
        $this->assertTrue(function_exists('_site_export_get_shared_secret'));
        $this->assertFalse(class_exists('Site_Export_Plugin'));
        $this->assertTrue(class_exists('WordPress\\Reprint\\Server\\Plugin\\SettingsPage'));
    }

    public function testLegacyOptionsMigrateWithoutChangingTheirValues(): void
    {
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] =
            'legacy-fingerprint';

        reprint_server_compat_migrate_legacy_options();

        $this->assertSame(
            'legacy-token',
            $GLOBALS['reprint_server_test_options']['reprint_server_connection_token']
        );
        $this->assertSame(
            'legacy-fingerprint',
            $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint']
        );
        $this->assertArrayNotHasKey('site_export_secret', $GLOBALS['reprint_server_test_options']);
        $this->assertArrayNotHasKey(
            'site_export_push_authorized_token_fingerprint',
            $GLOBALS['reprint_server_test_options']
        );
        $this->assertSame('legacy-token', _site_export_get_shared_secret());
    }

    public function testCanonicalOptionsTakePrecedenceDuringLegacyMigration(): void
    {
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['reprint_server_connection_token'] = 'canonical-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] =
            'legacy-fingerprint';
        $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint'] =
            'canonical-fingerprint';

        reprint_server_compat_migrate_legacy_options();

        $this->assertSame(
            'canonical-token',
            $GLOBALS['reprint_server_test_options']['reprint_server_connection_token']
        );
        $this->assertSame(
            'canonical-fingerprint',
            $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint']
        );
        $this->assertArrayNotHasKey('site_export_secret', $GLOBALS['reprint_server_test_options']);
        $this->assertArrayNotHasKey(
            'site_export_push_authorized_token_fingerprint',
            $GLOBALS['reprint_server_test_options']
        );
    }

    public function testCanonicalManagedEnvironmentTakesPrecedenceOverLegacyEnvironment(): void
    {
        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');

        $this->assertFalse(_site_export_get_managed_push_enabled());
    }

    /**
     * Every released constant must keep resolving to its canonical value.
     *
     * Driven off compat.php's own map so a new entry there cannot be added
     * without being covered here.
     */
    public function testEveryReleasedConstantMirrorsItsCanonicalCounterpart(): void
    {
        $constant_map = reprint_server_compat_constants();
        $this->assertNotEmpty($constant_map);

        foreach ($constant_map as $released_name => $canonical_name) {
            $this->assertTrue(
                defined($canonical_name),
                "Canonical constant {$canonical_name} must be defined"
            );
            $this->assertTrue(
                defined($released_name),
                "Released constant {$released_name} must remain defined"
            );
            $this->assertSame(
                constant($canonical_name),
                constant($released_name),
                "{$released_name} must mirror {$canonical_name}"
            );
        }
    }

    /**
     * Every released function must still exist and delegate to the canonical
     * runtime API rather than carrying its own copy of the behaviour.
     */
    public function testEveryReleasedFunctionDelegatesToTheCanonicalRuntimeApi(): void
    {
        $released_functions = [
            '_site_export_error',
            '_site_export_push_error',
            '_site_export_is_push_endpoint',
            '_site_export_push_is_supported',
            '_site_export_load_exporter_runtime',
            '_site_export_has_secret_file',
            '_site_export_get_file_secret',
            '_site_export_get_option_secret',
            '_site_export_get_shared_secret',
            '_site_export_update_shared_secret',
            '_site_export_get_managed_push_enabled',
            '_site_export_is_push_authorized',
            '_site_export_get_push_authorization_error',
            '_site_export_update_push_authorization',
            '_site_export_verify_hmac',
            '_site_export_default_authenticate',
            '_site_export_handle_api_request',
        ];

        foreach ($released_functions as $released_function) {
            $this->assertTrue(
                function_exists($released_function),
                "Released function {$released_function}() must remain available"
            );
        }

        // Spot-check that the wrappers read and write the same state as the
        // canonical API, rather than merely existing.
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'released-token';
        $this->assertSame('released-token', _site_export_get_shared_secret());
        $this->assertSame('released-token', _site_export_get_option_secret());

        $this->assertTrue(_site_export_update_shared_secret('rotated-token'));
        $this->assertSame(
            'rotated-token',
            $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]
        );

        $this->assertFalse(_site_export_is_push_authorized());
        $this->assertTrue(_site_export_update_push_authorization(true));
        $this->assertTrue(_site_export_is_push_authorized());
        $this->assertSame(
            hash('sha256', 'rotated-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
    }

    /**
     * A platform which still defines SITE_EXPORT_* from a must-use plugin keeps
     * its values: the canonical constants are adopted from the released ones.
     *
     * Runs in a subprocess because constants cannot be redefined in-process,
     * and the canonical suite deliberately seeds only the canonical names.
     */
    public function testPlatformDefinedLegacyConstantsAreAdoptedAsCanonical(): void
    {
        $lib_path = realpath(__DIR__ . '/../reprint-server-wp/lib.php');
        $this->assertNotFalse($lib_path, 'lib.php must exist');
        $plugin_directory = dirname($lib_path) . '/';

        $php_code = '<?php' . "\n"
            . 'define(\'ABSPATH\', __DIR__ . \'/\');' . "\n"
            . 'define(\'SITE_EXPORT_PLUGIN_DIR\', '
            . var_export($plugin_directory, true) . ');' . "\n"
            . 'define(\'SITE_EXPORT_SECRET_FILE\', \'/platform/token.php\');' . "\n"
            . 'define(\'SITE_EXPORT_SECRET_OPTION\', \'platform_token_option\');' . "\n"
            . 'define(\'SITE_EXPORT_TIMESTAMP_TOLERANCE\', 42);' . "\n"
            . 'function plugin_dir_path($file) { return dirname($file) . \'/\'; }' . "\n"
            . 'require ' . var_export($lib_path, true) . ';' . "\n"
            . 'echo json_encode(['. "\n"
            . '    \'token_file\' => constant(\'WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\CONNECTION_TOKEN_FILE\'),' . "\n"
            . '    \'token_option\' => constant(\'WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\CONNECTION_TOKEN_OPTION\'),' . "\n"
            . '    \'tolerance\' => constant(\'WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\TIMESTAMP_TOLERANCE\'),' . "\n"
            . '], JSON_THROW_ON_ERROR);' . "\n";

        $result = $this->runPhpCode($php_code);

        $this->assertSame(0, $result['status'], $result['output']);
        $adopted = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/platform/token.php', $adopted['token_file']);
        $this->assertSame('platform_token_option', $adopted['token_option']);
        $this->assertSame(42, $adopted['tolerance']);
    }

    /**
     * @return array{status:int,output:string}
     */
    private function runPhpCode(string $php_code): array
    {
        $tmp_dir = sys_get_temp_dir() . '/reprint-server-compat-test-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            file_put_contents($tmp_dir . '/run.php', $php_code);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open([PHP_BINARY, $tmp_dir . '/run.php'], $descriptors, $pipes, $tmp_dir);
            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['status' => proc_close($process), 'output' => $output];
        } finally {
            @unlink($tmp_dir . '/run.php');
            @rmdir($tmp_dir);
        }
    }
}
