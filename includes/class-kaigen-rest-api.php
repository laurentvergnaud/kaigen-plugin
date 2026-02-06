<?php

/**
 * REST API handler
 * Registers custom REST API endpoints for Kaigen integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_REST_API
{

    private static $instance = null;
    private $namespace = 'kaigen/v1';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        // Get site structure
        register_rest_route($this->namespace, '/structure', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_structure'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get content library
        register_rest_route($this->namespace, '/content', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get specific post
        register_rest_route($this->namespace, '/content/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));

        // Update post
        register_rest_route($this->namespace, '/content/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));

        // Get internal linking candidates
        register_rest_route($this->namespace, '/links', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_links'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    /**
     * Check permission for API requests
     */
    public function check_permission()
    {
        $auth = Kaigen_Auth::get_instance();
        return $auth->verify_incoming_request();
    }

    /**
     * Get site structure
     */
    public function get_structure($request)
    {
        $content = Kaigen_Content::get_instance();

        $structure = array(
            'postTypes' => $content->get_custom_post_types(),
            'customFields' => $content->get_all_custom_fields(),
            'editorType' => $content->get_editor_type(),
            'seoPlugin' => $content->detect_seo_plugin(),
            'wpVersion' => get_bloginfo('version'),
            'siteUrl' => home_url(),
            'siteName' => get_bloginfo('name'),
            'pluginVersion' => KAIGEN_VERSION
        );

        return rest_ensure_response($structure);
    }

    /**
     * Get content library
     */
    public function get_content($request)
    {
        $content = Kaigen_Content::get_instance();
        $settings = get_option('kaigen_settings', array());

        $post_type = $request->get_param('post_type');
        $per_page = intval($request->get_param('per_page') ?: 100);
        $page = intval($request->get_param('page') ?: 1);

        // Get enabled post types
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        // Filter by specific post type if requested
        if ($post_type && in_array($post_type, $enabled_types)) {
            $post_types = array($post_type);
        } else {
            $post_types = $enabled_types;
        }

        // Query all post types together with global pagination
        $args = array(
            'post_type' => $post_types,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'publish',
            'orderby' => 'modified',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $posts = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $posts[] = array(
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'content' => get_the_content(),
                    'excerpt' => $content->get_smart_excerpt_public($post_id),
                    'url' => get_permalink(),
                    'postType' => get_post_type(),
                    'status' => get_post_status(),
                    'author' => get_the_author(),
                    'publishedDate' => get_the_date('c'),
                    'modifiedDate' => get_the_modified_date('c'),
                    'categories' => wp_get_post_categories($post_id, array('fields' => 'names')),
                    'tags' => wp_get_post_tags($post_id, array('fields' => 'names')) ?: array()
                );
            }
            wp_reset_postdata();
        }

        // Calculate total pages
        $total = $query->found_posts;
        $total_pages = $query->max_num_pages;

        return rest_ensure_response(array(
            'posts' => $posts,
            'total' => $total,
            'total_pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page
        ));
    }

    /**
     * Get specific post
     */
    public function get_post($request)
    {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'kaigen-connector'), array('status' => 404));
        }

        // Check if post type is enabled
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        if (!in_array($post->post_type, $enabled_types)) {
            return new WP_Error('post_type_disabled', __('This post type is not enabled', 'kaigen-connector'), array('status' => 403));
        }

        $content = Kaigen_Content::get_instance();

        $post_data = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'url' => get_permalink($post->ID),
            'postType' => $post->post_type,
            'status' => $post->post_status,
            'author' => get_the_author_meta('display_name', $post->post_author),
            'publishedDate' => get_the_date('c', $post->ID),
            'modifiedDate' => get_the_modified_date('c', $post->ID),
            'categories' => wp_get_post_categories($post->ID, array('fields' => 'names')),
            'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
            'customFields' => get_post_meta($post->ID),
            'editorType' => $content->get_editor_type($post->post_type)
        );

        return rest_ensure_response($post_data);
    }

    /**
     * Update post
     */
    public function update_post($request)
    {
        $post_id = $request->get_param('id');
        $data = $request->get_json_params();
        $data['post_id'] = $post_id;

        $updater = Kaigen_Update::get_instance();
        $result = $updater->handle_update_request($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Get internal linking candidates
     */
    public function get_links($request)
    {
        $content = Kaigen_Content::get_instance();
        $post_type = $request->get_param('post_type');
        $limit = $request->get_param('limit') ?: 100;

        $candidates = $content->get_internal_links_candidates($post_type, $limit);

        return rest_ensure_response(array(
            'links' => $candidates,
            'total' => count($candidates)
        ));
    }
}
