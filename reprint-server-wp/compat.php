<?php

/**
 * Installs support for integrations which still use Site Export names.
 *
 * @param bool $normalize_request Whether to map the legacy query key for plugin routing.
 */
function reprint_server_bootstrap_compatibility(bool $normalize_request = true): void {
    $constant_aliases = [
        'SITE_EXPORT_VERSION' => 'WordPress\\Reprint\\Server\\Plugin\\VERSION',
        'SITE_EXPORT_PLUGIN_DIR' => 'WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR',
        'SITE_EXPORT_SECRET_FILE' => 'WordPress\\Reprint\\Server\\Plugin\\SECRET_FILE',
        'SITE_EXPORT_SECRET_OPTION' => 'WordPress\\Reprint\\Server\\Plugin\\SECRET_OPTION',
        'SITE_EXPORT_PUSH_AUTHORIZATION_OPTION' =>
            'WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION',
        'SITE_EXPORT_TIMESTAMP_TOLERANCE' => 'WordPress\\Reprint\\Server\\Plugin\\TIMESTAMP_TOLERANCE',
    ];
    foreach ($constant_aliases as $legacy_name => $canonical_name) {
        if (defined($legacy_name) && !defined($canonical_name)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
            define($canonical_name, constant($legacy_name));
        }
        if (defined($canonical_name) && !defined($legacy_name)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
            define($legacy_name, constant($canonical_name));
        }
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only request routing presence is normalized.
    if ($normalize_request && isset($_GET['site-export-api']) && !isset($_GET['reprint-api'])) {
        $_GET['reprint-api'] = true;
    }

    static $hooks_bootstrapped = false;
    if ($hooks_bootstrapped) {
        return;
    }
    if (
        !function_exists('add_filter')
        || !function_exists('add_action')
        || !function_exists('apply_filters')
    ) {
        return;
    }
    $hooks_bootstrapped = true;

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

    add_action(
        'reprint_server_library_loaded',
        static function (): void {
            reprint_server_bootstrap_compatibility(false);
        },
        -PHP_INT_MAX
    );
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
    return \WordPress\Reprint\Server\Plugin\has_secret_file();
}

function _site_export_get_file_secret(): ?string {
    return \WordPress\Reprint\Server\Plugin\get_file_secret();
}

function _site_export_get_option_secret(): string {
    return \WordPress\Reprint\Server\Plugin\get_option_secret();
}

function _site_export_get_shared_secret(): ?string {
    return \WordPress\Reprint\Server\Plugin\get_shared_secret();
}

function _site_export_update_shared_secret(string $secret): bool {
    return \WordPress\Reprint\Server\Plugin\update_shared_secret($secret);
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
