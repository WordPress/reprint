<?php

namespace WordPress\Reprint\Server\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

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
        $this->render_page();
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
        $this->redirect_to_page(['reprint_server_notice' => 'network_token_' . $result]);
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
        // assert_valid_public_key() is the real check. The sanitizer only cleans the pasted text.
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
        $this->render_page();
    }

    /** Site and network pages share authorization, but each copied site needs its own API URL. */
    private function render_page(): void {
        $configuration = get_configuration_state();
        $remote_reprint_api_url = home_url('?reprint-api');
        $uses_http = parse_url($remote_reprint_api_url, PHP_URL_SCHEME) === 'http';
        // Nothing on the page changes which scheme this host accepts.
        $key_host = $configuration['required_scheme'] === 'key';
        $configured = $configuration['is_configured'];
        $notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The query selects a read-only action result, not a permission change.
        if (!isset($_GET['settings-updated']) && isset($_GET['reprint_server_notice']) && is_string($_GET['reprint_server_notice'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only fixed action-result names affect the display.
            $notice = sanitize_key(wp_unslash($_GET['reprint_server_notice']));
        }
        $authorized_key_id = null;
        // Saved access does not confirm that a tool has connected.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key id selects a read-only confirmation for a saved key.
        if ($key_host && $notice === 'enrolled' && isset($_GET['reprint_server_key_id']) && is_string($_GET['reprint_server_key_id'])) {
            foreach ($configuration['enrolled_keys'] as $entry) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key id must match the current key list before the form is replaced.
                if ($entry['key_id'] === sanitize_key(wp_unslash($_GET['reprint_server_key_id']))) {
                    $authorized_key_id = $entry['key_id'];
                    break;
                }
            }
        }
        $page_url = is_multisite()
            ? network_admin_url('settings.php?page=reprint-server')
            : admin_url('tools.php?page=reprint-server');
        ?>
        <div class="wrap reprint-server-page">
            <h1><?php echo esc_html__('Reprint Server', 'reprint'); ?></h1>
            <?php $this->render_settings_notices(); ?>
            <?php $this->render_push_access_notice(); ?>

            <section class="reprint-server-card reprint-server-authorization" aria-labelledby="reprint-server-access-heading">
                <?php if ($authorized_key_id !== null): ?>
                    <div role="status">
                        <h2 id="reprint-server-access-heading"><?php echo esc_html__('Tool authorized', 'reprint'); ?></h2>
                        <p><?php echo esc_html__('Return to your tool to start or resume the copy.', 'reprint'); ?></p>
                        <p class="description">
                        <?php
                        echo esc_html($configuration['push_supported'] && get_push_authorization_error($authorized_key_id) === null
                            ? __('This key also allows push to upload, replace, and delete files in this site’s document root, except excluded paths.', 'reprint')
                            : __('This key allows downloads. It cannot change files on this site.', 'reprint')
                        );
                        ?>
                        </p>
                    </div>
                    <a class="button" href="<?php echo esc_url($page_url); ?>"><?php echo esc_html__('Authorize another tool', 'reprint'); ?></a>
                <?php else: ?>
                    <h2 id="reprint-server-access-heading"><?php echo esc_html__('Authorize a tool to copy this site', 'reprint'); ?></h2>
                    <?php if ($key_host && $configuration['has_public_keys_file']): ?>
                        <p><strong><code>public-keys.php</code> <?php echo esc_html__('override is active.', 'reprint'); ?></strong> <?php echo esc_html__('Edit that file to authorize a tool or remove a key. This page cannot change its keys.', 'reprint'); ?></p>
                    <?php elseif ($key_host): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="reprint_server_enroll_public_key" />
                            <?php wp_nonce_field('reprint_server_enroll_public_key'); ?>
                            <p id="reprint-server-public-key-help"><?php echo esc_html__('Paste the public key shown by the tool you want to use.', 'reprint'); ?></p>
                            <p>
                                <label for="reprint_server_public_key"><?php echo esc_html__('Public key', 'reprint'); ?></label>
                                <textarea id="reprint_server_public_key" name="reprint_server_public_key" rows="3" class="large-text code" aria-describedby="reprint-server-public-key-help reprint-server-public-key-permission" spellcheck="false" required></textarea>
                            </p>
                            <p id="reprint-server-public-key-permission" class="description"><?php echo esc_html__('Allows downloads of your database and files. Only authorize a tool you trust. Never paste a private key.', 'reprint'); ?></p>
                            <?php submit_button(__('Authorize tool', 'reprint')); ?>
                            <details class="reprint-server-help">
                                <summary><?php echo esc_html__('I don’t have a public key yet', 'reprint'); ?></summary>
                                <ol>
                                    <li><?php echo esc_html(is_multisite() ? __('Choose a site below and use its Reprint API URL in your tool.', 'reprint') : __('Copy the Reprint API URL below into your tool.', 'reprint')); ?></li>
                                    <li><?php echo esc_html__('Copy the public key it gives you.', 'reprint'); ?></li>
                                    <li><?php echo esc_html__('Paste it here and choose Authorize tool.', 'reprint'); ?></li>
                                </ol>
                                <p><?php echo esc_html__('A PEM block or a single line is accepted.', 'reprint'); ?></p>
                                <?php if (!is_multisite()): ?>
                                    <details class="reprint-server-help">
                                        <summary><?php echo esc_html__('Using the Reprint CLI?', 'reprint'); ?></summary>
                                        <p><?php echo esc_html__('Generate a key in your terminal:', 'reprint'); ?></p>
                                        <pre><code><?php echo esc_html('reprint keygen ' . escapeshellarg($remote_reprint_api_url) . ' --state-dir=./reprint-state' . ( $uses_http ? ' --insecure' : '' )); ?></code></pre>
                                        <p><?php echo esc_html__('Use the same --state-dir when you run reprint pull. Keep the private key in your tool; paste only the public key here.', 'reprint'); ?></p>
                                        <?php if ($uses_http): ?>
                                            <p><?php echo esc_html__('This site uses HTTP. Prefer an HTTPS URL for transfers. If you use HTTP, pass --insecure to reprint pull too; site data, including passwords, travels unencrypted.', 'reprint'); ?></p>
                                        <?php endif; ?>
                                        <p><a href="https://github.com/WordPress/reprint#quick-start"><?php echo esc_html__('Reprint CLI setup instructions', 'reprint'); ?></a></p>
                                    </details>
                                <?php endif; ?>
                            </details>
                        </form>
                    <?php elseif ($configuration['has_connection_token_file']): ?>
                        <p><strong><code>secret.php</code> <?php echo esc_html__('override is active.', 'reprint'); ?></strong> <?php echo esc_html__('Use the connection token from that file in your tool. This page cannot change it.', 'reprint'); ?></p>
                    <?php else: ?>
                        <?php if ($configured): ?>
                            <p><?php echo esc_html__('Use the saved connection token in your tool. Anyone with it can download your database and files.', 'reprint'); ?></p>
                            <p><?php echo esc_html__('Changing the token disconnects tools using the old token and revokes its push access.', 'reprint'); ?></p>
                        <?php endif; ?>
                        <?php $this->render_connection_token_form(); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <div class="reprint-server-connection-details">
                <?php if (is_multisite()): ?>
                    <h2><?php echo esc_html__('Site to copy', 'reprint'); ?></h2>
                    <p>
                    <?php
                    echo esc_html($key_host
                        ? __('Enrolled public keys can pull any site in this network.', 'reprint')
                        : __('This network token can pull any site in this network.', 'reprint')
                    );
                    ?>
                    </p>
                    <p><?php echo esc_html__('Use the selected site’s home URL followed by ?reprint-api. Each pull creates a separate one-site network. Push is not supported.', 'reprint'); ?></p>
                    <p><a href="<?php echo esc_url(network_admin_url('sites.php')); ?>"><?php echo esc_html__('Find a site in this network', 'reprint'); ?></a></p>
                    <p class="description"><?php echo esc_html__('For example:', 'reprint'); ?> <code>https://your-site.example/?reprint-api</code></p>
                <?php else: ?>
                    <label for="reprint-server-api-url"><?php echo esc_html__('Remote Reprint API URL', 'reprint'); ?></label>
                    <div class="reprint-server-url-row">
                        <input type="text" class="code" id="reprint-server-api-url"
                               value="<?php echo esc_attr($remote_reprint_api_url); ?>" readonly />
                        <button type="button" class="button reprint-server-copy-url"
                                data-copied-message="<?php echo esc_attr__('Remote Reprint API URL copied.', 'reprint'); ?>"
                                data-copy-failed-message="<?php echo esc_attr__('Could not copy automatically. Select the URL and copy it.', 'reprint'); ?>">
                            <?php echo esc_html__('Copy URL', 'reprint'); ?>
                        </button>
                    </div>
                    <p class="description reprint-server-copy-status" role="status"></p>
                    <noscript><p class="description"><?php echo esc_html__('Select the URL and copy it into your tool.', 'reprint'); ?></p></noscript>
                <?php endif; ?>
            </div>

            <?php
            $show_management = $configuration['enrolled_keys'] !== [] || $configuration['has_connection_token_file']
                || ( $key_host ? $configuration['stored_connection_token'] !== '' : $configuration['has_public_keys_file'] || ( $configured && !is_multisite() ) );
            if ($show_management):
            ?>
                <details class="reprint-server-manage-access"<?php echo $notice !== '' && $notice !== 'enrolled' && strpos($notice, 'enroll_') !== 0 ? ' open' : ''; ?>>
                    <summary><?php echo esc_html__('Manage tool access', 'reprint'); ?></summary>
                    <?php if ($key_host): ?>
                        <?php $this->render_public_keys_section($configuration); ?>
                        <?php $this->render_stored_connection_token_section($configuration); ?>
                    <?php else: ?>
                        <?php if ($configuration['has_connection_token_file']): ?>
                            <details class="reprint-server-help">
                                <summary><?php echo esc_html__('Edit the stored option instead', 'reprint'); ?></summary>
                                <p><?php echo esc_html__('Remove secret.php to use the stored option value.', 'reprint'); ?></p>
                                <?php $this->render_connection_token_form(); ?>
                            </details>
                        <?php endif; ?>
                        <?php if ($configured && !is_multisite()): ?>
                            <p><?php echo esc_html__('You do not need push access when moving this site to another host.', 'reprint'); ?></p>
                            <?php $this->render_push_access_form($configuration); ?>
                        <?php endif; ?>
                        <?php if ($configuration['enrolled_keys'] !== [] || $configuration['has_public_keys_file']): ?>
                            <h3><?php echo esc_html__('Stored public keys', 'reprint'); ?></h3>
                            <p><?php echo esc_html__('These keys are not used on this host. Removing them does not change connection-token access.', 'reprint'); ?></p>
                            <?php $this->render_public_keys_section($configuration); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Site tokens use the Settings API; network tokens use the network administrator action. */
    private function render_connection_token_form(): void {
        ?>
        <form method="post" action="<?php echo esc_url(is_multisite() ? admin_url('admin-post.php') : admin_url('options.php')); ?>">
            <?php if (is_multisite()): ?>
                <input type="hidden" name="action" value="reprint_server_save_network_token" />
                <?php wp_nonce_field('reprint_server_save_network_token'); ?>
            <?php else: ?>
                <?php settings_fields('reprint_server'); ?>
            <?php endif; ?>
            <p><?php echo esc_html__('Paste the connection token supplied by your tool, or generate one here and copy it into your tool. Save the token to authorize downloads of your database and files.', 'reprint'); ?></p>
            <p><label for="reprint_server_connection_token"><?php echo esc_html__('Connection token', 'reprint'); ?></label></p>
            <div class="reprint-server-token-row"><?php $this->render_connection_token_field(); ?></div>
            <?php submit_button(__('Save connection token', 'reprint')); ?>
        </form>
        <?php
    }

    /** Render the option-backed connection-token field. */
    public function render_connection_token_field(): void {
        $configuration = get_configuration_state();
        ?>
        <input type="password"
               spellcheck="false"
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
        <button type="button"
                class="button reprint-server-generate-token"
                aria-controls="reprint_server_connection_token"
                data-generated-message="<?php echo esc_attr__('Random connection token generated. Save connection token to apply it.', 'reprint'); ?>">
            <?php echo esc_html__('Generate new token', 'reprint'); ?>
        </button>
        <?php
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
        <h3><?php echo esc_html__('Unused connection token', 'reprint'); ?></h3>
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
     * Enrolled-key table and file-managed access instructions.
     *
     * @param array $configuration Configuration returned by get_configuration_state().
     */
    private function render_public_keys_section(array $configuration): void {
        $file_override = $configuration['has_public_keys_file'];
        $post_url = admin_url('admin-post.php');
        if ($file_override) {
            echo '<p><strong><code>public-keys.php</code> '
                . esc_html__('override is active.', 'reprint') . '</strong> '
                . esc_html__('Edit that file to authorize a tool or remove a key. This page cannot change its keys.', 'reprint') . '</p>';
        }
        ?>
        <?php if ($configuration['enrolled_keys'] !== []): ?>
        <?php if (!is_multisite() && $configuration['required_scheme'] === 'key'): ?>
            <p><?php echo esc_html__('Allow push only if a tool needs to upload, replace, or delete files in this site’s document root, except excluded paths. Downloads do not need push access.', 'reprint'); ?></p>
            <?php if (!$configuration['push_supported']): ?>
                <p><?php echo esc_html__('Push access requires PHP 7.2 or newer. Downloads remain available.', 'reprint'); ?></p>
            <?php elseif ($configuration['managed_push_enabled'] !== null): ?>
                <p><?php echo esc_html__('Push access is managed by your hosting provider.', 'reprint'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <p><?php echo esc_html__('Match the key id shown by your tool. A removed key can no longer connect. Removing the last key stops all public-key access.', 'reprint'); ?></p>
        <div class="reprint-server-key-table-wrapper">
        <table class="widefat striped reprint-server-key-table" role="table">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Key id', 'reprint'); ?></th>
                    <th scope="col"><?php echo esc_html__('Added', 'reprint'); ?></th>
                    <?php if (!is_multisite() && $configuration['required_scheme'] === 'key'): ?>
                        <th scope="col"><?php echo esc_html__('Push access', 'reprint'); ?></th>
                    <?php endif; ?>
                    <th scope="col"><?php echo esc_html__('Actions', 'reprint'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($configuration['enrolled_keys'] as $entry): ?>
                <?php
                // Show what a push signed with this key would get, so a host
                // policy that overrides the stored flag shows here too.
                $key_may_push = $configuration['push_supported'] && get_push_authorization_error($entry['key_id']) === null;
                ?>
                <tr>
                    <td data-label="<?php echo esc_attr__('Key id', 'reprint'); ?>"><code><?php echo esc_html($entry['key_id']); ?></code></td>
                    <td data-label="<?php echo esc_attr__('Added', 'reprint'); ?>"><?php echo esc_html($entry['added_at'] > 0 ? gmdate('Y-m-d', $entry['added_at']) : '—'); ?></td>
                    <?php if (!is_multisite() && $configuration['required_scheme'] === 'key'): ?>
                    <td data-label="<?php echo esc_attr__('Push access', 'reprint'); ?>">
                        <?php if (!$configuration['push_supported'] || $configuration['managed_push_enabled'] !== null || $file_override): ?>
                            <?php echo esc_html($key_may_push ? __('Allowed', 'reprint') : __('Downloads only', 'reprint')); ?>
                        <?php else: ?>
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <input type="hidden" name="action" value="reprint_server_save_key_push_access" />
                            <input type="hidden" name="reprint_server_key_id" value="<?php echo esc_attr($entry['key_id']); ?>" />
                            <?php wp_nonce_field('reprint_server_save_key_push_access'); ?>
                            <label>
                                <input type="checkbox" name="reprint_server_key_push_enabled" value="1"
                                       aria-label="
                                       <?php
                                       echo esc_attr(sprintf(
                                           /* translators: %s: Enrolled key id. */
                                           __('Allow push for key %s', 'reprint'), $entry['key_id']
                                       ));
                                       ?>
                                       " <?php checked($key_may_push); ?> />
                                <?php echo esc_html__('Allow push', 'reprint'); ?>
                            </label>
                            <?php
                            submit_button(__('Save push access', 'reprint'), 'secondary', 'submit', false, [
                                'aria-label' => sprintf(
                                    /* translators: %s: Enrolled key id. */
                                    __('Save push access for key %s', 'reprint'), $entry['key_id']
                                ),
                            ]);
                            ?>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td data-label="<?php echo esc_attr__('Actions', 'reprint'); ?>">
                        <?php if ($file_override): ?>
                            <?php echo esc_html__('Edit public-keys.php', 'reprint'); ?>
                        <?php else: ?>
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <input type="hidden" name="action" value="reprint_server_remove_public_key" />
                            <input type="hidden" name="reprint_server_key_id" value="<?php echo esc_attr($entry['key_id']); ?>" />
                            <?php wp_nonce_field('reprint_server_remove_public_key'); ?>
                            <?php
                            submit_button(__('Remove key', 'reprint'), 'link-delete', 'submit', false, [
                                'aria-label' => sprintf(
                                    /* translators: %s: Enrolled key id. */
                                    __('Remove key %s', 'reprint'), $entry['key_id']
                                ),
                            ]);
                            ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
            'enroll_invalid' => ['error', __('That is not a usable public key. Paste an RSA public key of at least 3072 bits, as a PEM block or one line.', 'reprint')],
            'enroll_no_openssl' => ['error', __('This host cannot read public keys because the OpenSSL extension is missing. Clients authenticate with the connection token here.', 'reprint')],
            'enroll_duplicate' => ['info', __('That public key is already enrolled.', 'reprint')],
            'enroll_file_override' => ['error', __('public-keys.php is active. Edit that file to change enrolled keys.', 'reprint')],
            'enroll_storage_failure' => ['error', __('Failed to save the public key.', 'reprint')],
            'enroll_runtime_missing' => ['error', __('The Reprint Server runtime is missing. Run composer install in the plugin directory or reinstall the release package.', 'reprint')],
            'key_removed' => ['success', __('Public key removed.', 'reprint')],
            'remove_unknown' => ['error', __('That key is not enrolled.', 'reprint')],
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
            'network_token_saved' => ['success', __('Connection token saved.', 'reprint')],
            'network_token_unchanged' => ['success', __('Connection token was already up to date.', 'reprint')],
            'token_removed' => ['success', __('Connection token removed.', 'reprint')],
            'token_remove_storage_failure' => ['error', __('Failed to remove the connection token.', 'reprint')],
        ];
        if (!isset($notices[$result])) {
            return;
        }

        $message = esc_html($notices[$result][1]);
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
