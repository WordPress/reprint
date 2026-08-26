<?php

namespace WordPress\Reprint\Server\Plugin;

/** UI-independent WordPress configuration integration for Reprint Server. */

/** Register the option and hooks used by the option-backed configuration. */
function register_wordpress_configuration(): void {
    static $registered = false;

    if ($registered) {
        return;
    }
    $registered = true;

    add_action('admin_init', __NAMESPACE__ . '\\register_connection_token_setting');
    add_action('rest_api_init', __NAMESPACE__ . '\\register_connection_token_setting');
    add_action(
        'update_option_' . \SITE_EXPORT_SECRET_OPTION,
        __NAMESPACE__ . '\\revoke_push_authorization_after_connection_token_change',
        10,
        2
    );
    add_action(
        'add_option_' . \SITE_EXPORT_SECRET_OPTION,
        __NAMESPACE__ . '\\revoke_push_authorization_after_connection_token_added',
        10,
        0
    );
}

/** Register the connection token for the Settings and REST APIs. */
function register_connection_token_setting(): void {
    register_setting(
        'reprint_server',
        \SITE_EXPORT_SECRET_OPTION,
        [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'show_in_rest' => true,
        ]
    );
}

/**
 * Revoke local push authorization when the effective connection token changes.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $new_value New option value.
 */
function revoke_push_authorization_after_connection_token_change($old_value, $new_value): void {
    if (\_site_export_has_secret_file()) {
        return;
    }

    $old_connection_token = is_string($old_value) && $old_value !== '' ? $old_value : null;
    $new_connection_token = is_string($new_value) && $new_value !== '' ? $new_value : null;
    if ($old_connection_token !== $new_connection_token) {
        \_site_export_update_push_authorization(false);
    }
}

/** Revoke stale local push authorization when the connection-token option is added. */
function revoke_push_authorization_after_connection_token_added(): void {
    if (!\_site_export_has_secret_file()) {
        \_site_export_update_push_authorization(false);
    }
}

/**
 * Applies an option-backed connection-token change.
 *
 * @return string One of saved, unchanged, or storage_failure.
 */
function change_connection_token(string $connection_token): string {
    if (\_site_export_get_option_secret() === $connection_token) {
        return 'unchanged';
    }

    return \_site_export_update_shared_secret($connection_token) ? 'saved' : 'storage_failure';
}

/**
 * Returns the configuration state used by WordPress integrations.
 *
 * @return array {
 *     Current Reprint Server configuration state.
 *
 *     @type string    $stored_connection_token Option-backed connection token.
 *     @type bool      $is_configured Whether an effective connection token exists.
 *     @type bool      $has_secret_file Whether secret.php supplies the effective connection token.
 *     @type bool      $push_supported Whether this PHP runtime can serve push endpoints.
 *     @type bool|null $managed_push_enabled Hosting-provider push policy, or null when the site controls it.
 *     @type bool      $push_enabled Whether push is authorized for the current connection token.
 * }
 * @phpstan-return array{
 *     stored_connection_token:string,
 *     is_configured:bool,
 *     has_secret_file:bool,
 *     push_supported:bool,
 *     managed_push_enabled:bool|null,
 *     push_enabled:bool
 * }
 */
function get_configuration_state(): array {
    $effective_connection_token = \_site_export_get_shared_secret();
    $push_supported = \_site_export_push_is_supported();

    return [
        'stored_connection_token' => \_site_export_get_option_secret(),
        'is_configured' => $effective_connection_token !== null && $effective_connection_token !== '',
        'has_secret_file' => \_site_export_has_secret_file(),
        'push_supported' => $push_supported,
        'managed_push_enabled' => \_site_export_get_managed_push_enabled(),
        'push_enabled' => $push_supported && \_site_export_is_push_authorized(),
    ];
}

/**
 * Applies a site-controlled push-access change.
 *
 * @return string One of saved, unchanged, unsupported, managed,
 *                not_configured, or storage_failure.
 */
function change_push_access(bool $enabled): string {
    if (!\_site_export_push_is_supported()) {
        return 'unsupported';
    }

    if (\_site_export_get_managed_push_enabled() !== null) {
        return 'managed';
    }

    $connection_token = \_site_export_get_shared_secret();
    if ($enabled && ( $connection_token === null || $connection_token === '' )) {
        return 'not_configured';
    }

    $fingerprint = $enabled ? hash('sha256', $connection_token) : '';
    if (
        function_exists('get_option')
        && get_option(\SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, '') === $fingerprint
    ) {
        return 'unchanged';
    }

    return \_site_export_update_push_authorization($enabled) ? 'saved' : 'storage_failure';
}
