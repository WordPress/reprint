<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Plugin\SettingsPage;

use const WordPress\Reprint\Server\Plugin\PLUGIN_DIR;
use const WordPress\Reprint\Server\Plugin\PUSH_AUTHORIZATION_OPTION;
use const WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_FILE;
use const WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_OPTION;

use function WordPress\Reprint\Server\Plugin\get_connection_token;
use function WordPress\Reprint\Server\Plugin\get_managed_push_enabled;
use function WordPress\Reprint\Server\Plugin\get_push_authorization_error;
use function WordPress\Reprint\Server\Plugin\is_push_authorized;
use function WordPress\Reprint\Server\Plugin\update_connection_token;
use function WordPress\Reprint\Server\Plugin\update_push_authorization;
use function WordPress\Reprint\Server\Plugin\verify_hmac;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress test stubs.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$reprint_server_test_plugin_dir = sys_get_temp_dir() . '/reprint-server-plugin-test-' . getmypid() . '/';
if (!defined('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR')) {
    define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', $reprint_server_test_plugin_dir);
}
if (!defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE')) {
    define(
        'WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE',
        $reprint_server_test_plugin_dir . 'secret.php'
    );
}

$GLOBALS['reprint_server_test_options'] = [];
$GLOBALS['reprint_server_registered_settings'] = [];
$GLOBALS['reprint_server_settings_errors'] = [];
$GLOBALS['reprint_server_checked_nonce_action'] = null;
$GLOBALS['reprint_server_test_redirect'] = null;
$GLOBALS['reprint_server_test_transients'] = [];
$GLOBALS['reprint_server_test_admin_menu'] = null;
$GLOBALS['reprint_server_test_actions'] = [];

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return PLUGIN_DIR;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        if (array_key_exists($name, $GLOBALS['reprint_server_test_options'])) {
            return $GLOBALS['reprint_server_test_options'][$name];
        }
        return apply_filters('default_option_' . $name, $default, $name, true);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        if (!array_key_exists($name, $GLOBALS['reprint_server_test_options'])) {
            $GLOBALS['reprint_server_test_options'][$name] = $value;
            do_action('add_option_' . $name, $name, $value);
            return true;
        }

        $old_value = get_option($name);
        $GLOBALS['reprint_server_test_options'][$name] = $value;

        if ($old_value !== $value) {
            do_action('update_option_' . $name, $old_value, $value, $name);
        }

        return true;
    }
}

if (!function_exists('register_setting')) {
function register_setting(string $group, string $name, array $args = []): void {
        $GLOBALS['reprint_server_registered_settings'][$name] = [
            'group' => $group,
            'args' => $args,
        ];
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['reprint_server_test_actions'][$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['reprint_test_filters'][$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook_name, $callback, int $priority = 10): void {
        foreach ($GLOBALS['reprint_test_filters'][$hook_name] ?? [] as $index => $filter) {
            if ($filter['callback'] === $callback && $filter['priority'] === $priority) {
                unset($GLOBALS['reprint_test_filters'][$hook_name][$index]);
            }
        }
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, ...$args): void {
        $actions = $GLOBALS['reprint_server_test_actions'][$hook_name] ?? [];
        usort($actions, static function (array $left, array $right): int {
            return $left['priority'] <=> $right['priority'];
        });
        foreach ($actions as $action) {
            call_user_func_array(
                $action['callback'],
                array_slice($args, 0, $action['accepted_args'])
            );
        }
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename($file);
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$args): void {}
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return true;
    }
}

if (!function_exists('set_transient')) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
    function set_transient(string $name, $value, int $expiration = 0): bool {
        $GLOBALS['reprint_server_test_transients'][$name] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $name) {
        return $GLOBALS['reprint_server_test_transients'][$name] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool {
        unset($GLOBALS['reprint_server_test_transients'][$name]);
        return true;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): void {
        $GLOBALS['reprint_server_test_redirect'] = $location;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test catches and compares the URL.
        throw new RuntimeException($location);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(...$args): void {
        $GLOBALS['reprint_server_test_admin_menu'] = $args;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return $url;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return $capability === 'manage_options';
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): void {
        $GLOBALS['reprint_server_checked_nonce_action'] = $action;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string {
        return trim( (string) $value );
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('add_settings_error')) {
function add_settings_error(string $setting, string $code, string $message, string $type): void {
        $GLOBALS['reprint_server_settings_errors'][] = [
            'setting' => $setting,
            'code' => $code,
            'message' => $message,
            'type' => $type,
        ];
    }
}

if (!function_exists('settings_errors')) {
    function settings_errors(string $setting): void {}
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action): void {
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action) . '" />';
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

require_once __DIR__ . '/../reprint-server-wp/compat.php';
reprint_server_bootstrap_compatibility();
require_once __DIR__ . '/../reprint-server-wp/lib.php';
require_once __DIR__ . '/../reprint-server-wp/wordpress/reprint-server.php';

final class ReprintServerPluginTest extends TestCase {
    /** @var array<string, mixed> */
    private $original_server = [];

    /** @var array<string, mixed> */
    private $original_files = [];

    /** @var array<string, mixed> */
    private $original_post = [];

    /** @var array<string, mixed> */
    private $original_get = [];

    /** @var string|false */
    private $original_push_enabled_environment;

    /** @var string|false */
    private $original_reprint_server_push_enabled_environment;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(PLUGIN_DIR)) {
            mkdir(PLUGIN_DIR, 0755, true);
        }

        $this->original_server = $_SERVER;
        $this->original_files = $_FILES;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $this->original_post = $_POST;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test resets request globals.
        $this->original_get = $_GET;
        $this->original_push_enabled_environment = getenv('SITE_EXPORT_PUSH_ENABLED');
        $this->original_reprint_server_push_enabled_environment = getenv('REPRINT_SERVER_PUSH_ENABLED');

        $GLOBALS['reprint_server_test_options'] = [];
        $GLOBALS['reprint_server_registered_settings'] = [];
        $GLOBALS['reprint_server_settings_errors'] = [];
        $GLOBALS['reprint_server_checked_nonce_action'] = null;
        $GLOBALS['reprint_server_test_redirect'] = null;
        $GLOBALS['reprint_server_test_transients'] = [];
        $GLOBALS['reprint_server_test_admin_menu'] = null;
        $_SERVER = [];
        $_FILES = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $_POST = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test resets request globals.
        $_GET = [];
        putenv('SITE_EXPORT_PUSH_ENABLED');
        putenv('REPRINT_SERVER_PUSH_ENABLED');

        if (file_exists(CONNECTION_TOKEN_FILE)) {
            unlink(CONNECTION_TOKEN_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(CONNECTION_TOKEN_FILE)) {
            unlink(CONNECTION_TOKEN_FILE);
        }

        if (is_dir(PLUGIN_DIR)) {
            rmdir(PLUGIN_DIR);
        }

        $_SERVER = $this->original_server;
        $_FILES = $this->original_files;
        $_POST = $this->original_post;
        $_GET = $this->original_get;
        if ($this->original_push_enabled_environment === false) {
            putenv('SITE_EXPORT_PUSH_ENABLED');
        } else {
            putenv('SITE_EXPORT_PUSH_ENABLED=' . $this->original_push_enabled_environment);
        }
        if ($this->original_reprint_server_push_enabled_environment === false) {
            putenv('REPRINT_SERVER_PUSH_ENABLED');
        } else {
            putenv('REPRINT_SERVER_PUSH_ENABLED=' . $this->original_reprint_server_push_enabled_environment);
        }

        parent::tearDown();
    }

    public function testConnectionTokenFallsBackToOptionWhenSecretFileMissing(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token';

        $this->assertSame('option-token', get_connection_token());
    }

    public function testLegacyOptionValuesRemainVisibleBeforeMigration(): void
    {
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] =
            hash('sha256', 'legacy-token');

        $this->assertSame('legacy-token', get_connection_token());
        $this->assertTrue(is_push_authorized());
    }

    public function testCanonicalPluginSymbolsArePrimaryAndReleasedCompatibilityNamesRemainAvailable(): void
    {
        $this->assertTrue(defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE'));
        $this->assertTrue(defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION'));
        $this->assertSame(
            'reprint_server_connection_token',
            constant('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION')
        );
        $this->assertFalse(defined('WordPress\\Reprint\\Server\\Plugin\\SECRET_OPTION'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\handle_api_request'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\get_connection_token'));
        $this->assertTrue(function_exists('WordPress\\Reprint\\Server\\Plugin\\update_connection_token'));
        $this->assertFalse(function_exists('WordPress\\Reprint\\Server\\Plugin\\get_shared_secret'));
        $this->assertTrue(class_exists('WordPress\\Reprint\\Server\\Plugin\\SettingsPage'));
        $this->assertTrue(function_exists('_site_export_handle_api_request'));
        $this->assertTrue(class_exists('Site_Export_Plugin'));
        $this->assertTrue(defined('SITE_EXPORT_SECRET_OPTION'));
        $this->assertSame(CONNECTION_TOKEN_OPTION, SITE_EXPORT_SECRET_OPTION);
        $this->assertSame(CONNECTION_TOKEN_FILE, SITE_EXPORT_SECRET_FILE);
    }

    public function testLegacyAdminIncludeAndClassNameResolveToCanonicalSettingsPage(): void
    {
        require_once __DIR__ . '/../reprint-server-wp/wordpress/site-export.php';

        $this->assertTrue(class_exists('Site_Export_Plugin'));
        $this->assertSame(SettingsPage::get_instance(), \Site_Export_Plugin::get_instance());
    }

    public function testCompatibilityNamesAreConfinedToTheCompatibilityModule(): void
    {
        $canonical_files = [
            __DIR__ . '/../reprint-server-wp/index.php',
            __DIR__ . '/../reprint-server-wp/lib.php',
            __DIR__ . '/../reprint-server-wp/wordpress/reprint-server.php',
        ];
        foreach ($canonical_files as $canonical_file) {
            $canonical_source = file_get_contents($canonical_file);
            $this->assertIsString($canonical_source);
            $this->assertStringNotContainsString('SITE_EXPORT', $canonical_source);
            $this->assertStringNotContainsString('site_export', $canonical_source);
            $this->assertStringNotContainsString('site-export', $canonical_source);
            $this->assertStringNotContainsString('Site_Export', $canonical_source);
        }

        $compatibility_source = file_get_contents(__DIR__ . '/../reprint-server-wp/compat.php');
        $this->assertIsString($compatibility_source);
        $this->assertStringContainsString('site-export-api', $compatibility_source);
        $this->assertStringContainsString('site_export_api_options', $compatibility_source);
        $this->assertStringContainsString('Site_Export_Plugin', $compatibility_source);
    }

    public function testLegacyOptionsMigrateWithoutChangingTheirValues(): void
    {
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] = 'legacy-fingerprint';

        do_action('plugins_loaded');

        $this->assertSame(
            'legacy-token',
            $GLOBALS['reprint_server_test_options']['reprint_server_connection_token']
        );
        $this->assertSame(
            'legacy-fingerprint',
            $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint']
        );
    }

    public function testCanonicalOptionsTakePrecedenceDuringLegacyMigration(): void
    {
        $GLOBALS['reprint_server_test_options']['site_export_secret'] = 'legacy-token';
        $GLOBALS['reprint_server_test_options']['reprint_server_connection_token'] = 'canonical-token';
        $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint'] =
            'legacy-fingerprint';
        $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint'] =
            'canonical-fingerprint';

        do_action('plugins_loaded');

        $this->assertSame(
            'canonical-token',
            $GLOBALS['reprint_server_test_options']['reprint_server_connection_token']
        );
        $this->assertSame(
            'canonical-fingerprint',
            $GLOBALS['reprint_server_test_options']['reprint_server_push_authorized_token_fingerprint']
        );
    }

    public function testLegacyOptionWritesStayVisibleThroughCanonicalOptions(): void
    {
        SettingsPage::get_instance();

        update_option('site_export_secret', 'legacy-write');
        update_option('site_export_push_authorized_token_fingerprint', 'legacy-fingerprint');

        $this->assertSame('legacy-write', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
        $this->assertSame(
            'legacy-fingerprint',
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
    }

    public function testLegacySettingsRequestIsNormalizedBeforeCanonicalHandling(): void
    {
        $_POST = [
            'site_export_save_settings' => '1',
            'site_export_secret' => 'legacy-form-token',
        ];

        do_action('plugins_loaded');
        do_action('admin_init');

        $this->assertSame('site_export_settings', $GLOBALS['reprint_server_checked_nonce_action']);
        $this->assertSame(
            'legacy-form-token',
            $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]
        );
    }

    public function testLegacyPushAccessRequestIsNormalizedBeforeCanonicalHandling(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $_POST = [
            'site_export_save_push_access' => '1',
            'site_export_push_enabled' => '1',
        ];

        do_action('plugins_loaded');
        do_action('admin_init');

        $this->assertSame('site_export_settings', $GLOBALS['reprint_server_checked_nonce_action']);
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
    }

    public function testLegacyAdminPageRedirectsToCanonicalSlug(): void
    {
        $_GET['page'] = 'site-export';

        try {
            do_action('admin_init');
            $this->fail('The legacy settings page must redirect.');
        } catch (RuntimeException $redirect) {
            $this->assertSame(
                'https://example.test/wp-admin/admin.php?page=reprint-server',
                $redirect->getMessage()
            );
        }
    }

    public function testLegacyActivationTransientContinuesCanonicalRedirect(): void
    {
        $GLOBALS['reprint_server_test_transients']['site_export_activated'] = 1;

        try {
            do_action('admin_init');
            $this->fail('The migrated activation transient must redirect.');
        } catch (RuntimeException $redirect) {
            $this->assertSame(
                'https://example.test/wp-admin/admin.php?page=reprint-server',
                $redirect->getMessage()
            );
        }

        $this->assertArrayNotHasKey('site_export_activated', $GLOBALS['reprint_server_test_transients']);
        $this->assertArrayNotHasKey('reprint_server_activated', $GLOBALS['reprint_server_test_transients']);
    }

    public function testCanonicalAdminUsesCanonicalSlugFieldsNonceAndSettingsErrors(): void
    {
        $settings_page = SettingsPage::get_instance();
        $links = $settings_page->add_settings_link([]);
        $this->assertSame(
            '<a href="https://example.test/wp-admin/admin.php?page=reprint-server">Settings</a>',
            $links[0]
        );

        $settings_page->add_admin_menu();
        $this->assertSame('reprint-server', $GLOBALS['reprint_server_test_admin_menu'][3]);

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('name="reprint_server_connection_token"', $html);
        $this->assertStringNotContainsString('name="reprint_server_secret"', $html);

        $_POST = [
            'reprint_server_save_settings' => '1',
            'reprint_server_connection_token' => 'canonical-token',
        ];
        $settings_page->handle_settings_save();

        $this->assertSame('reprint_server_settings', $GLOBALS['reprint_server_checked_nonce_action']);
        $this->assertSame('reprint_server', $GLOBALS['reprint_server_settings_errors'][0]['setting']);
    }

    public function testCanonicalManagedEnvironmentTakesPrecedenceOverLegacyEnvironment(): void
    {
        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');

        $this->assertFalse(\WordPress\Reprint\Server\Plugin\get_managed_push_enabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCanonicalGlobalManagedConstantTakesPrecedenceOverCanonicalEnvironment(): void
    {
        define('REPRINT_SERVER_PUSH_ENABLED', false);
        putenv('REPRINT_SERVER_PUSH_ENABLED=true');

        $this->assertFalse(get_managed_push_enabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNamespacedManagedConstantTakesPrecedenceOverGlobalConfiguration(): void
    {
        define('WordPress\\Reprint\\Server\\Plugin\\PUSH_ENABLED', true);
        define('REPRINT_SERVER_PUSH_ENABLED', false);
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');

        $this->assertTrue(get_managed_push_enabled());
    }

    public function testLegacySecretFileConnectionTokenOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token';
        file_put_contents(CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");

        $this->assertSame('file-token', get_connection_token());
    }

    public function testUpdatingConnectionTokenWritesCanonicalAndLegacyOptions(): void
    {
        $this->assertTrue(update_connection_token('new-token'));
        $this->assertSame('new-token', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
        $this->assertSame('new-token', $GLOBALS['reprint_server_test_options']['site_export_secret']);
        $this->assertFileDoesNotExist(CONNECTION_TOKEN_FILE);
    }

    public function testPluginRegistersConnectionTokenOptionForCoreSettingsRestEndpoint(): void
    {
        SettingsPage::get_instance()->register_settings();

        $setting = $GLOBALS['reprint_server_registered_settings'][CONNECTION_TOKEN_OPTION] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame('general', $setting['group']);
        $this->assertTrue($setting['args']['show_in_rest']);
        $this->assertSame('string', $setting['args']['type']);
        $this->assertSame('', $setting['args']['default']);
    }

    public function testPluginHmacVerifierDelegatesToPackageServer(): void
    {
        $secret = 'delegated-secret';
        $nonce = '0123456789abcdef0123456789abcdef';
        $client = new Site_Export_HMAC_Client($secret);
        $timestamp = $client->get_timestamp();
        $content_hash = hash('sha256', '');

        $_SERVER['HTTP_X_AUTH_SIGNATURE'] = $client->compute_signature($nonce, $timestamp, $content_hash);
        $_SERVER['HTTP_X_AUTH_NONCE'] = $nonce;
        $_SERVER['HTTP_X_AUTH_TIMESTAMP'] = $timestamp;
        $_SERVER['HTTP_X_AUTH_CONTENT_HASH'] = $content_hash;

        $this->assertNull(verify_hmac($secret));
    }

    public function testPushAuthorizationMatchesOnlyTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertFalse(is_push_authorized());

        $this->assertTrue(update_push_authorization(true));
        $this->assertTrue(is_push_authorized());
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options']['site_export_push_authorized_token_fingerprint']
        );

        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'rotated-token';
        $this->assertFalse(is_push_authorized());
    }

    public function testManagedEnvironmentOverridesLocalPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        $this->assertTrue(is_push_authorized());

        $this->assertTrue(update_push_authorization(true));
        putenv('SITE_EXPORT_PUSH_ENABLED=false');
        $this->assertFalse(is_push_authorized());
    }

    public function testPresentEmptyManagedEnvironmentFailsClosed(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        putenv('SITE_EXPORT_PUSH_ENABLED=');

        $this->assertFalse(get_managed_push_enabled());
        $this->assertFalse(is_push_authorized());
        $this->assertSame(
            'Push access is disabled by the hosting provider through REPRINT_SERVER_PUSH_ENABLED.',
            get_push_authorization_error()
        );
    }

    public function testSavingRotatedConnectionTokenRevokesPriorConsent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $_POST = [
            'reprint_server_save_settings' => '1',
            'reprint_server_connection_token' => 'rotated-token',
        ];
        SettingsPage::get_instance()->handle_settings_save();

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testRestSettingsConnectionTokenRotationPermanentlyRevokesPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'token-a';
        $this->assertTrue(update_push_authorization(true));
        SettingsPage::get_instance()->register_settings();

        update_option(CONNECTION_TOKEN_OPTION, 'token-b');
        update_option(CONNECTION_TOKEN_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testAddingAFormerSecretFileConnectionTokenCannotRestorePushAuthorization(): void
    {
        file_put_contents(CONNECTION_TOKEN_FILE, "<?php return 'token-a';\n");
        $this->assertTrue(update_push_authorization(true));
        SettingsPage::get_instance()->register_settings();

        unlink(CONNECTION_TOKEN_FILE);
        $this->assertFalse(is_push_authorized());
        update_option(CONNECTION_TOKEN_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testRestSettingsOptionChangePreservesAuthorizationForSecretFileConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token-a';
        file_put_contents(CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");
        $this->assertTrue(update_push_authorization(true));
        SettingsPage::get_instance()->register_settings();

        update_option(CONNECTION_TOKEN_OPTION, 'option-token-b');

        $this->assertSame(
            hash('sha256', 'file-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(is_push_authorized());
    }

    public function testPushAccessFormAuthorizesTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $_POST = [
            'reprint_server_save_push_access' => '1',
            'reprint_server_push_enabled' => '1',
        ];

        SettingsPage::get_instance()->handle_settings_save();

        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(is_push_authorized());
    }

    public function testDownloadOnlyAdminCopyAndPushAccessForm(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads.</strong>', $html);
        $this->assertStringContainsString('The connection token cannot change files on this site.', $html);
        $this->assertStringContainsString('You do not need push access when moving this site to another host.', $html);
        $this->assertStringContainsString('Allow push to change files on this site', $html);
        $this->assertStringContainsString('except excluded paths.', $html);
        $this->assertStringNotContainsString('name="reprint_server_push_enabled" value="1" checked', $html);
    }

    public function testOptedInAdminCopyShowsCurrentConnectionTokenCanPush(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads and push.</strong>', $html);
        $this->assertStringContainsString('name="reprint_server_push_enabled" value="1" checked', $html);
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        putenv('SITE_EXPORT_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('name="reprint_server_push_enabled" value="1" checked disabled', $html);
        $this->assertStringNotContainsString('name="reprint_server_save_push_access"', $html);
    }

    private function renderAdminPage(): string
    {
        ob_start();
        SettingsPage::get_instance()->render_admin_page();
        return (string) ob_get_clean();
    }
}
