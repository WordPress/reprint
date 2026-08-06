<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$site_export_test_plugin_dir = sys_get_temp_dir() . '/site-export-secret-test-' . getmypid() . '/';
if (!defined('SITE_EXPORT_PLUGIN_DIR')) {
    define('SITE_EXPORT_PLUGIN_DIR', $site_export_test_plugin_dir);
}
if (!defined('SITE_EXPORT_SECRET_FILE')) {
    define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
}

$GLOBALS['site_export_test_options'] = [];
$GLOBALS['site_export_registered_settings'] = [];
$GLOBALS['site_export_settings_errors'] = [];
$GLOBALS['site_export_test_actions'] = [];

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return SITE_EXPORT_PLUGIN_DIR;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return array_key_exists($name, $GLOBALS['site_export_test_options'])
            ? $GLOBALS['site_export_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        if (!array_key_exists($name, $GLOBALS['site_export_test_options'])) {
            $GLOBALS['site_export_test_options'][$name] = $value;
            foreach ($GLOBALS['site_export_test_actions']['add_option_' . $name] ?? [] as $action) {
                $args = array_slice([$name, $value], 0, $action['accepted_args']);
                call_user_func_array($action['callback'], $args);
            }
            return true;
        }

        $old_value = get_option($name);
        $GLOBALS['site_export_test_options'][$name] = $value;

        if ($old_value !== $value) {
            foreach ($GLOBALS['site_export_test_actions']['update_option_' . $name] ?? [] as $action) {
                $args = array_slice([$old_value, $value, $name], 0, $action['accepted_args']);
                call_user_func_array($action['callback'], $args);
            }
        }

        return true;
    }
}

if (!function_exists('register_setting')) {
    function register_setting(string $group, string $name, array $args = []): void {
        $GLOBALS['site_export_registered_settings'][$name] = [
            'group' => $group,
            'args' => $args,
        ];
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['site_export_test_actions'][$hook_name][] = [
            'callback' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('add_filter')) {
    function add_filter(...$args): void {}
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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress test stubs.
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
        $GLOBALS['site_export_settings_errors'][] = [
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

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string {
        return $file === '' ? '' : 'https://example.test/wp-content/plugins/reprint-exporter/';
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
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require_once __DIR__ . '/../reprint-exporter-wp/lib.php';
require_once __DIR__ . '/../reprint-exporter-wp/wordpress/site-export.php';

final class SiteExportSecretTest extends TestCase
{
    /** @var array<string, mixed> */
    private $original_server = [];

    /** @var array<string, mixed> */
    private $original_files = [];

    /** @var array<string, mixed> */
    private $original_post = [];

    /** @var string|false */
    private $original_push_enabled_environment;

    private string $standalone_push_root;

    private string $standalone_docroot;

    private string $standalone_reprint_directory;

    private string $standalone_push_configuration_path;

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
        $this->original_push_enabled_environment = getenv('SITE_EXPORT_PUSH_ENABLED');

        $GLOBALS['site_export_test_options'] = [];
        $GLOBALS['site_export_registered_settings'] = [];
        $GLOBALS['site_export_settings_errors'] = [];
        $_SERVER = [];
        $_FILES = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test resets request globals.
        $_POST = [];
        putenv('SITE_EXPORT_PUSH_ENABLED');

        $this->standalone_push_root = sys_get_temp_dir()
            . '/site-export-standalone-push-test-'
            . bin2hex(random_bytes(6));
        $docroot = $this->standalone_push_root . '/site';
        mkdir($docroot, 0700, true);
        $_SERVER['DOCUMENT_ROOT'] = $docroot;
        $canonical_docroot = realpath($docroot);
        $this->assertIsString($canonical_docroot);
        $this->standalone_docroot = $canonical_docroot;
        $this->standalone_reprint_directory = dirname($canonical_docroot)
            . '/.reprint-'
            . substr(hash('sha256', $canonical_docroot), 0, 12);
        $this->standalone_push_configuration_path = $this->standalone_reprint_directory
            . '/.reprint/push-config.json';

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
        if (isset($this->standalone_push_root)) {
            $this->removeTree($this->standalone_push_root);
        }

        $_SERVER = $this->original_server;
        $_FILES = $this->original_files;
        $_POST = $this->original_post;
        if ($this->original_push_enabled_environment === false) {
            putenv('SITE_EXPORT_PUSH_ENABLED');
        } else {
            putenv('SITE_EXPORT_PUSH_ENABLED=' . $this->original_push_enabled_environment);
        }

        parent::tearDown();
    }

    public function testSharedSecretFallsBackToOptionWhenSecretFileMissing(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-secret';

        $this->assertSame('option-secret', _site_export_get_shared_secret());
    }

    public function testSecretFileOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-secret';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-secret';\n");

        $this->assertSame('file-secret', _site_export_get_shared_secret());
    }

    public function testUpdatingSharedSecretOnlyTouchesTheSiteOption(): void
    {
        $this->assertTrue(_site_export_update_shared_secret('new-secret'));
        $this->assertSame('new-secret', $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION]);
        $this->assertFileDoesNotExist(SITE_EXPORT_SECRET_FILE);
    }

    public function testPluginRegistersSecretOptionForCoreSettingsRestEndpoint(): void
    {
        Site_Export_Plugin::get_instance()->register_settings();

        $setting = $GLOBALS['site_export_registered_settings'][SITE_EXPORT_SECRET_OPTION] ?? null;
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

        $this->assertNull(_site_export_verify_hmac($secret));
    }

    public function testPushAuthorizationMatchesOnlyTheCurrentConnectionToken(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertFalse(_site_export_is_push_authorized());

        $this->assertTrue(_site_export_update_push_authorization(true));
        $this->assertTrue(_site_export_is_push_authorized());

        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'rotated-token';
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testManagedEnvironmentOverridesLocalPushAuthorization(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        putenv('SITE_EXPORT_PUSH_ENABLED=true');
        $this->assertTrue(_site_export_is_push_authorized());

        $this->assertTrue(_site_export_update_push_authorization(true));
        putenv('SITE_EXPORT_PUSH_ENABLED=false');
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testPresentEmptyManagedEnvironmentFailsClosed(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        putenv('SITE_EXPORT_PUSH_ENABLED=');

        $this->assertFalse(_site_export_get_managed_push_enabled());
        $this->assertFalse(_site_export_is_push_authorized());
        $this->assertSame(
            'Push access is disabled by the hosting provider through SITE_EXPORT_PUSH_ENABLED.',
            _site_export_get_push_authorization_error()
        );
    }

    public function testSavingRotatedConnectionTokenRevokesPriorConsent(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        $_POST = [
            'site_export_save_settings' => '1',
            'site_export_secret' => 'rotated-token',
        ];
        Site_Export_Plugin::get_instance()->handle_settings_save();

        $this->assertSame('', $GLOBALS['site_export_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testRestSettingsTokenRotationPermanentlyRevokesPushAuthorization(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'token-a';
        $this->assertTrue(_site_export_update_push_authorization(true));
        Site_Export_Plugin::get_instance()->register_settings();

        update_option(SITE_EXPORT_SECRET_OPTION, 'token-b');
        update_option(SITE_EXPORT_SECRET_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['site_export_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testAddingAFormerSecretFileTokenCannotRestorePushAuthorization(): void
    {
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'token-a';\n");
        $this->assertTrue(_site_export_update_push_authorization(true));
        Site_Export_Plugin::get_instance()->register_settings();

        unlink(SITE_EXPORT_SECRET_FILE);
        $this->assertFalse(_site_export_is_push_authorized());
        update_option(SITE_EXPORT_SECRET_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['site_export_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(_site_export_is_push_authorized());
    }

    public function testRestSettingsOptionChangePreservesAuthorizationForSecretFileToken(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'option-token-a';
        file_put_contents(SITE_EXPORT_SECRET_FILE, "<?php return 'file-token';\n");
        $this->assertTrue(_site_export_update_push_authorization(true));
        Site_Export_Plugin::get_instance()->register_settings();

        update_option(SITE_EXPORT_SECRET_OPTION, 'option-token-b');

        $this->assertSame(
            hash('sha256', 'file-token'),
            $GLOBALS['site_export_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(_site_export_is_push_authorized());
    }

    public function testPushAccessFormAuthorizesTheCurrentConnectionToken(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $_POST = [
            'site_export_save_push_access' => '1',
            'site_export_push_enabled' => '1',
        ];

        Site_Export_Plugin::get_instance()->handle_settings_save();

        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['site_export_test_options'][SITE_EXPORT_PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(_site_export_is_push_authorized());
    }

    public function testPushAccessWritesPrivateStandaloneConfiguration(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        $this->assertTrue(_site_export_update_push_authorization(true));

        $this->assertFileExists($this->standalone_push_configuration_path);
        $this->assertSame(0600, fileperms($this->standalone_push_configuration_path) & 0777);
        $this->assertSame(
            [
                'connection_secret_b64' => base64_encode('current-token'),
                'push_authorization_error' => null,
                'docroot_b64' => base64_encode($this->standalone_docroot),
                'reprint_directory_b64' => base64_encode($this->standalone_reprint_directory),
                'excluded_paths_b64' => [],
            ],
            $this->readStandalonePushConfiguration()
        );
    }

    public function testRevokingPushAccessKeepsOnlyDurableCommitRecovery(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        $this->assertTrue(_site_export_update_push_authorization(false));

        $this->assertSame(
            [
                'connection_secret_b64' => base64_encode('current-token'),
                'push_authorization_error' => 'Push access is disabled for the current connection token.',
                'docroot_b64' => base64_encode($this->standalone_docroot),
                'reprint_directory_b64' => base64_encode($this->standalone_reprint_directory),
                'excluded_paths_b64' => [],
            ],
            $this->readStandalonePushConfiguration()
        );
    }

    public function testDownloadOnlyAdminCopyAndPushAccessForm(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads.</strong>', $html);
        $this->assertStringContainsString('The connection token cannot change files on this site.', $html);
        $this->assertStringContainsString('You do not need push access when moving this site to another host.', $html);
        $this->assertStringContainsString('Allow push to change files on this site', $html);
        $this->assertStringContainsString('except excluded paths.', $html);
        $this->assertStringNotContainsString('name="site_export_push_enabled" value="1" checked', $html);
    }

    public function testOptedInAdminCopyShowsCurrentTokenCanPush(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        $this->assertTrue(_site_export_update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads and push.</strong>', $html);
        $this->assertStringContainsString('name="site_export_push_enabled" value="1" checked', $html);
        $this->assertStringContainsString('<h2>Push endpoint</h2>', $html);
        $this->assertStringContainsString(
            'https://example.test/wp-content/plugins/reprint-exporter/push.php',
            $html
        );
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        $GLOBALS['site_export_test_options'][SITE_EXPORT_SECRET_OPTION] = 'current-token';
        putenv('SITE_EXPORT_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('name="site_export_push_enabled" value="1" checked disabled', $html);
        $this->assertStringNotContainsString('name="site_export_save_push_access"', $html);
    }

    private function renderAdminPage(): string
    {
        ob_start();
        Site_Export_Plugin::get_instance()->render_admin_page();
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function readStandalonePushConfiguration(): array
    {
        $configuration = json_decode(
            (string) file_get_contents($this->standalone_push_configuration_path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($configuration);
        return $configuration;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
