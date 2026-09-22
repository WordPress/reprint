<?php

namespace WordPress\Reprint\Server\Plugin;

/**
 * UI-independent WordPress configuration integration for Reprint Server.
 *
 * A project embedding lib.php can require this file to get the option-backed
 * connection token, its push-authorization revocation hooks, the option-backed
 * public-key enrollment, and the read and write operations behind them,
 * without loading the bundled administrator.
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
    add_action('admin_init', __NAMESPACE__ . '\\register_public_keys_setting');
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
    // Core's Settings REST controller reads site options, not network options.
    if (function_exists('is_multisite') && is_multisite()) {
        return;
    }
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

/** Register the public-keys option. Never exposed through REST: writing it is the option-write path this work narrows. */
function register_public_keys_setting(): void {
    if (function_exists('is_multisite') && is_multisite()) {
        return;
    }
    // Own group: options.php resets every option in a submitted group that the form did not post,
    // so sharing the token form's reprint_server group would empty the keys on each token save.
    register_setting(
        'reprint_server_public_keys',
        PUBLIC_KEYS_OPTION,
        [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => __NAMESPACE__ . '\\sanitize_public_keys_option',
        ]
    );
}

/**
 * Keeps only entries that carry a valid RSA public key when the option is
 * written through the Settings API, so an options.php POST in the keys' own
 * group cannot store entries enroll_public_key() would have refused.
 *
 * @param mixed $value Submitted option value.
 * @return array[] Entries in the shape normalize_public_key_entry() returns; empty
 *                 when the value is not an array or the server runtime is missing.
 */
function sanitize_public_keys_option($value): array {
    if (!is_array($value) || !require_server_runtime()) {
        return [];
    }
    $entries = [];
    foreach ($value as $stored_entry) {
        $entry = normalize_public_key_entry($stored_entry);
        if ($entry === null) {
            continue;
        }
        // assert_valid_public_key() throws for every key on a host without
        // OpenSSL. Keys are inert there but the settings page still lets the
        // administrator remove one or change its push grant, and dropping the
        // rest on that write would leave the site not_configured once it moves
        // back to an OpenSSL host. The normalized entry is already strict base64.
        if (function_exists('openssl_pkey_get_public')) {
            try {
                $entry['public_key'] = \WordPress\Reprint\Server\PublicKeyServer::assert_valid_public_key($entry['public_key']);
            } catch (\InvalidArgumentException $exception) {
                continue;
            }
        }
        $entries[] = $entry;
    }
    return $entries;
}

/** @var string|null Key id of the entry enroll_public_key() last stored. */
$GLOBALS['reprint_server_last_enrolled_key_id'] = null;

/** Returns the key id of the entry enroll_public_key() last stored, or null when the last call did not store one. */
function get_last_enrolled_key_id(): ?string {
    return $GLOBALS['reprint_server_last_enrolled_key_id'] ?? null;
}

/**
 * Validates and stores one pasted public key.
 *
 * @return string saved, invalid, duplicate, file_override, storage_failure, or
 *                runtime_missing when the server runtime cannot be loaded.
 */
function enroll_public_key(string $pasted_key, string $label): string {
    $GLOBALS['reprint_server_last_enrolled_key_id'] = null;
    if (!require_server_runtime()) {
        return 'runtime_missing';
    }
    if (has_public_keys_file()) {
        return 'file_override';
    }
    try {
        $public_key = \WordPress\Reprint\Server\PublicKeyServer::assert_valid_public_key($pasted_key);
    } catch (\InvalidArgumentException $exception) {
        return 'invalid';
    }
    $key_id = \WordPress\Reprint\Server\Utils::public_key_fingerprint($public_key);
    $entries = get_option_public_keys();
    foreach ($entries as $entry) {
        if ($entry['key_id'] === $key_id) {
            return 'duplicate';
        }
    }
    $entries[] = [
        'key_id' => $key_id,
        'public_key' => $public_key,
        'label' => $label,
        'added_at' => time(),
        'push' => false,
    ];
    if (!update_option_public_keys($entries)) {
        return 'storage_failure';
    }
    $GLOBALS['reprint_server_last_enrolled_key_id'] = $key_id;
    return 'saved';
}

/**
 * Removes one enrolled key. Refuses the last key on a host that requires
 * key auth, since that would lock every client out.
 *
 * @return string saved, unknown, last_key, file_override, storage_failure, or
 *                runtime_missing when the server runtime cannot be loaded.
 */
function remove_public_key(string $key_id): string {
    // Reading the entries below already needs the runtime: without it every
    // stored entry normalizes to null and the key would look unknown.
    if (!require_server_runtime()) {
        return 'runtime_missing';
    }
    if (has_public_keys_file()) {
        return 'file_override';
    }
    $entries = get_option_public_keys();
    $remaining = [];
    $found = false;
    foreach ($entries as $entry) {
        if ($entry['key_id'] === $key_id) {
            $found = true;
            continue;
        }
        $remaining[] = $entry;
    }
    if (!$found) {
        return 'unknown';
    }
    if ($remaining === [] && \WordPress\Reprint\Server\Utils::key_auth_required()) {
        return 'last_key';
    }
    return update_option_public_keys($remaining) ? 'saved' : 'storage_failure';
}

/**
 * Grants or revokes push for one key.
 *
 * @return string saved, unchanged, unknown, multisite, unsupported, managed, file_override, storage_failure,
 *                or runtime_missing when the server runtime cannot be loaded.
 */
function change_key_push_access(string $key_id, bool $enabled): string {
    if (!require_server_runtime()) {
        return 'runtime_missing';
    }
    // get_push_authorization_error() refuses every push into a network, so a grant could never take effect.
    if (function_exists('is_multisite') && is_multisite()) {
        return 'multisite';
    }
    if (!push_is_supported()) {
        return 'unsupported';
    }
    if (get_managed_push_enabled() !== null) {
        return 'managed';
    }
    if (has_public_keys_file()) {
        return 'file_override';
    }
    $entries = get_option_public_keys();
    $found = false;
    foreach ($entries as $index => $entry) {
        if ($entry['key_id'] !== $key_id) {
            continue;
        }
        $found = true;
        if ($entry['push'] === $enabled) {
            return 'unchanged';
        }
        $entries[$index]['push'] = $enabled;
    }
    if (!$found) {
        return 'unknown';
    }
    return update_option_public_keys($entries) ? 'saved' : 'storage_failure';
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
 *     @type bool      $is_configured Whether a credential the host accepts exists: an enrolled key on a key host,
 *                                    an effective connection token on an HMAC host.
 *     @type bool      $has_connection_token_file Whether secret.php supplies the effective connection token.
 *     @type bool      $push_supported Whether this PHP runtime can serve push endpoints.
 *     @type bool|null $managed_push_enabled Hosting-provider push policy, or null when the site controls it.
 *     @type bool      $push_enabled Whether push is authorized for any enrolled key on a key host, or for the
 *                                   current connection token on an HMAC host.
 *     @type string    $required_scheme Scheme this host accepts: key when OpenSSL is available, otherwise hmac.
 *     @type array[]   $enrolled_keys Effective enrolled keys, in the shape normalize_public_key_entry() returns.
 *     @type bool      $has_public_keys_file Whether public-keys.php supplies the enrolled keys.
 * }
 * @phpstan-return array{
 *     stored_connection_token:string,
 *     is_configured:bool,
 *     has_connection_token_file:bool,
 *     push_supported:bool,
 *     managed_push_enabled:bool|null,
 *     push_enabled:bool,
 *     required_scheme:'key'|'hmac',
 *     enrolled_keys:array<int,array{key_id:string,public_key:string,label:string,added_at:int,push:bool}>,
 *     has_public_keys_file:bool
 * }
 */
function get_configuration_state(): array {
    if (require_server_runtime()) {
        // The normal path: the host rule and its test seam live in the runtime.
        $key_auth_required = \WordPress\Reprint\Server\Utils::key_auth_required();
    } else {
        // Only reached when the Composer runtime is missing, where the API
        // answers 500 "runtime is incomplete". The page still needs a scheme
        // to render, so apply the same OpenSSL rule Utils::key_auth_required()
        // uses.
        $key_auth_required = function_exists('openssl_verify');
    }
    $enrolled_keys = get_enrolled_public_keys();
    $effective_connection_token = get_connection_token();
    $push_supported = push_is_supported();

    if ($key_auth_required) {
        $is_configured = $enrolled_keys !== [];
        // get_push_authorization_error() applies the multisite refusal and the managed policy before the key's flag.
        $push_enabled = false;
        foreach ($enrolled_keys as $entry) {
            if (get_push_authorization_error($entry['key_id']) === null) {
                $push_enabled = true;
                break;
            }
        }
    } else {
        $is_configured = $effective_connection_token !== null && $effective_connection_token !== '';
        $push_enabled = is_push_authorized();
    }

    return [
        'stored_connection_token' => get_option_connection_token(),
        'is_configured' => $is_configured,
        'has_connection_token_file' => has_connection_token_file(),
        'push_supported' => $push_supported,
        'managed_push_enabled' => get_managed_push_enabled(),
        'push_enabled' => $push_supported && $push_enabled,
        'required_scheme' => $key_auth_required ? 'key' : 'hmac',
        'enrolled_keys' => $enrolled_keys,
        'has_public_keys_file' => has_public_keys_file(),
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
