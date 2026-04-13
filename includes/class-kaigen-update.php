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
     * Handle v2 patch update request from Kaigen
     */
    public function handle_update_request($data) {
        if (!isset($data['post_id'])) {
            return new WP_Error('missing_post_id', __('Missing post id', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($data['schema_version']) || intval($data['schema_version']) !== 2) {
            return new WP_Error('invalid_schema_version', __('schema_version must be 2', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($data['changes']) || !is_array($data['changes'])) {
            return new WP_Error('invalid_changes', __('changes must be an object', 'kaigen-connector'), array('status' => 400));
        }

        $post_id = intval($data['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found', 'kaigen-connector'), array('status' => 404));
        }

        if (!$this->validate_capabilities(get_current_user_id(), $post->post_type)) {
            return new WP_Error('insufficient_permissions', __('Insufficient permissions', 'kaigen-connector'), array('status' => 403));
        }

        $content = Kaigen_Content::get_instance();
        $project_id = isset($data['project_id']) ? strval($data['project_id']) : '';
        $platform_id = isset($data['platform_id']) ? strval($data['platform_id']) : null;
        $site_url = isset($data['site_url']) ? strval($data['site_url']) : '';

        $document = $content->build_wordpress_document_v2($post_id, $project_id, $platform_id, $site_url);
        if (!$document) {
            return new WP_Error('document_build_failed', __('Unable to build current document', 'kaigen-connector'), array('status' => 500));
        }

        $merged_document = $this->apply_document_patch($document, $data['changes']);

        $validation_error = $this->validate_merged_document($merged_document);
        if (is_wp_error($validation_error)) {
            return $validation_error;
        }

        $persist_error = $this->persist_document($post_id, $merged_document);
        if (is_wp_error($persist_error)) {
            return $persist_error;
        }

        $updated = $content->build_wordpress_document_v2($post_id, $project_id, $platform_id, $site_url);
        $this->log_update($post_id, $data['changes']);

        do_action('kaigen_after_update', $post_id, $updated);

        return $updated;
    }

    /**
     * Handle creation request from Kaigen (canonical v2 document)
     */
    public function handle_create_request($data) {
        if (!isset($data['schema_version']) || intval($data['schema_version']) !== 2) {
            return new WP_Error('invalid_schema_version', __('schema_version must be 2', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($data['post']) || !is_array($data['post'])) {
            return new WP_Error('invalid_document', __('post object is required', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($data['post']['post_type']) || !is_string($data['post']['post_type']) || $data['post']['post_type'] === '') {
            $data['post']['post_type'] = 'post';
        }

        if (!isset($data['post']['status']) || !is_string($data['post']['status']) || $data['post']['status'] === '') {
            $data['post']['status'] = 'draft';
        }

        if (!isset($data['post']['id'])) {
            $data['post']['id'] = 0;
        }

        $validation_error = $this->validate_merged_document($data);
        if (is_wp_error($validation_error)) {
            return $validation_error;
        }

        $created_post_id = $this->persist_document(0, $data, true);
        if (is_wp_error($created_post_id)) {
            return $created_post_id;
        }

        $post_id = intval($created_post_id);
        if ($post_id <= 0) {
            return new WP_Error('post_create_failed', __('Failed to create post', 'kaigen-connector'), array('status' => 500));
        }

        $content = Kaigen_Content::get_instance();
        $project_id = isset($data['project_id']) ? strval($data['project_id']) : '';
        $platform_id = isset($data['platform_id']) ? strval($data['platform_id']) : null;
        $site_url = isset($data['site_url']) ? strval($data['site_url']) : '';

        $created = $content->build_wordpress_document_v2($post_id, $project_id, $platform_id, $site_url);
        if (!$created) {
            return new WP_Error('document_build_failed', __('Unable to build created document', 'kaigen-connector'), array('status' => 500));
        }

        $this->log_update($post_id, array('create', 'post', 'seo', 'taxonomies', 'custom_fields', 'media'));

        return $created;
    }

    /**
     * Validate user capabilities
     */
    public function validate_capabilities($user_id, $post_type) {
        // API-key authenticated requests do not map to a WP user session.
        // Permission is already checked by the REST permission callback.
        if (!$user_id) {
            return true;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }

        if (!$user->has_cap('kaigen_edit_posts')) {
            return false;
        }

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj) {
            return false;
        }

        return $user->has_cap($post_type_obj->cap->edit_posts);
    }

    /**
     * Merge a v2 patch into a v2 document.
     */
    private function apply_document_patch($document, $changes) {
        if (isset($changes['post']) && is_array($changes['post'])) {
            foreach ($changes['post'] as $key => $value) {
                if ($value === null) {
                    if ($key === 'title' || $key === 'content') {
                        continue;
                    }
                    unset($document['post'][$key]);
                } else {
                    $document['post'][$key] = $value;
                }
            }
        }

        if (isset($changes['seo']) && is_array($changes['seo'])) {
            foreach ($changes['seo'] as $key => $value) {
                if ($key === 'raw_meta') {
                    if ($value === null) {
                        unset($document['seo']['raw_meta']);
                    } elseif (is_array($value)) {
                        if (!isset($document['seo']['raw_meta']) || !is_array($document['seo']['raw_meta'])) {
                            $document['seo']['raw_meta'] = array();
                        }
                        foreach ($value as $meta_key => $meta_value) {
                            if ($meta_value === null) {
                                unset($document['seo']['raw_meta'][$meta_key]);
                            } else {
                                $document['seo']['raw_meta'][$meta_key] = $meta_value;
                            }
                        }
                    }
                    continue;
                }

                if ($value === null) {
                    unset($document['seo'][$key]);
                } else {
                    $document['seo'][$key] = $value;
                }
            }
        }

        if (isset($changes['taxonomies']) && is_array($changes['taxonomies'])) {
            foreach ($changes['taxonomies'] as $taxonomy => $taxonomy_value) {
                if ($taxonomy_value === null) {
                    unset($document['taxonomies'][$taxonomy]);
                    continue;
                }

                if (is_array($taxonomy_value)) {
                    $document['taxonomies'][$taxonomy] = array();
                    if (isset($taxonomy_value['ids']) && is_array($taxonomy_value['ids'])) {
                        $document['taxonomies'][$taxonomy]['ids'] = $taxonomy_value['ids'];
                    }
                    if (isset($taxonomy_value['names']) && is_array($taxonomy_value['names'])) {
                        $document['taxonomies'][$taxonomy]['names'] = $taxonomy_value['names'];
                    }
                }
            }
        }

        if (isset($changes['custom_fields']) && is_array($changes['custom_fields'])) {
            if (array_key_exists('acf', $changes['custom_fields'])) {
                if ($changes['custom_fields']['acf'] === null) {
                    $document['custom_fields']['acf'] = array();
                } elseif (is_array($changes['custom_fields']['acf'])) {
                    if (!isset($document['custom_fields']['acf']) || !is_array($document['custom_fields']['acf'])) {
                        $document['custom_fields']['acf'] = array();
                    }
                    foreach ($changes['custom_fields']['acf'] as $key => $value) {
                        if ($value === null) {
                            unset($document['custom_fields']['acf'][$key]);
                        } else {
                            $document['custom_fields']['acf'][$key] = $value;
                        }
                    }
                }
            }

            if (array_key_exists('meta', $changes['custom_fields'])) {
                if ($changes['custom_fields']['meta'] === null) {
                    $document['custom_fields']['meta'] = array();
                } elseif (is_array($changes['custom_fields']['meta'])) {
                    if (!isset($document['custom_fields']['meta']) || !is_array($document['custom_fields']['meta'])) {
                        $document['custom_fields']['meta'] = array();
                    }
                    foreach ($changes['custom_fields']['meta'] as $key => $value) {
                        if ($value === null) {
                            unset($document['custom_fields']['meta'][$key]);
                        } else {
                            $document['custom_fields']['meta'][$key] = $value;
                        }
                    }
                }
            }
        }

        if (isset($changes['media']) && is_array($changes['media'])) {
            foreach ($changes['media'] as $key => $value) {
                if ($value === null) {
                    unset($document['media'][$key]);
                } else {
                    $document['media'][$key] = $value;
                }
            }
        }

        if (array_key_exists('extensions', $changes)) {
            if ($changes['extensions'] === null) {
                unset($document['extensions']);
            } elseif (is_array($changes['extensions'])) {
                if (!isset($document['extensions']) || !is_array($document['extensions'])) {
                    $document['extensions'] = array();
                }
                foreach ($changes['extensions'] as $key => $value) {
                    if ($value === null) {
                        unset($document['extensions'][$key]);
                    } else {
                        $document['extensions'][$key] = $value;
                    }
                }
            }
        }

        return $document;
    }

    /**
     * Validate required document constraints after merge.
     */
    private function validate_merged_document($document) {
        if (!isset($document['post']) || !is_array($document['post'])) {
            return new WP_Error('invalid_document', __('post object is required', 'kaigen-connector'), array('status' => 400));
        }

        if (!isset($document['post']['title']) || !is_string($document['post']['title'])) {
            return new WP_Error('invalid_post_title', __('post.title must be a string', 'kaigen-connector'), array('status' => 422));
        }

        if (!isset($document['post']['content']) || !is_string($document['post']['content'])) {
            return new WP_Error('invalid_post_content', __('post.content must be a string', 'kaigen-connector'), array('status' => 422));
        }

        if (isset($document['post']['status'])) {
            $allowed_statuses = array('publish', 'draft', 'pending', 'private');
            if (!in_array($document['post']['status'], $allowed_statuses)) {
                return new WP_Error('invalid_post_status', __('post.status is invalid', 'kaigen-connector'), array('status' => 422));
            }
        }

        return true;
    }

    /**
     * Persist merged v2 document back to WordPress.
     */
    private function persist_document($post_id, $document, $allow_create = false) {
        $post_data = isset($document['post']) && is_array($document['post']) ? $document['post'] : array();
        $post_id = intval($post_id);

        if ($allow_create && $post_id <= 0) {
            $insert_data = array(
                'post_type' => isset($post_data['post_type']) ? sanitize_key($post_data['post_type']) : 'post',
                'post_status' => isset($post_data['status']) ? $post_data['status'] : 'draft',
                'post_title' => isset($post_data['title']) ? sanitize_text_field($post_data['title']) : '',
                // Keep raw block comments (<!-- wp:* -->) to preserve Gutenberg structure.
                'post_content' => isset($post_data['content']) ? strval($post_data['content']) : '',
            );

            if (isset($post_data['excerpt'])) {
                $insert_data['post_excerpt'] = sanitize_textarea_field($post_data['excerpt']);
            }
            if (isset($post_data['slug'])) {
                $insert_data['post_name'] = sanitize_title($post_data['slug']);
            }
            if (isset($post_data['date']) && !empty($post_data['date'])) {
                $insert_data['post_date'] = gmdate('Y-m-d H:i:s', strtotime($post_data['date']));
                $insert_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($post_data['date']));
            }
            if (isset($post_data['author']) && is_array($post_data['author']) && isset($post_data['author']['id'])) {
                $insert_data['post_author'] = intval($post_data['author']['id']);
            }

            $inserted = wp_insert_post($insert_data, true);
            if (is_wp_error($inserted)) {
                error_log('[Kaigen connector] Failed to create post from v2 document: post_id=0, content_length=' . strlen(isset($post_data['content']) ? strval($post_data['content']) : '') . ', error=' . $inserted->get_error_message());
                return $inserted;
            }
            $post_id = intval($inserted);
        } else {
            $update_data = array('ID' => $post_id);

            if (isset($post_data['title'])) {
                $update_data['post_title'] = sanitize_text_field($post_data['title']);
            }
            if (isset($post_data['content'])) {
                // Keep raw block comments (<!-- wp:* -->) to preserve Gutenberg structure.
                $update_data['post_content'] = strval($post_data['content']);
            }
            if (isset($post_data['excerpt'])) {
                $update_data['post_excerpt'] = sanitize_textarea_field($post_data['excerpt']);
            }
            if (isset($post_data['status'])) {
                $update_data['post_status'] = $post_data['status'];
            }
            if (isset($post_data['slug'])) {
                $update_data['post_name'] = sanitize_title($post_data['slug']);
            }
            if (isset($post_data['date']) && !empty($post_data['date'])) {
                $update_data['post_date'] = gmdate('Y-m-d H:i:s', strtotime($post_data['date']));
                $update_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($post_data['date']));
            }
            if (isset($post_data['author']) && is_array($post_data['author']) && isset($post_data['author']['id'])) {
                $update_data['post_author'] = intval($post_data['author']['id']);
            }

            $result = wp_update_post($update_data, true);
            if (is_wp_error($result)) {
                error_log('[Kaigen connector] Failed to update post from v2 document: post_id=' . $post_id . ', content_length=' . strlen(isset($post_data['content']) ? strval($post_data['content']) : '') . ', error=' . $result->get_error_message());
                return $result;
            }
        }

        if (isset($document['custom_fields']) && is_array($document['custom_fields'])) {
            if (isset($document['custom_fields']['meta']) && is_array($document['custom_fields']['meta'])) {
                $this->update_post_meta_values($post_id, $document['custom_fields']['meta']);
            }
            if (isset($document['custom_fields']['acf']) && is_array($document['custom_fields']['acf'])) {
                $this->update_custom_fields($post_id, $document['custom_fields']['acf']);
            }
        }

        if (isset($document['seo']) && is_array($document['seo'])) {
            $this->update_seo_fields_v2($post_id, $document['seo']);
        }

        if (isset($document['extensions']) && is_array($document['extensions'])) {
            $this->update_structured_data_meta($post_id, $document['extensions']);
        }

        if (isset($document['taxonomies']) && is_array($document['taxonomies'])) {
            $this->update_taxonomies($post_id, $document['taxonomies']);
        }

        if (isset($document['media']) && is_array($document['media'])) {
            $this->update_media($post_id, $document['media']);
        }

        return $post_id;
    }

    /**
     * Update regular post meta values.
     */
    private function update_post_meta_values($post_id, $meta_data) {
        foreach ($meta_data as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '') {
                continue;
            }

            if ($value === null) {
                delete_post_meta($post_id, $key);
                continue;
            }

            update_post_meta($post_id, $key, $this->sanitize_meta_value($value));
        }
    }

    /**
     * Update custom fields (ACF).
     */
    private function update_custom_fields($post_id, $fields) {
        foreach ($fields as $field_key => $field_value) {
            if (function_exists('update_field')) {
                update_field($field_key, $field_value, $post_id);
            } else {
                update_post_meta($post_id, $field_key, $this->sanitize_meta_value($field_value));
            }
        }
    }

    /**
     * Update SEO payload.
     */
    private function update_seo_fields_v2($post_id, $seo_data) {
        $seo_plugin = isset($seo_data['plugin']) ? $seo_data['plugin'] : Kaigen_Content::get_instance()->detect_seo_plugin();
        $keys = $this->get_seo_meta_keys($seo_plugin);

        if (array_key_exists('title', $seo_data)) {
            if ($seo_data['title'] === null) {
                delete_post_meta($post_id, $keys['title']);
            } else {
                update_post_meta($post_id, $keys['title'], sanitize_text_field($seo_data['title']));
            }
        }

        if (array_key_exists('description', $seo_data)) {
            if ($seo_data['description'] === null) {
                delete_post_meta($post_id, $keys['description']);
            } else {
                update_post_meta($post_id, $keys['description'], sanitize_textarea_field($seo_data['description']));
            }
        }

        if (array_key_exists('focus_keyword', $seo_data)) {
            if ($seo_data['focus_keyword'] === null) {
                delete_post_meta($post_id, $keys['focus_keyword']);
            } else {
                update_post_meta($post_id, $keys['focus_keyword'], sanitize_text_field($seo_data['focus_keyword']));
            }
        }

        if (isset($seo_data['raw_meta']) && is_array($seo_data['raw_meta'])) {
            foreach ($seo_data['raw_meta'] as $meta_key => $meta_value) {
                $meta_key = sanitize_key($meta_key);
                if ($meta_key === '') {
                    continue;
                }

                if ($meta_value === null) {
                    delete_post_meta($post_id, $meta_key);
                } else {
                    update_post_meta($post_id, $meta_key, $this->sanitize_meta_value($meta_value));
                }
            }
        }
    }

    /**
     * Persist Kaigen-owned structured data separately from post_content.
     */
    private function update_structured_data_meta($post_id, $extensions) {
        if (!isset($extensions['kaigen']) || !is_array($extensions['kaigen'])) {
            return;
        }

        $kaigen = $extensions['kaigen'];

        if (array_key_exists('structured_data_json', $kaigen)) {
            $structured_data_json = $kaigen['structured_data_json'];

            if ($structured_data_json === null || trim(strval($structured_data_json)) === '') {
                delete_post_meta($post_id, Kaigen_Structured_Data::META_JSON);
                delete_post_meta($post_id, Kaigen_Structured_Data::META_ENABLED);
            } else {
                update_post_meta($post_id, Kaigen_Structured_Data::META_JSON, wp_slash(strval($structured_data_json)));

                if (!metadata_exists('post', $post_id, Kaigen_Structured_Data::META_ENABLED)) {
                    update_post_meta($post_id, Kaigen_Structured_Data::META_ENABLED, '1');
                }
            }
        }

        if (array_key_exists('structured_data_enabled', $kaigen)) {
            update_post_meta(
                $post_id,
                Kaigen_Structured_Data::META_ENABLED,
                intval($kaigen['structured_data_enabled']) === 1 ? '1' : '0'
            );
        }
    }

    /**
     * Resolve SEO key mapping by plugin.
     */
    private function get_seo_meta_keys($seo_plugin) {
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
     * Persist taxonomies from canonical payload.
     */
    private function update_taxonomies($post_id, $taxonomies) {
        foreach ($taxonomies as $taxonomy => $value) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            if (!is_array($value)) {
                wp_set_object_terms($post_id, array(), $taxonomy, false);
                continue;
            }

            if (isset($value['ids']) && is_array($value['ids'])) {
                $ids = array_map('intval', $value['ids']);
                wp_set_object_terms($post_id, $ids, $taxonomy, false);
                continue;
            }

            if (isset($value['names']) && is_array($value['names'])) {
                $names = array_map('sanitize_text_field', $value['names']);
                wp_set_object_terms($post_id, $names, $taxonomy, false);
                continue;
            }

            wp_set_object_terms($post_id, array(), $taxonomy, false);
        }
    }

    /**
     * Persist featured media updates.
     */
    private function update_media($post_id, $media) {
        if (array_key_exists('featured_media_id', $media)) {
            if ($media['featured_media_id'] === null) {
                delete_post_thumbnail($post_id);
            } else {
                set_post_thumbnail($post_id, intval($media['featured_media_id']));
            }
            return;
        }

        if (array_key_exists('featured_media_url', $media)) {
            if ($media['featured_media_url'] === null || $media['featured_media_url'] === '') {
                delete_post_thumbnail($post_id);
                return;
            }

            $attachment_id = attachment_url_to_postid(esc_url_raw($media['featured_media_url']));
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
    }

    /**
     * Sanitize arbitrary meta values recursively.
     */
    private function sanitize_meta_value($value) {
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || is_numeric($value)) {
            return $value;
        }
        if (is_string($value)) {
            return sanitize_text_field($value);
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $child) {
                $out[$key] = $this->sanitize_meta_value($child);
            }
            return $out;
        }

        return sanitize_text_field(strval($value));
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
