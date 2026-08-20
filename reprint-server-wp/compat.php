<?php

/** Installs support for integrations which still use Site Export names. */
function reprint_server_bootstrap_compatibility(): void {
    if (
        !function_exists('add_filter')
        || !function_exists('add_action')
        || !function_exists('apply_filters')
        || !function_exists('get_option')
        || !function_exists('update_option')
    ) {
        return;
    }

    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    $constant_aliases = [
        'SITE_EXPORT_VERSION' => 'WordPress\\Reprint\\Server\\Plugin\\VERSION',
        'SITE_EXPORT_PLUGIN_DIR' => 'WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR',
        'SITE_EXPORT_SECRET_FILE' => 'WordPress\\Reprint\\Server\\Plugin\\SECRET_FILE',
        'SITE_EXPORT_SECRET_OPTION' => 'WordPress\\Reprint\\Server\\Plugin\\SECRET_OPTION',
        'SITE_EXPORT_PUSH_AUTHORIZATION_OPTION' =>
            'WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION',
        'SITE_EXPORT_TIMESTAMP_TOLERANCE' => 'WordPress\\Reprint\\Server\\Plugin\\TIMESTAMP_TOLERANCE',
    ];
    foreach ($constant_aliases as $old_name => $canonical_name) {
        if (defined($old_name) && !defined($canonical_name)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
            define($canonical_name, constant($old_name));
        }
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only request routing presence is normalized.
    if (isset($_GET['site-export-api']) && !isset($_GET['reprint-api'])) {
        $_GET['reprint-api'] = true;
    }

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

    spl_autoload_register(
        static function ($requested_class) {
            if (
                $requested_class === 'Site_Export_Plugin'
                && class_exists('WordPress\\Reprint\\Server\\Plugin\\SettingsPage')
                && !class_exists($requested_class, false)
            ) {
                class_alias('WordPress\\Reprint\\Server\\Plugin\\SettingsPage', $requested_class);
            }
        }
    );

    add_action(
        'reprint_server_library_loaded',
        static function () use ($constant_aliases) {
            foreach ($constant_aliases as $old_name => $canonical_name) {
                if (!defined($old_name)) {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are constrained by the compatibility map above.
                    define($old_name, constant($canonical_name));
                }
            }

            $secret_option = constant('WordPress\\Reprint\\Server\\Plugin\\SECRET_OPTION');
            $push_authorization_option = constant(
                'WordPress\\Reprint\\Server\\Plugin\\PUSH_AUTHORIZATION_OPTION'
            );
            $old_secret_option = 'site_export_secret';
            $old_push_authorization_option = 'site_export_push_authorized_token_fingerprint';

            if ($secret_option !== $old_secret_option) {
                add_filter(
                    'default_option_' . $secret_option,
                    static function ($default_value) use ($old_secret_option) {
                        return get_option($old_secret_option, $default_value);
                    }
                );
                add_action(
                    'update_option_' . $secret_option,
                    static function ($old_value, $new_value) use ($old_secret_option): void {
                        update_option($old_secret_option, $new_value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'add_option_' . $secret_option,
                    static function ($option_name, $value) use ($old_secret_option): void {
                        update_option($old_secret_option, $value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'update_option_' . $old_secret_option,
                    static function ($old_value, $new_value) use ($secret_option): void {
                        update_option($secret_option, $new_value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'add_option_' . $old_secret_option,
                    static function ($option_name, $value) use ($secret_option): void {
                        update_option($secret_option, $value, false);
                    },
                    10,
                    2
                );
            }

            if ($push_authorization_option !== $old_push_authorization_option) {
                add_filter(
                    'default_option_' . $push_authorization_option,
                    static function ($default_value) use ($old_push_authorization_option) {
                        return get_option($old_push_authorization_option, $default_value);
                    }
                );
                add_action(
                    'update_option_' . $push_authorization_option,
                    static function ($old_value, $new_value) use ($old_push_authorization_option): void {
                        update_option($old_push_authorization_option, $new_value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'add_option_' . $push_authorization_option,
                    static function ($option_name, $value) use ($old_push_authorization_option): void {
                        update_option($old_push_authorization_option, $value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'update_option_' . $old_push_authorization_option,
                    static function ($old_value, $new_value) use ($push_authorization_option): void {
                        update_option($push_authorization_option, $new_value, false);
                    },
                    10,
                    2
                );
                add_action(
                    'add_option_' . $old_push_authorization_option,
                    static function ($option_name, $value) use ($push_authorization_option): void {
                        update_option($push_authorization_option, $value, false);
                    },
                    10,
                    2
                );
            }

            add_action(
                'plugins_loaded',
                static function () use (
                    $secret_option,
                    $push_authorization_option,
                    $old_secret_option,
                    $old_push_authorization_option
                ): void {
                    $old_secret = get_option($old_secret_option, null);
                    if (
                        is_string($old_secret)
                        && get_option($secret_option, null) === $old_secret
                    ) {
                        update_option($secret_option, $old_secret, false);
                    }
                    $old_fingerprint = get_option($old_push_authorization_option, null);
                    if (
                        is_string($old_fingerprint)
                        && get_option($push_authorization_option, null) === $old_fingerprint
                    ) {
                        update_option($push_authorization_option, $old_fingerprint, false);
                    }
                },
                -PHP_INT_MAX
            );

            add_filter(
                'reprint_server_settings_nonce_action',
                static function ($action) {
                    // The canonical settings handler verifies this nonce immediately after the filter.
                    // phpcs:disable WordPress.Security.NonceVerification.Missing
                    if (
                        isset($_POST['site_export_save_settings'])
                        || isset($_POST['site_export_save_push_access'])
                    ) {
                        return 'site_export_settings';
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Missing
                    return $action;
                }
            );

            add_action(
                'admin_init',
                static function (): void {
                    // The canonical settings handler verifies the selected nonce and sanitizes mapped values.
                    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
                    if (isset($_POST['site_export_save_settings'])) {
                        $_POST['reprint_server_save_settings'] = $_POST['site_export_save_settings'];
                    }
                    if (isset($_POST['site_export_save_push_access'])) {
                        $_POST['reprint_server_save_push_access'] = $_POST['site_export_save_push_access'];
                    }
                    if (isset($_POST['site_export_secret']) && !isset($_POST['reprint_server_secret'])) {
                        $_POST['reprint_server_secret'] = $_POST['site_export_secret'];
                    }
                    if (
                        isset($_POST['site_export_push_enabled'])
                        && !isset($_POST['reprint_server_push_enabled'])
                    ) {
                        $_POST['reprint_server_push_enabled'] = $_POST['site_export_push_enabled'];
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

                    // This only redirects a read-only settings URL.
                    // phpcs:disable WordPress.Security.NonceVerification.Recommended
                    if (
                        isset($_GET['page'])
                        && $_GET['page'] === 'site-export'
                        && function_exists('wp_safe_redirect')
                        && function_exists('admin_url')
                    ) {
                        wp_safe_redirect(admin_url('admin.php?page=reprint-server'));
                        exit;
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Recommended
                    if (
                        function_exists('get_transient')
                        && function_exists('set_transient')
                        && function_exists('delete_transient')
                        && get_transient('site_export_activated')
                    ) {
                        set_transient('reprint_server_activated', 1, 30);
                        delete_transient('site_export_activated');
                    }
                },
                -PHP_INT_MAX
            );
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
