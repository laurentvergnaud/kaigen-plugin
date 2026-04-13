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
    public function validate_with_kaigen($api_key = null, $api_url_override = null, $enforce_project_guard = true) {
        if (!$api_key) {
            $api_key = $this->get_api_key();
        }

        if (is_string($api_key)) {
            $api_key = preg_replace('/\s+/', '', trim($api_key));
        }

        if (!$api_key) {
            return array(
                'valid' => false,
                'error' => __('No API key configured', 'kaigen-connector')
            );
        }

        $api_url = $api_url_override ? esc_url_raw($api_url_override) : $this->get_api_url();
        $wp_url = home_url();
        $api_key_preview = is_string($api_key) ? substr($api_key, 0, 12) . '...' : 'null';
        error_log('[Kaigen connector][validate_with_kaigen] Sending validation request: api_url=' . $api_url . ', wp_url=' . $wp_url . ', api_key=' . $api_key_preview);

        $response = wp_remote_post($api_url . '/api/wordpress/validate', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode(array('wpUrl' => $wp_url)),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            error_log('[Kaigen connector][validate_with_kaigen] Request failed: ' . $response->get_error_message());
            return array(
                'valid' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        $trace_id = isset($body['traceId']) ? $body['traceId'] : null;
        $platform_id = isset($body['platformId']) ? $body['platformId'] : null;
        $project_id = isset($body['projectId']) ? $body['projectId'] : null;
        $error_message = isset($body['error']) ? $body['error'] : '';
        error_log('[Kaigen connector][validate_with_kaigen] Response: code=' . intval($code) . ', valid=' . (isset($body['valid']) ? var_export($body['valid'], true) : 'null') . ', project_id=' . strval($project_id) . ', platform_id=' . strval($platform_id) . ', trace_id=' . strval($trace_id) . ', error=' . strval($error_message));

        if ($code !== 200) {
            return array(
                'valid' => false,
                'error' => isset($body['error']) ? $body['error'] : __('Validation failed', 'kaigen-connector')
            );
        }

        $resolved_project_id = isset($body['projectId']) ? strval($body['projectId']) : '';
        $settings = get_option('kaigen_settings', array());
        $existing_project_id = isset($settings['project_id']) ? strval($settings['project_id']) : '';
        if ($enforce_project_guard && !empty($existing_project_id) && !empty($resolved_project_id) && $existing_project_id !== $resolved_project_id) {
            $mismatch_error = sprintf(
                __('API key belongs to another Kaigen project (expected %1$s, got %2$s). Please paste the dedicated key generated for this project.', 'kaigen-connector'),
                $existing_project_id,
                $resolved_project_id
            );
            error_log('[Kaigen connector][validate_with_kaigen] Project mismatch: existing=' . $existing_project_id . ', resolved=' . $resolved_project_id);
            return array(
                'valid' => false,
                'error' => $mismatch_error
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
            if (is_string($api_key)) {
                $api_key = preg_replace('/\s+/', '', trim($api_key));
            }
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
        $log_auth = defined('WP_DEBUG') && WP_DEBUG;
        $auth_method = $this->get_auth_method();

        if ($log_auth) {
            $has_auth = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            error_log('[Kaigen connector][verify_incoming_request] Start auth_method=' . $auth_method . ', has_auth_header=' . ($has_auth ? 'yes' : 'no') . ', request_uri=' . $request_uri);
        }

        // Always try API key auth first (works even if auth_method is app_password).
        $provided_key = $this->extract_api_key_from_request();
        if (is_string($provided_key) && $provided_key !== '') {
            $stored_key = $this->get_api_key();
            if (is_string($stored_key) && $stored_key !== '') {
                $stored_key = preg_replace('/\s+/', '', trim($stored_key));
                if (hash_equals($stored_key, $provided_key)) {
                    if ($log_auth) {
                        error_log('[Kaigen connector][verify_incoming_request] Authorized via API key header provided=' . $this->mask_key($provided_key) . ', stored=' . $this->mask_key($stored_key));
                    }
                    return true;
                }
                if ($log_auth) {
                    error_log('[Kaigen connector][verify_incoming_request] API key header mismatch provided=' . $this->mask_key($provided_key) . ', stored=' . $this->mask_key($stored_key));
                }
            } elseif ($log_auth) {
                error_log('[Kaigen connector][verify_incoming_request] Provided API key header but no stored key in settings');
            }
        } elseif ($log_auth) {
            error_log('[Kaigen connector][verify_incoming_request] No API key found in headers');
        }

        // Fallback for legacy app-password mode (authenticated WP user context).
        if ($auth_method === 'app_password') {
            $allowed = is_user_logged_in() && current_user_can('kaigen_edit_posts');
            if ($log_auth) {
                error_log('[Kaigen connector][verify_incoming_request] App password fallback result=' . ($allowed ? 'true' : 'false'));
            }
            return $allowed;
        }

        if ($log_auth) {
            error_log('[Kaigen connector][verify_incoming_request] Authorization failed');
        }

        return false;
    }

    private function extract_api_key_from_request() {
        $candidates = array();

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach (array('Authorization', 'authorization') as $name) {
                if (isset($headers[$name])) {
                    $candidates[] = sanitize_text_field(wp_unslash($headers[$name]));
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $value = '';
            if (stripos($candidate, 'Bearer ') === 0) {
                $value = substr($candidate, 7);
            } else {
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $normalized = preg_replace('/\s+/', '', trim($value));
            if (!is_string($normalized) || $normalized === '') {
                continue;
            }

            return $normalized;
        }

        return false;
    }

    private function mask_key($key) {
        if (!is_string($key) || $key === '') {
            return 'none';
        }
        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        return substr($key, 0, 6) . '...' . substr($key, -4);
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
