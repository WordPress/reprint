<?php

/**
 * Maps every released Site Export constant to its Reprint Server name.
 *
 * @return array<string, string> Released constant name keyed to its canonical name.
 */
function reprint_server_compat_constants(): array {
    return [
        'SITE_EXPORT_VERSION' => 'WordPress\\Reprint\\Server\\Plugin\\VERSION',
        'SITE_EXPORT_PLUGIN_DIR' => 'WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR',
        'SITE_EXPORT_SECRET_FILE' => 'WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE',
        'SITE_EXPORT_SECRET_OPTION' => 'WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION',
        'SITE_EXPORT_PUSH_AUTHORIZATION_OPTION' =>
            'WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION',
        'SITE_EXPORT_TIMESTAMP_TOLERANCE' => 'WordPress\\Reprint\\Server\\Plugin\\TIMESTAMP_TOLERANCE',
    ];
}

/**
 * Defines canonical constants from the released names a platform already set.
 *
 * The library requires this before it defines its own defaults, so a platform
 * which still defines SITE_EXPORT_* from a must-use plugin keeps its values.
 */
function reprint_server_compat_adopt_legacy_constants(): void {
    foreach (reprint_server_compat_constants() as $legacy_name => $canonical_name) {
        if (defined($legacy_name) && !defined($canonical_name)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
            define($canonical_name, constant($legacy_name));
        }
    }
}

/**
 * Publishes the loaded library under the released Site Export names.
 *
 * The library requires this once its own constants exist, which is also
 * before index.php applies the endpoint configuration filter.
 */
function reprint_server_compat_expose_legacy_names(): void {
    foreach (reprint_server_compat_constants() as $legacy_name => $canonical_name) {
        if (defined($canonical_name) && !defined($legacy_name)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
            define($legacy_name, constant($canonical_name));
        }
    }

    if (!function_exists('add_filter') || !function_exists('apply_filters')) {
        return;
    }

    // TODO: This filter should be deleted after September 2026, as it should no longer be relevant by then.
    add_filter(
        'reprint_server_api_options',
        static function ($options) {
            $options = apply_filters('site_export_api_options', $options);
            if (!is_array($options)) {
                \WordPress\Reprint\Server\Plugin\push_error(
                    503,
                    'not_configured',
                    'The legacy site_export_api_options filter must return an array; observed '
                    . gettype($options) . '.'
                );
            }
            return $options;
        },
        -PHP_INT_MAX
    );

    // TODO: This filter should be deleted after September 2026, as it should no longer be relevant by then.
    add_filter(
        'reprint_server_managed_push_enabled',
        static function ($enabled) {
            if ($enabled !== null) {
                return $enabled;
            }
            if (defined('SITE_EXPORT_PUSH_ENABLED')) {
                return constant('SITE_EXPORT_PUSH_ENABLED') === true;
            }
            $environment_value = getenv('SITE_EXPORT_PUSH_ENABLED');
            if ($environment_value === false) {
                return null;
            }
            return filter_var($environment_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        },
        -PHP_INT_MAX
    );
}

/**
 * Routes a released ?site-export-api request through the canonical query key.
 *
 * Only the plugin entry point calls this: a project embedding lib.php owns its
 * own routing and must keep the request globals it was given.
 */
function reprint_server_compat_normalize_legacy_request(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only request routing presence is normalized.
    if (isset($_GET['site-export-api']) && !isset($_GET['reprint-api'])) {
        $_GET['reprint-api'] = true;
    }
}

/**
 * Moves values stored under Site Export option names to the Reprint Server names.
 *
 * Each legacy option is copied to its canonical option when that option does
 * not exist yet, and then deleted. A site therefore migrates on the first
 * request after the update and carries no legacy option afterwards, which is
 * what lets this compatibility file be deleted later.
 *
 * The library calls this once per request until then.
 *
 * TODO: Delete this migration after September 2026, as it should no longer be relevant by then.
 */
function reprint_server_compat_migrate_legacy_options(): void {
    if (
        !function_exists('get_option')
        || !function_exists('update_option')
        || !function_exists('delete_option')
        || !defined('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION')
        || !defined('WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION')
    ) {
        return;
    }

    $legacy_option_names = [
        'site_export_secret' => constant('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_OPTION'),
        'site_export_push_authorized_token_fingerprint' =>
            constant('WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION'),
    ];
    foreach ($legacy_option_names as $legacy_name => $canonical_name) {
        if ($canonical_name === $legacy_name) {
            continue;
        }

        // WordPress stores no option as null, so it marks a missing one.
        $legacy_value = get_option($legacy_name, null);
        if ($legacy_value === null) {
            continue;
        }

        // An empty canonical value counts as missing. The plugin migrates
        // while lib.php loads, before the settings page registers the
        // listener which revokes push authorization when the connection token
        // option appears. A project which embeds lib.php after that listener
        // exists writes an empty fingerprint here, and the entry below then
        // restores the granted one.
        $canonical_value = get_option($canonical_name, null);
        if ($canonical_value === null || $canonical_value === '') {
            update_option($canonical_name, $legacy_value, false);
        }
        delete_option($legacy_name);
    }
}

function _site_export_error(int $code, string $message): void {
    \WordPress\Reprint\Server\Plugin\error($code, $message);
}

function _site_export_push_error(int $http_code, string $reason, string $detail): void {
    \WordPress\Reprint\Server\Plugin\push_error($http_code, $reason, $detail);
}

function _site_export_is_push_endpoint(string $endpoint): bool {
    return \WordPress\Reprint\Server\Plugin\is_push_endpoint($endpoint);
}

function _site_export_push_is_supported(): bool {
    return \WordPress\Reprint\Server\Plugin\push_is_supported();
}

function _site_export_load_exporter_runtime(): ?string {
    return \WordPress\Reprint\Server\Plugin\load_server_runtime();
}

function _site_export_has_secret_file(): bool {
    return \WordPress\Reprint\Server\Plugin\has_connection_token_file();
}

function _site_export_get_file_secret(): ?string {
    return \WordPress\Reprint\Server\Plugin\get_file_connection_token();
}

function _site_export_get_option_secret(): string {
    return \WordPress\Reprint\Server\Plugin\get_option_connection_token();
}

function _site_export_get_shared_secret(): ?string {
    return \WordPress\Reprint\Server\Plugin\get_connection_token();
}

function _site_export_update_shared_secret(string $secret): bool {
    return \WordPress\Reprint\Server\Plugin\update_connection_token($secret);
}

function _site_export_get_managed_push_enabled(): ?bool {
    return \WordPress\Reprint\Server\Plugin\get_managed_push_enabled();
}

function _site_export_is_push_authorized(): bool {
    return \WordPress\Reprint\Server\Plugin\is_push_authorized();
}

function _site_export_get_push_authorization_error(): ?string {
    return \WordPress\Reprint\Server\Plugin\get_push_authorization_error();
}

function _site_export_update_push_authorization(bool $enabled): bool {
    return \WordPress\Reprint\Server\Plugin\update_push_authorization($enabled);
}

function _site_export_verify_hmac(string $secret): ?string {
    return \WordPress\Reprint\Server\Plugin\verify_hmac($secret);
}

function _site_export_default_authenticate(): void {
    \WordPress\Reprint\Server\Plugin\default_authenticate();
}

function _site_export_handle_api_request(array $options = []): void {
    \WordPress\Reprint\Server\Plugin\handle_api_request($options);
}
