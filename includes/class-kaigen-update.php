<?php
/**
 * Content Update handler
 * Handles updating WordPress posts from Kaigen
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Update {

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
     * Handle update request from Kaigen
     */
    public function handle_update_request($data) {
        // Validate required fields
        if (!isset($data['post_id']) || !isset($data['content'])) {
            return new WP_Error('missing_data', __('Missing required fields', 'kaigen-connector'));
        }

        $post_id = intval($data['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'kaigen-connector'));
        }

        // Check capabilities
        if (!$this->validate_capabilities(get_current_user_id(), $post->post_type)) {
            return new WP_Error('insufficient_permissions', __('Insufficient permissions', 'kaigen-connector'));
        }

        // Prepare update data
        $update_data = array(
            'ID' => $post_id
        );

        // Update content
        if (isset($data['content'])) {
            $update_data['post_content'] = wp_kses_post($data['content']);
        }

        // Update title
        if (isset($data['title'])) {
            $update_data['post_title'] = sanitize_text_field($data['title']);
        }

        // Update excerpt
        if (isset($data['excerpt'])) {
            $update_data['post_excerpt'] = sanitize_textarea_field($data['excerpt']);
        }

        // Update status
        if (isset($data['status'])) {
            $allowed_statuses = array('publish', 'draft', 'pending', 'private');
            if (in_array($data['status'], $allowed_statuses)) {
                $update_data['post_status'] = $data['status'];
            }
        }

        // Perform the update
        $result = wp_update_post($update_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        // Update meta fields
        if (isset($data['meta']) && is_array($data['meta'])) {
            $this->update_post_meta($post_id, $data['meta']);
        }

        // Update custom fields (ACF, etc.)
        if (isset($data['customFields']) && is_array($data['customFields'])) {
            $this->update_custom_fields($post_id, $data['customFields']);
        }

        // Update SEO fields
        if (isset($data['seo']) && is_array($data['seo'])) {
            $this->update_seo_fields($post_id, $data['seo']);
        }

        // Log the update
        $this->log_update($post_id, $data);

        do_action('kaigen_after_update', $post_id, $result);

        return array(
            'success' => true,
            'post_id' => $post_id,
            'url' => get_permalink($post_id)
        );
    }

    /**
     * Validate user capabilities
     */
    public function validate_capabilities($user_id, $post_type) {
        if (!$user_id) {
            return false;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }

        // Check if user has kaigen_edit_posts capability
        if (!$user->has_cap('kaigen_edit_posts')) {
            return false;
        }

        // Check if user can edit posts of this type
        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj) {
            return false;
        }

        return $user->has_cap($post_type_obj->cap->edit_posts);
    }

    /**
     * Update post meta
     */
    private function update_post_meta($post_id, $meta_data) {
        foreach ($meta_data as $key => $value) {
            // Sanitize key
            $key = sanitize_key($key);

            // Skip internal meta
            if (substr($key, 0, 1) === '_') {
                continue;
            }

            // Sanitize value
            if (is_array($value)) {
                $value = array_map('sanitize_text_field', $value);
            } else {
                $value = sanitize_text_field($value);
            }

            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Update custom fields (ACF, etc.)
     */
    private function update_custom_fields($post_id, $fields) {
        // ACF fields
        if (function_exists('update_field')) {
            foreach ($fields as $field_key => $field_value) {
                update_field($field_key, $field_value, $post_id);
            }
        }
    }

    /**
     * Update SEO fields
     */
    private function update_seo_fields($post_id, $seo_data) {
        $seo_plugin = Kaigen_Content::get_instance()->detect_seo_plugin();

        switch ($seo_plugin) {
            case 'yoast':
                $this->update_yoast_seo($post_id, $seo_data);
                break;
            case 'rankmath':
                $this->update_rankmath_seo($post_id, $seo_data);
                break;
            case 'seopress':
                $this->update_seopress_seo($post_id, $seo_data);
                break;
        }
    }

    /**
     * Update Yoast SEO fields
     */
    private function update_yoast_seo($post_id, $seo_data) {
        if (isset($seo_data['title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($seo_data['title']));
        }
        if (isset($seo_data['description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($seo_data['description']));
        }
        if (isset($seo_data['focusKeyword'])) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($seo_data['focusKeyword']));
        }
    }

    /**
     * Update Rank Math SEO fields
     */
    private function update_rankmath_seo($post_id, $seo_data) {
        if (isset($seo_data['title'])) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($seo_data['title']));
        }
        if (isset($seo_data['description'])) {
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($seo_data['description']));
        }
        if (isset($seo_data['focusKeyword'])) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($seo_data['focusKeyword']));
        }
    }

    /**
     * Update SEOPress fields
     */
    private function update_seopress_seo($post_id, $seo_data) {
        if (isset($seo_data['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($seo_data['title']));
        }
        if (isset($seo_data['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', sanitize_textarea_field($seo_data['description']));
        }
        if (isset($seo_data['focusKeyword'])) {
            update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($seo_data['focusKeyword']));
        }
    }

    /**
     * Log update activity
     */
    private function log_update($post_id, $changes) {
        $logs = get_option('kaigen_update_logs', array());

        $logs[] = array(
            'post_id' => $post_id,
            'post_title' => get_the_title($post_id),
            'changes' => array_keys($changes),
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        );

        // Keep only last 100 logs
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        update_option('kaigen_update_logs', $logs);
    }

    /**
     * Get update logs
     */
    public function get_update_logs($limit = 50) {
        $logs = get_option('kaigen_update_logs', array());
        return array_slice(array_reverse($logs), 0, $limit);
    }
}





