<?php

/**
 * Admin Settings handler
 * Manages the plugin settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Admin
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_kaigen_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_kaigen_sync_content', array($this, 'ajax_sync_content'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('Kaigen Connector', 'kaigen-connector'),
            __('Kaigen Connector', 'kaigen-connector'),
            'kaigen_manage_settings',
            'kaigen-connector',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('kaigen_settings', 'kaigen_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input)
    {
        // Get existing settings to preserve values from other tabs
        $existing = get_option('kaigen_settings', array());
        $sanitized = $existing;

        // Determine which tab submitted the form
        $tab = isset($input['_tab']) ? $input['_tab'] : '';

        // Authentication Tab
        if ($tab === 'authentication') {
            // Auth method
            if (isset($input['auth_method'])) {
                $sanitized['auth_method'] = in_array($input['auth_method'], array('api_key', 'app_password'))
                    ? $input['auth_method']
                    : 'api_key';
            }

            // API URL - always have a default
            if (isset($input['api_url']) && !empty($input['api_url'])) {
                $url = $input['api_url'];

                // Auto-add protocol if missing
                if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
                    // Default to https unless it looks like localhost
                    if (strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
                        $url = 'http://' . $url;
                    } else {
                        $url = 'https://' . $url;
                    }
                }

                $sanitized_url = esc_url_raw($url);

                if (empty($sanitized_url)) {
                    add_settings_error(
                        'kaigen_settings',
                        'invalid_api_url',
                        __('Invalid API URL provided. Please enter a valid URL.', 'kaigen-connector'),
                        'error'
                    );
                    // Fallback to existing
                    $sanitized['api_url'] = isset($existing['api_url']) ? $existing['api_url'] : 'https://kaigen.app';
                } else {
                    $sanitized['api_url'] = $sanitized_url;
                }
            } else {
                // If empty, check if we have an existing one, otherwise default
                if (isset($existing['api_url']) && !empty($existing['api_url'])) {
                    $sanitized['api_url'] = $existing['api_url'];
                } else {
                    $sanitized['api_url'] = 'https://kaigen.app';
                }
            }

            // API key - encrypt if new, preserve if placeholder or empty
            $api_key_placeholder = '••••••••••••••••';
            if (isset($input['api_key']) && !empty($input['api_key']) && $input['api_key'] !== $api_key_placeholder) {
                // Only encrypt if it's a new key (starts with kaigen_)
                if (strpos($input['api_key'], 'kaigen_') === 0) {
                    $auth = Kaigen_Auth::get_instance();
                    $sanitized['api_key'] = $auth->encrypt_value($input['api_key']);
                }
            }
            // If empty or placeholder, we keep $sanitized['api_key'] which is already $existing['api_key']

            // WordPress username
            if (isset($input['wp_username'])) {
                $sanitized['wp_username'] = sanitize_text_field($input['wp_username']);
            }

            // App password - encrypt if new, preserve if placeholder or empty
            $password_placeholder = '••••••••••••••••';
            if (isset($input['wp_app_password']) && !empty($input['wp_app_password']) && $input['wp_app_password'] !== $password_placeholder) {
                $auth = Kaigen_Auth::get_instance();
                $sanitized['wp_app_password'] = $auth->encrypt_value($input['wp_app_password']);
            }
        }

        // Post Types Tab
        if ($tab === 'post-types') {
            if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
                $sanitized['enabled_post_types'] = array_map('sanitize_text_field', $input['enabled_post_types']);
            } else {
                // If not set but we are on this tab, it means all were unchecked
                $sanitized['enabled_post_types'] = array();
            }
        }

        // Permissions Tab
        if ($tab === 'permissions') {
            if (isset($input['role_permissions']) && is_array($input['role_permissions'])) {
                $sanitized['role_permissions'] = array_map('sanitize_text_field', $input['role_permissions']);
            } else {
                // If not set but we are on this tab, it means all were unchecked
                $sanitized['role_permissions'] = array();
            }
            $this->update_role_permissions($sanitized['role_permissions']);
        }

        return $sanitized;
    }

    /**
     * Update role permissions
     */
    private function update_role_permissions($allowed_roles)
    {
        $all_roles = wp_roles()->get_names();

        foreach ($all_roles as $role_slug => $role_name) {
            $role = get_role($role_slug);
            if ($role) {
                if (in_array($role_slug, $allowed_roles)) {
                    $role->add_cap('kaigen_edit_posts');
                } else {
                    $role->remove_cap('kaigen_edit_posts');
                }
            }
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'settings_page_kaigen-connector') {
            return;
        }

        wp_enqueue_style(
            'kaigen-admin',
            KAIGEN_PLUGIN_URL . 'admin/css/kaigen-admin.css',
            array(),
            KAIGEN_VERSION
        );

        wp_enqueue_script(
            'kaigen-admin',
            KAIGEN_PLUGIN_URL . 'admin/js/kaigen-admin.js',
            array('jquery'),
            KAIGEN_VERSION,
            true
        );

        wp_localize_script('kaigen-admin', 'kaigenAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kaigen_admin'),
            'strings' => array(
                'testing' => __('Testing connection...', 'kaigen-connector'),
                'syncing' => __('Syncing content...', 'kaigen-connector'),
                'success' => __('Success!', 'kaigen-connector'),
                'error' => __('Error:', 'kaigen-connector'),
                'testConnection' => __('Test Connection', 'kaigen-connector'),
                'syncContentNow' => __('Sync Content Now', 'kaigen-connector')
            )
        ));
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('kaigen_manage_settings')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'kaigen-connector'));
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'authentication';
        $settings = get_option('kaigen_settings', array());
        $auth = Kaigen_Auth::get_instance();
        $content = Kaigen_Content::get_instance();
        $api = Kaigen_API::get_instance();

?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=kaigen-connector&tab=authentication" class="nav-tab <?php echo $active_tab === 'authentication' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Authentication', 'kaigen-connector'); ?>
                </a>
                <a href="?page=kaigen-connector&tab=post-types" class="nav-tab <?php echo $active_tab === 'post-types' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Post Types', 'kaigen-connector'); ?>
                </a>
                <a href="?page=kaigen-connector&tab=permissions" class="nav-tab <?php echo $active_tab === 'permissions' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Permissions', 'kaigen-connector'); ?>
                </a>
                <a href="?page=kaigen-connector&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'kaigen-connector'); ?>
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('kaigen_settings'); ?>

                <?php if ($active_tab === 'authentication'): ?>
                    <?php $this->render_authentication_tab($settings, $auth); ?>
                <?php elseif ($active_tab === 'post-types'): ?>
                    <?php $this->render_post_types_tab($settings, $content); ?>
                <?php elseif ($active_tab === 'permissions'): ?>
                    <?php $this->render_permissions_tab($settings); ?>
                <?php elseif ($active_tab === 'logs'): ?>
                    <?php $this->render_logs_tab($api); ?>
                <?php endif; ?>

                <?php if ($active_tab !== 'logs'): ?>
                    <?php submit_button(); ?>
                <?php endif; ?>
            </form>
        </div>
    <?php
    }

    /**
     * Render authentication tab
     */
    private function render_authentication_tab($settings, $auth)
    {
        $auth_method = isset($settings['auth_method']) ? $settings['auth_method'] : 'api_key';
        $api_url = isset($settings['api_url']) ? $settings['api_url'] : 'https://kaigen.app';
    ?>
        <input type="hidden" name="kaigen_settings[_tab]" value="authentication">
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Authentication Method', 'kaigen-connector'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="kaigen_settings[auth_method]" value="api_key" <?php checked($auth_method, 'api_key'); ?>>
                        <?php _e('API Key (Recommended)', 'kaigen-connector'); ?>
                    </label><br>
                    <label>
                        <input type="radio" name="kaigen_settings[auth_method]" value="app_password" <?php checked($auth_method, 'app_password'); ?>>
                        <?php _e('WordPress Application Password', 'kaigen-connector'); ?>
                    </label>
                    <p class="description"><?php _e('Choose how Kaigen will authenticate with your WordPress site.', 'kaigen-connector'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Kaigen API URL', 'kaigen-connector'); ?></th>
                <td>
                    <input type="url" name="kaigen_settings[api_url]" value="<?php echo esc_attr($api_url); ?>" class="regular-text">
                    <p class="description"><?php _e('The URL of your Kaigen instance.', 'kaigen-connector'); ?></p>
                </td>
            </tr>

            <tr class="kaigen-api-key-field" style="<?php echo $auth_method !== 'api_key' ? 'display:none;' : ''; ?>">
                <th scope="row"><?php _e('API Key', 'kaigen-connector'); ?></th>
                <td>
                    <?php $has_api_key = $auth->get_api_key(); ?>
                    <input type="password" name="kaigen_settings[api_key]" value="<?php echo $has_api_key ? '••••••••••••••••' : ''; ?>" class="regular-text" placeholder="<?php _e('Enter your API key', 'kaigen-connector'); ?>">
                    <p class="description">
                        <?php _e('Generate an API key in your Kaigen dashboard and paste it here.', 'kaigen-connector'); ?>
                        <?php if ($has_api_key): ?>
                            <br><strong><?php _e('API key is currently configured. Enter a new key to replace it.', 'kaigen-connector'); ?></strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>

            <?php
            $wp_username = isset($settings['wp_username']) ? $settings['wp_username'] : '';
            $has_app_password = isset($settings['wp_app_password']) && !empty($settings['wp_app_password']);
            ?>
            <tr class="kaigen-app-password-field" style="<?php echo $auth_method !== 'app_password' ? 'display:none;' : ''; ?>">
                <th scope="row"><?php _e('WordPress Username', 'kaigen-connector'); ?></th>
                <td>
                    <input type="text" name="kaigen_settings[wp_username]" value="<?php echo esc_attr($wp_username); ?>" class="regular-text" placeholder="<?php _e('WordPress username', 'kaigen-connector'); ?>">
                </td>
            </tr>

            <tr class="kaigen-app-password-field" style="<?php echo $auth_method !== 'app_password' ? 'display:none;' : ''; ?>">
                <th scope="row"><?php _e('Application Password', 'kaigen-connector'); ?></th>
                <td>
                    <input type="password" name="kaigen_settings[wp_app_password]" value="<?php echo $has_app_password ? '••••••••••••••••' : ''; ?>" class="regular-text" placeholder="<?php _e('Application password', 'kaigen-connector'); ?>">
                    <p class="description"><?php _e('Generate an application password in your WordPress profile.', 'kaigen-connector'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Connection Status', 'kaigen-connector'); ?></th>
                <td>
                    <button type="button" id="kaigen-test-connection" class="button">
                        <?php _e('Test Connection', 'kaigen-connector'); ?>
                    </button>
                    <span id="kaigen-connection-status"></span>
                </td>
            </tr>
        </table>
    <?php
    }

    /**
     * Render post types tab
     */
    private function render_post_types_tab($settings, $content)
    {
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');
        $post_types = $content->get_custom_post_types();
    ?>
        <input type="hidden" name="kaigen_settings[_tab]" value="post-types">
        <p><?php _e('Select which post types should be accessible to Kaigen:', 'kaigen-connector'); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" id="kaigen-select-all">
                    </th>
                    <th><?php _e('Post Type', 'kaigen-connector'); ?></th>
                    <th><?php _e('Label', 'kaigen-connector'); ?></th>
                    <th><?php _e('Count', 'kaigen-connector'); ?></th>
                    <th><?php _e('Public', 'kaigen-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($post_types as $post_type): ?>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox"
                                name="kaigen_settings[enabled_post_types][]"
                                value="<?php echo esc_attr($post_type['slug']); ?>"
                                <?php checked(in_array($post_type['slug'], $enabled_types)); ?>>
                        </th>
                        <td><code><?php echo esc_html($post_type['slug']); ?></code></td>
                        <td><?php echo esc_html($post_type['label']); ?></td>
                        <td><?php echo esc_html($post_type['count']); ?></td>
                        <td><?php echo $post_type['public'] ? '✓' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description">
            <?php _e('Only selected post types will be visible and editable through Kaigen.', 'kaigen-connector'); ?>
        </p>
    <?php
    }

    /**
     * Render permissions tab
     */
    private function render_permissions_tab($settings)
    {
        $role_permissions = isset($settings['role_permissions']) ? $settings['role_permissions'] : array('administrator', 'editor');
        $roles = wp_roles()->get_names();
    ?>
        <input type="hidden" name="kaigen_settings[_tab]" value="permissions">
        <p><?php _e('Select which user roles can use Kaigen features:', 'kaigen-connector'); ?></p>

        <table class="form-table">
            <?php foreach ($roles as $role_slug => $role_name): ?>
                <tr>
                    <th scope="row"><?php echo esc_html($role_name); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                name="kaigen_settings[role_permissions][]"
                                value="<?php echo esc_attr($role_slug); ?>"
                                <?php checked(in_array($role_slug, $role_permissions)); ?>>
                            <?php _e('Can edit posts with Kaigen', 'kaigen-connector'); ?>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p class="description">
            <?php _e('Users with selected roles will see the "Open in Kaigen" button in the post editor.', 'kaigen-connector'); ?>
        </p>
    <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab($api)
    {
        $logs = $api->get_sync_logs(50);
    ?>
        <div class="kaigen-logs-header">
            <button type="button" id="kaigen-sync-content" class="button button-primary">
                <?php _e('Sync Content Now', 'kaigen-connector'); ?>
            </button>
            <span id="kaigen-sync-status"></span>
        </div>

        <h3><?php _e('Recent Activity', 'kaigen-connector'); ?></h3>

        <?php if (empty($logs)): ?>
            <p><?php _e('No activity logged yet.', 'kaigen-connector'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'kaigen-connector'); ?></th>
                        <th><?php _e('Action', 'kaigen-connector'); ?></th>
                        <th><?php _e('Status', 'kaigen-connector'); ?></th>
                        <th><?php _e('Details', 'kaigen-connector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><?php echo esc_html($log['action']); ?></td>
                            <td>
                                <span class="kaigen-status-<?php echo esc_attr($log['status']); ?>">
                                    <?php echo esc_html($log['status']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(json_encode($log['details'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
<?php
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('kaigen_admin', 'nonce');

        if (!current_user_can('kaigen_manage_settings')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'kaigen-connector')));
        }

        $api = Kaigen_API::get_instance();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Sync content
     */
    public function ajax_sync_content()
    {
        check_ajax_referer('kaigen_admin', 'nonce');

        if (!current_user_can('kaigen_manage_settings')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'kaigen-connector')));
        }

        $api = Kaigen_API::get_instance();
        $result = $api->sync_content();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }
}
