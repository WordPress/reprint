<?php

declare(strict_types=1);

/**
 * Bundled WordPress plugin behaviour, exercised through the canonical
 * Reprint Server runtime API only.
 *
 * Backwards compatibility for the released SITE_EXPORT_* constants and
 * _site_export_*() functions lives in ReprintServerCompatTest, which is
 * deleted together with compat.php.
 */

use WordPress\Reprint\Server\Plugin\SettingsPage;

use function WordPress\Reprint\Server\Plugin\change_connection_token;
use function WordPress\Reprint\Server\Plugin\change_push_access;
use function WordPress\Reprint\Server\Plugin\get_configuration_state;
use function WordPress\Reprint\Server\Plugin\get_connection_token;
use function WordPress\Reprint\Server\Plugin\get_managed_push_enabled;
use function WordPress\Reprint\Server\Plugin\get_push_authorization_error;
use function WordPress\Reprint\Server\Plugin\is_push_authorized;
use function WordPress\Reprint\Server\Plugin\register_connection_token_setting;
use function WordPress\Reprint\Server\Plugin\update_connection_token;
use function WordPress\Reprint\Server\Plugin\update_push_authorization;
use function WordPress\Reprint\Server\Plugin\verify_hmac;

use const WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_OPTION;
use const WordPress\Reprint\Server\Plugin\PUSH_AUTHORIZATION_OPTION;

require_once __DIR__ . '/lib/ReprintServerPluginTestCase.php';

final class ReprintServerPluginTest extends ReprintServerPluginTestCase
{
    public function testConnectionTokenFallsBackToOptionWhenSecretFileMissing(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token';

        $this->assertSame('option-token', get_connection_token());
    }

    public function testSecretFileConnectionTokenOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token';
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");

        $this->assertSame('file-token', get_connection_token());
    }

    public function testUpdatingConnectionTokenOnlyTouchesTheSiteOption(): void
    {
        $this->assertTrue(update_connection_token('new-token'));
        $this->assertSame('new-token', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
        $this->assertFileDoesNotExist(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE);
    }

    public function testConnectionTokenOperationReturnsExplicitOutcomes(): void
    {
        $this->assertSame('saved', change_connection_token('new-token'));
        $this->assertSame('unchanged', change_connection_token('new-token'));

        $GLOBALS['reprint_server_fail_option_updates'][] = CONNECTION_TOKEN_OPTION;
        $this->assertSame('storage_failure', change_connection_token('other-token'));
    }

    public function testPluginRegistersConnectionTokenOptionForCoreSettingsRestEndpoint(): void
    {
        register_connection_token_setting();

        $setting = $GLOBALS['reprint_server_registered_settings'][CONNECTION_TOKEN_OPTION] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame('reprint_server', $setting['group']);
        $this->assertTrue($setting['args']['show_in_rest']);
        $this->assertSame('string', $setting['args']['type']);
        $this->assertSame('', $setting['args']['default']);
    }

    public function testConfigurationIntegrationRegistersOutsideTheAdministratorAdapter(): void
    {
        $rest_hooks = $GLOBALS['reprint_server_test_actions']['rest_api_init'] ?? [];
        $update_hooks = $GLOBALS['reprint_server_test_actions']['update_option_' . CONNECTION_TOKEN_OPTION] ?? [];
        $add_hooks = $GLOBALS['reprint_server_test_actions']['add_option_' . CONNECTION_TOKEN_OPTION] ?? [];

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

        $this->assertNull(verify_hmac($connection_token));
    }

    public function testPushAuthorizationMatchesOnlyTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertFalse(is_push_authorized());

        $this->assertTrue(update_push_authorization(true));
        $this->assertTrue(is_push_authorized());
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );

        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'rotated-token';
        $this->assertFalse(is_push_authorized());
    }

    public function testManagedEnvironmentOverridesLocalPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        putenv('REPRINT_SERVER_PUSH_ENABLED=true');
        $this->assertTrue(is_push_authorized());

        $this->assertTrue(update_push_authorization(true));
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');
        $this->assertFalse(is_push_authorized());
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

    public function testPresentEmptyManagedEnvironmentFailsClosed(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        putenv('REPRINT_SERVER_PUSH_ENABLED=');

        $this->assertFalse(get_managed_push_enabled());
        $this->assertFalse(is_push_authorized());
        $this->assertSame(
            'Push access is disabled by the hosting provider through REPRINT_SERVER_PUSH_ENABLED.',
            get_push_authorization_error()
        );
    }

    public function testSettingsApiTokenRotationRevokesPriorConsent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        update_option(CONNECTION_TOKEN_OPTION, 'rotated-token');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testRestSettingsConnectionTokenRotationPermanentlyRevokesPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'token-a';
        $this->assertTrue(update_push_authorization(true));

        update_option(CONNECTION_TOKEN_OPTION, 'token-b');
        update_option(CONNECTION_TOKEN_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testAddingAFormerSecretFileConnectionTokenCannotRestorePushAuthorization(): void
    {
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'token-a';\n");
        $this->assertTrue(update_push_authorization(true));

        unlink(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE);
        $this->assertFalse(is_push_authorized());
        update_option(CONNECTION_TOKEN_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testRestSettingsOptionChangePreservesAuthorizationForSecretFileConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-token-a';
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");
        $this->assertTrue(update_push_authorization(true));

        update_option(CONNECTION_TOKEN_OPTION, 'option-token-b');

        $this->assertSame(
            hash('sha256', 'file-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(is_push_authorized());
    }

    public function testPushAccessOperationAuthorizesTheCurrentConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        $this->assertSame('saved', change_push_access(true));
        $this->assertSame(
            hash('sha256', 'current-token'),
            $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]
        );
        $this->assertTrue(is_push_authorized());
    }

    public function testPushAccessOperationReturnsExplicitOutcomes(): void
    {
        $this->assertSame('not_configured', change_push_access(true));

        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertSame('saved', change_push_access(true));
        $this->assertSame('unchanged', change_push_access(true));

        putenv('REPRINT_SERVER_PUSH_ENABLED=false');
        $this->assertSame('managed', change_push_access(false));
    }

    public function testPushAccessOperationReportsStorageFailure(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $GLOBALS['reprint_server_fail_option_updates'][] = PUSH_AUTHORIZATION_OPTION;

        $this->assertSame('storage_failure', change_push_access(true));
        $this->assertFalse(is_push_authorized());
    }

    public function testConfigurationStateDescribesTheEffectiveConnection(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        $configuration = get_configuration_state();

        $this->assertSame('current-token', $configuration['stored_connection_token']);
        $this->assertTrue($configuration['is_configured']);
        $this->assertFalse($configuration['has_connection_token_file']);
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
        $this->assertStringContainsString('name="' . CONNECTION_TOKEN_OPTION . '"', $html);
        $this->assertStringNotContainsString('<h2>Push access</h2>', $html);
        $this->assertStringNotContainsString('id="reprint-server-api-url"', $html);
    }

    public function testDownloadOnlyAdminCopyAndPushAccessForm(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

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
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads and push.</strong>', $html);
        $this->assertStringContainsString('notice notice-info inline', $html);
        $this->assertStringNotContainsString('notice notice-success inline', $html);
        $this->assertStringContainsString('name="reprint_server_push_enabled"', $html);
        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        putenv('REPRINT_SERVER_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('checked="checked" disabled="disabled"', $html);
        $this->assertStringNotContainsString('action="reprint_server_save_push_access"', $html);
    }

    public function testManagedDisabledAdminCopyIsReadOnlyAndUnchecked(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        putenv('REPRINT_SERVER_PUSH_ENABLED=false');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('value="1" disabled="disabled"', $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
        $this->assertStringNotContainsString('action="reprint_server_save_push_access"', $html);
    }

    public function testSecretFileOverrideShowsStoredOptionAndWarning(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'stored-token';
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");

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
            'has_connection_token_file' => false,
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
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
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
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
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
}
