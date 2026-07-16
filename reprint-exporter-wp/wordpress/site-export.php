<?php
/**
 * Admin interface for Reprint Exporter plugin.
 *
 * This plugin provides a WordPress admin UI for configuring the export API.
 * The export API is triggered via `?reprint-api` (or the legacy
 * `?site-export-api` alias) during plugin load,
 * before WordPress finishes booting. It reads the secret from a site option,
 * with secret.php supported only as an override when present.
 *
 * Authentication uses HMAC signatures: the importing side generates a secret,
 * the user enters it here, and all requests must include a valid signature
 * computed from the nonce, timestamp, and request content hash.
 */
class Site_Export_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_filter('plugin_action_links_' . plugin_basename(SITE_EXPORT_PLUGIN_DIR . 'index.php'), [$this, 'add_settings_link']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_node'], 100);
    }

    /** Register the option so core's /wp/v2/settings endpoint can update it. */
    public function register_settings() {
        register_setting(
            'general',
            SITE_EXPORT_SECRET_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
                'show_in_rest' => true,
            ]
        );
    }

    /**
     * Add "Settings" link to the plugin row on the Plugins page.
     */
    public function add_settings_link(array $links): array {
        $url = admin_url('admin.php?page=site-export');
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }

    /**
     * Add top-level admin menu page.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Reprint Exporter',
            'Reprint Exporter',
            'manage_options',
            'site-export',
            [$this, 'render_admin_page'],
            'dashicons-cloud-upload'
        );
    }

    /**
     * Add "Reprint Exporter" link to the admin bar.
     */
    public function add_admin_bar_node($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'site-export',
            'title' => 'Reprint Exporter',
            'href'  => admin_url('admin.php?page=site-export'),
            'meta'  => ['title' => 'Reprint Exporter'],
        ]);
    }

    /**
     * Handle settings form submission.
     */
    public function handle_settings_save() {
        $saving_connection_token = isset($_POST['site_export_save_settings']);
        $saving_push_access = isset($_POST['site_export_save_push_access']);
        if (!$saving_connection_token && !$saving_push_access) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('site_export_settings');

        if ($saving_connection_token) {
            $previous_effective_secret = _site_export_get_shared_secret();
            $secret = isset($_POST['site_export_secret'])
                ? sanitize_text_field(wp_unslash($_POST['site_export_secret']))
                : '';
            $updated = _site_export_update_shared_secret($secret);
            $saved = $updated || _site_export_get_option_secret() === $secret;

            if (!$saved) {
                add_settings_error('site_export', 'save_failed', 'Failed to save secret.', 'error');
                return;
            }

            if (_site_export_get_shared_secret() !== $previous_effective_secret) {
                _site_export_update_push_authorization(false);
            }
            add_settings_error(
                'site_export',
                'save_success',
                'Settings saved successfully.',
                'success'
            );
            return;
        }

        if (_site_export_get_managed_push_enabled() !== null) {
            add_settings_error(
                'site_export',
                'push_access_managed',
                'Push access is managed by your hosting provider.',
                'info'
            );
            return;
        }

        $push_enabled = isset($_POST['site_export_push_enabled']);
        if (!_site_export_update_push_authorization($push_enabled)) {
            add_settings_error('site_export', 'push_access_save_failed', 'Failed to save push access.', 'error');
            return;
        }

        add_settings_error('site_export', 'push_access_saved', 'Push access updated.', 'success');
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stored_secret = _site_export_get_option_secret();
        $effective_secret = _site_export_get_shared_secret() ?? '';
        $api_url = home_url('?reprint-api');
        $is_configured = $effective_secret !== '';
        $has_file_override = _site_export_has_secret_file();
        $managed_push_enabled = _site_export_get_managed_push_enabled();
        $push_enabled = _site_export_is_push_authorized();

        ?>
        <style>
            .site-export-wrap {
                max-width: 680px;
                margin: 40px auto 0;
                font-size: 14px;
            }
            .site-export-wrap h1 {
                font-size: 28px;
                font-weight: 600;
                margin-bottom: 4px;
            }
            .site-export-wrap .subtitle {
                color: #646970;
                font-size: 14px;
                margin: 0 0 30px;
            }
            .site-export-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 28px 32px;
                margin-bottom: 24px;
            }
            .site-export-card h2 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 6px;
                padding: 0;
            }
            .site-export-card .card-desc {
                color: #646970;
                margin: 0 0 20px;
            }
            .site-export-push-access {
                padding: 20px 24px;
            }
            .site-export-push-access label {
                display: flex;
                gap: 8px;
                align-items: flex-start;
                font-weight: 600;
            }
            .site-export-push-access input[type="checkbox"] {
                margin-top: 1px;
            }
            .site-export-push-disclosure {
                color: #646970;
                font-size: 13px;
                margin: 10px 0 16px 24px;
            }
            .site-export-managed-copy {
                color: #646970;
                margin: 12px 0 0 24px;
            }
            .site-export-secret-field {
                display: flex;
                gap: 8px;
                align-items: start;
            }
            .site-export-secret-field input[type="password"],
            .site-export-secret-field input[type="text"] {
                flex: 1;
                font-family: monospace;
                font-size: 14px;
                padding: 8px 12px;
                border-radius: 4px;
            }
            .site-export-secret-field .button {
                flex-shrink: 0;
                height: 38px;
            }
            .site-export-toggle-btn {
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
            .site-export-toggle-btn:hover {
                border-color: #2271b1;
                color: #2271b1;
            }
            .site-export-status {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 14px 18px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .site-export-status.is-ready {
                background: #edfaef;
                border: 1px solid #b8e6be;
                color: #1e4620;
            }
            .site-export-status.is-pending {
                background: #fef8ee;
                border: 1px solid #f0d9a8;
                color: #6e4e00;
            }
            .site-export-status .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .site-export-endpoint {
                background: #f6f7f7;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 14px 18px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .site-export-endpoint code {
                flex: 1;
                font-size: 13px;
                word-break: break-all;
                background: none;
                padding: 0;
            }
            .site-export-copy-btn {
                background: none;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                cursor: pointer;
                padding: 4px 10px;
                color: #50575e;
                font-size: 12px;
                white-space: nowrap;
            }
            .site-export-copy-btn:hover {
                border-color: #2271b1;
                color: #2271b1;
            }
        </style>

        <div class="site-export-wrap">
            <h1>Reprint Exporter</h1>
            <p class="subtitle">Allow an external tool to download your site's database and files.</p>

            <?php settings_errors('site_export'); ?>

            <?php if ($has_file_override): ?>
            <div class="site-export-status is-pending">
                <span class="dashicons dashicons-lock"></span>
                <span><strong><code>secret.php</code> override is active.</strong> This screen and the REST API update only the site option. Remove <code>secret.php</code> to use the stored option value.</span>
            </div>
            <?php endif; ?>

            <?php if ($is_configured): ?>
            <div class="site-export-status is-ready">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php if ($push_enabled): ?>
                <span><strong>Connected for downloads and push.</strong> The current connection token can change files on this site.</span>
                <?php else: ?>
                <span><strong>Connected for downloads.</strong> The connection token cannot change files on this site.</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="site-export-status is-pending">
                <span class="dashicons dashicons-warning"></span>
                <span><strong>Not configured yet.</strong> Paste the connection token from your import tool below to get started.</span>
            </div>
            <?php endif; ?>

            <div class="site-export-card">
                <h2>Connection Token</h2>
                <p class="card-desc">
                    Your import tool will give you a token. Paste it here to authorize the connection.
                </p>

                <form method="post" action="">
                    <?php wp_nonce_field('site_export_settings'); ?>

                    <div class="site-export-secret-field">
                        <input type="password"
                               id="site_export_secret"
                               name="site_export_secret"
                               value="<?php echo esc_attr($stored_secret); ?>"
                               placeholder="Paste your token here"
                               autocomplete="off" />
                        <button type="button" class="site-export-toggle-btn" onclick="siteExportToggleSecret()" title="Show / hide token">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                        <input type="submit"
                               name="site_export_save_settings"
                               class="button button-primary"
                               value="Save" />
                    </div>
                </form>
            </div>

            <?php if ($is_configured): ?>
            <div class="site-export-card site-export-push-access">
                <h2>Push access</h2>
                <p class="card-desc">You do not need push access when moving this site to another host.</p>

                <?php if ($managed_push_enabled !== null): ?>
                <label>
                    <input type="checkbox" name="site_export_push_enabled" value="1"<?php echo $push_enabled ? ' checked' : ''; ?> disabled />
                    <span>Allow push to change files on this site</span>
                </label>
                <p class="site-export-push-disclosure">While enabled, anyone with the connection token can upload, replace, and delete files in this site's document root, except excluded paths.</p>
                <p class="site-export-managed-copy">Push access is managed by your hosting provider.</p>
                <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('site_export_settings'); ?>
                    <label>
                        <input type="checkbox" name="site_export_push_enabled" value="1"<?php echo $push_enabled ? ' checked' : ''; ?> />
                        <span>Allow push to change files on this site</span>
                    </label>
                    <p class="site-export-push-disclosure">While enabled, anyone with the connection token can upload, replace, and delete files in this site's document root, except excluded paths.</p>
                    <input type="submit"
                           name="site_export_save_push_access"
                           class="button button-secondary"
                           value="Save push access" />
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_configured): ?>
            <div class="site-export-card">
                <h2>API Endpoint</h2>
                <p class="card-desc">
                    If your import tool asks for an endpoint URL, copy this:
                </p>
                <div class="site-export-endpoint">
                    <code id="site-export-api-url"><?php echo esc_html($api_url); ?></code>
                    <button type="button" class="site-export-copy-btn" onclick="siteExportCopyUrl()">Copy</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function siteExportToggleSecret() {
            var input = document.getElementById('site_export_secret');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
        function siteExportCopyUrl() {
            var url = document.getElementById('site-export-api-url').textContent.trim();
            navigator.clipboard.writeText(url).then(function() {
                var btn = document.querySelector('.site-export-copy-btn');
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
    Site_Export_Plugin::get_instance();
});

// On activation: set a transient so we can redirect on the next admin page load.
register_activation_hook(SITE_EXPORT_PLUGIN_DIR . 'index.php', function() {
    // Only redirect when activated through the admin UI (not via WP-CLI or bulk).
    if (!wp_doing_ajax() && is_admin()) {
        set_transient('site_export_activated', 1, 30);
    }

    $gitignore = SITE_EXPORT_PLUGIN_DIR . '.gitignore';
    if (!file_exists($gitignore)) {
        file_put_contents($gitignore, "secret.php\n");
    }
});

// Redirect to settings page after activation.
add_action('admin_init', function() {
    if (get_transient('site_export_activated')) {
        delete_transient('site_export_activated');
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('admin.php?page=site-export'));
            exit;
        }
    }
});
