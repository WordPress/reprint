<?php
/** Bundled WordPress administrator adapter for Reprint Server. */

class Site_Export_Plugin {

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
        add_action('admin_post_site_export_save_push_access', [$this, 'handle_push_access_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter(
            'plugin_action_links_' . plugin_basename(SITE_EXPORT_PLUGIN_DIR . 'index.php'),
            [$this, 'add_settings_link']
        );
    }

    /** Add the bundled page beneath Tools. */
    public function add_admin_menu(): void {
        $this->page_hook = add_management_page(
            __('Reprint Server', 'reprint'),
            __('Reprint Server', 'reprint'),
            'manage_options',
            'site-export',
            [$this, 'render_admin_page']
        );
    }

    /** Register the connection-token section rendered by the Settings API. */
    public function register_settings_fields(): void {
        add_settings_section(
            'site_export_connection',
            __('Connection token', 'reprint'),
            [$this, 'render_connection_section'],
            'site-export'
        );
        add_settings_field(
            SITE_EXPORT_SECRET_OPTION,
            __('Connection token', 'reprint'),
            [$this, 'render_connection_token_field'],
            'site-export',
            'site_export_connection',
            ['label_for' => SITE_EXPORT_SECRET_OPTION]
        );
    }

    /** Add the Settings link to the plugin row. */
    public function add_settings_link(array $links): array {
        $url = admin_url('tools.php?page=site-export');
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
            'site-export-admin',
            plugins_url('wordpress/site-export.js', SITE_EXPORT_PLUGIN_DIR . 'index.php'),
            ['wp-a11y'],
            SITE_EXPORT_VERSION,
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
        $configuration = _site_export_get_configuration_state();
        ?>
        <input type="password"
               class="regular-text code"
               id="site_export_secret"
               name="<?php echo esc_attr(SITE_EXPORT_SECRET_OPTION); ?>"
               value="<?php echo esc_attr($configuration['stored_connection_token']); ?>"
               autocomplete="off" />
        <button type="button"
                class="button site-export-toggle-token"
                aria-controls="site_export_secret"
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

        check_admin_referer('site_export_save_push_access');
        $enabled = isset($_POST['site_export_push_enabled']);
        $result = _site_export_change_push_access($enabled);
        $redirect_url = add_query_arg(
            'site_export_notice',
            $result,
            admin_url('tools.php?page=site-export')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /** Render the bundled Tools page. */
    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $configuration = _site_export_get_configuration_state();
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

            <?php
            ob_start();
            settings_errors();
            $settings_errors_markup = ob_get_clean();
            if (is_string($settings_errors_markup)) {
                // Core bolds each complete message and moves non-inline notices after load.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_errors() returns escaped core markup.
                echo str_replace(
                    ['settings-error is-dismissible', '<strong>', '</strong>'],
                    ['settings-error is-dismissible inline', '', ''],
                    $settings_errors_markup
                );
            }
            ?>
            <?php $this->render_push_access_notice(); ?>
            <?php $this->render_configuration_status($configuration); ?>

            <form method="post" action="options.php">
                <?php settings_fields('site_export'); ?>
                <?php do_settings_sections('site-export'); ?>
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
                       id="site-export-api-url"
                       value="<?php echo esc_attr($remote_reprint_api_url); ?>"
                       readonly />
                <button type="button"
                        class="button site-export-copy-url"
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
     * @param array $configuration Configuration returned by _site_export_get_configuration_state().
     */
    private function render_configuration_status(array $configuration): void {
        if ($configuration['has_secret_file']) {
            echo '<div class="notice notice-warning inline"><p><strong><code>secret.php</code> '
                . esc_html__('override is active.', 'reprint')
                . '</strong> '
                . esc_html__(
                    'This page and the REST API update only the site option. Remove secret.php to use the stored option value.',
                    'reprint'
                )
                . '</p></div>';
        }

        if (!$configuration['is_configured']) {
            echo '<div class="notice notice-warning inline"><p><strong>'
                . esc_html__('Not configured yet.', 'reprint')
                . '</strong> '
                . esc_html__('Enter a connection token to get started.', 'reprint')
                . '</p></div>';
            return;
        }

        echo '<div class="notice notice-info inline"><p>';
        if ($configuration['push_enabled']) {
            echo '<strong>' . esc_html__('Connected for downloads and push.', 'reprint') . '</strong> '
                . esc_html__('The current connection token can change files on this site.', 'reprint');
        } else {
            echo '<strong>' . esc_html__('Connected for downloads.', 'reprint') . '</strong> '
                . esc_html__('The connection token cannot change files on this site.', 'reprint');
        }
        echo '</p></div>';
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
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html($unsupported_message)
                . '</p></div>';
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
            <input type="hidden" name="action" value="site_export_save_push_access" />
            <?php wp_nonce_field('site_export_save_push_access'); ?>
            <label>
                <input type="checkbox"
                       name="site_export_push_enabled"
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
        if (!isset($_GET['site_export_notice']) || !is_string($_GET['site_export_notice'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixed query value selects a read-only notice.
        $result = sanitize_key(wp_unslash($_GET['site_export_notice']));
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

        $notice_classes = 'notice notice-' . $notices[$result][0] . ' is-dismissible inline';
        echo '<div class="' . esc_attr($notice_classes) . '"><p>'
            . esc_html($notices[$result][1])
            . '</p></div>';
    }
}

add_action('plugins_loaded', function() {
    Site_Export_Plugin::get_instance();
});

register_activation_hook(SITE_EXPORT_PLUGIN_DIR . 'index.php', function() {
    if (!wp_doing_ajax() && is_admin()) {
        set_transient('site_export_activated', 1, 30);
    }

    $gitignore = SITE_EXPORT_PLUGIN_DIR . '.gitignore';
    if (!file_exists($gitignore)) {
        file_put_contents($gitignore, "secret.php\n");
    }
});

add_action('admin_init', function() {
    if (get_transient('site_export_activated')) {
        delete_transient('site_export_activated');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only suppresses activation redirects for bulk activation.
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('tools.php?page=site-export'));
            exit;
        }
    }
});
