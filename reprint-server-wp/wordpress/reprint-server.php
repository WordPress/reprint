<?php

namespace WordPress\Reprint\Server\Plugin;

/**
 * Admin interface for Reprint Server plugin.
 *
 * This plugin provides a WordPress admin UI for configuring Reprint Server.
 * The server API is triggered via `?reprint-api` during plugin load,
 * before WordPress finishes booting. It reads the connection token from a site option,
 * with secret.php supported only as an override when present.
 *
 * Authentication uses HMAC signatures: the importing side generates a connection token,
 * the user enters it here, and all requests must include a valid signature
 * computed from the nonce, timestamp, and request content hash.
 */
class SettingsPage {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_settings']);
        add_action(
            'update_option_' . CONNECTION_TOKEN_OPTION,
            [$this, 'revoke_push_authorization_after_connection_token_change'],
            10,
            2
        );
        add_action(
            'add_option_' . CONNECTION_TOKEN_OPTION,
            [$this, 'revoke_push_authorization_after_connection_token_added'],
            10,
            0
        );
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_filter('plugin_action_links_' . plugin_basename(PLUGIN_DIR . 'index.php'), [$this, 'add_settings_link']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_node'], 100);
    }

    /** Register the option so core's /wp/v2/settings endpoint can update it. */
    public function register_settings() {
        register_setting(
            'general',
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
    public function revoke_push_authorization_after_connection_token_change($old_value, $new_value) {
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
     *
     */
    public function revoke_push_authorization_after_connection_token_added() {
        if (!has_connection_token_file()) {
            update_push_authorization(false);
        }
    }

    /**
     * Add "Settings" link to the plugin row on the Plugins page.
     */
    public function add_settings_link(array $links): array {
        $url = admin_url('admin.php?page=reprint-server');
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }

    /**
     * Add top-level admin menu page.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Reprint Server',
            'Reprint Server',
            'manage_options',
            'reprint-server',
            [$this, 'render_admin_page'],
            'dashicons-cloud-upload'
        );
    }

    /**
     * Add "Reprint Server" link to the admin bar.
     */
    public function add_admin_bar_node($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'reprint-server',
            'title' => 'Reprint Server',
            'href'  => admin_url('admin.php?page=reprint-server'),
            'meta'  => ['title' => 'Reprint Server'],
        ]);
    }

    /**
     * Handle settings form submission.
     */
    public function handle_settings_save() {
        $saving_connection_token = isset($_POST['reprint_server_save_settings']);
        $saving_push_access = isset($_POST['reprint_server_save_push_access']);
        if (!$saving_connection_token && !$saving_push_access) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        /**
         * Filters the nonce action used by a Reprint Server settings request.
         *
         * @param string $nonce_action Nonce action name.
         */
        $nonce_action = apply_filters('reprint_server_settings_nonce_action', 'reprint_server_settings');
        check_admin_referer(is_string($nonce_action) ? $nonce_action : 'reprint_server_settings');

        if ($saving_connection_token) {
            if (isset($_POST['reprint_server_connection_token'])) {
                $connection_token = sanitize_text_field(
                    wp_unslash($_POST['reprint_server_connection_token'])
                );
            } else {
                $connection_token = '';
            }
            $updated = update_connection_token($connection_token);
            $saved = $updated || get_option_connection_token() === $connection_token;

            if (!$saved) {
                add_settings_error(
                    'reprint_server',
                    'save_failed',
                    'Failed to save connection token.',
                    'error'
                );
                return;
            }

            add_settings_error(
                'reprint_server',
                'save_success',
                'Settings saved successfully.',
                'success'
            );
            return;
        }

        if (!push_is_supported()) {
            add_settings_error(
                'reprint_server',
                'push_access_unsupported',
                'Push access requires PHP 7.2 or newer. Downloads remain available.',
                'error'
            );
            return;
        }

        if (get_managed_push_enabled() !== null) {
            add_settings_error(
                'reprint_server',
                'push_access_managed',
                'Push access is managed by your hosting provider.',
                'info'
            );
            return;
        }

        $push_enabled = isset($_POST['reprint_server_push_enabled']);
        if (!update_push_authorization($push_enabled)) {
            add_settings_error('reprint_server', 'push_access_save_failed', 'Failed to save push access.', 'error');
            return;
        }

        add_settings_error('reprint_server', 'push_access_saved', 'Push access updated.', 'success');
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stored_connection_token = get_option_connection_token();
        $effective_connection_token = get_connection_token() ?? '';
        $api_url = home_url('?reprint-api');
        $is_configured = $effective_connection_token !== '';
        $has_file_override = has_connection_token_file();
        $push_supported = push_is_supported();
        $managed_push_enabled = get_managed_push_enabled();
        $push_enabled = $push_supported && is_push_authorized();

        ?>
        <style>
            .reprint-server-wrap {
                max-width: 680px;
                margin: 40px auto 0;
                font-size: 14px;
            }
            .reprint-server-wrap h1 {
                font-size: 28px;
                font-weight: 600;
                margin-bottom: 4px;
            }
            .reprint-server-wrap .subtitle {
                color: #646970;
                font-size: 14px;
                margin: 0 0 30px;
            }
            .reprint-server-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 28px 32px;
                margin-bottom: 24px;
            }
            .reprint-server-card h2 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 6px;
                padding: 0;
            }
            .reprint-server-card .card-desc {
                color: #646970;
                margin: 0 0 20px;
            }
            .reprint-server-push-access {
                padding: 20px 24px;
            }
            .reprint-server-push-access label {
                display: flex;
                gap: 8px;
                align-items: flex-start;
                font-weight: 600;
            }
            .reprint-server-push-access input[type="checkbox"] {
                margin-top: 1px;
            }
            .reprint-server-push-disclosure {
                color: #646970;
                font-size: 13px;
                margin: 10px 0 16px 24px;
            }
            .reprint-server-managed-copy {
                color: #646970;
                margin: 12px 0 0 24px;
            }
            .reprint-server-connection-token-field {
                display: flex;
                gap: 8px;
                align-items: start;
            }
            .reprint-server-connection-token-field input[type="password"],
            .reprint-server-connection-token-field input[type="text"] {
                flex: 1;
                font-family: monospace;
                font-size: 14px;
                padding: 8px 12px;
                border-radius: 4px;
            }
            .reprint-server-connection-token-field .button {
                flex-shrink: 0;
                height: 38px;
            }
            .reprint-server-toggle-btn {
                background: none;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                cursor: pointer;
                padding: 6px 10px;
                color: #50575e;
                height: 38px;
                display: inline-flex;
                align-items: center;
            }
            .reprint-server-toggle-btn:hover {
                border-color: #2271b1;
                color: #2271b1;
            }
            .reprint-server-status {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 14px 18px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .reprint-server-status.is-ready {
                background: #edfaef;
                border: 1px solid #b8e6be;
                color: #1e4620;
            }
            .reprint-server-status.is-pending {
                background: #fef8ee;
                border: 1px solid #f0d9a8;
                color: #6e4e00;
            }
            .reprint-server-status .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .reprint-server-endpoint {
                background: #f6f7f7;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 14px 18px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .reprint-server-endpoint code {
                flex: 1;
                font-size: 13px;
                word-break: break-all;
                background: none;
                padding: 0;
            }
            .reprint-server-copy-btn {
                background: none;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                cursor: pointer;
                padding: 4px 10px;
                color: #50575e;
                font-size: 12px;
                white-space: nowrap;
            }
            .reprint-server-copy-btn:hover {
                border-color: #2271b1;
                color: #2271b1;
            }
        </style>

        <div class="reprint-server-wrap">
            <h1>Reprint Server</h1>
            <p class="subtitle">Allow an external tool to download your site's database and files.</p>

            <?php settings_errors('reprint_server'); ?>

            <?php if ($has_file_override): ?>
            <div class="reprint-server-status is-pending">
                <span class="dashicons dashicons-lock"></span>
                <span><strong><code>secret.php</code> override is active.</strong> This screen and the REST API update only the site option. Remove <code>secret.php</code> to use the stored option value.</span>
            </div>
            <?php endif; ?>

            <?php if ($is_configured): ?>
            <div class="reprint-server-status is-ready">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php if ($push_enabled): ?>
                <span><strong>Connected for downloads and push.</strong> The current connection token can change files on this site.</span>
                <?php else: ?>
                <span><strong>Connected for downloads.</strong> The connection token cannot change files on this site.</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="reprint-server-status is-pending">
                <span class="dashicons dashicons-warning"></span>
                <span><strong>Not configured yet.</strong> Paste the connection token from your import tool below to get started.</span>
            </div>
            <?php endif; ?>

            <div class="reprint-server-card">
                <h2>Connection Token</h2>
                <p class="card-desc">
                    Your import tool will give you a token. Paste it here to authorize the connection.
                </p>

                <form method="post" action="">
                    <?php wp_nonce_field('reprint_server_settings'); ?>

                    <div class="reprint-server-connection-token-field">
                        <input type="password"
                               id="reprint_server_connection_token"
                               name="reprint_server_connection_token"
                               value="<?php echo esc_attr($stored_connection_token); ?>"
                               placeholder="Paste your token here"
                               autocomplete="off" />
                        <button type="button" class="reprint-server-toggle-btn" onclick="reprintServerToggleConnectionToken()" title="Show / hide token">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                        <input type="submit"
                               name="reprint_server_save_settings"
                               class="button button-primary"
                               value="Save" />
                    </div>
                </form>
            </div>

            <?php if ($is_configured): ?>
            <div class="reprint-server-card reprint-server-push-access">
                <h2>Push access</h2>
                <p class="card-desc">You do not need push access when moving this site to another host.</p>

                <?php if (!$push_supported): ?>
                <p class="card-desc">Push access requires PHP 7.2 or newer. This site runs PHP <?php echo esc_html(PHP_VERSION); ?>. Downloads remain available.</p>
                <?php elseif ($managed_push_enabled !== null): ?>
                <label>
                    <input type="checkbox" name="reprint_server_push_enabled" value="1"<?php echo $push_enabled ? ' checked' : ''; ?> disabled />
                    <span>Allow push to change files on this site</span>
                </label>
                <p class="reprint-server-push-disclosure">While enabled, anyone with the connection token can upload, replace, and delete files in this site's document root, except excluded paths.</p>
                <p class="reprint-server-managed-copy">Push access is managed by your hosting provider.</p>
                <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('reprint_server_settings'); ?>
                    <label>
                        <input type="checkbox" name="reprint_server_push_enabled" value="1"<?php echo $push_enabled ? ' checked' : ''; ?> />
                        <span>Allow push to change files on this site</span>
                    </label>
                    <p class="reprint-server-push-disclosure">While enabled, anyone with the connection token can upload, replace, and delete files in this site's document root, except excluded paths.</p>
                    <input type="submit"
                           name="reprint_server_save_push_access"
                           class="button button-secondary"
                           value="Save push access" />
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_configured): ?>
            <div class="reprint-server-card">
                <h2>API Endpoint</h2>
                <p class="card-desc">
                    If your import tool asks for an endpoint URL, copy this:
                </p>
                <div class="reprint-server-endpoint">
                    <code id="reprint-server-api-url"><?php echo esc_html($api_url); ?></code>
                    <button type="button" class="reprint-server-copy-btn" onclick="reprintServerCopyUrl()">Copy</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function reprintServerToggleConnectionToken() {
            var input = document.getElementById('reprint_server_connection_token');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
        function reprintServerCopyUrl() {
            var url = document.getElementById('reprint-server-api-url').textContent.trim();
            navigator.clipboard.writeText(url).then(function() {
                var btn = document.querySelector('.reprint-server-copy-btn');
                var original = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = original; }, 1500);
            });
        }
        </script>
        <?php
    }
}

// Initialize
add_action('plugins_loaded', function() {
    SettingsPage::get_instance();
});

// On activation: set a transient so we can redirect on the next admin page load.
register_activation_hook(PLUGIN_DIR . 'index.php', function() {
    // Only redirect when activated through the admin UI (not via WP-CLI or bulk).
    if (!wp_doing_ajax() && is_admin()) {
        set_transient('reprint_server_activated', 1, 30);
    }

    $gitignore = PLUGIN_DIR . '.gitignore';
    if (!file_exists($gitignore)) {
        file_put_contents($gitignore, "secret.php\n");
    }
});

// Redirect to settings page after activation.
add_action('admin_init', function() {
    if (get_transient('reprint_server_activated')) {
        delete_transient('reprint_server_activated');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only suppresses activation redirects for bulk activation.
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('admin.php?page=reprint-server'));
            exit;
        }
    }
});
