<?php

/**
 * Content Discovery handler
 * Discovers and manages WordPress content structure
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Content
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
        // Constructor
    }

    /**
     * Get all custom post types
     */
    public function get_custom_post_types()
    {
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();

        $post_types = get_post_types(array(), 'objects');
        $result = array();

        foreach ($post_types as $post_type) {
            // Skip built-in types we don't want
            if (in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block'))) {
                continue;
            }

            $result[] = array(
                'slug' => $post_type->name,
                'label' => $post_type->label,
                'description' => $post_type->description,
                'public' => $post_type->public,
                'hierarchical' => $post_type->hierarchical,
                'supports' => get_all_post_type_supports($post_type->name),
                'taxonomies' => get_object_taxonomies($post_type->name),
                'count' => wp_count_posts($post_type->name)->publish,
                'enabled' => in_array($post_type->name, $enabled_types)
            );
        }

        return apply_filters('kaigen_post_types', $result);
    }

    /**
     * Get custom fields for a specific post type
     */
    public function get_custom_fields($post_type)
    {
        $fields = array();

        // ACF (Advanced Custom Fields)
        if (function_exists('acf_get_field_groups')) {
            $acf_groups = acf_get_field_groups(array('post_type' => $post_type));
            foreach ($acf_groups as $group) {
                $acf_fields = acf_get_fields($group['key']);
                if ($acf_fields) {
                    foreach ($acf_fields as $field) {
                        $fields[] = array(
                            'key' => $field['name'],
                            'label' => $field['label'],
                            'type' => $field['type'],
                            'source' => 'acf',
                            'group' => $group['title']
                        );
                    }
                }
            }
        }

        // Meta Box
        if (function_exists('rwmb_get_registry')) {
            $registry = call_user_func('rwmb_get_registry', 'meta_box');
            if ($registry) {
                $meta_boxes = $registry->get_by(array('object_type' => $post_type));
                foreach ($meta_boxes as $meta_box) {
                    if (isset($meta_box->fields)) {
                        foreach ($meta_box->fields as $field) {
                            $fields[] = array(
                                'key' => $field['id'],
                                'label' => isset($field['name']) ? $field['name'] : $field['id'],
                                'type' => $field['type'],
                                'source' => 'metabox',
                                'group' => $meta_box->title
                            );
                        }
                    }
                }
            }
        }

        // Pods
        if (function_exists('pods')) {
            $pod = pods($post_type);
            if ($pod) {
                $pod_fields = $pod->fields();
                foreach ($pod_fields as $field_name => $field_data) {
                    $fields[] = array(
                        'key' => $field_name,
                        'label' => isset($field_data['label']) ? $field_data['label'] : $field_name,
                        'type' => isset($field_data['type']) ? $field_data['type'] : 'text',
                        'source' => 'pods',
                        'group' => 'Pods'
                    );
                }
            }
        }

        // CMB2
        if (function_exists('cmb2_get_metaboxes')) {
            $cmb2_boxes = cmb2_get_metaboxes();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                if (isset($cmb->meta_box['object_types']) && in_array($post_type, $cmb->meta_box['object_types'])) {
                    foreach ($cmb->prop('fields') as $field) {
                        $fields[] = array(
                            'key' => $field['id'],
                            'label' => isset($field['name']) ? $field['name'] : $field['id'],
                            'type' => $field['type'],
                            'source' => 'cmb2',
                            'group' => $cmb->prop('title')
                        );
                    }
                }
            }
        }

        return apply_filters('kaigen_custom_fields', $fields, $post_type);
    }

    /**
     * Get all custom fields for all enabled post types
     */
    public function get_all_custom_fields()
    {
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        $all_fields = array();
        foreach ($enabled_types as $post_type) {
            $all_fields[$post_type] = $this->get_custom_fields($post_type);
        }

        return $all_fields;
    }

    /**
     * Detect editor type
     */
    public function get_editor_type($post_type = 'post')
    {
        // Check if Gutenberg is disabled
        if (function_exists('classic_editor_replace')) {
            return 'classic';
        }

        // Check if post type supports block editor
        if (function_exists('use_block_editor_for_post_type')) {
            if (use_block_editor_for_post_type($post_type)) {
                return 'gutenberg';
            }
        }

        // Check for custom editors
        if (class_exists('Elementor\Plugin')) {
            return 'elementor';
        }

        if (defined('FL_BUILDER_VERSION')) {
            return 'beaver_builder';
        }

        if (function_exists('et_divi_fonts_url')) {
            return 'divi';
        }

        // Default to Gutenberg for WP 5.0+
        global $wp_version;
        if (version_compare($wp_version, '5.0', '>=')) {
            return 'gutenberg';
        }

        return 'classic';
    }

    /**
     * Detect SEO plugin
     */
    public function detect_seo_plugin()
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }

        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }

        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }

        if (class_exists('AIOSEO\Plugin\AIOSEO')) {
            return 'aioseo';
        }

        return 'none';
    }

    /**
     * Get existing posts with excerpts
     */
    public function get_existing_posts($post_type, $args = array())
    {
        $defaults = array(
            'post_type' => $post_type,
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'modified',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);
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
                    'excerpt' => $this->get_smart_excerpt($post_id),
                    'url' => get_permalink(),
                    'postType' => get_post_type(),
                    'status' => get_post_status(),
                    'author' => get_the_author(),
                    'publishedDate' => get_the_date('c'),
                    'modifiedDate' => get_the_modified_date('c'),
                    'categories' => $this->get_post_categories($post_id),
                    'tags' => $this->get_post_tags($post_id),
                    'customFields' => $this->get_post_custom_fields($post_id)
                );
            }
            wp_reset_postdata();
        }

        return $posts;
    }

    /**
     * Get smart excerpt (use excerpt if available, otherwise truncate content)
     * Public wrapper for use in other classes
     */
    public function get_smart_excerpt_public($post_id)
    {
        return $this->get_smart_excerpt($post_id);
    }

    /**
     * Get smart excerpt (use excerpt if available, otherwise truncate content)
     */
    private function get_smart_excerpt($post_id)
    {
        $excerpt = get_the_excerpt($post_id);

        if (empty($excerpt)) {
            $content = get_post_field('post_content', $post_id);
            $content = wp_strip_all_tags($content);
            $excerpt = wp_trim_words($content, 55, '...');
        }

        return $excerpt;
    }

    /**
     * Get post categories
     */
    private function get_post_categories($post_id)
    {
        $categories = get_the_category($post_id);
        return array_map(function ($cat) {
            return $cat->name;
        }, $categories);
    }

    /**
     * Get post tags
     */
    private function get_post_tags($post_id)
    {
        $tags = get_the_tags($post_id);
        if (!$tags) {
            return array();
        }
        return array_map(function ($tag) {
            return $tag->name;
        }, $tags);
    }

    /**
     * Get post custom fields
     */
    private function get_post_custom_fields($post_id)
    {
        $fields = array();

        // ACF fields
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id);
            if ($acf_fields) {
                $fields['acf'] = $acf_fields;
            }
        }

        // Regular post meta (filtered)
        $meta = get_post_meta($post_id);
        $filtered_meta = array();
        foreach ($meta as $key => $value) {
            // Skip internal WordPress meta
            if (substr($key, 0, 1) === '_') {
                continue;
            }
            $filtered_meta[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
        }

        if (!empty($filtered_meta)) {
            $fields['meta'] = $filtered_meta;
        }

        return $fields;
    }

    /**
     * Build canonical WordPress document payload (schema_version = 2)
     */
    public function build_wordpress_document_v2($post_id, $project_id = '', $platform_id = null, $site_url = '')
    {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        $custom_fields = $this->get_post_custom_fields($post_id);
        $seo = $this->get_post_seo_data($post_id);
        $taxonomies = $this->get_post_taxonomies_v2($post_id, $post->post_type);
        $featured_media_id = get_post_thumbnail_id($post_id);

        return array(
            'schema_version' => 2,
            'post' => array(
                'id' => intval($post->ID),
                'project_id' => strval($project_id),
                'platform_id' => $platform_id ? strval($platform_id) : null,
                'site_url' => !empty($site_url) ? esc_url_raw($site_url) : home_url(),
                'post_type' => strval($post->post_type),
                'status' => strval($post->post_status),
                'title' => strval($post->post_title),
                'content' => strval($post->post_content),
                'excerpt' => strval($post->post_excerpt),
                'slug' => strval($post->post_name),
                'date' => get_post_time('c', true, $post),
                'url' => get_permalink($post->ID),
                'author' => array(
                    'id' => intval($post->post_author),
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ),
            ),
            'seo' => $seo,
            'taxonomies' => $taxonomies,
            'custom_fields' => array(
                'acf' => isset($custom_fields['acf']) && is_array($custom_fields['acf']) ? $custom_fields['acf'] : array(),
                'meta' => isset($custom_fields['meta']) && is_array($custom_fields['meta']) ? $custom_fields['meta'] : array(),
            ),
            'media' => array(
                'featured_media_id' => $featured_media_id ? intval($featured_media_id) : null,
                'featured_media_url' => $featured_media_id ? wp_get_attachment_url($featured_media_id) : null,
            ),
            'extensions' => array(
                'editor_type' => $this->get_editor_type($post->post_type),
            ),
        );
    }

    /**
     * Build taxonomy payload for canonical document.
     */
    private function get_post_taxonomies_v2($post_id, $post_type)
    {
        $taxonomies = array();
        $taxonomy_objects = get_object_taxonomies($post_type, 'objects');

        if (!is_array($taxonomy_objects)) {
            return $taxonomies;
        }

        foreach ($taxonomy_objects as $taxonomy) {
            if (!isset($taxonomy->name)) {
                continue;
            }
            $terms = wp_get_object_terms($post_id, $taxonomy->name);
            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }

            $ids = array();
            $names = array();
            foreach ($terms as $term) {
                if (!isset($term->term_id) || !isset($term->name)) {
                    continue;
                }
                $ids[] = intval($term->term_id);
                $names[] = strval($term->name);
            }

            $taxonomies[$taxonomy->name] = array(
                'ids' => $ids,
                'names' => $names,
            );
        }

        return $taxonomies;
    }

    /**
     * Read normalized SEO payload from post meta.
     */
    private function get_post_seo_data($post_id)
    {
        $seo_plugin = $this->detect_seo_plugin();
        $seo_keys = $this->get_seo_meta_keys($seo_plugin);

        $title = get_post_meta($post_id, $seo_keys['title'], true);
        $description = get_post_meta($post_id, $seo_keys['description'], true);
        $focus_keyword = get_post_meta($post_id, $seo_keys['focus_keyword'], true);

        $raw_meta = array();
        $all_meta = get_post_meta($post_id);
        if (is_array($all_meta)) {
            foreach ($all_meta as $key => $value) {
                $is_seo_key = strpos($key, '_yoast_wpseo_') === 0
                    || strpos($key, 'rank_math_') === 0
                    || strpos($key, '_seopress_') === 0;
                if (!$is_seo_key) {
                    continue;
                }

                if (is_array($value) && count($value) === 1) {
                    $raw_meta[$key] = $value[0];
                } else {
                    $raw_meta[$key] = $value;
                }
            }
        }

        return array(
            'plugin' => strval($seo_plugin),
            'title' => $title !== '' ? strval($title) : null,
            'description' => $description !== '' ? strval($description) : null,
            'focus_keyword' => $focus_keyword !== '' ? strval($focus_keyword) : null,
            'raw_meta' => $raw_meta,
        );
    }

    /**
     * Resolve SEO meta keys by plugin.
     */
    private function get_seo_meta_keys($seo_plugin)
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
     * Get internal linking candidates
     */
    public function get_internal_links_candidates($post_type = null, $limit = 100)
    {
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        $args = array(
            'post_type' => $post_type ? $post_type : $enabled_types,
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $candidates = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $candidates[] = array(
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'url' => get_permalink(),
                    'excerpt' => $this->get_smart_excerpt(get_the_ID()),
                    'postType' => get_post_type()
                );
            }
            wp_reset_postdata();
        }

        return $candidates;
    }
}
