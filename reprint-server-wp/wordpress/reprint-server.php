<?php

namespace WordPress\Reprint\Server\Plugin;

/** Bundled WordPress administrator adapter for Reprint Server. */

class SettingsPage {

    private static $instance = null;

    /** @var string|false Page hook returned by add_management_page(). */
    private $page_hook = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings_fields']);
        add_action('admin_post_reprint_server_save_push_access', [$this, 'handle_push_access_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter(
            'plugin_action_links_' . plugin_basename(PLUGIN_DIR . 'index.php'),
            [$this, 'add_settings_link']
        );
    }

    /** Add the bundled page beneath Tools. */
    public function add_admin_menu(): void {
        if (is_multisite()) {
            return;
        }
        $this->page_hook = add_management_page(
            __('Reprint Server', 'reprint'),
            __('Reprint Server', 'reprint'),
            'manage_options',
            'reprint-server',
            [$this, 'render_admin_page']
        );
    }

    /** Register the connection-token section rendered by the Settings API. */
    public function register_settings_fields(): void {
        add_settings_section(
            'reprint_server_connection',
            __('Connection token', 'reprint'),
            [$this, 'render_connection_section'],
            'reprint-server'
        );
        add_settings_field(
            CONNECTION_TOKEN_OPTION,
            __('Connection token', 'reprint'),
            [$this, 'render_connection_token_field'],
            'reprint-server',
            'reprint_server_connection',
            ['label_for' => CONNECTION_TOKEN_OPTION]
        );
    }

    /** Add the Settings link to the plugin row. */
    public function add_settings_link(array $links): array {
        $url = admin_url('tools.php?page=reprint-server');
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'reprint') . '</a>'
        );
        return $links;
    }

    /** Enqueue browser-only behavior on the bundled page. */
    public function enqueue_assets(string $hook_suffix): void {
        if ($this->page_hook === false || $hook_suffix !== $this->page_hook) {
            return;
        }

        wp_enqueue_script(
            'reprint-server-admin',
            plugins_url('wordpress/reprint-server.js', PLUGIN_DIR . 'index.php'),
            ['wp-a11y'],
            VERSION,
            true
        );
    }

    /** Explain where the connection token comes from. */
    public function render_connection_section(): void {
        echo '<p>' . esc_html__(
            'Paste the connection token supplied by the tool which will connect to this site.',
            'reprint'
        ) . '</p>';
    }

    /** Render the option-backed connection-token field. */
    public function render_connection_token_field(): void {
        $configuration = get_configuration_state();
        ?>
        <input type="password"
               class="regular-text code"
               id="reprint_server_connection_token"
               name="<?php echo esc_attr(CONNECTION_TOKEN_OPTION); ?>"
               value="<?php echo esc_attr($configuration['stored_connection_token']); ?>"
               autocomplete="off" />
        <button type="button"
                class="button reprint-server-toggle-token"
                aria-controls="reprint_server_connection_token"
                aria-pressed="false"
                aria-label="<?php echo esc_attr__('Show connection token', 'reprint'); ?>"
                data-show-label="<?php echo esc_attr__('Show connection token', 'reprint'); ?>"
                data-hide-label="<?php echo esc_attr__('Hide connection token', 'reprint'); ?>">
            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
        </button>
        <?php
    }

    /** Apply one push-access change and redirect back to the bundled page. */
    public function handle_push_access_save(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Reprint Server.', 'reprint'));
        }

        check_admin_referer('reprint_server_save_push_access');
        $enabled = isset($_POST['reprint_server_push_enabled']);
        $result = change_push_access($enabled);
        $redirect_url = add_query_arg(
            'reprint_server_notice',
            $result,
            admin_url('tools.php?page=reprint-server')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /** Render the bundled Tools page. */
    public function render_admin_page(): void {
        if (is_multisite()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $configuration = get_configuration_state();
        $remote_reprint_api_url = home_url('?reprint-api');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>
            <?php
            echo esc_html__(
                'Allow an external tool to download your site\'s database and files.',
                'reprint'
            );
            ?>
            </p>

            <?php $this->render_settings_notices(); ?>
            <?php $this->render_push_access_notice(); ?>
            <?php $this->render_configuration_status($configuration); ?>

            <form method="post" action="options.php">
                <?php settings_fields('reprint_server'); ?>
                <?php do_settings_sections('reprint-server'); ?>
                <?php submit_button(); ?>
            </form>

            <?php if ($configuration['is_configured']): ?>
                <hr />
                <h2><?php echo esc_html__('Push access', 'reprint'); ?></h2>
                <p>
                <?php
                echo esc_html__(
                    'You do not need push access when moving this site to another host.',
                    'reprint'
                );
                ?>
                </p>
                <?php $this->render_push_access_form($configuration); ?>

                <hr />
                <h2><?php echo esc_html__('Remote Reprint API URL', 'reprint'); ?></h2>
                <p>
                <?php
                echo esc_html__(
                    'Use this URL when another tool asks for the remote Reprint API URL.',
                    'reprint'
                );
                ?>
                </p>
                <input type="text"
                       class="regular-text code"
                       id="reprint-server-api-url"
                       value="<?php echo esc_attr($remote_reprint_api_url); ?>"
                       readonly />
                <button type="button"
                        class="button reprint-server-copy-url"
                        data-copied-message="<?php echo esc_attr__('Remote Reprint API URL copied.', 'reprint'); ?>">
                    <?php echo esc_html__('Copy', 'reprint'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render current configuration status and notices which require attention.
     *
     * @param array $configuration Configuration returned by get_configuration_state().
     */
    private function render_configuration_status(array $configuration): void {
        if ($configuration['has_connection_token_file']) {
            $message = '<strong><code>secret.php</code> '
                . esc_html__('override is active.', 'reprint')
                . '</strong> '
                . esc_html__(
                    'This page and the REST API update only the site option. Remove secret.php to use the stored option value.',
                    'reprint'
                );
            $this->render_notice('warning', $message);
        }

        if (!$configuration['is_configured']) {
            $message = '<strong>'
                . esc_html__('Not configured yet.', 'reprint')
                . '</strong> '
                . esc_html__('Enter a connection token to get started.', 'reprint');
            $this->render_notice('warning', $message);
            return;
        }

        if ($configuration['push_enabled']) {
            $message = '<strong>' . esc_html__('Connected for downloads and push.', 'reprint') . '</strong> '
                . esc_html__('The current connection token can change files on this site.', 'reprint');
        } else {
            $message = '<strong>' . esc_html__('Connected for downloads.', 'reprint') . '</strong> '
                . esc_html__('The connection token cannot change files on this site.', 'reprint');
        }
        $this->render_notice('info', $message);
    }

    /** Render the push-access form or its read-only state. */
    private function render_push_access_form(array $configuration): void {
        if (!$configuration['push_supported']) {
            $unsupported_message = sprintf(
                /* translators: %s: Current PHP version. */
                __(
                'Push access requires PHP 7.2 or newer. This site runs PHP %s. Downloads remain available.',
                'reprint'
                ),
                PHP_VERSION
            );
            $this->render_notice('warning', esc_html($unsupported_message));
            return;
        }

        if ($configuration['managed_push_enabled'] !== null) {
            ?>
            <label>
                <input type="checkbox"
                       value="1"<?php checked($configuration['push_enabled']); ?><?php disabled(true); ?> />
                <?php echo esc_html__('Allow push to change files on this site', 'reprint'); ?>
            </label>
            <p class="description">
            <?php
            echo esc_html__(
                'While enabled, anyone with the connection token can upload, replace, and delete files in this site\'s document root, except excluded paths.',
                'reprint'
            );
            ?>
            </p>
            <p class="description">
            <?php
            echo esc_html__(
                'Push access is managed by your hosting provider.',
                'reprint'
            );
            ?>
            </p>
            <?php
            return;
        }

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="reprint_server_save_push_access" />
            <?php wp_nonce_field('reprint_server_save_push_access'); ?>
            <label>
                <input type="checkbox"
                       name="reprint_server_push_enabled"
                       value="1"<?php checked($configuration['push_enabled']); ?> />
                <?php echo esc_html__('Allow push to change files on this site', 'reprint'); ?>
            </label>
            <p class="description">
            <?php
            echo esc_html__(
                'While enabled, anyone with the connection token can upload, replace, and delete files in this site\'s document root, except excluded paths.',
                'reprint'
            );
            ?>
            </p>
            <p class="submit">
                <?php submit_button(__('Save push access', 'reprint'), 'secondary', 'submit', false); ?>
            </p>
        </form>
        <?php
    }

    /** Render a fixed native notice for the admin-post result. */
    private function render_push_access_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A newer Settings API result supersedes the stale push result.
        if (isset($_GET['settings-updated'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixed query value selects a read-only notice.
        if (!isset($_GET['reprint_server_notice']) || !is_string($_GET['reprint_server_notice'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixed query value selects a read-only notice.
        $result = sanitize_key(wp_unslash($_GET['reprint_server_notice']));
        $notices = [
            'saved' => ['success', __('Push access updated.', 'reprint')],
            'unchanged' => ['success', __('Push access was already up to date.', 'reprint')],
            'unsupported' => ['error', __('Push access requires PHP 7.2 or newer. Downloads remain available.', 'reprint')],
            'managed' => ['info', __('Push access is managed by your hosting provider.', 'reprint')],
            'not_configured' => ['error', __('Configure a connection token before enabling push access.', 'reprint')],
            'storage_failure' => ['error', __('Failed to save push access.', 'reprint')],
        ];
        if (!isset($notices[$result])) {
            return;
        }

        $this->render_notice(
            $notices[$result][0],
            esc_html($notices[$result][1]),
            true
        );
    }

    /** Render Settings API results with stable native notice markup. */
    private function render_settings_notices(): void {
        foreach (get_settings_errors() as $notice) {
            $type = $notice['type'] === 'updated' ? 'success' : $notice['type'];
            $this->render_notice(
                $type,
                $notice['message'],
                true,
                'setting-error-' . $notice['code'],
                true
            );
        }
    }

    /** Render one native inline administrator notice. */
    private function render_notice(
        string $type,
        string $message,
        bool $dismissible = false,
        string $id = '',
        bool $settings_error = false
    ): void {
        $allowed_types = ['error', 'success', 'warning', 'info'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'error';
        }

        $classes = 'notice notice-' . $type;
        if ($settings_error) {
            $classes .= ' settings-error';
        }
        if ($dismissible) {
            $classes .= ' is-dismissible';
        }
        $classes .= ' inline';
        ?>
        <div<?php if ($id !== ''): ?> id="<?php echo esc_attr($id); ?>"<?php endif; ?>
             class="<?php echo esc_attr($classes); ?>">
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }
}

add_action('plugins_loaded', function() {
    SettingsPage::get_instance();
});

register_activation_hook(PLUGIN_DIR . 'index.php', function() {
    if (!wp_doing_ajax() && is_admin()) {
        set_transient('reprint_server_activated', 1, 30);
    }

    $gitignore = PLUGIN_DIR . '.gitignore';
    if (!file_exists($gitignore)) {
        file_put_contents($gitignore, "secret.php\n");
    }
});

add_action('admin_init', function() {
    if (get_transient('reprint_server_activated')) {
        delete_transient('reprint_server_activated');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only suppresses activation redirects for bulk activation.
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('tools.php?page=reprint-server'));
            exit;
        }
    }
});
