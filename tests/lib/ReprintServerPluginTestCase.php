<?php

declare(strict_types=1);

/**
 * Shared harness for the bundled WordPress plugin tests.
 *
 * Boots the plugin against the canonical Reprint Server runtime API only, so
 * the suite keeps working unchanged when compat.php is deleted. Backwards
 * compatibility is covered separately by ReprintServerCompatTest.
 */

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Plugin\SettingsPage;

use function WordPress\Reprint\Server\Plugin\register_wordpress_configuration;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Test-owned paths, named independently of the plugin's own constants so the
// tests never reach for a compat.php alias.
if (!defined('REPRINT_SERVER_TEST_PLUGIN_DIR')) {
    define(
        'REPRINT_SERVER_TEST_PLUGIN_DIR',
        sys_get_temp_dir() . '/reprint-server-plugin-test-' . getmypid() . '/'
    );
}
if (!defined('REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE')) {
    define('REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE', REPRINT_SERVER_TEST_PLUGIN_DIR . 'secret.php');
}

// Seed the canonical namespaced constants lib.php would otherwise derive, so
// the plugin boots without compat.php adopting any SITE_EXPORT_* name.
if (!defined('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR')) {
    define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', REPRINT_SERVER_TEST_PLUGIN_DIR);
}
if (!defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE')) {
    define(
        'WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE',
        REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE
    );
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
        return REPRINT_SERVER_TEST_PLUGIN_DIR;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return !empty($GLOBALS['reprint_server_test_multisite']);
    }
}
if (!function_exists('get_site_option')) {
    function get_site_option(string $name, $default = false) {
        return $GLOBALS['reprint_server_test_network_options'][$name] ?? $default;
    }
}
if (!function_exists('update_site_option')) {
    function update_site_option(string $name, $value): bool {
        $GLOBALS['reprint_server_test_network_options'][$name] = $value;
        return true;
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

require_once __DIR__ . '/../../reprint-server-wp/lib.php';
require_once __DIR__ . '/../../reprint-server-wp/wordpress/configuration.php';
register_wordpress_configuration();
require_once __DIR__ . '/../../reprint-server-wp/wordpress/reprint-server.php';
abstract class ReprintServerPluginTestCase extends TestCase
{
    /** @var array<string, mixed> */
    protected $original_server = [];

    /** @var array<string, mixed> */
    protected $original_files = [];

    /** @var array<string, mixed> */
    protected $original_post = [];

    /** @var array<string, mixed> */
    protected $original_get = [];

    /** @var string|false */
    protected $original_reprint_server_push_enabled_environment;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(REPRINT_SERVER_TEST_PLUGIN_DIR)) {
            mkdir(REPRINT_SERVER_TEST_PLUGIN_DIR, 0755, true);
        }

        $this->original_server = $_SERVER;
        $this->original_files = $_FILES;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $this->original_post = $_POST;
        $this->original_get = $_GET;
        $this->original_reprint_server_push_enabled_environment = getenv('REPRINT_SERVER_PUSH_ENABLED');

        $GLOBALS['reprint_server_test_multisite'] = false;
        $GLOBALS['reprint_server_test_network_options'] = [];
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
        putenv('REPRINT_SERVER_PUSH_ENABLED');

        if (file_exists(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE)) {
            unlink(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE)) {
            unlink(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE);
        }

        if (is_dir(REPRINT_SERVER_TEST_PLUGIN_DIR)) {
            rmdir(REPRINT_SERVER_TEST_PLUGIN_DIR);
        }

        $_SERVER = $this->original_server;
        $_FILES = $this->original_files;
        $_POST = $this->original_post;
        $_GET = $this->original_get;
        if ($this->original_reprint_server_push_enabled_environment === false) {
            putenv('REPRINT_SERVER_PUSH_ENABLED');
        } else {
            putenv('REPRINT_SERVER_PUSH_ENABLED=' . $this->original_reprint_server_push_enabled_environment);
        }

        $GLOBALS['reprint_server_test_multisite'] = false;
        parent::tearDown();
    }

    protected function renderAdminPage(): string
    {
        SettingsPage::get_instance()->register_settings_fields();
        ob_start();
        SettingsPage::get_instance()->render_admin_page();
        return (string) ob_get_clean();
    }
}
