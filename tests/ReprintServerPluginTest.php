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
use function WordPress\Reprint\Server\Plugin\get_enrolled_public_keys;
use function WordPress\Reprint\Server\Plugin\get_enrolled_public_keys_by_id;
use function WordPress\Reprint\Server\Plugin\get_managed_push_enabled;
use function WordPress\Reprint\Server\Plugin\get_push_authorization_error;
use function WordPress\Reprint\Server\Plugin\has_public_keys_file;
use function WordPress\Reprint\Server\Plugin\is_push_authorized;
use function WordPress\Reprint\Server\Plugin\register_connection_token_setting;
use function WordPress\Reprint\Server\Plugin\update_connection_token;
use function WordPress\Reprint\Server\Plugin\update_option_public_keys;
use function WordPress\Reprint\Server\Plugin\update_push_authorization;

use const WordPress\Reprint\Server\Plugin\CONNECTION_TOKEN_OPTION;
use const WordPress\Reprint\Server\Plugin\PUBLIC_KEYS_FILE;
use const WordPress\Reprint\Server\Plugin\PUBLIC_KEYS_OPTION;
use const WordPress\Reprint\Server\Plugin\PUSH_AUTHORIZATION_OPTION;

require_once __DIR__ . '/lib/ReprintServerPluginTestCase.php';

final class ReprintServerPluginTest extends ReprintServerPluginTestCase
{
    public function testFirstVisitHasOneSetupJourneyAndNoRoutineNotices(): void
    {
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Setup needed', $html);
        $this->assertStringContainsString('1. Copy the API URL', $html);
        $this->assertStringContainsString('2. Authorize your tool', $html);
        $this->assertStringContainsString('3. Continue in your tool', $html);
        $this->assertStringContainsString('id="reprint-server-api-url"', $html);
        $this->assertStringContainsString('reprint keygen', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
        $this->assertStringNotContainsString('Connected for', $html);
        $this->assertStringNotContainsString('name="' . CONNECTION_TOKEN_OPTION . '"', $html);

        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//textarea[@id="reprint_server_public_key" and @required and @aria-describedby="reprint-server-public-key-help"]'));
        $this->assertCount(1, $xpath->query('//input[@type="submit" and @value="Authorize tool" and contains(@class, "button-primary")]'));
    }

    public function testConfiguredPagePutsConnectionBeforeAccessAndCollapsesAddingAKey(): void
    {
        update_option_public_keys([$this->sampleKeyEntry()]);
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Ready for downloads', $html);
        $this->assertStringContainsString('Return to your tool', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
        $this->assertStringNotContainsString('Connected for', $html);
        $this->assertStringNotContainsString('1. Copy', $html);
        $this->assertLessThan(strpos($html, 'reprint-server-key-table'), strpos($html, 'reprint-server-api-url'));

        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//details[not(@open)]/summary[text()="Authorize another tool"]'));
        $this->assertCount(1, $xpath->query('//input[@name="reprint_server_key_push_enabled" and not(@onchange)]'));
        $this->assertCount(1, $xpath->query('//input[@type="submit" and @value="Save push access"]'));
    }

    public function testEnrollmentErrorReopensTheAddKeyForm(): void
    {
        update_option_public_keys([$this->sampleKeyEntry()]);
        $_GET['reprint_server_notice'] = 'enroll_invalid';
        $document = new DOMDocument();
        $document->loadHTML($this->renderAdminPage(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//details[@open]/summary[text()="Authorize another tool"]'));
        $this->assertCount(1, $xpath->query('//div[contains(@class, "notice-error")]'));
    }

    public function testTokenHostShowsOnlyUsableAuthenticationControls(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('id="reprint-server-api-url"', $html);
        $this->assertStringContainsString('id="reprint_server_connection_token"', $html);
        $this->assertStringNotContainsString('reprint_server_enroll_public_key', $html);
        $this->assertStringNotContainsString('reprint_server_key_push_enabled', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
    }

    public function testFileManagedKeysGiveTheNextActionWithoutDisabledForms(): void
    {
        file_put_contents(PUBLIC_KEYS_FILE, "<?php return [];\n");
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('public-keys.php', $html);
        $this->assertStringContainsString('Edit that file to authorize a tool', $html);
        $this->assertStringNotContainsString('reprint_server_enroll_public_key', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
    }

    public function testNetworkJourneyDoesNotOfferTheNetworkUrlAsASiteUrl(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_user_can_manage_network'] = true;
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('1. Choose the site to copy', $html);
        $this->assertStringContainsString('https://your-site.example/?reprint-api', $html);
        $this->assertStringContainsString('any site in this network', $html);
        $this->assertStringNotContainsString('id="reprint-server-api-url"', $html);
        $this->assertStringNotContainsString('name="reprint_server_key_push_enabled"', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
    }

    public function testNetworkTokenSaveReportsTheSavedResult(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_user_can_manage_network'] = true;
        $_POST = [CONNECTION_TOKEN_OPTION => 'network-token'];
        try {
            SettingsPage::get_instance()->handle_network_token_save();
        } catch (\RuntimeException $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The harness redirect throws before exit.
        }
        $this->assertSame('network-token', get_connection_token());
        $this->assertStringContainsString('reprint_server_notice=network_token_saved', (string) $GLOBALS['reprint_server_test_redirect']);
        $_GET['reprint_server_notice'] = 'network_token_saved';
        $this->assertStringContainsString('Connection token saved.', $this->renderAdminPage());
    }

    public function testKeyActionsHaveDistinctNamesForEachKey(): void
    {
        $entry = $this->sampleKeyEntry();
        update_option_public_keys([$entry]);
        $document = new DOMDocument();
        $document->loadHTML($this->renderAdminPage(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        foreach (['Allow push for key ', 'Save push access for key ', 'Remove key '] as $label) {
            $this->assertCount(1, $xpath->query('//input[normalize-space(@aria-label)="' . $label . $entry['key_id'] . '"]'));
        }
        $this->assertCount(4, $xpath->query('//table[contains(@class, "reprint-server-key-table")]//td[@data-label]'));
    }

    /** A subsite's token cannot grant access to shared network data. */
    public function testMultisiteUsesOnlyTheNetworkConnectionToken(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'subsite-token';
        $this->assertNull(get_connection_token());
        $this->assertTrue(update_connection_token('network-token'));
        $this->assertSame('network-token', get_connection_token());
        $this->assertSame('subsite-token', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
    }

    /** A site administrator cannot render the network's connection token. */
    public function testMultisiteSiteAdministratorCannotReadTheNetworkToken(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_network_options'][CONNECTION_TOKEN_OPTION] = 'network-token';
        $this->assertSame('', $this->renderAdminPage());
        SettingsPage::get_instance()->add_admin_menu();
        $this->assertNull($GLOBALS['reprint_server_test_menu']);
    }

    /** The site Settings REST endpoint must not reveal a network credential. */
    public function testMultisiteDoesNotRegisterTheSiteRestSetting(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        register_connection_token_setting();
        $this->assertArrayNotHasKey(CONNECTION_TOKEN_OPTION, $GLOBALS['reprint_server_registered_settings']);
    }

    /** Pulling a site does not authorize pushing into a live network. */
    public function testMultisitePushRemainsDisabledEvenWithManagedAccess(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        putenv('REPRINT_SERVER_PUSH_ENABLED=1');
        $this->assertFalse(is_push_authorized());
    }

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
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
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
        $key_handlers = [
            'admin_post_reprint_server_enroll_public_key' => 'handle_public_key_enroll',
            'admin_post_reprint_server_remove_public_key' => 'handle_public_key_remove',
            'admin_post_reprint_server_save_key_push_access' => 'handle_key_push_access_save',
            'admin_post_reprint_server_remove_connection_token' => 'handle_connection_token_remove',
        ];
        foreach ($key_handlers as $hook_name => $method) {
            $hooks = $GLOBALS['reprint_server_test_actions'][$hook_name] ?? [];
            $this->assertNotEmpty($hooks, $hook_name);
            $this->assertSame([$plugin, $method], $hooks[0]['callback']);
        }
        $this->assertEmpty($GLOBALS['reprint_server_test_actions']['admin_bar_menu'] ?? []);

        $plugin->enqueue_assets('tools_page_other');
        $this->assertSame([], $GLOBALS['reprint_server_test_scripts']);
        $this->assertSame([], $GLOBALS['reprint_server_test_styles']);

        $plugin->enqueue_assets('tools_page_reprint-server');
        $this->assertSame(
            ['wp-a11y'],
            $GLOBALS['reprint_server_test_scripts']['reprint-server-admin']['dependencies']
        );
        $this->assertStringEndsWith(
            'wordpress/reprint-server.css',
            $GLOBALS['reprint_server_test_styles']['reprint-server-admin']['src']
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
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Setup needed', $html);
        $this->assertStringContainsString('Save the token to authorize downloads', $html);
        $this->assertStringContainsString('id="reprint_server_connection_token"', $html);
        $this->assertStringContainsString('name="' . CONNECTION_TOKEN_OPTION . '"', $html);
        $this->assertStringNotContainsString('<h2>Push access</h2>', $html);
        $this->assertStringContainsString('id="reprint-server-api-url"', $html);
    }

    public function testTokenGeneratorDoesNotSubmitTheFormOrChangeTheStoredToken(): void
    {
        // Only a host without OpenSSL renders the editable token field the generator fills.
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        $document = new DOMDocument();
        $document->loadHTML($this->renderAdminPage(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $buttons = $xpath->query('//button[contains(@class, "reprint-server-generate-token")]');

        $this->assertCount(1, $buttons);
        $button = $buttons->item(0);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertSame('reprint_server_connection_token', $button->getAttribute('aria-controls'));
        $this->assertSame('Generate new token', trim($button->textContent));
        $this->assertSame('current-token', get_connection_token());
    }

    public function testDownloadOnlyAdminCopyAndPushAccessForm(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';

        $html = $this->renderAdminPage();

        $this->assertStringNotContainsString('class="notice ', $html);
        $this->assertStringContainsString('Ready for downloads', $html);
        $this->assertStringContainsString('Return to your tool', $html);
        $this->assertStringNotContainsString('notice notice-success inline', $html);
        $this->assertStringContainsString('You do not need push access when moving this site to another host.', $html);
        $this->assertStringContainsString('Allow push to change files on this site', $html);
        $this->assertStringContainsString('except excluded paths.', $html);
        $this->assertStringContainsString('<form method="post" action="https://example.test/wp-admin/options.php">', $html);
        $this->assertStringContainsString('name="option_page" value="reprint_server"', $html);
        $this->assertStringContainsString('action="https://example.test/wp-admin/admin-post.php"', $html);
        // Token hosts show only the token and its push settings, not key enrollment.
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
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Downloads and push enabled', $html);
        $this->assertStringNotContainsString('class="notice ', $html);
        $this->assertStringNotContainsString('notice notice-success inline', $html);
        $this->assertStringContainsString('name="reprint_server_push_enabled"', $html);
        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        putenv('REPRINT_SERVER_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('checked="checked" disabled="disabled"', $html);
        $this->assertStringNotContainsString('action="reprint_server_save_push_access"', $html);
    }

    public function testManagedDisabledAdminCopyIsReadOnlyAndUnchecked(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
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
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
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
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
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

    /** Each key handler reports a missing Composer runtime under its own notice prefix. */
    public function testAdministratorMapsAMissingRuntimeResultToAnErrorNoticeForEveryKeyHandler(): void
    {
        $expected_message = 'The Reprint Server runtime is missing. Run composer install in the plugin directory or reinstall the release package.';
        foreach (['enroll_runtime_missing', 'remove_runtime_missing', 'key_push_runtime_missing'] as $result) {
            $_GET['reprint_server_notice'] = $result;
            $html = $this->renderAdminPage();

            $this->assertStringContainsString('notice-error is-dismissible inline', $html, $result);
            $this->assertStringContainsString($expected_message, $html, $result);
        }
    }

    public function testSettingsSaveSupersedesAStalePushAccessNotice(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
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

    private function sampleKeyEntry(): array
    {
        [, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        return [
            'key_id' => \WordPress\Reprint\Server\Utils::public_key_fingerprint($public_key),
            'public_key' => $public_key,
            'added_at' => 1700000000,
            'push' => false,
        ];
    }

    public function testNoKeysByDefault(): void
    {
        $this->assertSame([], get_enrolled_public_keys());
        $this->assertSame([], get_enrolled_public_keys_by_id());
        $this->assertFalse(has_public_keys_file());
    }

    public function testOptionKeysAreReturnedById(): void
    {
        $entry = $this->sampleKeyEntry();
        $this->assertTrue(update_option_public_keys([$entry]));

        $this->assertSame([$entry], get_enrolled_public_keys());
        $this->assertSame([$entry['key_id'] => $entry['public_key']], get_enrolled_public_keys_by_id());
    }

    public function testMultisiteStoresKeysInTheNetworkOption(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $entry = $this->sampleKeyEntry();
        update_option_public_keys([$entry]);

        $this->assertSame([$entry], $GLOBALS['reprint_server_test_network_options'][PUBLIC_KEYS_OPTION]);
        $this->assertArrayNotHasKey(PUBLIC_KEYS_OPTION, $GLOBALS['reprint_server_test_options']);
        $this->assertSame([$entry], get_enrolled_public_keys());
    }

    public function testPublicKeysFileOverridesTheOption(): void
    {
        $option_entry = $this->sampleKeyEntry('option');
        update_option_public_keys([$option_entry]);
        [, $file_public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Builds the public-keys.php fixture source.
        file_put_contents(PUBLIC_KEYS_FILE, "<?php return [" . var_export(\WordPress\Reprint\Server\Utils::public_key_to_pem($file_public_key), true) . "];\n");

        try {
            $this->assertTrue(has_public_keys_file());
            $enrolled = get_enrolled_public_keys();
            $this->assertCount(1, $enrolled);
            $this->assertSame($file_public_key, $enrolled[0]['public_key']);
            $this->assertSame(\WordPress\Reprint\Server\Utils::public_key_fingerprint($file_public_key), $enrolled[0]['key_id']);
        } finally {
            unlink(PUBLIC_KEYS_FILE);
        }
    }

    public function testMalformedOptionValueYieldsNoKeys(): void
    {
        $GLOBALS['reprint_server_test_options'][PUBLIC_KEYS_OPTION] = 'not an array';
        $this->assertSame([], get_enrolled_public_keys());

        $GLOBALS['reprint_server_test_options'][PUBLIC_KEYS_OPTION] = [['key_id' => 'x']];
        $this->assertSame([], get_enrolled_public_keys(), 'entries missing public_key are dropped');
    }

    public function testPushGateHonoursThePerKeyFlag(): void
    {
        $entry = $this->sampleKeyEntry();
        $entry['push'] = true;
        update_option_public_keys([$entry]);

        $this->assertNull(\WordPress\Reprint\Server\Plugin\get_push_authorization_error($entry['key_id']));
        $entry['push'] = false;
        update_option_public_keys([$entry]);
        $this->assertSame(
            'Push access is disabled for the current key.',
            \WordPress\Reprint\Server\Plugin\get_push_authorization_error($entry['key_id'])
        );
        $this->assertSame(
            'Push access is disabled for the current key.',
            \WordPress\Reprint\Server\Plugin\get_push_authorization_error('0000000000000000')
        );
    }

    public function testEnrollStoresNormalizedKeyAndReportsItsId(): void
    {
        [, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $pem = \WordPress\Reprint\Server\Utils::public_key_to_pem($public_key);

        $this->assertSame('saved', \WordPress\Reprint\Server\Plugin\enroll_public_key($pem));
        $expected_id = \WordPress\Reprint\Server\Utils::public_key_fingerprint($public_key);
        $this->assertSame($expected_id, \WordPress\Reprint\Server\Plugin\get_last_enrolled_key_id());

        $enrolled = get_enrolled_public_keys();
        $this->assertCount(1, $enrolled);
        $this->assertSame($public_key, $enrolled[0]['public_key']);
        $this->assertFalse($enrolled[0]['push']);
        $this->assertGreaterThan(0, $enrolled[0]['added_at']);
    }

    public function testEnrollRejectsInvalidAndDuplicateKeys(): void
    {
        $this->assertSame('invalid', \WordPress\Reprint\Server\Plugin\enroll_public_key('garbage'));
        [, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        \WordPress\Reprint\Server\Plugin\enroll_public_key($public_key);
        $this->assertSame('duplicate', \WordPress\Reprint\Server\Plugin\enroll_public_key($public_key));
        $this->assertCount(1, get_enrolled_public_keys());
    }

    public function testEnrollRefusesWhenTheFileOverrideIsActive(): void
    {
        file_put_contents(PUBLIC_KEYS_FILE, "<?php return [];\n");
        [, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $this->assertSame('file_override', \WordPress\Reprint\Server\Plugin\enroll_public_key($public_key));
        unlink(PUBLIC_KEYS_FILE);
    }

    public function testRemoveAndPushAccessOperations(): void
    {
        $first = $this->sampleKeyEntry('first');
        $second = $this->sampleKeyEntry('second');
        update_option_public_keys([$first, $second]);

        $this->assertSame('saved', \WordPress\Reprint\Server\Plugin\change_key_push_access($first['key_id'], true));
        $this->assertSame('unchanged', \WordPress\Reprint\Server\Plugin\change_key_push_access($first['key_id'], true));
        $this->assertSame('unknown', \WordPress\Reprint\Server\Plugin\change_key_push_access('0000000000000000', true));
        $this->assertTrue(get_enrolled_public_keys()[0]['push']);

        $this->assertSame('saved', \WordPress\Reprint\Server\Plugin\remove_public_key($second['key_id']));
        $this->assertSame('unknown', \WordPress\Reprint\Server\Plugin\remove_public_key($second['key_id']));
        $this->assertCount(1, get_enrolled_public_keys());
        // A key host may lose its last key: revoking a leaked key must not wait for another enrollment.
        $this->assertSame('saved', \WordPress\Reprint\Server\Plugin\remove_public_key($first['key_id']));
        $this->assertSame([], get_enrolled_public_keys());
    }

    public function testConfigurationStateReportsSchemeAndKeys(): void
    {
        $entry = $this->sampleKeyEntry();
        update_option_public_keys([$entry]);
        $state = \WordPress\Reprint\Server\Plugin\get_configuration_state();

        $this->assertSame('key', $state['required_scheme']);
        $this->assertSame([$entry], $state['enrolled_keys']);
        $this->assertFalse($state['has_public_keys_file']);

        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $this->assertSame('hmac', \WordPress\Reprint\Server\Plugin\get_configuration_state()['required_scheme']);
    }

    public function testPublicKeysOptionIsNotExposedThroughRest(): void
    {
        \WordPress\Reprint\Server\Plugin\register_public_keys_setting();
        $registered = $GLOBALS['reprint_server_registered_settings'][PUBLIC_KEYS_OPTION] ?? null;
        $this->assertNotNull($registered);
        $this->assertArrayNotHasKey('show_in_rest', $registered['args']);
    }

    /** A Settings API write in the keys' own group passes the same validation as enrollment. */
    public function testPublicKeysOptionSanitizesSettingsApiWrites(): void
    {
        \WordPress\Reprint\Server\Plugin\register_public_keys_setting();
        $registered = $GLOBALS['reprint_server_registered_settings'][PUBLIC_KEYS_OPTION];
        $this->assertSame('WordPress\\Reprint\\Server\\Plugin\\sanitize_public_keys_option', $registered['args']['sanitize_callback']);

        $valid_entry = $this->sampleKeyEntry();
        $valid_entry['push'] = true;
        $sanitized = call_user_func($registered['args']['sanitize_callback'], [
            'not an entry',
            ['key_id' => 'garbage', 'public_key' => 'bm90IGEga2V5', 'push' => true],
            $valid_entry,
        ]);
        $this->assertSame([$valid_entry], $sanitized, 'only the entry with a parseable RSA key survives; added_at and push are kept');
        $this->assertSame([], call_user_func($registered['args']['sanitize_callback'], 'not an array'));
    }

    /**
     * options.php resets every option in a submitted group that the form did not post, so the keys must
     * not share the token form's group or each token save would empty them.
     */
    public function testPublicKeysOptionIsNotInTheTokenFormsSettingsGroup(): void
    {
        \WordPress\Reprint\Server\Plugin\register_public_keys_setting();
        register_connection_token_setting();
        $keys_setting = $GLOBALS['reprint_server_registered_settings'][PUBLIC_KEYS_OPTION];
        $token_setting = $GLOBALS['reprint_server_registered_settings'][CONNECTION_TOKEN_OPTION];
        $this->assertSame('reprint_server', $token_setting['group']);
        $this->assertNotSame('reprint_server', $keys_setting['group']);
        $this->assertSame('reprint_server_public_keys', $keys_setting['group']);
    }

    public function testMultisiteRefusesKeyPushGrants(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_user_can_manage_network'] = true;
        $entry = $this->sampleKeyEntry();
        update_option_public_keys([$entry]);

        $this->assertSame('multisite', \WordPress\Reprint\Server\Plugin\change_key_push_access($entry['key_id'], true));
        $this->assertFalse(get_enrolled_public_keys()[0]['push']);

        $html = $this->renderAdminPage();
        $this->assertStringNotContainsString('name="reprint_server_key_push_enabled"', $html, 'networks cannot grant push');

        $_GET['reprint_server_notice'] = 'key_push_multisite';
        $this->assertStringContainsString('Push is not supported on multisite networks.', $this->renderAdminPage());
    }

    public function testAdminPageShowsTheKeyStatusLineOnAnOpensslHost(): void
    {
        update_option(CONNECTION_TOKEN_OPTION, 'stale-token');
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Clients authenticate with public keys', $html);
        $this->assertStringContainsString('reprint_server_enroll_public_key', $html);
        $this->assertStringContainsString('not accepted on this host', $html, 'a stored token is shown as inert');
        $this->assertStringContainsString('2. Authorize your tool', $html);
    }

    public function testAdminPageShowsTheTokenStatusLineOnAnHmacHost(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Clients authenticate with a connection token', $html);
        $this->assertStringNotContainsString('reprint_server_enroll_public_key', $html, 'a token host cannot use newly enrolled keys');
        $this->assertStringContainsString('public keys are not used on this host', $html);
    }

    public function testAdminPageListsEnrolledKeysWithRemoveAndPushControls(): void
    {
        $entry = $this->sampleKeyEntry();
        update_option_public_keys([$entry]);
        $html = $this->renderAdminPage();

        $this->assertStringContainsString($entry['key_id'], $html);
        $this->assertStringContainsString(gmdate('Y-m-d', $entry['added_at']), $html);
        $this->assertStringContainsString('reprint_server_remove_public_key', $html);
        $this->assertStringContainsString('reprint_server_save_key_push_access', $html);
        $this->assertStringContainsString('reprint_server_key_id', $html);
    }

    public function testAdminPageOmitsEnrollmentWhenTheFileOverrideIsActive(): void
    {
        file_put_contents(PUBLIC_KEYS_FILE, "<?php return [];\n");
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('public-keys.php', $html);
        $this->assertStringNotContainsString('reprint_server_enroll_public_key', $html);
        unlink(PUBLIC_KEYS_FILE);
    }

    public function testEnrollmentNoticeNamesTheKeyId(): void
    {
        $_GET = ['reprint_server_notice' => 'enrolled', 'reprint_server_key_id' => '0123456789abcdef'];
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('0123456789abcdef', $html);
        $this->assertStringContainsString('Public key enrolled', $html);
    }

    public function testEnrollHandlerStoresAndRedirects(): void
    {
        [, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $_POST = ['reprint_server_public_key' => $public_key];
        $GLOBALS['reprint_server_test_redirect'] = null;
        try {
            SettingsPage::get_instance()->handle_public_key_enroll();
        } catch (\RuntimeException $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The harness wp_safe_redirect stub throws so the handler exit is never reached.
        }
        $this->assertCount(1, get_enrolled_public_keys());
        $this->assertStringContainsString('reprint_server_notice=enrolled', (string) $GLOBALS['reprint_server_test_redirect']);
    }

    /** A token stored in the option is inert on a key host, so the read-only section offers to remove it. */
    public function testAdminPageOffersToRemoveAStoredOptionTokenOnAKeyHost(): void
    {
        update_option(CONNECTION_TOKEN_OPTION, 'stale-token');
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('reprint_server_remove_connection_token', $html);

        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'file-token';\n");
        $html = $this->renderAdminPage();
        $this->assertStringContainsString('secret.php', $html);
        $this->assertStringNotContainsString('reprint_server_remove_connection_token', $html, 'a secret.php token has no Remove button');
        $this->assertStringContainsString('secret.php is present but not accepted on this host. Remove it.', $html);
        $this->assertStringNotContainsString('Remove secret.php to use the stored option value.', $html);
    }

    public function testRemoveConnectionTokenHandlerClearsTheOptionAndRedirects(): void
    {
        update_option(CONNECTION_TOKEN_OPTION, 'stale-token');
        $GLOBALS['reprint_server_test_redirect'] = null;
        try {
            SettingsPage::get_instance()->handle_connection_token_remove();
        } catch (\RuntimeException $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The harness wp_safe_redirect stub throws so the handler exit is never reached.
        }
        $this->assertSame('', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
        $this->assertNull(get_connection_token());
        $this->assertStringContainsString('reprint_server_notice=token_removed', (string) $GLOBALS['reprint_server_test_redirect']);
    }

    /** A stored token is not a credential on a key host, so it does not make the site configured. */
    public function testKeyHostWithOnlyATokenIsNotConfiguredOnThePage(): void
    {
        update_option(CONNECTION_TOKEN_OPTION, 'stale-token');

        $state = get_configuration_state();
        $this->assertFalse($state['is_configured']);
        $this->assertFalse($state['push_enabled']);

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Setup needed', $html);
        $this->assertStringContainsString('2. Authorize your tool', $html);
        $this->assertStringNotContainsString('name="reprint_server_push_enabled"', $html);
        $this->assertStringNotContainsString('<h2>Push access</h2>', $html);
        $this->assertStringContainsString('id="reprint-server-api-url"', $html);
    }

    public function testKeyHostWithAPushingKeyReportsDownloadsAndPushAccess(): void
    {
        $entry = $this->sampleKeyEntry();
        $entry['push'] = true;
        update_option_public_keys([$entry]);

        $state = get_configuration_state();
        $this->assertTrue($state['is_configured']);
        $this->assertTrue($state['push_enabled']);

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Downloads and push enabled', $html);
        $this->assertStringContainsString('Allow push only if a tool needs to upload, replace, or delete files', $html);
        $this->assertStringNotContainsString('<h2>Push access</h2>', $html, 'push grants live in the key table on a key host');
        $this->assertStringContainsString('id="reprint-server-api-url"', $html);
    }

    public function testKeyHostWithANonPushingKeyReportsDownloadAccess(): void
    {
        update_option_public_keys([$this->sampleKeyEntry()]);

        $state = get_configuration_state();
        $this->assertTrue($state['is_configured']);
        $this->assertFalse($state['push_enabled']);

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('Ready for downloads', $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    /** The managed policy and the multisite refusal outrank a key's push flag, as they do for a request. */
    public function testKeyHostPushEnabledFollowsTheManagedPolicyAndMultisiteRefusal(): void
    {
        $entry = $this->sampleKeyEntry();
        $entry['push'] = true;
        update_option_public_keys([$entry]);

        putenv('REPRINT_SERVER_PUSH_ENABLED=false');
        $this->assertFalse(get_configuration_state()['push_enabled']);
        putenv('REPRINT_SERVER_PUSH_ENABLED');

        $GLOBALS['reprint_server_test_multisite'] = true;
        update_option_public_keys([$entry]);
        $this->assertFalse(get_configuration_state()['push_enabled']);
    }

    /**
     * The key table shows the push permission a request signed with the key
     * gets, so a host policy outranks the stored flag there too.
     *
     * @dataProvider managedKeyPushAccessProvider
     */
    public function testReadOnlyKeyPushAccessShowsThePermissionTheApiEnforces(string $managed_policy, bool $stored_push, bool $expect_allowed): void
    {
        $entry = $this->sampleKeyEntry();
        $entry['push'] = $stored_push;
        update_option_public_keys([$entry]);
        putenv('REPRINT_SERVER_PUSH_ENABLED=' . $managed_policy);
        try {
            $html = $this->renderAdminPage();
        } finally {
            putenv('REPRINT_SERVER_PUSH_ENABLED');
        }

        $this->assertStringContainsString($expect_allowed ? 'Allowed' : 'Downloads only', $html);
        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringNotContainsString('reprint_server_save_key_push_access', $html, 'the host decides, so the key cannot be changed here');
    }

    public static function managedKeyPushAccessProvider(): array
    {
        return [
            'host enables push for a key never granted it' => ['true', false, true],
            'host disables push for a key granted it' => ['false', true, false],
        ];
    }

    /** The network page offers the same read-only token section on a key host as the site page. */
    public function testMultisiteKeyHostShowsAReadOnlyNetworkTokenWithRemove(): void
    {
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_network_options'][CONNECTION_TOKEN_OPTION] = 'network-token';
        $GLOBALS['reprint_server_test_user_can_manage_network'] = true;

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('reprint_server_remove_connection_token', $html);
        $this->assertStringNotContainsString('name="' . CONNECTION_TOKEN_OPTION . '"', $html);
        $this->assertStringNotContainsString('reprint_server_save_network_token', $html);
        $this->assertStringContainsString('reprint_server_enroll_public_key', $html);
        $this->assertStringContainsString('Enrolled public keys can pull any site in this network.', $html);
        $this->assertStringNotContainsString('This network token can pull any site in this network.', $html);
    }

    /** On an HMAC host the network page keeps its editable token form. */
    public function testMultisiteHmacHostKeepsTheEditableNetworkTokenForm(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        $GLOBALS['reprint_server_test_multisite'] = true;
        $GLOBALS['reprint_server_test_user_can_manage_network'] = true;

        $html = $this->renderAdminPage();
        $this->assertStringContainsString('reprint_server_save_network_token', $html);
        $this->assertStringContainsString('name="' . CONNECTION_TOKEN_OPTION . '"', $html);
        $this->assertStringNotContainsString('reprint_server_remove_connection_token', $html);
        $this->assertStringContainsString('This network token can pull any site in this network.', $html);
    }

    public function testPluginAuthVersionMatchesTheServerPackage(): void
    {
        $this->assertSame(\WordPress\Reprint\Server\Utils::AUTH_VERSION, \WordPress\Reprint\Server\Plugin\AUTH_VERSION);
    }
}
