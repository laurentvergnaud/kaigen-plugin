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

        // Create new content from canonical v2 payload
        register_rest_route($this->namespace, '/content', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
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

        // Import a remote media asset into the WordPress media library
        register_rest_route($this->namespace, '/media/import', array(
            'methods' => 'POST',
            'callback' => array($this, 'import_media'),
            'permission_callback' => array($this, 'check_permission')
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
    public function check_permission($request = null)
    {
        $auth = Kaigen_Auth::get_instance();
        $allowed = $auth->verify_incoming_request();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $route = '';
            if ($request instanceof WP_REST_Request) {
                $route = $request->get_route();
            } elseif (is_object($request) && method_exists($request, 'get_route')) {
                $route = $request->get_route();
            } else {
                $route = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            }
            error_log('[Kaigen connector][rest_permission] route=' . $route . ', allowed=' . ($allowed ? 'true' : 'false'));
        }

        return $allowed;
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

        $per_page = intval($request->get_param('per_page') ?: 100);
        $per_page = max(1, min($per_page, 100));
        $page = intval($request->get_param('page') ?: 1);
        $page = max(1, $page);

        // Get enabled post types
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        // Filter by post types (CSV or array)
        $requested_post_types = $this->parse_csv_or_array($request->get_param('post_type'));
        $post_types = empty($requested_post_types)
            ? $enabled_types
            : array_values(array_intersect($requested_post_types, $enabled_types));
        if (empty($post_types)) {
            $post_types = $enabled_types;
        }

        // Filter by status (CSV or array)
        $requested_statuses = array_map('strtolower', $this->parse_csv_or_array($request->get_param('post_status')));
        $allowed_statuses = array('publish', 'draft', 'pending', 'private', 'future');
        $post_statuses = empty($requested_statuses)
            ? array('publish')
            : array_values(array_intersect($requested_statuses, $allowed_statuses));
        if (empty($post_statuses)) {
            $post_statuses = array('publish');
        }

        // Sort configuration
        $orderby = strtolower(strval($request->get_param('orderby') ?: 'modified'));
        $allowed_orderby = array('modified', 'date', 'title', 'id', 'rand');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'modified';
        }
        $wp_orderby = $orderby === 'id' ? 'ID' : $orderby;

        $order = strtoupper(strval($request->get_param('order') ?: 'DESC'));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }

        $search = sanitize_text_field(strval($request->get_param('search') ?: ''));
        $modified_after = sanitize_text_field(strval($request->get_param('modified_after') ?: ''));
        $modified_before = sanitize_text_field(strval($request->get_param('modified_before') ?: ''));

        // Optional field selection
        $allowed_fields = array(
            'id',
            'title',
            'content',
            'excerpt',
            'url',
            'postType',
            'status',
            'author',
            'publishedDate',
            'modifiedDate',
            'categories',
            'tags',
            'seo_title',
            'seo_meta_description',
            'seo_keyword',
        );
        $requested_fields = $this->parse_csv_or_array($request->get_param('fields'));
        $requested_fields = array_values(array_intersect($requested_fields, $allowed_fields));
        $has_field_filter = !empty($requested_fields);
        $required_fields = array('id', 'modifiedDate');
        $fields_to_keep = array_values(array_unique(array_merge($requested_fields, $required_fields)));

        // Query all post types together with global pagination
        $args = array(
            'post_type' => $post_types,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => $post_statuses,
            'orderby' => $wp_orderby,
            'order' => $order
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $date_query = array();
        if (!empty($modified_after)) {
            $after_ts = strtotime($modified_after);
            if ($after_ts !== false) {
                $date_query[] = array(
                    'after' => gmdate('c', $after_ts),
                    'column' => 'post_modified_gmt',
                    'inclusive' => true,
                );
            }
        }
        if (!empty($modified_before)) {
            $before_ts = strtotime($modified_before);
            if ($before_ts !== false) {
                $date_query[] = array(
                    'before' => gmdate('c', $before_ts),
                    'column' => 'post_modified_gmt',
                    'inclusive' => true,
                );
            }
        }
        if (!empty($date_query)) {
            $args['date_query'] = $date_query;
        }

        $query = new WP_Query($args);
        $posts = array();
        $seo_plugin = $content->detect_seo_plugin();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $seo_summary = $this->get_post_seo_summary($post_id, $seo_plugin);

                $post_item = array(
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
                    'tags' => wp_get_post_tags($post_id, array('fields' => 'names')) ?: array(),
                    'seo_title' => $seo_summary['seo_title'],
                    'seo_meta_description' => $seo_summary['seo_meta_description'],
                    'seo_keyword' => $seo_summary['seo_keyword'],
                );

                if ($has_field_filter) {
                    $post_item = array_intersect_key($post_item, array_flip($fields_to_keep));
                }

                $posts[] = $post_item;
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

    private function parse_csv_or_array($value)
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = explode(',', $value);
        } else {
            return array();
        }

        $normalized = array();
        foreach ($items as $item) {
            $item = trim(strval($item));
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    private function get_post_seo_summary($post_id, $seo_plugin)
    {
        $seo_keys = $this->get_seo_meta_keys_for_plugin($seo_plugin);

        $seo_title = get_post_meta($post_id, $seo_keys['title'], true);
        $seo_description = get_post_meta($post_id, $seo_keys['description'], true);
        $seo_keyword = get_post_meta($post_id, $seo_keys['focus_keyword'], true);

        return array(
            'seo_title' => $seo_title !== '' ? strval($seo_title) : null,
            'seo_meta_description' => $seo_description !== '' ? strval($seo_description) : null,
            'seo_keyword' => $seo_keyword !== '' ? strval($seo_keyword) : null,
        );
    }

    private function get_seo_meta_keys_for_plugin($seo_plugin)
    {
        if ($seo_plugin === 'rankmath') {
            return array(
                'title' => 'rank_math_title',
                'description' => 'rank_math_description',
                'focus_keyword' => 'rank_math_focus_keyword',
            );
        }

        if ($seo_plugin === 'seopress') {
            return array(
                'title' => '_seopress_titles_title',
                'description' => '_seopress_titles_desc',
                'focus_keyword' => '_seopress_analysis_target_kw',
            );
        }

        return array(
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw',
        );
    }

    /**
     * Create a new post from canonical v2 payload
     */
    public function create_post($request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', __('Invalid JSON payload', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($data['post']) || !is_array($data['post'])) {
            return new WP_Error('missing_post', __('post object is required', 'kaigen-connector'), array('status' => 400));
        }

        $post_type = isset($data['post']['post_type']) ? sanitize_key($data['post']['post_type']) : 'post';
        if ($post_type === '') {
            $post_type = 'post';
        }

        // Check if post type is enabled
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');
        if (!in_array($post_type, $enabled_types, true)) {
            return new WP_Error('post_type_disabled', __('This post type is not enabled', 'kaigen-connector'), array('status' => 403));
        }

        $data['post']['post_type'] = $post_type;
        $data['project_id'] = $request->get_param('project_id') ?: '';
        $data['platform_id'] = $request->get_param('platform_id') ?: null;
        $data['site_url'] = $request->get_param('site_url') ?: '';

        $updater = Kaigen_Update::get_instance();
        $result = $updater->handle_create_request($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
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
        $project_id = $request->get_param('project_id');
        if (!$project_id) {
            $project_id = '';
        }
        $platform_id = $request->get_param('platform_id');
        if (!$platform_id) {
            $platform_id = null;
        }
        $site_url = $request->get_param('site_url');
        if (!$site_url) {
            $site_url = '';
        }

        $document = $content->build_wordpress_document_v2($post->ID, $project_id, $platform_id, $site_url);
        return rest_ensure_response($document);
    }

    /**
     * Update post
     */
    public function update_post($request)
    {
        $post_id = $request->get_param('id');
        $data = $request->get_json_params();
        $data['post_id'] = $post_id;
        $data['project_id'] = $request->get_param('project_id') ?: '';
        $data['platform_id'] = $request->get_param('platform_id') ?: null;
        $data['site_url'] = $request->get_param('site_url') ?: '';

        $updater = Kaigen_Update::get_instance();
        $result = $updater->handle_update_request($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Import a remote image into the WordPress media library.
     */
    public function import_media($request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', __('Invalid JSON payload', 'kaigen-connector'), array('status' => 400));
        }

        $source_url = isset($data['source_url']) ? esc_url_raw($data['source_url']) : '';
        if ($source_url === '' || !wp_http_validate_url($source_url)) {
            return new WP_Error('invalid_source_url', __('source_url must be a valid absolute URL', 'kaigen-connector'), array('status' => 400));
        }

        $filename = isset($data['filename']) ? sanitize_file_name(wp_unslash($data['filename'])) : '';
        $alt = isset($data['alt']) ? sanitize_text_field(wp_unslash($data['alt'])) : '';
        $title = isset($data['title']) ? sanitize_text_field(wp_unslash($data['title'])) : '';

        $result = $this->import_remote_media_asset($source_url, $filename, $alt, $title);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    private function find_existing_imported_media($source_url)
    {
        $existing_attachment_id = attachment_url_to_postid($source_url);
        if ($existing_attachment_id) {
            return intval($existing_attachment_id);
        }

        $existing = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_kaigen_original_source_url',
                    'value' => $source_url,
                ),
            ),
        ));

        if (is_array($existing) && !empty($existing[0])) {
            return intval($existing[0]);
        }

        return 0;
    }

    private function build_media_import_response($attachment_id, $original_source_url)
    {
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (!$attachment_url) {
            return new WP_Error('attachment_url_missing', __('Unable to resolve the imported media URL', 'kaigen-connector'), array('status' => 500));
        }

        return array(
            'attachment_id' => intval($attachment_id),
            'source_url' => esc_url_raw($attachment_url),
            'original_source_url' => esc_url_raw($original_source_url),
        );
    }

    private function import_remote_media_asset($source_url, $filename = '', $alt = '', $title = '')
    {
        $existing_attachment_id = $this->find_existing_imported_media($source_url);
        if ($existing_attachment_id > 0) {
            if ($alt !== '') {
                update_post_meta($existing_attachment_id, '_wp_attachment_image_alt', $alt);
            }
            if ($title !== '') {
                wp_update_post(array(
                    'ID' => $existing_attachment_id,
                    'post_title' => $title,
                ));
            }

            return $this->build_media_import_response($existing_attachment_id, $source_url);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp_file = download_url($source_url, 60);
        if (is_wp_error($tmp_file)) {
            return new WP_Error(
                'media_download_failed',
                sprintf(__('Failed to download remote media: %s', 'kaigen-connector'), $tmp_file->get_error_message()),
                array('status' => 400)
            );
        }

        $derived_filename = $filename;
        if ($derived_filename === '') {
            $path = parse_url($source_url, PHP_URL_PATH);
            if (is_string($path)) {
                $derived_filename = basename($path);
            }
        }
        if ($derived_filename === '') {
            $derived_filename = 'kaigen-media-' . time() . '.jpg';
        }

        $file_array = array(
            'name' => sanitize_file_name($derived_filename),
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, 0, $title !== '' ? $title : null);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return new WP_Error(
                'media_import_failed',
                sprintf(__('Failed to import remote media: %s', 'kaigen-connector'), $attachment_id->get_error_message()),
                array('status' => 500)
            );
        }

        update_post_meta($attachment_id, '_kaigen_original_source_url', esc_url_raw($source_url));

        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }

        if ($title !== '') {
            wp_update_post(array(
                'ID' => intval($attachment_id),
                'post_title' => $title,
            ));
        }

        return $this->build_media_import_response($attachment_id, $source_url);
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
