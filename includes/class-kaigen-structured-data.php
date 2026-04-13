<?php
/**
 * Structured data injector for Kaigen-generated JSON-LD.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Structured_Data {

    public const META_JSON = '_kaigen_structured_data_json';
    public const META_ENABLED = '_kaigen_structured_data_enabled';
    public const DEFAULT_MAX_BYTES = 50000;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_head', array($this, 'inject_structured_data'), 20);
    }

    public function inject_structured_data() {
        if (!is_singular()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id || !$this->is_enabled_for_post($post_id)) {
            return;
        }

        $raw_json = get_post_meta($post_id, self::META_JSON, true);
        $max_bytes = $this->get_max_bytes($post_id);
        $items = $this->normalize_items($raw_json, $max_bytes);
        $items = apply_filters('kaigen_structured_data_items', $items, $post_id);

        if (!is_array($items)) {
            return;
        }

        $items = $this->filter_valid_items($items);
        if (empty($items)) {
            return;
        }

        $permalink = get_permalink($post_id);
        foreach (array_values($items) as $index => $item) {
            if (!isset($item['@id']) && is_string($permalink) && $permalink !== '') {
                $item['@id'] = rtrim($permalink, '#') . '#kaigen-schema-' . ($index + 1);
            }

            $json = wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$json) {
                continue;
            }

            echo "\n<script type=\"application/ld+json\" class=\"kaigen-structured-data\">\n";
            echo $json;
            echo "\n</script>\n";
        }
    }

    private function is_enabled_for_post($post_id) {
        $settings = get_option('kaigen_settings', array());
        $global_enabled = !isset($settings['structured_data_injection_enabled'])
            || intval($settings['structured_data_injection_enabled']) === 1;

        $enabled = $global_enabled;
        if ($enabled) {
            $post_enabled = get_post_meta($post_id, self::META_ENABLED, true);
            if ($post_enabled !== '') {
                $enabled = intval($post_enabled) === 1;
            }
        }

        return (bool) apply_filters('kaigen_structured_data_enabled', $enabled, $post_id);
    }

    private function get_max_bytes($post_id) {
        $max_bytes = apply_filters('kaigen_structured_data_max_bytes', self::DEFAULT_MAX_BYTES, $post_id);
        $max_bytes = intval($max_bytes);
        return $max_bytes > 0 ? $max_bytes : self::DEFAULT_MAX_BYTES;
    }

    private function normalize_items($raw_json, $max_bytes) {
        if (!is_string($raw_json) || trim($raw_json) === '') {
            return array();
        }

        if (strlen($raw_json) > $max_bytes) {
            return array();
        }

        $decoded = json_decode($raw_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array();
        }

        if ($this->is_jsonld_item($decoded)) {
            return array($decoded);
        }

        if (!$this->is_list_array($decoded)) {
            return array();
        }

        return $decoded;
    }

    private function filter_valid_items($items) {
        $valid = array();
        foreach ($items as $item) {
            if ($this->is_jsonld_item($item)) {
                $valid[] = $item;
            }
        }
        return $valid;
    }

    private function is_jsonld_item($item) {
        return is_array($item)
            && !empty($item)
            && isset($item['@context'])
            && isset($item['@type'])
            && (is_string($item['@context']) || is_array($item['@context']))
            && (is_string($item['@type']) || is_array($item['@type']));
    }

    private function is_list_array($value) {
        if (!is_array($value)) {
            return false;
        }

        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
