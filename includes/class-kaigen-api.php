<?php
/**
 * API Communication handler
 * Handles all communication with Kaigen API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_API {

    private static $instance = null;
    private $auth;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->auth = Kaigen_Auth::get_instance();
    }

    /**
     * Make API request to Kaigen
     */
    public function request($endpoint, $method = 'GET', $data = null) {
        $api_url = $this->auth->get_api_url();
        $headers = $this->auth->get_headers();

        if (!$headers) {
            return new WP_Error('no_auth', __('No authentication configured', 'kaigen-connector'));
        }

        $url = trailingslashit($api_url) . ltrim($endpoint, '/');

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );

        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error(
                'api_error',
                isset($body['error']) ? $body['error'] : __('API request failed', 'kaigen-connector'),
                array('status' => $code)
            );
        }

        return $body;
    }

    /**
     * Get project information
     */
    public function get_project_info($project_id) {
        return $this->request("api/wordpress/{$project_id}/test-connection", 'POST', array(
            'wpUrl' => home_url()
        ));
    }

    /**
     * Send site structure to Kaigen
     */
    public function send_site_structure($project_id) {
        $content_handler = Kaigen_Content::get_instance();

        $structure = array(
            'postTypes' => $content_handler->get_custom_post_types(),
            'customFields' => $content_handler->get_all_custom_fields(),
            'editorType' => $content_handler->get_editor_type(),
            'seoPlugin' => $content_handler->detect_seo_plugin(),
            'wpVersion' => get_bloginfo('version'),
            'pluginVersion' => KAIGEN_VERSION
        );

        return $this->request("api/wordpress/{$project_id}/ingest-content", 'POST', array(
            'wpUrl' => home_url(),
            'structure' => $structure,
            'content' => array() // Structure only for now
        ));
    }

    /**
     * Send content library to Kaigen
     */
    public function send_content_library($project_id, $post_types = null) {
        $content_handler = Kaigen_Content::get_instance();

        if (!$post_types) {
            $settings = get_option('kaigen_settings', array());
            $post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');
        }

        $posts = array();
        foreach ($post_types as $post_type) {
            $type_posts = $content_handler->get_existing_posts($post_type, array(
                'posts_per_page' => -1,
                'post_status' => array('publish', 'draft')
            ));
            $posts = array_merge($posts, $type_posts);
        }

        $structure = array(
            'postTypes' => $content_handler->get_custom_post_types(),
            'editorType' => $content_handler->get_editor_type()
        );

        return $this->request("api/wordpress/{$project_id}/ingest-content", 'POST', array(
            'wpUrl' => home_url(),
            'structure' => $structure,
            'content' => $posts
        ));
    }

    /**
     * Get editor URL for a post
     */
    public function get_editor_url($project_id, $post_id, $locale = 'en') {
        $result = $this->request("api/wordpress/{$project_id}/editor-url", 'GET', null);

        if (is_wp_error($result)) {
            return $result;
        }

        $wp_url = home_url();
        $editor_url = add_query_arg(array(
            'wp_post_id' => $post_id,
            'wp_site' => urlencode($wp_url)
        ), $result['editorUrl']);

        return $editor_url;
    }

    /**
     * Test connection to Kaigen
     */
    public function test_connection() {
        $validation = $this->auth->validate_with_kaigen();

        if (!$validation['valid']) {
            return new WP_Error('connection_failed', $validation['error']);
        }

        return array(
            'success' => true,
            'project_id' => $validation['project_id'],
            'user_id' => $validation['user_id'],
            'capabilities' => $validation['capabilities']
        );
    }

    /**
     * Sync content to Kaigen
     */
    public function sync_content($project_id = null) {
        if (!$project_id) {
            // Try to get from validation
            $validation = $this->auth->validate_with_kaigen();
            if (!$validation['valid']) {
                return new WP_Error('no_project', __('No project ID available', 'kaigen-connector'));
            }
            $project_id = $validation['project_id'];
            if (empty($project_id)) {
                return new WP_Error('no_project', __('No project ID available', 'kaigen-connector'));
            }
        }

        // Send structure first
        $structure_result = $this->send_site_structure($project_id);
        if (is_wp_error($structure_result)) {
            return $structure_result;
        }

        // Then send content
        $content_result = $this->send_content_library($project_id);
        if (is_wp_error($content_result)) {
            return $content_result;
        }

        // Log the sync
        $this->log_sync_activity('full_sync', 'success', array(
            'project_id' => $project_id,
            'posts_synced' => isset($content_result['postsIngested']) ? $content_result['postsIngested'] : 0
        ));

        return $content_result;
    }

    /**
     * Log sync activity
     */
    private function log_sync_activity($action, $status, $details = array()) {
        $logs = get_option('kaigen_sync_logs', array());

        $logs[] = array(
            'action' => $action,
            'status' => $status,
            'details' => $details,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        );

        // Keep only last 100 logs
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        update_option('kaigen_sync_logs', $logs);
    }

    /**
     * Get sync logs
     */
    public function get_sync_logs($limit = 50) {
        $logs = get_option('kaigen_sync_logs', array());
        return array_slice(array_reverse($logs), 0, $limit);
    }
}





