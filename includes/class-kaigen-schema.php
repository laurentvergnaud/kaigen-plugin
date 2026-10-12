<?php
/**
 * Schema templates (ADR 0064 point 4, plugin 1.4.0). A template is a schema type from a closed list
 * and, for each of its properties, the WordPress data that carries it; it applies to a post type, a
 * post type and a term, or to the posts that name it in `_kaigen_schema_template`. The node is built
 * from the post's data on every page view, so it stays right when the post changes, and is left out
 * when a required property is missing. It joins the SEO plugin's graph (Yoast, Rank Math) beside the
 * page's node, never a second node of a family the graph already has; without one, the plugin prints
 * its own graph. The automations' JSON-LD of a post joins the same graph under the same rule.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Schema {

    public const OPTION_TEMPLATES = 'kaigen_schema_templates';
    public const META_TEMPLATE = '_kaigen_schema_template';
    private const MAX_TEMPLATES = 50;
    private const MAX_RESOLVE = 200;

    // The types a template may produce, their properties and the ones without which nothing is printed.
    private const TYPES = array(
        'Article' => array('family' => 'article', 'required' => array('headline', 'datePublished')),
        'BlogPosting' => array('family' => 'article', 'required' => array('headline', 'datePublished')),
        'NewsArticle' => array('family' => 'article', 'required' => array('headline', 'datePublished')),
        'Service' => array('family' => 'service', 'required' => array('name', 'provider.name')),
        'FAQPage' => array('family' => 'faq', 'required' => array('question', 'answer')),
        'Event' => array('family' => 'event', 'required' => array('name', 'startDate', 'location.name')),
    );
    private const PROPERTIES = array(
        'article' => array('headline', 'description', 'datePublished', 'dateModified', 'author.name', 'image', 'publisher.name', 'publisher.logo', 'articleSection'),
        'service' => array('name', 'description', 'serviceType', 'url', 'image', 'provider.name', 'provider.url', 'provider.logo', 'provider.telephone', 'areaServed', 'offers.price', 'offers.priceCurrency'),
        'faq' => array('question', 'answer'),
        'event' => array('name', 'description', 'startDate', 'endDate', 'image', 'url', 'location.name', 'location.address', 'eventAttendanceMode', 'eventStatus', 'organizer.name', 'organizer.url', 'offers.price', 'offers.priceCurrency', 'offers.url'),
    );
    // The schema.org types each family stands for: a graph that has one already gets no second node.
    private const FAMILY_TYPES = array(
        'article' => array('Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'SatiricalArticle', 'AdvertiserContentArticle', 'SocialMediaPosting'),
        'service' => array('Service'),
        'faq' => array('FAQPage', 'Question'),
        'event' => array('Event'),
    );
    // The SEO plugins' settings Tamiro may read and write, per post type (keys verified 2026-10-07).
    private const YOAST_ARTICLE_TYPES = array('Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report', 'None');
    private const YOAST_PAGE_TYPES = array('WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage', 'ContactPage', 'MedicalWebPage', 'CollectionPage', 'CheckoutPage', 'RealEstateListing', 'SearchResultsPage');
    private const RANKMATH_RICH_SNIPPETS = array('off', 'article', 'service', 'event');
    private const RANKMATH_ARTICLE_TYPES = array('Article', 'BlogPosting', 'NewsArticle');

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('wpseo_schema_graph', array($this, 'graft_yoast'), 20, 2);
        add_filter('rank_math/json_ld', array($this, 'graft_rankmath'), 99, 2);
        add_action('wp_head', array($this, 'print_own_graph'), 30);
    }

    // ---- Rendering ----------------------------------------------------------------------------

    private function seo_plugin() {
        return Kaigen_Content::get_instance()->detect_seo_plugin();
    }

    public function graft_yoast($graph, $context = null) {
        if (!is_array($graph) || !is_singular()) {
            return $graph;
        }
        $page_id = is_object($context) && isset($context->main_schema_id) ? strval($context->main_schema_id) : null;
        $organization_id = is_object($context) && isset($context->site_url) ? trailingslashit(strval($context->site_url)) . '#organization' : null;
        return array_merge($graph, $this->nodes_for_graph(get_queried_object_id(), $graph, $page_id, $organization_id));
    }

    public function graft_rankmath($data, $jsonld = null) {
        if (!is_array($data) || !is_singular()) {
            return $data;
        }
        $page_id = isset($data['WebPage']['@id']) ? strval($data['WebPage']['@id']) : null;
        $organization_id = isset($data['publisher']['@id']) ? strval($data['publisher']['@id']) : null;
        foreach ($this->nodes_for_graph(get_queried_object_id(), array_values($data), $page_id, $organization_id) as $index => $node) {
            $data['kaigen_' . $index] = $node;
        }
        return $data;
    }

    // Without Yoast or Rank Math, the plugin prints its own graph.
    public function print_own_graph() {
        if (!is_singular() || in_array($this->seo_plugin(), array('yoast', 'rankmath'), true)) {
            return;
        }
        // The automations' JSON-LD is printed by Kaigen_Structured_Data: its families count as present.
        $automation = Kaigen_Structured_Data::get_instance()->items_for_post(get_queried_object_id());
        $nodes = $this->nodes_for_graph(get_queried_object_id(), $automation, null, null, false);
        if (empty($nodes)) {
            return;
        }
        $json = wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $nodes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($json) {
            echo "\n<script type=\"application/ld+json\" class=\"kaigen-schema\">\n" . $json . "\n</script>\n";
        }
    }

    /**
     * The nodes Tamiro adds to a page's graph: the template's, then the automations' JSON-LD, each
     * left out when the graph already has a node of its family.
     */
    private function nodes_for_graph($post_id, $graph, $page_id, $organization_id, $with_automation = true) {
        $post = get_post($post_id);
        if (!$post) {
            return array();
        }
        $present = $this->families_in($graph);
        $nodes = array();
        $template = $this->template_for_post($post);
        if ($template) {
            $family = self::TYPES[$template['type']]['family'];
            if (!in_array($family, $present, true)) {
                $node = $this->build_node($post, $template, $page_id, $organization_id);
                if (is_array($node) && empty($node['_missing'])) {
                    $nodes[] = $node;
                    $present[] = $family;
                }
            }
        }
        if ($with_automation) {
            foreach (Kaigen_Structured_Data::get_instance()->items_for_post($post_id) as $index => $item) {
                $families = $this->families_in(array($item));
                if (!empty(array_intersect($families, $present))) {
                    continue;
                }
                unset($item['@context']);
                if (!isset($item['@id'])) {
                    $item['@id'] = rtrim(get_permalink($post_id), '#') . '#kaigen-schema-' . ($index + 1);
                }
                $nodes[] = $item;
                $present = array_merge($present, $families);
            }
        }
        return $nodes;
    }

    private function families_in($graph) {
        $families = array();
        foreach ($graph as $node) {
            if (!is_array($node) || !isset($node['@type'])) {
                continue;
            }
            $types = is_array($node['@type']) ? $node['@type'] : array($node['@type']);
            foreach (self::FAMILY_TYPES as $family => $family_types) {
                if (!empty(array_intersect($types, $family_types))) {
                    $families[] = $family;
                }
            }
        }
        return array_values(array_unique($families));
    }

    /**
     * The template of a post: the one it names, else the most precise rule that matches it (a post
     * type and a term before a post type alone).
     */
    public function template_for_post($post) {
        $templates = $this->templates();
        $named = get_post_meta($post->ID, self::META_TEMPLATE, true);
        if (is_string($named) && $named !== '') {
            foreach ($templates as $template) {
                if ($template['id'] === $named && !empty($template['enabled'])) {
                    return $template;
                }
            }
            return null;
        }
        $best = null;
        $best_score = 0;
        foreach ($templates as $template) {
            if (empty($template['enabled'])) {
                continue;
            }
            foreach ($template['rules'] as $rule) {
                if ($rule['post_type'] !== $post->post_type) {
                    continue;
                }
                $score = 1;
                if (!empty($rule['taxonomy']) && !empty($rule['term'])) {
                    if (!has_term($rule['term'], $rule['taxonomy'], $post)) {
                        continue;
                    }
                    $score = 2;
                }
                if ($score > $best_score) {
                    $best = $template;
                    $best_score = $score;
                }
            }
        }
        return $best;
    }

    /**
     * The node of a post from its template; `_missing` lists the required properties it lacks.
     */
    public function build_node($post, $template, $page_id = null, $organization_id = null) {
        $type = $template['type'];
        $family = self::TYPES[$type]['family'];
        $values = array();
        foreach ($template['properties'] as $property => $source) {
            $value = $this->read_source($post, $source);
            if ($value !== null && $value !== '') {
                $values[$property] = $value;
            }
        }
        $missing = array();
        foreach (self::TYPES[$type]['required'] as $required) {
            if (!isset($values[$required])) {
                $missing[] = $required;
            }
        }
        $permalink = get_permalink($post);
        $node = array('@type' => $type, '@id' => rtrim($permalink, '#') . '#kaigen-' . strtolower($type));
        if ($family === 'faq') {
            $node['mainEntity'] = array(array(
                '@type' => 'Question',
                'name' => isset($values['question']) ? $values['question'] : null,
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => isset($values['answer']) ? $values['answer'] : null),
            ));
        } else {
            foreach ($values as $property => $value) {
                $this->set_path($node, $property, $value);
            }
            $this->type_nested($node);
        }
        if ($page_id) {
            $node[$family === 'article' ? 'mainEntityOfPage' : 'isPartOf'] = array('@id' => $page_id);
        } else {
            $node['url'] = isset($node['url']) ? $node['url'] : $permalink;
        }
        if ($family === 'article' && !isset($node['publisher']) && $organization_id) {
            $node['publisher'] = array('@id' => $organization_id);
        }
        if (!empty($missing)) {
            $node['_missing'] = $missing;
        }
        return $node;
    }

    // The nested objects get their schema.org type.
    private function type_nested(&$node) {
        $types = array('author' => 'Person', 'publisher' => 'Organization', 'provider' => 'Organization', 'organizer' => 'Organization', 'location' => 'Place', 'offers' => 'Offer');
        foreach ($types as $key => $nested_type) {
            if (isset($node[$key]) && is_array($node[$key]) && !isset($node[$key]['@type'])) {
                $node[$key] = array_merge(array('@type' => $nested_type), $node[$key]);
            }
        }
        if (isset($node['publisher']['logo']) && is_string($node['publisher']['logo'])) {
            $node['publisher']['logo'] = array('@type' => 'ImageObject', 'url' => $node['publisher']['logo']);
        }
    }

    private function set_path(&$node, $path, $value) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            $node[$parts[0]] = $value;
            return;
        }
        if (!isset($node[$parts[0]]) || !is_array($node[$parts[0]])) {
            $node[$parts[0]] = array();
        }
        $node[$parts[0]][$parts[1]] = $value;
    }

    /**
     * A source of the closed grammar: post:<field>, meta:<key>, acf:<field>, term:<taxonomy>,
     * const:<value>. A protected meta is never read.
     */
    private function read_source($post, $source) {
        if (!is_string($source) || strpos($source, ':') === false) {
            return null;
        }
        list($kind, $key) = explode(':', $source, 2);
        switch ($kind) {
            case 'post':
                return $this->post_field($post, $key);
            case 'meta':
                if (is_protected_meta($key, 'post')) {
                    return null;
                }
                $value = get_post_meta($post->ID, $key, true);
                return is_scalar($value) ? strval($value) : null;
            case 'acf':
                if (!function_exists('get_field')) {
                    return null;
                }
                $value = get_field($key, $post->ID);
                if (is_array($value) && isset($value['url'])) {
                    return strval($value['url']);
                }
                if (is_array($value) && isset($value['address'])) {
                    return strval($value['address']);
                }
                return is_scalar($value) ? strval($value) : null;
            case 'term':
                $terms = get_the_terms($post, $key);
                return is_array($terms) && !empty($terms) ? $terms[0]->name : null;
            case 'const':
                return $key;
        }
        return null;
    }

    private function post_field($post, $field) {
        switch ($field) {
            case 'title':
                return wp_strip_all_tags(get_the_title($post));
            case 'excerpt':
                $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 40, '…');
                return trim(wp_strip_all_tags($excerpt));
            case 'content_text':
                return trim(wp_strip_all_tags(strip_shortcodes(apply_filters('the_content', $post->post_content))));
            case 'date':
                return get_post_time('c', true, $post);
            case 'modified':
                return get_post_modified_time('c', true, $post);
            case 'url':
                return get_permalink($post);
            case 'author_name':
                return get_the_author_meta('display_name', $post->post_author);
            case 'image':
                $url = get_the_post_thumbnail_url($post, 'full');
                return $url ? $url : null;
        }
        return null;
    }

    // ---- Templates ---------------------------------------------------------------------------

    public function templates() {
        $templates = get_option(self::OPTION_TEMPLATES, array());
        return is_array($templates) ? $templates : array();
    }

    /**
     * A template as Tamiro sends it, checked against the closed lists, or a WP_Error naming the fault.
     */
    public function sanitize_template($template) {
        if (!is_array($template)) {
            return new WP_Error('invalid_template', __('A template must be an object', 'kaigen-connector'), array('status' => 400));
        }
        $id = isset($template['id']) ? sanitize_key($template['id']) : '';
        $type = isset($template['type']) ? strval($template['type']) : '';
        if ($id === '' || !isset(self::TYPES[$type])) {
            return new WP_Error('invalid_template', __('A template needs an id and a known type', 'kaigen-connector'), array('status' => 400));
        }
        $allowed = self::PROPERTIES[self::TYPES[$type]['family']];
        $properties = array();
        foreach ((isset($template['properties']) && is_array($template['properties']) ? $template['properties'] : array()) as $property => $source) {
            if (!in_array($property, $allowed, true)) {
                return new WP_Error('invalid_property', sprintf(__('%1$s is not a property of %2$s', 'kaigen-connector'), $property, $type), array('status' => 422));
            }
            if (!is_string($source) || !preg_match('/^(post:(title|excerpt|content_text|date|modified|url|author_name|image)|meta:[A-Za-z0-9_\-]+|acf:[A-Za-z0-9_\-]+|term:[a-z0-9_\-]+|const:.{1,500})$/su', $source)) {
                return new WP_Error('invalid_source', sprintf(__('Unknown source for %s', 'kaigen-connector'), $property), array('status' => 422));
            }
            if (strpos($source, 'meta:') === 0 && is_protected_meta(substr($source, 5), 'post')) {
                return new WP_Error('protected_meta_key', sprintf(__('Protected meta key: %s', 'kaigen-connector'), substr($source, 5)), array('status' => 422));
            }
            $properties[$property] = strpos($source, 'const:') === 0 ? 'const:' . sanitize_text_field(substr($source, 6)) : $source;
        }
        $rules = array();
        foreach ((isset($template['rules']) && is_array($template['rules']) ? $template['rules'] : array()) as $rule) {
            $post_type = isset($rule['post_type']) ? sanitize_key($rule['post_type']) : '';
            if (!post_type_exists($post_type)) {
                return new WP_Error('invalid_post_type', sprintf(__('Unknown post type: %s', 'kaigen-connector'), $post_type), array('status' => 422));
            }
            $taxonomy = isset($rule['taxonomy']) ? sanitize_key($rule['taxonomy']) : '';
            $term = isset($rule['term']) ? sanitize_title($rule['term']) : '';
            if ($taxonomy !== '' && !is_object_in_taxonomy($post_type, $taxonomy)) {
                return new WP_Error('invalid_taxonomy', sprintf(__('Taxonomy %1$s is not attached to %2$s', 'kaigen-connector'), $taxonomy, $post_type), array('status' => 422));
            }
            $rules[] = array('post_type' => $post_type, 'taxonomy' => $taxonomy, 'term' => $term);
        }
        return array('id' => $id, 'type' => $type, 'properties' => $properties, 'rules' => $rules, 'enabled' => !isset($template['enabled']) || (bool) $template['enabled']);
    }

    /**
     * Upserts the templates sent, deletes the ids named; returns the list before and after.
     */
    public function save_templates($templates, $delete_ids) {
        $current = $this->templates();
        $before = $current;
        $by_id = array();
        foreach ($current as $template) {
            $by_id[$template['id']] = $template;
        }
        foreach ($templates as $template) {
            $clean = $this->sanitize_template($template);
            if (is_wp_error($clean)) {
                return $clean;
            }
            $by_id[$clean['id']] = $clean;
        }
        foreach ($delete_ids as $id) {
            unset($by_id[sanitize_key($id)]);
        }
        if (count($by_id) > self::MAX_TEMPLATES) {
            return new WP_Error('too_many_templates', __('Too many templates', 'kaigen-connector'), array('status' => 422));
        }
        update_option(self::OPTION_TEMPLATES, array_values($by_id), false);
        return array('before' => $before, 'after' => array_values($by_id));
    }

    /**
     * Names a template on posts, or clears it (null): the posts of the enabled types only.
     */
    public function assign_template($template_id, $post_ids) {
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');
        if ($template_id !== null) {
            $known = wp_list_pluck($this->templates(), 'id');
            if (!in_array($template_id, $known, true)) {
                return new WP_Error('unknown_template', __('Unknown template', 'kaigen-connector'), array('status' => 422));
            }
        }
        $done = array();
        foreach (array_slice(array_map('intval', $post_ids), 0, self::MAX_RESOLVE) as $post_id) {
            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, $enabled_types, true)) {
                return new WP_Error('post_type_disabled', sprintf(__('Post %d is not of an enabled type', 'kaigen-connector'), $post_id), array('status' => 403));
            }
            $done[] = array('id' => $post_id, 'previous' => get_post_meta($post_id, self::META_TEMPLATE, true));
        }
        foreach ($done as $entry) {
            if ($template_id === null) {
                delete_post_meta($entry['id'], self::META_TEMPLATE);
            } else {
                update_post_meta($entry['id'], self::META_TEMPLATE, $template_id);
            }
        }
        return $done;
    }

    // ---- SEO plugin settings -------------------------------------------------------------------

    /**
     * The schema settings of the SEO plugin for each public post type.
     */
    public function seo_settings() {
        $plugin = $this->seo_plugin();
        $settings = array('plugin' => $plugin, 'postTypes' => array());
        $yoast = get_option('wpseo_titles', array());
        $rankmath = get_option('rank_math_titles', array());
        foreach (get_post_types(array('public' => true), 'names') as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }
            $entry = array();
            if ($plugin === 'yoast') {
                $entry['articleType'] = isset($yoast['schema-article-type-' . $post_type]) ? $yoast['schema-article-type-' . $post_type] : null;
                $entry['pageType'] = isset($yoast['schema-page-type-' . $post_type]) ? $yoast['schema-page-type-' . $post_type] : null;
            } elseif ($plugin === 'rankmath') {
                $entry['richSnippet'] = isset($rankmath['pt_' . $post_type . '_default_rich_snippet']) ? $rankmath['pt_' . $post_type . '_default_rich_snippet'] : null;
                $entry['articleType'] = isset($rankmath['pt_' . $post_type . '_default_article_type']) ? $rankmath['pt_' . $post_type . '_default_article_type'] : null;
            }
            $settings['postTypes'][$post_type] = $entry;
        }
        return $settings;
    }

    /**
     * Sets the schema type of a post type in the SEO plugin, from closed lists; returns the values
     * before and after.
     */
    public function set_seo_setting($post_type, $values) {
        $plugin = $this->seo_plugin();
        $post_type = sanitize_key($post_type);
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', sprintf(__('Unknown post type: %s', 'kaigen-connector'), $post_type), array('status' => 422));
        }
        if ($plugin === 'yoast') {
            $option = 'wpseo_titles';
            $map = array('articleType' => array('schema-article-type-' . $post_type, self::YOAST_ARTICLE_TYPES), 'pageType' => array('schema-page-type-' . $post_type, self::YOAST_PAGE_TYPES));
        } elseif ($plugin === 'rankmath') {
            $option = 'rank_math_titles';
            $map = array('richSnippet' => array('pt_' . $post_type . '_default_rich_snippet', self::RANKMATH_RICH_SNIPPETS), 'articleType' => array('pt_' . $post_type . '_default_article_type', self::RANKMATH_ARTICLE_TYPES));
        } else {
            return new WP_Error('unsupported_seo_plugin', __('Schema settings are written for Yoast and Rank Math only', 'kaigen-connector'), array('status' => 422));
        }
        $stored = get_option($option, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $changes = array();
        foreach ($values as $name => $value) {
            if (!isset($map[$name]) || !in_array($value, $map[$name][1], true)) {
                return new WP_Error('invalid_setting', sprintf(__('%s: not a value Tamiro may set', 'kaigen-connector'), $name), array('status' => 422));
            }
            $changes[$map[$name][0]] = array('before' => isset($stored[$map[$name][0]]) ? $stored[$map[$name][0]] : null, 'after' => $value);
            $stored[$map[$name][0]] = $value;
        }
        update_option($option, $stored);
        return array('plugin' => $plugin, 'changes' => $changes);
    }

    // ---- Reads for Tamiro -----------------------------------------------------------------------

    /**
     * The posts behind addresses: their type and their terms, for the bridge between the crawl's
     * page types and the site's post types.
     */
    public function resolve_urls($urls) {
        $resolved = array();
        foreach (array_slice($urls, 0, self::MAX_RESOLVE) as $url) {
            $url = esc_url_raw(strval($url));
            $post_id = $url !== '' ? url_to_postid($url) : 0;
            $post = $post_id ? get_post($post_id) : null;
            if (!$post || $post->post_status !== 'publish') {
                $resolved[] = array('url' => $url, 'id' => null);
                continue;
            }
            $terms = array();
            foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
                $names = wp_get_post_terms($post->ID, $taxonomy, array('fields' => 'slugs'));
                if (is_array($names) && !empty($names)) {
                    $terms[$taxonomy] = $names;
                }
            }
            $resolved[] = array('url' => $url, 'id' => $post->ID, 'postType' => $post->post_type, 'terms' => $terms, 'template' => get_post_meta($post->ID, self::META_TEMPLATE, true) ?: null);
        }
        return $resolved;
    }

    /**
     * A few posts of a scope with their fields, their custom fields and their terms: what Tamiro's
     * model reads to propose a template.
     */
    public function samples($post_type, $taxonomy, $term, $limit) {
        $post_type = sanitize_key($post_type);
        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');
        if (!post_type_exists($post_type) || !in_array($post_type, $enabled_types, true)) {
            return new WP_Error('post_type_disabled', __('This post type is not enabled', 'kaigen-connector'), array('status' => 403));
        }
        $args = array('post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => max(1, min(5, intval($limit))), 'orderby' => 'modified', 'order' => 'DESC');
        if ($taxonomy && $term) {
            $args['tax_query'] = array(array('taxonomy' => sanitize_key($taxonomy), 'field' => 'slug', 'terms' => sanitize_title($term)));
        }
        $samples = array();
        foreach (get_posts($args) as $post) {
            $meta = array();
            foreach (get_post_meta($post->ID) as $key => $values) {
                if (is_protected_meta($key, 'post') || !isset($values[0]) || !is_scalar($values[0]) || is_serialized($values[0])) {
                    continue;
                }
                $meta[$key] = mb_substr(strval($values[0]), 0, 200);
            }
            $acf = array();
            if (function_exists('get_fields')) {
                foreach ((array) get_fields($post->ID) as $key => $value) {
                    $acf[$key] = is_scalar($value) ? mb_substr(strval($value), 0, 200) : (is_array($value) ? array_slice(array_map(function ($item) { return is_scalar($item) ? mb_substr(strval($item), 0, 100) : null; }, $value), 0, 10, true) : null);
                }
            }
            $terms = array();
            foreach (get_object_taxonomies($post->post_type) as $tax) {
                $names = wp_get_post_terms($post->ID, $tax, array('fields' => 'names'));
                if (is_array($names) && !empty($names)) {
                    $terms[$tax] = $names;
                }
            }
            $samples[] = array(
                'id' => $post->ID, 'url' => get_permalink($post), 'title' => wp_strip_all_tags(get_the_title($post)),
                'excerpt' => $this->post_field($post, 'excerpt'), 'date' => $this->post_field($post, 'date'), 'modified' => $this->post_field($post, 'modified'),
                'author' => $this->post_field($post, 'author_name'), 'image' => $this->post_field($post, 'image'),
                'meta' => $meta, 'acf' => $acf, 'terms' => $terms,
            );
        }
        return $samples;
    }

    /**
     * What a template would print on given posts, without saving anything.
     */
    public function preview($template, $post_ids) {
        $clean = $this->sanitize_template($template);
        if (is_wp_error($clean)) {
            return $clean;
        }
        $previews = array();
        foreach (array_slice(array_map('intval', $post_ids), 0, 5) as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }
            $node = $this->build_node($post, $clean);
            $missing = isset($node['_missing']) ? $node['_missing'] : array();
            unset($node['_missing']);
            $previews[] = array('id' => $post_id, 'url' => get_permalink($post), 'node' => $node, 'missing' => $missing);
        }
        return $previews;
    }
}
