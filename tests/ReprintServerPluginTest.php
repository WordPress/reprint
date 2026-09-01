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

use function WordPress\Reprint\Server\Plugin\get_connection_token;
use function WordPress\Reprint\Server\Plugin\get_managed_push_enabled;
use function WordPress\Reprint\Server\Plugin\get_push_authorization_error;
use function WordPress\Reprint\Server\Plugin\is_push_authorized;
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
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-secret';

        $this->assertSame('option-secret', get_connection_token());
    }

    public function testSecretFileConnectionTokenOverridesSiteOptionWhenPresent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'option-secret';
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'file-secret';\n");

        $this->assertSame('file-secret', get_connection_token());
    }

    public function testUpdatingConnectionTokenOnlyTouchesTheSiteOption(): void
    {
        $this->assertTrue(update_connection_token('new-secret'));
        $this->assertSame('new-secret', $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION]);
        $this->assertFileDoesNotExist(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE);
    }

    public function testPluginRegistersConnectionTokenOptionForCoreSettingsRestEndpoint(): void
    {
        Site_Export_Plugin::get_instance()->register_settings();

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

    public function testSavingRotatedConnectionTokenRevokesPriorConsent(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $_POST = [
            'site_export_save_settings' => '1',
            'site_export_secret' => 'rotated-token',
        ];
        Site_Export_Plugin::get_instance()->handle_settings_save();

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testRestSettingsConnectionTokenRotationPermanentlyRevokesPushAuthorization(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'token-a';
        $this->assertTrue(update_push_authorization(true));
        Site_Export_Plugin::get_instance()->register_settings();

        update_option(CONNECTION_TOKEN_OPTION, 'token-b');
        update_option(CONNECTION_TOKEN_OPTION, 'token-a');

        $this->assertSame('', $GLOBALS['reprint_server_test_options'][PUSH_AUTHORIZATION_OPTION]);
        $this->assertFalse(is_push_authorized());
    }

    public function testAddingAFormerSecretFileConnectionTokenCannotRestorePushAuthorization(): void
    {
        file_put_contents(REPRINT_SERVER_TEST_CONNECTION_TOKEN_FILE, "<?php return 'token-a';\n");
        $this->assertTrue(update_push_authorization(true));
        Site_Export_Plugin::get_instance()->register_settings();

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
        Site_Export_Plugin::get_instance()->register_settings();

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
            'site_export_save_push_access' => '1',
            'site_export_push_enabled' => '1',
        ];

        Site_Export_Plugin::get_instance()->handle_settings_save();

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
        $this->assertStringNotContainsString('name="site_export_push_enabled" value="1" checked', $html);
    }

    public function testOptedInAdminCopyShowsCurrentConnectionTokenCanPush(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        $this->assertTrue(update_push_authorization(true));

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('<strong>Connected for downloads and push.</strong>', $html);
        $this->assertStringContainsString('name="site_export_push_enabled" value="1" checked', $html);
    }

    public function testManagedAdminCopyIsReadOnlyAndShowsEffectiveState(): void
    {
        $GLOBALS['reprint_server_test_options'][CONNECTION_TOKEN_OPTION] = 'current-token';
        putenv('REPRINT_SERVER_PUSH_ENABLED=true');

        $html = $this->renderAdminPage();

        $this->assertStringContainsString('Push access is managed by your hosting provider.', $html);
        $this->assertStringContainsString('name="site_export_push_enabled" value="1" checked disabled', $html);
        $this->assertStringNotContainsString('name="site_export_save_push_access"', $html);
    }
}
