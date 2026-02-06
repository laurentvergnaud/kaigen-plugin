<?php
/**
 * Plugin Name: Kaigen Connector
 * Plugin URI: https://kaigen.app
 * Description: Connect your WordPress site to Kaigen for AI-powered content generation and management
 * Version: 1.0.0
 * Author: Kaigen
 * Author URI: https://kaigen.app
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kaigen-connector
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('KAIGEN_VERSION', '1.0.0');
define('KAIGEN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KAIGEN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KAIGEN_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Kaigen Connector class
 */
class Kaigen_Connector {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-auth.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-api.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-content.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-update.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-admin.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-rest-api.php';
        require_once KAIGEN_PLUGIN_DIR . 'includes/class-kaigen-editor-button.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create custom capabilities
        $this->add_capabilities();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set default options
        if (!get_option('kaigen_settings')) {
            add_option('kaigen_settings', array(
                'auth_method' => 'api_key',
                'api_url' => 'https://kaigen.app',
                'enabled_post_types' => array('post', 'page'),
            ));
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize components
        Kaigen_Admin::get_instance();
        Kaigen_REST_API::get_instance();
        Kaigen_Editor_Button::get_instance();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'kaigen-connector',
            false,
            dirname(KAIGEN_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Add custom capabilities
     */
    private function add_capabilities() {
        $roles = array('administrator', 'editor');

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('kaigen_edit_posts');
                if ($role_name === 'administrator') {
                    $role->add_cap('kaigen_manage_settings');
                }
            }
        }
    }

    /**
     * Get plugin version
     */
    public function get_version() {
        return KAIGEN_VERSION;
    }
}

/**
 * Initialize the plugin
 */
function kaigen_connector() {
    return Kaigen_Connector::get_instance();
}

// Start the plugin
kaigen_connector();





