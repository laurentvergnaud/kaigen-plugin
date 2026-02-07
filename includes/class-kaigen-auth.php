<?php
/**
 * Authentication handler
 * Manages API key and application password authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Auth {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor
    }

    /**
     * Get authentication method
     */
    public function get_auth_method() {
        $settings = get_option('kaigen_settings', array());
        return isset($settings['auth_method']) ? $settings['auth_method'] : 'api_key';
    }

    /**
     * Store API key (encrypted)
     */
    public function store_api_key($api_key, $api_url) {
        $settings = get_option('kaigen_settings', array());

        // Encrypt the API key using WordPress salts
        $encrypted_key = $this->encrypt($api_key);

        $settings['api_key'] = $encrypted_key;
        $settings['api_url'] = esc_url_raw($api_url);
        $settings['auth_method'] = 'api_key';

        return update_option('kaigen_settings', $settings);
    }

    /**
     * Get API key (decrypted)
     */
    public function get_api_key() {
        $settings = get_option('kaigen_settings', array());

        if (!isset($settings['api_key'])) {
            return false;
        }

        return $this->decrypt($settings['api_key']);
    }

    /**
     * Store application password credentials
     */
    public function store_app_password($username, $password) {
        $settings = get_option('kaigen_settings', array());

        $settings['wp_username'] = sanitize_text_field($username);
        $settings['wp_app_password'] = $this->encrypt($password);
        $settings['auth_method'] = 'app_password';

        return update_option('kaigen_settings', $settings);
    }

    /**
     * Get application password credentials
     */
    public function get_app_password_credentials() {
        $settings = get_option('kaigen_settings', array());

        if (!isset($settings['wp_username']) || !isset($settings['wp_app_password'])) {
            return false;
        }

        return array(
            'username' => $settings['wp_username'],
            'password' => $this->decrypt($settings['wp_app_password'])
        );
    }

    /**
     * Get Kaigen API URL
     */
    public function get_api_url() {
        $settings = get_option('kaigen_settings', array());
        return isset($settings['api_url']) ? $settings['api_url'] : 'https://kaigen.app';
    }

    /**
     * Validate API key with Kaigen
     */
    public function validate_with_kaigen($api_key = null) {
        if (!$api_key) {
            $api_key = $this->get_api_key();
        }

        if (!$api_key) {
            return array(
                'valid' => false,
                'error' => __('No API key configured', 'kaigen-connector')
            );
        }

        $api_url = $this->get_api_url();
        $response = wp_remote_post($api_url . '/api/wordpress/validate', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode(array('wpUrl' => home_url())),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array(
                'valid' => false,
                'error' => isset($body['error']) ? $body['error'] : __('Validation failed', 'kaigen-connector')
            );
        }

        return array(
            'valid' => isset($body['valid']) ? $body['valid'] : false,
            'project_id' => isset($body['projectId']) ? $body['projectId'] : null,
            'user_id' => isset($body['userId']) ? $body['userId'] : null,
            'capabilities' => isset($body['capabilities']) ? $body['capabilities'] : array()
        );
    }

    /**
     * Get authorization headers for API requests
     */
    public function get_headers() {
        $auth_method = $this->get_auth_method();

        if ($auth_method === 'api_key') {
            $api_key = $this->get_api_key();
            if (!$api_key) {
                return false;
            }

            return array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            );
        } else {
            // Application password - not used for outgoing requests to Kaigen
            // This is for Kaigen to authenticate TO WordPress
            return array(
                'Content-Type' => 'application/json'
            );
        }
    }

    /**
     * Verify incoming request from Kaigen
     */
    public function verify_incoming_request() {
        $auth_method = $this->get_auth_method();

        if ($auth_method === 'api_key') {
            // For API key method, Kaigen sends the key in Authorization header
            $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

            if (empty($auth_header)) {
                // Try alternative header
                $auth_header = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '';
            }

            if (empty($auth_header) && function_exists('getallheaders')) {
                $headers = getallheaders();
                if (isset($headers['Authorization'])) {
                    $auth_header = $headers['Authorization'];
                } elseif (isset($headers['authorization'])) {
                    $auth_header = $headers['authorization'];
                }
            }

            if (empty($auth_header)) {
                return false;
            }

            $auth_header = trim($auth_header);

            // Extract the key
            if (strpos($auth_header, 'Bearer ') === 0) {
                $provided_key = substr($auth_header, 7);
            } elseif (strpos($auth_header, 'ApiKey ') === 0) {
                $provided_key = substr($auth_header, 7);
            } else {
                return false;
            }

            $stored_key = $this->get_api_key();

            // get_api_key() returns false when no key is configured
            // hash_equals() requires string arguments in PHP 8.0+
            if (!is_string($stored_key) || $stored_key === '') {
                return false;
            }

            return hash_equals($stored_key, $provided_key);
        } else {
            // For app password method, WordPress handles authentication
            return is_user_logged_in() && current_user_can('kaigen_edit_posts');
        }
    }

    /**
     * Public method to encrypt a value (for use by admin class)
     */
    public function encrypt_value($data) {
        return $this->encrypt($data);
    }

    /**
     * Encrypt data
     */
    private function encrypt($data) {
        if (!$data) {
            return '';
        }

        $key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    private function decrypt($data) {
        if (!$data) {
            return '';
        }

        $key = $this->get_encryption_key();
        $data = base64_decode($data);
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Get encryption key from WordPress salts
     */
    private function get_encryption_key() {
        return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
    }

    /**
     * Clear stored credentials
     */
    public function clear_credentials() {
        $settings = get_option('kaigen_settings', array());

        unset($settings['api_key']);
        unset($settings['wp_username']);
        unset($settings['wp_app_password']);

        return update_option('kaigen_settings', $settings);
    }
}




