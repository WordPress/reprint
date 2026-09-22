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
        add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
        add_action('admin_post_reprint_server_save_network_token', [$this, 'handle_network_token_save']);
        add_action('admin_init', [$this, 'register_settings_fields']);
        add_action('admin_post_reprint_server_save_push_access', [$this, 'handle_push_access_save']);
        add_action('admin_post_reprint_server_enroll_public_key', [$this, 'handle_public_key_enroll']);
        add_action('admin_post_reprint_server_remove_public_key', [$this, 'handle_public_key_remove']);
        add_action('admin_post_reprint_server_save_key_push_access', [$this, 'handle_key_push_access_save']);
        add_action('admin_post_reprint_server_remove_connection_token', [$this, 'handle_connection_token_remove']);
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

    /** Network credentials are configured only by network administrators. */
    public function add_network_admin_menu(): void {
        $this->page_hook = add_submenu_page(
            'settings.php',
            __('Reprint Server', 'reprint'),
            __('Reprint Server', 'reprint'),
            'manage_network_options',
            'reprint-server',
            [$this, 'render_network_admin_page']
        );
    }

    /** Render a network-option form without the site Settings API. */
    public function render_network_admin_page(): void {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            return;
        }
        $configuration = get_configuration_state();
        echo '<div class="wrap"><h1>' . esc_html__('Reprint Server', 'reprint') . '</h1>';
        echo '<p>' . ( $configuration['required_scheme'] === 'key'
            ? esc_html__(
                'Enrolled public keys can pull any site in this network. Use the selected site’s home URL followed by ?reprint-api. Each pull creates a separate one-site network. Push is not supported.',
                'reprint'
            )
            : esc_html__(
                'This network token can pull any site in this network. Use the selected site’s home URL followed by ?reprint-api. Each pull creates a separate one-site network. Push is not supported.',
                'reprint'
            )
        ) . '</p>';
        $this->render_push_access_notice();
        $this->render_configuration_status($configuration);
        $this->render_scheme_status($configuration);
        if ($configuration['required_scheme'] === 'hmac') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="reprint_server_save_network_token" />';
            wp_nonce_field('reprint_server_save_network_token');
            $this->render_connection_token_field();
            submit_button();
            echo '</form>';
        } else {
            $this->render_stored_connection_token_section($configuration);
        }
        echo '<hr /><h2>' . esc_html__('Public keys', 'reprint') . '</h2>';
        $this->render_public_keys_section($configuration);
        echo '</div>';
    }

    /** Validate network capability and nonce before updating the network token. */
    public function handle_network_token_save(): void {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage this network.', 'reprint'));
        }
        check_admin_referer('reprint_server_save_network_token');
        $connection_token = isset($_POST[CONNECTION_TOKEN_OPTION]) && is_string($_POST[CONNECTION_TOKEN_OPTION])
            ? sanitize_text_field(wp_unslash($_POST[CONNECTION_TOKEN_OPTION]))
            : '';
        $result = change_connection_token($connection_token);
        if ($result === 'storage_failure') {
            wp_die(esc_html__('The network connection token could not be saved.', 'reprint'));
        }
        wp_safe_redirect(network_admin_url('settings.php?page=reprint-server'));
        exit;
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
        $url = is_multisite()
            ? network_admin_url('settings.php?page=reprint-server')
            : admin_url('tools.php?page=reprint-server');
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

        wp_enqueue_style(
            'reprint-server-admin',
            plugins_url('wordpress/reprint-server.css', PLUGIN_DIR . 'index.php'),
            [],
            VERSION
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

    /** Validate capability and nonce, then enroll one pasted public key. */
    public function handle_public_key_enroll(): void {
        $this->require_manage_capability();
        check_admin_referer('reprint_server_enroll_public_key');
        // The textarea sanitizer keeps the PEM line breaks; assert_valid_public_key() parses the result.
        $pasted_key = isset($_POST['reprint_server_public_key']) && is_string($_POST['reprint_server_public_key'])
            ? sanitize_textarea_field(wp_unslash($_POST['reprint_server_public_key']))
            : '';
        $result = enroll_public_key($pasted_key);
        $query = ['reprint_server_notice' => $result === 'saved' ? 'enrolled' : 'enroll_' . $result];
        if ($result === 'saved') {
            $query['reprint_server_key_id'] = (string) get_last_enrolled_key_id();
        }
        $this->redirect_to_page($query);
    }

    /** Validate capability and nonce, then remove one enrolled key. */
    public function handle_public_key_remove(): void {
        $this->require_manage_capability();
        check_admin_referer('reprint_server_remove_public_key');
        $key_id = isset($_POST['reprint_server_key_id']) && is_string($_POST['reprint_server_key_id'])
            ? sanitize_key(wp_unslash($_POST['reprint_server_key_id']))
            : '';
        $result = remove_public_key($key_id);
        $this->redirect_to_page(['reprint_server_notice' => $result === 'saved' ? 'key_removed' : 'remove_' . $result]);
    }

    /** Validate capability and nonce, then grant or revoke push for one key. */
    public function handle_key_push_access_save(): void {
        $this->require_manage_capability();
        check_admin_referer('reprint_server_save_key_push_access');
        $key_id = isset($_POST['reprint_server_key_id']) && is_string($_POST['reprint_server_key_id'])
            ? sanitize_key(wp_unslash($_POST['reprint_server_key_id']))
            : '';
        $enabled = isset($_POST['reprint_server_key_push_enabled']);
        $result = change_key_push_access($key_id, $enabled);
        $this->redirect_to_page(['reprint_server_notice' => $result === 'saved' ? 'key_push_saved' : 'key_push_' . $result]);
    }

    /**
     * Validate capability and nonce, then clear the option-backed connection token.
     *
     * Offered on a key host, where a stored token is never accepted; the plugin
     * does not delete a credential the administrator set without being asked.
     */
    public function handle_connection_token_remove(): void {
        $this->require_manage_capability();
        check_admin_referer('reprint_server_remove_connection_token');
        $result = change_connection_token('');
        $this->redirect_to_page([
            'reprint_server_notice' => $result === 'storage_failure' ? 'token_remove_storage_failure' : 'token_removed',
        ]);
    }

    /** Stop with wp_die() unless the current user may manage this site's, or on multisite the network's, options. */
    private function require_manage_capability(): void {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to manage Reprint Server.', 'reprint'));
        }
    }

    /** @param array<string,string> $query */
    private function redirect_to_page(array $query): void {
        $base = is_multisite()
            ? network_admin_url('settings.php?page=reprint-server')
            : admin_url('tools.php?page=reprint-server');
        wp_safe_redirect(add_query_arg($query, $base));
        exit;
    }

    /** Render the bundled Tools page. */
    public function render_admin_page(): void {
        if (is_multisite()) {
            $this->render_network_admin_page();
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
            <?php $this->render_scheme_status($configuration); ?>

            <?php if ($configuration['required_scheme'] === 'hmac'): ?>
                <form method="post" action="options.php">
                    <?php settings_fields('reprint_server'); ?>
                    <?php do_settings_sections('reprint-server'); ?>
                    <?php submit_button(); ?>
                </form>
            <?php else: ?>
                <?php $this->render_stored_connection_token_section($configuration); ?>
            <?php endif; ?>

            <hr />
            <h2><?php echo esc_html__('Public keys', 'reprint'); ?></h2>
            <?php $this->render_public_keys_section($configuration); ?>

            <?php if ($configuration['is_configured']): ?>
                <?php if ($configuration['required_scheme'] === 'hmac'): ?>
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
                <?php endif; ?>

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
        $key_host = $configuration['required_scheme'] === 'key';
        if ($configuration['has_connection_token_file']) {
            if ($key_host) {
                $file_detail = esc_html__('It is not accepted on this host.', 'reprint');
            } elseif (is_multisite()) {
                $file_detail = esc_html__(
                    'This page updates only the network option. Remove secret.php to use the stored option value.',
                    'reprint'
                );
            } else {
                $file_detail = esc_html__(
                    'This page and the REST API update only the site option. Remove secret.php to use the stored option value.',
                    'reprint'
                );
            }
            $message = '<strong><code>secret.php</code> '
                . esc_html__('override is active.', 'reprint')
                . '</strong> '
                . $file_detail;
            $this->render_notice('warning', $message);
        }

        if (!$configuration['is_configured']) {
            $message = '<strong>'
                . esc_html__('Not configured yet.', 'reprint')
                . '</strong> '
                . ( $key_host
                    ? esc_html__('Enroll a public key to get started.', 'reprint')
                    : esc_html__('Enter a connection token to get started.', 'reprint')
                );
            $this->render_notice('warning', $message);
            return;
        }

        if ($configuration['push_enabled']) {
            $message = '<strong>' . esc_html__('Connected for downloads and push.', 'reprint') . '</strong> '
                . ( $key_host
                    ? esc_html__('At least one enrolled key can change files on this site.', 'reprint')
                    : esc_html__('The current connection token can change files on this site.', 'reprint')
                );
        } else {
            $message = '<strong>' . esc_html__('Connected for downloads.', 'reprint') . '</strong> '
                . ( $key_host
                    ? esc_html__('No enrolled key can change files on this site.', 'reprint')
                    : esc_html__('The connection token cannot change files on this site.', 'reprint')
                );
        }
        $this->render_notice('info', $message);
    }

    /**
     * Read-only connection-token section for a key host, where a stored token is never accepted.
     *
     * Renders nothing when no token is stored. An option-stored token gets a Remove button;
     * a secret.php token is named without one, since this page cannot delete that file.
     *
     * @param array $configuration Configuration returned by get_configuration_state().
     */
    private function render_stored_connection_token_section(array $configuration): void {
        if ($configuration['stored_connection_token'] === '' && !$configuration['has_connection_token_file']) {
            return;
        }
        ?>
        <h2><?php echo esc_html__('Connection token', 'reprint'); ?></h2>
        <p class="description">
        <?php
        echo esc_html(
            $configuration['has_connection_token_file']
                ? __('secret.php is present but not accepted on this host. Remove it.', 'reprint')
                : __('A connection token is stored but not accepted on this host. It is safe to remove.', 'reprint')
        );
        ?>
        </p>
        <?php if ($configuration['stored_connection_token'] !== '' && !$configuration['has_connection_token_file']): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="reprint_server_remove_connection_token" />
                <?php wp_nonce_field('reprint_server_remove_connection_token'); ?>
                <?php submit_button(__('Remove connection token', 'reprint'), 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * One sentence saying which scheme this host accepts. Nothing on the page changes it.
     *
     * @param array $configuration Configuration returned by get_configuration_state().
     */
    private function render_scheme_status(array $configuration): void {
        $message = $configuration['required_scheme'] === 'key'
            ? esc_html__('This host has OpenSSL. Clients authenticate with public keys; connection tokens are not accepted.', 'reprint')
            : esc_html__('This host has no OpenSSL. Clients authenticate with a connection token; public keys are enrolled but not in use on this host.', 'reprint');
        $this->render_notice('info', '<strong>' . $message . '</strong>');
    }

    /**
     * Enrollment form and the enrolled-key table.
     *
     * @param array $configuration Configuration returned by get_configuration_state().
     */
    private function render_public_keys_section(array $configuration): void {
        $file_override = $configuration['has_public_keys_file'];
        $post_url = admin_url('admin-post.php');
        if ($file_override) {
            $this->render_notice('warning', '<strong><code>public-keys.php</code> '
                . esc_html__('override is active.', 'reprint') . '</strong> '
                . esc_html__('Keys come from that file; this page cannot change them.', 'reprint'));
        }
        if ($configuration['required_scheme'] === 'key' && $configuration['enrolled_keys'] === []) {
            $this->render_notice('warning', esc_html__('No client can connect until a key is enrolled.', 'reprint'));
        }
        ?>
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <input type="hidden" name="action" value="reprint_server_enroll_public_key" />
            <?php wp_nonce_field('reprint_server_enroll_public_key'); ?>
            <p>
                <label for="reprint_server_public_key"><?php echo esc_html__('Public key', 'reprint'); ?></label><br />
                <textarea id="reprint_server_public_key" name="reprint_server_public_key" rows="4" class="large-text code"<?php disabled($file_override); ?>></textarea>
            </p>
            <p class="description">
            <?php
            echo esc_html__(
                'Paste the public key printed by "reprint keygen" or by "reprint pull". A PEM block or the single line are both accepted.',
                'reprint'
            );
            ?>
            </p>
            <?php submit_button(__('Enroll key', 'reprint'), 'secondary', 'submit', true, $file_override ? ['disabled' => 'disabled'] : []); ?>
        </form>

        <?php if ($configuration['enrolled_keys'] !== []): ?>
        <table class="widefat striped reprint-server-key-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Key id', 'reprint'); ?></th>
                    <th><?php echo esc_html__('Added', 'reprint'); ?></th>
                    <th><?php echo esc_html__('May push', 'reprint'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($configuration['enrolled_keys'] as $entry): ?>
                <tr>
                    <td><code><?php echo esc_html($entry['key_id']); ?></code></td>
                    <td><?php echo esc_html($entry['added_at'] > 0 ? gmdate('Y-m-d', $entry['added_at']) : '—'); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline">
                            <input type="hidden" name="action" value="reprint_server_save_key_push_access" />
                            <input type="hidden" name="reprint_server_key_id" value="<?php echo esc_attr($entry['key_id']); ?>" />
                            <?php wp_nonce_field('reprint_server_save_key_push_access'); ?>
                            <label>
                                <input type="checkbox" name="reprint_server_key_push_enabled" value="1"
                                    <?php checked($entry['push']); ?>
                                    <?php disabled(is_multisite() || !$configuration['push_supported'] || $configuration['managed_push_enabled'] !== null || $file_override); ?>
                                    onchange="this.form.submit()" />
                                <?php echo esc_html__('Allow push', 'reprint'); ?>
                            </label>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline">
                            <input type="hidden" name="action" value="reprint_server_remove_public_key" />
                            <input type="hidden" name="reprint_server_key_id" value="<?php echo esc_attr($entry['key_id']); ?>" />
                            <?php wp_nonce_field('reprint_server_remove_public_key'); ?>
                            <?php submit_button(__('Remove', 'reprint'), 'link-delete', 'submit', false, $file_override ? ['disabled' => 'disabled'] : []); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
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

    /** Render a fixed native notice for the admin-post result: push access, key enrollment, or token removal. */
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
            'enrolled' => ['success', __('Public key enrolled.', 'reprint')],
            'enroll_invalid' => ['error', __('That is not a usable public key. Paste an RSA public key of at least 2048 bits, as a PEM block or one line.', 'reprint')],
            'enroll_duplicate' => ['info', __('That public key is already enrolled.', 'reprint')],
            'enroll_file_override' => ['error', __('public-keys.php is active. Edit that file to change enrolled keys.', 'reprint')],
            'enroll_storage_failure' => ['error', __('Failed to save the public key.', 'reprint')],
            'enroll_runtime_missing' => ['error', __('The Reprint Server runtime is missing. Run composer install in the plugin directory or reinstall the release package.', 'reprint')],
            'key_removed' => ['success', __('Public key removed.', 'reprint')],
            'remove_unknown' => ['error', __('That key is not enrolled.', 'reprint')],
            'remove_last_key' => ['error', __('This host requires key authentication, so the last key cannot be removed. Enroll another key first.', 'reprint')],
            'remove_file_override' => ['error', __('public-keys.php is active. Edit that file to change enrolled keys.', 'reprint')],
            'remove_storage_failure' => ['error', __('Failed to remove the public key.', 'reprint')],
            'remove_runtime_missing' => ['error', __('The Reprint Server runtime is missing. Run composer install in the plugin directory or reinstall the release package.', 'reprint')],
            'key_push_saved' => ['success', __('Push access for the key updated.', 'reprint')],
            'key_push_unchanged' => ['success', __('Push access for the key was already up to date.', 'reprint')],
            'key_push_unknown' => ['error', __('That key is not enrolled.', 'reprint')],
            'key_push_multisite' => ['info', __('Push is not supported on multisite networks.', 'reprint')],
            'key_push_unsupported' => ['error', __('Push access requires PHP 7.2 or newer.', 'reprint')],
            'key_push_managed' => ['info', __('Push access is managed by your hosting provider.', 'reprint')],
            'key_push_file_override' => ['error', __('public-keys.php is active. Push grants cannot be stored for file-provided keys.', 'reprint')],
            'key_push_storage_failure' => ['error', __('Failed to save push access for the key.', 'reprint')],
            'key_push_runtime_missing' => ['error', __('The Reprint Server runtime is missing. Run composer install in the plugin directory or reinstall the release package.', 'reprint')],
            'token_removed' => ['success', __('Connection token removed.', 'reprint')],
            'token_remove_storage_failure' => ['error', __('Failed to remove the connection token.', 'reprint')],
        ];
        if (!isset($notices[$result])) {
            return;
        }

        $message = esc_html($notices[$result][1]);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key id only labels a read-only notice.
        if ($result === 'enrolled' && isset($_GET['reprint_server_key_id']) && is_string($_GET['reprint_server_key_id'])) {
            $message .= ' ' . sprintf(
                /* translators: %s: Key id of the public key that was just enrolled. */
                esc_html__('Key id: %s', 'reprint'),
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key id only labels a read-only notice.
                '<code>' . esc_html(sanitize_key(wp_unslash($_GET['reprint_server_key_id']))) . '</code>'
            );
        }

        $this->render_notice(
            $notices[$result][0],
            $message,
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
