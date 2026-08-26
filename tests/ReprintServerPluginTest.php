<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Plugin\SettingsPage;

use function WordPress\Reprint\Server\Plugin\change_connection_token;
use function WordPress\Reprint\Server\Plugin\change_push_access;
use function WordPress\Reprint\Server\Plugin\get_configuration_state;
use function WordPress\Reprint\Server\Plugin\register_connection_token_setting;
use function WordPress\Reprint\Server\Plugin\register_wordpress_configuration;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$reprint_server_test_plugin_dir = sys_get_temp_dir() . '/reprint-server-plugin-test-' . getmypid() . '/';
if (!defined('SITE_EXPORT_PLUGIN_DIR')) {
    define('SITE_EXPORT_PLUGIN_DIR', $reprint_server_test_plugin_dir);
}
if (!defined('SITE_EXPORT_SECRET_FILE')) {
    define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
}

$GLOBALS['reprint_server_test_options'] = [];
$GLOBALS['reprint_server_registered_settings'] = [];
$GLOBALS['reprint_server_settings_errors'] = [];
$GLOBALS['reprint_server_settings_error_requests'] = [];
$GLOBALS['reprint_server_test_actions'] = [];
$GLOBALS['reprint_server_test_menu'] = null;
$GLOBALS['reprint_server_test_sections'] = [];
$GLOBALS['reprint_server_test_fields'] = [];
$GLOBALS['reprint_server_test_scripts'] = [];
$GLOBALS['reprint_server_fail_option_updates'] = [];

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound,WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress test stubs.
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return SITE_EXPORT_PLUGIN_DIR;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        if (array_key_exists($name, $GLOBALS['reprint_server_test_options'])) {
            return $GLOBALS['reprint_server_test_options'][$name];
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Generic WordPress option test stub.
        return apply_filters('default_option_' . $name, $default, $name, true);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        if (in_array($name, $GLOBALS['reprint_server_fail_option_updates'], true)) {
            return false;
        }

        if (!array_key_exists($name, $GLOBALS['reprint_server_test_options'])) {
            $GLOBALS['reprint_server_test_options'][$name] = $value;
            foreach ($GLOBALS['reprint_server_test_actions']['add_option_' . $name] ?? [] as $action) {
                $args = array_slice([$name, $value], 0, $action['accepted_args']);
                call_user_func_array($action['callback'], $args);
            }
            return true;
        }

        $old_value = get_option($name);
        $GLOBALS['reprint_server_test_options'][$name] = $value;

        if ($old_value !== $value) {
            foreach ($GLOBALS['reprint_server_test_actions']['update_option_' . $name] ?? [] as $action) {
                $args = array_slice([$old_value, $value, $name], 0, $action['accepted_args']);
                call_user_func_array($action['callback'], $args);
            }
        }

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool {
        if (!array_key_exists($name, $GLOBALS['reprint_server_test_options'])) {
            return false;
        }

        unset($GLOBALS['reprint_server_test_options'][$name]);
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

if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, $callback, string $page): void {
        $GLOBALS['reprint_server_test_sections'][$page][$id] = [
            'title' => $title,
            'callback' => $callback,
        ];
    }
}

if (!function_exists('add_settings_field')) {
    function add_settings_field(
        string $id,
        string $title,
        $callback,
        string $page,
        string $section,
        array $args = []
    ): void {
        $GLOBALS['reprint_server_test_fields'][$page][$section][$id] = [
            'title' => $title,
            'callback' => $callback,
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

if (!function_exists('add_management_page')) {
    function add_management_page($page_title, $menu_title, $capability, $menu_slug, $callback): string {
        $GLOBALS['reprint_server_test_menu'] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
        ];
        return 'tools_page_' . $menu_slug;
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
    function set_transient(...$args): void {}
}

if (!function_exists('get_transient')) {
    function get_transient(...$args): bool {
        return false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(...$args): void {}
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(...$args): void {}
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string {
        return esc_attr($text);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return $capability === 'manage_options';
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): void {}
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

if (!function_exists('get_settings_errors')) {
    function get_settings_errors(string $setting = '', bool $sanitize = false): array {
        $GLOBALS['reprint_server_settings_error_requests'][] = $setting;
        if ($setting === '') {
            return $GLOBALS['reprint_server_settings_errors'];
        }

        return array_values(
            array_filter(
                $GLOBALS['reprint_server_settings_errors'],
                static function(array $notice) use ($setting): bool {
                    return $notice['setting'] === $setting;
                }
            )
        );
    }
}

if (!function_exists('settings_fields')) {
    function settings_fields(string $group): void {
        echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '" />';
    }
}

if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void {
        foreach ($GLOBALS['reprint_server_test_sections'][$page] ?? [] as $section_id => $section) {
            echo '<h2>' . esc_html($section['title']) . '</h2>';
            call_user_func($section['callback']);
            echo '<table class="form-table">';
            foreach ($GLOBALS['reprint_server_test_fields'][$page][$section_id] ?? [] as $field) {
                echo '<tr><th>' . esc_html($field['title']) . '</th><td>';
                call_user_func($field['callback'], $field['args']);
                echo '</td></tr>';
            }
            echo '</table>';
        }
    }
}

if (!function_exists('submit_button')) {
    function submit_button(
        string $text = 'Save Changes',
        string $type = 'primary',
        string $name = 'submit',
        bool $wrap = true
    ): void {
        $button = '<input type="submit" name="' . esc_attr($name) . '" class="button button-'
            . esc_attr($type) . '" value="' . esc_attr($text) . '" />';
        echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('get_admin_page_title')) {
    function get_admin_page_title(): string {
        return 'Reprint Server';
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true): string {
        $result = $checked == $current ? ' checked="checked"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('disabled')) {
    function disabled($disabled, $current = true, bool $display = true): string {
        $result = $disabled == $current ? ' disabled="disabled"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string {
        return 'https://example.test/wp-content/plugins/reprint-server/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src, $dependencies, $version, $in_footer): void {
        $GLOBALS['reprint_server_test_scripts'][$handle] = compact(
            'src',
            'dependencies',
            'version',
            'in_footer'
        );
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
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

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string {
        return $value;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $value): string {
        return esc_attr($value);
    }
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require_once __DIR__ . '/../reprint-server-wp/lib.php';
require_once __DIR__ . '/../reprint-server-wp/wordpress/configuration.php';
register_wordpress_configuration();
require_once __DIR__ . '/../reprint-server-wp/wordpress/reprint-server.php';

final class ReprintServerPluginTest extends TestCase
{
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

        if (!is_dir(SITE_EXPORT_PLUGIN_DIR)) {
            mkdir(SITE_EXPORT_PLUGIN_DIR, 0755, true);
        }

        $this->original_server = $_SERVER;
        $this->original_files = $_FILES;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $this->original_post = $_POST;
        $this->original_get = $_GET;
        $this->original_push_enabled_environment = getenv('SITE_EXPORT_PUSH_ENABLED');
        $this->original_reprint_server_push_enabled_environment = getenv('REPRINT_SERVER_PUSH_ENABLED');

        $GLOBALS['reprint_server_test_options'] = [];
        $GLOBALS['reprint_server_registered_settings'] = [];
        $GLOBALS['reprint_server_settings_errors'] = [];
        $GLOBALS['reprint_server_settings_error_requests'] = [];
        $GLOBALS['reprint_server_test_menu'] = null;
        $GLOBALS['reprint_server_test_sections'] = [];
        $GLOBALS['reprint_server_test_fields'] = [];
        $GLOBALS['reprint_server_test_scripts'] = [];
        $GLOBALS['reprint_server_fail_option_updates'] = [];
        $_SERVER = [];
        $_FILES = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $_POST = [];
        $_GET = [];
        putenv('SITE_EXPORT_PUSH_ENABLED');
        putenv('REPRINT_SERVER_PUSH_ENABLED');

        if (file_exists(SITE_EXPORT_SECRET_FILE)) {
            unlink(SITE_EXPORT_SECRET_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(SITE_EXPORT_SECRET_FILE)) {
            unlink(SITE_EXPORT_SECRET_FILE);
        }

        if (is_dir(SITE_EXPORT_PLUGIN_DIR)) {
            rmdir(SITE_EXPORT_PLUGIN_DIR);
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
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-token';

        $this->assertSame('option-token', _site_export_get_shared_secret());
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
            SITE_EXPORT_SECRET_FILE
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

    public function testSecretFileConnectionTokenOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-token';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-token';\n");

        $this->assertSame('file-token', _site_export_get_shared_secret());
    }

    public function testUpdatingConnectionTokenOnlyTouchesTheSiteOption(): void
    {
        $this->assertTrue(_site_export_update_shared_secret('new-token'));
        $this->assertSame('new-token', $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION]);
        $this->assertFileDoesNotExist(SITE_EXPORT_SECRET_FILE);
    }

    public function testConnectionTokenOperationReturnsExplicitOutcomes(): void
    {
        $this->assertSame('saved', change_connection_token('new-token'));
        $this->assertSame('unchanged', change_connection_token('new-token'));

        $GLOBALS['reprint_server_fail_option_updates'][] = SITE_EXPORT_SECRET_OPTION;
        $this->assertSame('storage_failure', change_connection_token('other-token'));
    }

    public function testPluginRegistersConnectionTokenOptionForCoreSettingsRestEndpoint(): void
    {
        register_connection_token_setting();

        $setting = $GLOBALS['reprint_server_registered_settings'][SITE_EXPORT_SECRET_OPTION] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame('reprint_server', $setting['group']);
        $this->assertTrue($setting['args']['show_in_rest']);
        $this->assertSame('string', $setting['args']['type']);
        $this->assertSame('', $setting['args']['default']);
    }

    public function testConfigurationIntegrationRegistersOutsideTheAdministratorAdapter(): void
    {
        $rest_hooks = $GLOBALS['reprint_server_test_actions']['rest_api_init'] ?? [];
        $update_hooks = $GLOBALS['reprint_server_test_actions']['update_option_' . SITE_EXPORT_SECRET_OPTION] ?? [];
        $add_hooks = $GLOBALS['reprint_server_test_actions']['add_option_' . SITE_EXPORT_SECRET_OPTION] ?? [];

        $this->assertNotEmpty($rest_hooks);
        $this->assertSame(
            'WordPress\\Reprint\\Server\\Plugin\\register_connection_token_setting',
            $rest_hooks[0]['callback']
        );
        $this->assertNotEmpty($update_hooks);
        $this->assertSame(
            'WordPress\\Reprint\\Server\\Plugin\\revoke_push_authorization_after_connection_token_change',
            $update_hooks[0]['callback']
        );
        $this->assertNotEmpty($add_hooks);
        $this->assertSame(
            'WordPress\\Reprint\\Server\\Plugin\\revoke_push_authorization_after_connection_token_added',
            $add_hooks[0]['callback']
        );
    }

    public function testPluginHmacVerifierDelegatesToPackageServer(): void
    {
        $connection_token = 'delegated-token';
        $nonce = '0123456789abcdef0123456789abcdef';
        $client = new Site_Export_HMAC_Client($connection_token);
        $timestamp = $client->get_timestamp();
        $content_hash = hash('sha256', '');

        $_SERVER['HTTP_X_AUTH_SIGNATURE'] = $client->compute_signature($nonce, $timestamp, $content_hash);
        $_SERVER['HTTP_X_AUTH_NONCE'] = $nonce;
        $_SERVER['HTTP_X_AUTH_TIMESTAMP'] = $timestamp;
        $_SERVER['HTTP_X_AUTH_CONTENT_HASH'] = $content_hash;

        $this->assertNull(_site_export_verify_hmac($connection_token));
    }

    public function testPushAuthorizationMatchesOnlyTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertFalse(_site_export_is_push_authorized());

        $this->assertTrue(_site_export_update_push_authorization(true));
        $this->assertTrue(_site_export_is_push_authorized());
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );

        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'rotated-token';
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testManagedEnvironmentOverridesLocalPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        $this->assertTrue(_site_export_is_push_authorized());

        $this->assertTrue(_site_export_update_push_authorization(true));
        putenv('SITE_EXPORT_PUSH_ENABLED=false');
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testCanonicalManagedEnvironmentTakesPrecedenceOverLegacyEnvironment(): void
    {
        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');

        $this->assertFalse(_site_export_get_managed_push_enabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCanonicalGlobalManagedConstantTakesPrecedenceOverCanonicalEnvironment(): void
    {
        define('REPRINT_SERVER_PUSH_ENABLED', false);
        putenv('REPRINT_SERVER_PUSH_ENABLED=true');

        $this->assertFalse(_site_export_get_managed_push_enabled());
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

        $this->assertTrue(_site_export_get_managed_push_enabled());
    }

    public function testPresentEmptyManagedEnvironmentFailsClosed(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        putenv('SITE_EXPORT_PUSH_ENABLED=');

        $this->assertFalse(_site_export_get_managed_push_enabled());
        $this->assertFalse(_site_export_is_push_authorized());
        $this->assertSame(
            'Push access is disabled by the hosting provider through REPRINT_SERVER_PUSH_ENABLED.',
            _site_export_get_push_authorization_error()
        );
    }

    public function testSettingsApiTokenRotationRevokesPriorConsent(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        update_option(SITE_EXPORT_SECRET_OPTION, 'rotated-token');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testRestSettingsConnectionTokenRotationPermanentlyRevokesPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'token-a';
        $this->assertTrue(_site_export_update_push_authorization(true));

        update_option(SITE_EXPORT_SECRET_OPTION, 'token-b');
        update_option(SITE_EXPORT_SECRET_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testAddingAFormerSecretFileConnectionTokenCannotRestorePushAuthorization(): void
    {
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'token-a';\n");
        $this->assertTrue(_site_export_update_push_authorization(true));

        unlink(SITE_EXPORT_SECRET_FILE);
        $this->assertFalse(_site_export_is_push_authorized());
        update_option(SITE_EXPORT_SECRET_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testRestSettingsOptionChangePreservesAuthorizationForSecretFileConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-token-a';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-token';\n");
        $this->assertTrue(_site_export_update_push_authorization(true));

        update_option(SITE_EXPORT_SECRET_OPTION, 'option-token-b');

        $this->assertSame(
            hash('sha256', 'file-token'),
            $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(_site_export_is_push_authorized());
    }

    public function testPushAccessOperationAuthorizesTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        $this->assertSame('saved', change_push_access(true));
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(_site_export_is_push_authorized());
    }

    public function testPushAccessOperationReturnsExplicitOutcomes(): void
    {
        $this->assertSame('not_configured', change_push_access(true));

        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertSame('saved', change_push_access(true));
        $this->assertSame('unchanged', change_push_access(true));

        putenv('SITE_EXPORT_PUSH_ENABLED=false');
        $this->assertSame('managed', change_push_access(false));
    }

    public function testPushAccessOperationReportsStorageFailure(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $GLOBALS['reprint_server_fail_option_updates'][] = SITE_EXPORT_PUSH_AUTHORIZATION_OPTION;

        $this->assertSame('storage_failure', change_push_access(true));
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testConfigurationStateDescribesTheEffectiveConnection(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        $configuration = get_configuration_state();

        $this->assertSame('current-token', $configuration['stored_connection_token']);
        $this->assertTrue($configuration['is_configured']);
        $this->assertFalse($configuration['has_secret_file']);
        $this->assertTrue($configuration['push_supported']);
        $this->assertNull($configuration['managed_push_enabled']);
        $this->assertFalse($configuration['push_enabled']);
    }

    public function testBundledAdministratorRegistersUnderTools(): void
    {
        $plugin = SettingsPage::get_instance();
        $plugin->add_admin_menu();

        $this->assertSame('reprint-server', $GLOBALS['reprint_server_test_menu']['menu_slug']);
        $this->assertSame('manage_options', $GLOBALS['reprint_server_test_menu']['capability']);
        $this->assertSame(
            'WordPress\\Reprint\\Server\\Plugin\\SettingsPage',
            SettingsPage::class
        );
        $this->assertFalse(class_exists('Site_Export_Plugin', false));

        $links = $plugin->add_settings_link([]);
        $this->assertStringContainsString('tools.php?page=reprint-server', $links[0]);

        $admin_post_hooks = $GLOBALS['reprint_server_test_actions']['admin_post_reprint_server_save_push_access'] ?? [];
        $this->assertNotEmpty($admin_post_hooks);
        $this->assertSame([$plugin, 'handle_push_access_save'], $admin_post_hooks[0]['callback']);
        $this->assertEmpty($GLOBALS['reprint_server_test_actions']['admin_bar_menu'] ?? []);

        $plugin->enqueue_assets('tools_page_other');
        $this->assertSame([], $GLOBALS['reprint_server_test_scripts']);

        $plugin->enqueue_assets('tools_page_reprint-server');
        $this->assertSame(
            ['wp-a11y'],
            $GLOBALS['reprint_server_test_scripts']['reprint-server-admin']['dependencies']
        );
    }

    public function testNewWordPressAdapterDeclaresOnlyCurrentNamespacedSymbols(): void
    {
        $configuration_source = file_get_contents(
            __DIR__ . '/../reprint-server-wp/wordpress/configuration.php'
        );
        $administrator_source = file_get_contents(
            __DIR__ . '/../reprint-server-wp/wordpress/reprint-server.php'
        );
        $administrator_script = file_get_contents(
            __DIR__ . '/../reprint-server-wp/wordpress/reprint-server.js'
        );
        $this->assertIsString($configuration_source);
        $this->assertIsString($administrator_source);
        $this->assertIsString($administrator_script);
        $this->assertStringContainsString(
            'namespace WordPress\\Reprint\\Server\\Plugin;',
            $configuration_source
        );
        $this->assertDoesNotMatchRegularExpression('/function\\s+_site_export/', $configuration_source);
        $this->assertStringContainsString(
            'namespace WordPress\\Reprint\\Server\\Plugin;',
            $administrator_source
        );
        $this->assertStringContainsString('class SettingsPage', $administrator_source);
        $this->assertStringNotContainsString('class Site_Export', $administrator_source);
        $this->assertStringContainsString('get_settings_errors()', $administrator_source);
        $this->assertDoesNotMatchRegularExpression('/(?<!get_)settings_errors\s*\(/', $administrator_source);
        $this->assertStringNotContainsString('ob_start()', $administrator_source);
        $this->assertStringNotContainsString('str_replace(', $administrator_source);
        $this->assertStringNotContainsString('site-export', $administrator_source);
        $this->assertStringNotContainsString('site_export', $administrator_source);
        $this->assertStringNotContainsString('site-export', $administrator_script);
        $this->assertStringNotContainsString('site_export', $administrator_script);
    }

    public function testUnconfiguredAdminShowsOnlyConnectionTokenSetup(): void
    {
        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Not configured yet.</strong>', $html);
        $this->assertStringContainsString('Enter a connection token to get started.', $html);
        $this->assertStringContainsString('id="reprint_server_connection_token"', $html);
        $this->assertStringContainsString('name="' . SITE_EXPORT_SECRET_OPTION . '"', $html);
        $this->assertStringNotContainsString('<h2>Push access</h2>', $html);
        $this->assertStringNotContainsString('id="reprint-server-api-url"', $html);
    }

    public function testDownloadOnlyAdminCopyAndPushAccessForm(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('notice notice-info inline', $html);
        $this->assertStringContainsString('<strong>Connected for downloads.</strong>', $html);
        $this->assertStringContainsString('The connection token cannot change files on this site.', $html);
        $this->assertStringNotContainsString('notice notice-success inline', $html);
        $this->assertStringContainsString('You do not need push access when moving this site to another host.', $html);
        $this->assertStringContainsString('Allow push to change files on this site', $html);
        $this->assertStringContainsString('except excluded paths.', $html);
        $this->assertStringContainsString('<form method="post" action="options.php">', $html);
        $this->assertStringContainsString('name="option_page" value="reprint_server"', $html);
        $this->assertStringContainsString('action="https://example.test/wp-admin/admin-post.php"', $html);
        $this->assertSame(2, substr_count($html, '<p class="submit">'));
        $this->assertSame([''], $GLOBALS['reprint_server_settings_error_requests']);
        $this->assertStringNotContainsString('checked="checked"', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testCoreSettingsNoticeIsInlineAtItsRenderedPosition(): void
    {
        $GLOBALS['reprint_server_settings_errors'][] = [
            'setting' => 'general',
            'code' => 'settings_updated',
            'message' => 'Settings saved.',
            'type' => 'updated',
        ];

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('id="setting-error-settings_updated"', $html);
        $this->assertStringContainsString(
            'notice notice-success settings-error is-dismissible inline',
            $html
        );
        $this->assertStringNotContainsString(
            'notice notice-success settings-error is-dismissible"',
            $html
        );
        $this->assertStringContainsString('<p>Settings saved.</p>', $html);
        $this->assertStringNotContainsString('<strong>Settings saved.</strong>', $html);
    }

    public function testCoreSettingsNoticeFallsBackToErrorForAnUnknownType(): void
    {
        $GLOBALS['reprint_server_settings_errors'][] = [
            'setting' => 'general',
            'code' => 'unexpected_type',
            'message' => 'Something needs attention.',
            'type' => 'unexpected',
        ];

        $html = $this->renderAdminPage();

        $this->assertStringContainsString(
            'notice notice-error settings-error is-dismissible inline',
            $html
        );
        $this->assertStringContainsString('<p>Something needs attention.</p>', $html);
    }

    public function testOptedInAdminCopyShowsCurrentConnectionTokenCanPush(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads and push.</strong>', $html);
        $this->assertStringContainsString('notice notice-info inline', $html);
        $this->assertStringNotContainsString('notice notice-success inline', $html);
        $this->assertStringContainsString('name="reprint_server_push_enabled"', $html);
        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        putenv('SITE_EXPORT_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('checked="checked" disabled="disabled"', $html);
        $this->assertStringNotContainsString('action="reprint_server_save_push_access"', $html);
    }

    public function testManagedDisabledAdminCopyIsReadOnlyAndUnchecked(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        putenv('SITE_EXPORT_PUSH_ENABLED=false');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('value="1" disabled="disabled"', $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
        $this->assertStringNotContainsString('action="reprint_server_save_push_access"', $html);
    }

    public function testSecretFileOverrideShowsStoredOptionAndWarning(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'stored-token';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-token';\n");

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<code>secret.php</code> override is active.', $html);
        $this->assertStringContainsString('value="stored-token"', $html);
        $this->assertStringContainsString('Remove secret.php to use the stored option value.', $html);
    }

    public function testUnsupportedPushStateUsesANativeNoticeWithoutAForm(): void
    {
        $configuration = [
            'stored_connection_token' => 'current-token',
            'is_configured' => true,
            'has_secret_file' => false,
            'push_supported' => false,
            'managed_push_enabled' => null,
            'push_enabled' => false,
        ];
        $method = new ReflectionMethod(SettingsPage::class, 'render_push_access_form');

        ob_start();
        $method->invoke(SettingsPage::get_instance(), $configuration);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice notice-warning inline', $html);
        $this->assertStringContainsString('Push access requires PHP 7.2 or newer.', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testAdministratorMapsEveryPushAccessResultToANativeNotice(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $expected_notices = [
            'saved' => ['notice-success', 'Push access updated.'],
            'unchanged' => ['notice-success', 'Push access was already up to date.'],
            'unsupported' => ['notice-error', 'Push access requires PHP 7.2 or newer.'],
            'managed' => ['notice-info', 'Push access is managed by your hosting provider.'],
            'not_configured' => ['notice-error', 'Configure a connection token before enabling push access.'],
            'storage_failure' => ['notice-error', 'Failed to save push access.'],
        ];

        foreach ($expected_notices as $result => [$notice_class, $message]) {
            $_GET['reprint_server_notice'] = $result;
            $html = $this->renderAdminPage();

            $this->assertStringContainsString($notice_class, $html, $result);
            $this->assertStringContainsString($message, $html, $result);
            $this->assertStringContainsString(
                $notice_class . ' is-dismissible inline',
                $html,
                $result
            );
        }
    }

    public function testSettingsSaveSupersedesAStalePushAccessNotice(): void
    {
        $GLOBALS['reprint_server_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $GLOBALS['reprint_server_settings_errors'][] = [
            'setting' => 'general',
            'code' => 'settings_updated',
            'message' => 'Settings saved.',
            'type' => 'updated',
        ];
        $_GET['settings-updated'] = 'true';
        $_GET['reprint_server_notice'] = 'saved';

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Settings saved.', $html);
        $this->assertStringNotContainsString('<strong>Settings saved.</strong>', $html);
        $this->assertStringNotContainsString('Push access updated.', $html);
    }

    private function renderAdminPage(): string
    {
        SettingsPage::get_instance()->register_settings_fields();
        ob_start();
        SettingsPage::get_instance()->render_admin_page();
        return (string) ob_get_clean();
    }
}
