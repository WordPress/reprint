<?php

namespace WordPress\Reprint\Server\Plugin;

/**
 * UI-independent WordPress configuration integration for Reprint Server.
 *
 * A project embedding lib.php can require this file to get the option-backed
 * connection token, its push-authorization revocation hooks, and the read and
 * write operations behind them, without loading the bundled administrator.
 */

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
        'update_option_' . CONNECTION_TOKEN_OPTION,
        __NAMESPACE__ . '\\revoke_push_authorization_after_connection_token_change',
        10,
        2
    );
    add_action(
        'add_option_' . CONNECTION_TOKEN_OPTION,
        __NAMESPACE__ . '\\revoke_push_authorization_after_connection_token_added',
        10,
        0
    );
}

/** Register the connection token for the Settings and REST APIs. */
function register_connection_token_setting(): void {
    register_setting(
        'reprint_server',
        CONNECTION_TOKEN_OPTION,
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
 * The secret.php override keeps the option from becoming the effective token.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $new_value New option value.
 */
function revoke_push_authorization_after_connection_token_change($old_value, $new_value): void {
    if (has_connection_token_file()) {
        return;
    }

    $old_connection_token = is_string($old_value) && $old_value !== '' ? $old_value : null;
    $new_connection_token = is_string($new_value) && $new_value !== '' ? $new_value : null;
    if ($old_connection_token !== $new_connection_token) {
        update_push_authorization(false);
    }
}

/**
 * Revoke stale local push authorization when the connection-token option is added.
 *
 * WordPress uses add_option() when update_option() receives a missing option,
 * so that path does not emit the update hook above. A secret.php override
 * still keeps the option from becoming the effective token.
 */
function revoke_push_authorization_after_connection_token_added(): void {
    if (!has_connection_token_file()) {
        update_push_authorization(false);
    }
}

/**
 * Returns the configuration state used by WordPress integrations.
 *
 * @return array {
 *     Current Reprint Server configuration state.
 *
 *     @type string    $stored_connection_token Option-backed connection token.
 *     @type bool      $is_configured Whether an effective connection token exists.
 *     @type bool      $has_connection_token_file Whether secret.php supplies the effective connection token.
 *     @type bool      $push_supported Whether this PHP runtime can serve push endpoints.
 *     @type bool|null $managed_push_enabled Hosting-provider push policy, or null when the site controls it.
 *     @type bool      $push_enabled Whether push is authorized for the current connection token.
 * }
 * @phpstan-return array{
 *     stored_connection_token:string,
 *     is_configured:bool,
 *     has_connection_token_file:bool,
 *     push_supported:bool,
 *     managed_push_enabled:bool|null,
 *     push_enabled:bool
 * }
 */
function get_configuration_state(): array {
    $effective_connection_token = get_connection_token();
    $push_supported = push_is_supported();

    return [
        'stored_connection_token' => get_option_connection_token(),
        'is_configured' => $effective_connection_token !== null && $effective_connection_token !== '',
        'has_connection_token_file' => has_connection_token_file(),
        'push_supported' => $push_supported,
        'managed_push_enabled' => get_managed_push_enabled(),
        'push_enabled' => $push_supported && is_push_authorized(),
    ];
}

/**
 * Applies an option-backed connection-token change.
 *
 * @return string One of saved, unchanged, or storage_failure.
 */
function change_connection_token(string $connection_token): string {
    if (get_option_connection_token() === $connection_token) {
        return 'unchanged';
    }

    return update_connection_token($connection_token) ? 'saved' : 'storage_failure';
}

/**
 * Applies a site-controlled push-access change.
 *
 * @return string One of saved, unchanged, unsupported, managed,
 *                not_configured, or storage_failure.
 */
function change_push_access(bool $enabled): string {
    if (!push_is_supported()) {
        return 'unsupported';
    }

    if (get_managed_push_enabled() !== null) {
        return 'managed';
    }

    $connection_token = get_connection_token();
    if ($enabled && ( $connection_token === null || $connection_token === '' )) {
        return 'not_configured';
    }

    $fingerprint = $enabled ? hash('sha256', $connection_token) : '';
    if (
        function_exists('get_option')
        && get_option(PUSH_AUTHORIZATION_OPTION, '') === $fingerprint
    ) {
        return 'unchanged';
    }

    return update_push_authorization($enabled) ? 'saved' : 'storage_failure';
}
