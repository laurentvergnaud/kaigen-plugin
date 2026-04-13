<?php
/**
 * Frontend tracker handler
 * Captures first-party pageviews and forwards them to Kaigen through WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Tracker
{

    private static $instance = null;
    private $namespace = 'kaigen/v1';
    const TRACK_PAYLOAD_VERSION = 'wp_tracker_v1';

    const RATE_LIMIT_PER_MINUTE = 120;

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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracker_script'));
    }

    /**
     * Register public tracking route.
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, '/track', array(
            'methods' => 'POST',
            'callback' => array($this, 'track_pageview'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Enqueue frontend tracker script when tracking is enabled and plugin is linked.
     */
    public function enqueue_tracker_script()
    {
        if (!$this->is_tracking_enabled()) {
            return;
        }

        if (is_admin() || is_feed() || is_preview()) {
            return;
        }

        $settings = get_option('kaigen_settings', array());
        $project_id = isset($settings['project_id']) ? sanitize_text_field($settings['project_id']) : '';

        if (empty($project_id)) {
            return;
        }

        $auth = Kaigen_Auth::get_instance();
        if (!$auth->get_api_key()) {
            return;
        }

        $token_day = gmdate('Y-m-d');
        $signed_token = $this->build_signed_token($project_id, $token_day);
        $queried_post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        $wp_context = array(
            'postId' => null,
            'postType' => null,
            'postSlug' => null,
        );

        if ($queried_post_id > 0) {
            $wp_context['postId'] = (string) $queried_post_id;
            $wp_context['postType'] = sanitize_text_field((string) get_post_type($queried_post_id));
            $wp_context['postSlug'] = sanitize_title((string) get_post_field('post_name', $queried_post_id));
        }

        wp_enqueue_script(
            'kaigen-tracker',
            KAIGEN_PLUGIN_URL . 'public/js/kaigen-tracker.js',
            array(),
            KAIGEN_VERSION,
            true
        );

        wp_localize_script('kaigen-tracker', 'kaigenTrackerConfig', array(
            'endpoint' => rest_url($this->namespace . '/track'),
            'projectId' => $project_id,
            'tokenDay' => $token_day,
            'token' => $signed_token,
            'consentScope' => 'audience_only',
            'sessionTtlMs' => 30 * MINUTE_IN_SECONDS * 1000,
            'visitorTtlDays' => 395,
            'wpContext' => $wp_context,
        ));
    }

    /**
     * Receive and forward one tracking pageview.
     */
    public function track_pageview($request)
    {
        if (!$this->is_tracking_enabled()) {
            return new WP_Error('kaigen_track_disabled', __('Tracking is disabled', 'kaigen-connector'), array('status' => 403));
        }

        if (!$this->is_same_origin_request()) {
            return new WP_Error('kaigen_track_origin', __('Invalid request origin', 'kaigen-connector'), array('status' => 403));
        }

        if ($this->is_rate_limited()) {
            return new WP_Error('kaigen_track_rate_limited', __('Rate limit exceeded', 'kaigen-connector'), array('status' => 429));
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $raw_payload = $request->get_body();
            if (is_string($raw_payload) && $raw_payload !== '') {
                $decoded = json_decode($raw_payload, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }
        if (!is_array($payload)) {
            return new WP_Error('kaigen_track_bad_payload', __('Invalid JSON payload', 'kaigen-connector'), array('status' => 400));
        }

        $settings = get_option('kaigen_settings', array());
        $project_id = isset($settings['project_id']) ? sanitize_text_field($settings['project_id']) : '';

        if (empty($project_id)) {
            return new WP_Error('kaigen_track_no_project', __('No linked project_id configured', 'kaigen-connector'), array('status' => 400));
        }

        if (!$this->validate_signed_token($project_id, $payload)) {
            return new WP_Error('kaigen_track_bad_token', __('Invalid tracking signature', 'kaigen-connector'), array('status' => 403));
        }

        $validated = $this->validate_payload($payload);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $event = array(
            'projectId' => $project_id,
            'eventId' => $validated['event_id'],
            'happenedAt' => $validated['happened_at'],
            'payloadVersion' => $validated['payload_version'],
            'visitorId' => $validated['visitor_id'],
            'sessionId' => $validated['session_id'],
            'pageUrl' => $validated['page_url'],
            'referrer' => $validated['referrer'],
            'utmSource' => $validated['utm_source'],
            'utmMedium' => $validated['utm_medium'],
            'utmCampaign' => $validated['utm_campaign'],
            'trackingSource' => 'wordpress_plugin',
            'consentScope' => 'audience_only',
            'payload' => array(
                'eventType' => $validated['event_type'],
                'payloadVersion' => $validated['payload_version'],
                'title' => $validated['title'],
                'language' => $validated['language'],
                'path' => $validated['path'],
                'contactEmail' => $validated['contact_email'],
                'contactPhone' => $validated['contact_phone'],
                'contactFirstName' => $validated['contact_first_name'],
                'contactLastName' => $validated['contact_last_name'],
                'form' => array(
                    'action' => $validated['form_action'],
                    'method' => $validated['form_method'],
                    'id' => $validated['form_id'],
                    'name' => $validated['form_name'],
                ),
                'source' => array(
                    'sourceAutomationId' => $validated['source_automation_id'],
                    'sourceAutomationItemId' => $validated['source_automation_item_id'],
                    'sourceTermId' => $validated['source_term_id'],
                    'sourceBatchId' => $validated['source_batch_id'],
                    'sourcePostId' => $validated['source_post_id'],
                    'sourcePostType' => $validated['source_post_type'],
                    'sourcePostSlug' => $validated['source_post_slug'],
                ),
            ),
        );

        $api = Kaigen_API::get_instance();
        $response = $api->send_tracking_event($event);

        if (is_wp_error($response)) {
            $api->log_activity('tracking_pageview', 'error', array(
                'project_id' => $project_id,
                'event_id' => $validated['event_id'],
                'error' => $response->get_error_message(),
            ));

            return new WP_Error(
                'kaigen_track_forward_failed',
                __('Unable to forward tracking event to Kaigen', 'kaigen-connector'),
                array('status' => 502)
            );
        }

        $api->log_activity('tracking_pageview', 'success', array(
            'project_id' => $project_id,
            'event_id' => $validated['event_id'],
            'page_url' => $validated['page_url'],
        ));

        return rest_ensure_response(array(
            'success' => true,
        ));
    }

    /**
     * Validate payload fields from the frontend tracker.
     */
    private function validate_payload($payload)
    {
        $event_id = isset($payload['event_id']) ? sanitize_text_field($payload['event_id']) : '';
        if (!$this->is_valid_event_id($event_id)) {
            return new WP_Error('kaigen_track_bad_event_id', __('Invalid event_id', 'kaigen-connector'), array('status' => 400));
        }

        $page_url = isset($payload['page_url']) ? esc_url_raw($payload['page_url']) : '';
        if (empty($page_url) || !$this->is_same_site_url($page_url)) {
            return new WP_Error('kaigen_track_bad_page_url', __('Invalid page_url', 'kaigen-connector'), array('status' => 400));
        }

        $happened_at = isset($payload['happened_at']) ? sanitize_text_field($payload['happened_at']) : '';
        if (empty($happened_at) || strtotime($happened_at) === false) {
            $happened_at = gmdate('c');
        }

        $event_type = isset($payload['event_type']) ? sanitize_key($payload['event_type']) : 'pageview';
        if (!in_array($event_type, array('pageview', 'form_submit'), true)) {
            $event_type = 'pageview';
        }
        $payload_version = isset($payload['payload_version']) ? sanitize_text_field($payload['payload_version']) : '';
        if (!$this->is_valid_payload_version($payload_version)) {
            $payload_version = self::TRACK_PAYLOAD_VERSION;
        }

        $visitor_id = isset($payload['visitor_id']) ? sanitize_text_field($payload['visitor_id']) : null;
        $session_id = isset($payload['session_id']) ? sanitize_text_field($payload['session_id']) : null;
        $referrer = isset($payload['referrer']) ? esc_url_raw($payload['referrer']) : null;
        $utm_source = isset($payload['utm_source']) ? sanitize_text_field($payload['utm_source']) : null;
        $utm_medium = isset($payload['utm_medium']) ? sanitize_text_field($payload['utm_medium']) : null;
        $utm_campaign = isset($payload['utm_campaign']) ? sanitize_text_field($payload['utm_campaign']) : null;
        $title = isset($payload['title']) ? sanitize_text_field($payload['title']) : null;
        $language = isset($payload['language']) ? sanitize_text_field($payload['language']) : null;
        $form_action = isset($payload['form_action']) ? esc_url_raw($payload['form_action']) : null;
        $form_method = isset($payload['form_method']) ? sanitize_text_field($payload['form_method']) : null;
        $form_id = isset($payload['form_id']) ? sanitize_text_field($payload['form_id']) : null;
        $form_name = isset($payload['form_name']) ? sanitize_text_field($payload['form_name']) : null;
        $contact_email_raw = isset($payload['contact_email']) ? sanitize_email($payload['contact_email']) : null;
        $contact_phone_raw = isset($payload['contact_phone']) ? sanitize_text_field($payload['contact_phone']) : null;
        $contact_first_name = isset($payload['contact_first_name']) ? sanitize_text_field($payload['contact_first_name']) : null;
        $contact_last_name = isset($payload['contact_last_name']) ? sanitize_text_field($payload['contact_last_name']) : null;
        $source_automation_id = isset($payload['source_automation_id']) ? sanitize_text_field($payload['source_automation_id']) : null;
        $source_automation_item_id = isset($payload['source_automation_item_id']) ? sanitize_text_field($payload['source_automation_item_id']) : null;
        $source_term_id = isset($payload['source_term_id']) ? sanitize_text_field($payload['source_term_id']) : null;
        $source_batch_id = isset($payload['source_batch_id']) ? sanitize_text_field($payload['source_batch_id']) : null;
        $source_post_id = isset($payload['source_post_id']) ? sanitize_text_field($payload['source_post_id']) : null;
        $source_post_type = isset($payload['source_post_type']) ? sanitize_text_field($payload['source_post_type']) : null;
        $source_post_slug = isset($payload['source_post_slug']) ? sanitize_text_field($payload['source_post_slug']) : null;
        $contact_email = $this->is_valid_email($contact_email_raw) ? $contact_email_raw : null;
        $contact_phone = $this->normalize_phone($contact_phone_raw);

        return array(
            'event_id' => $event_id,
            'event_type' => $event_type,
            'payload_version' => $payload_version,
            'page_url' => $page_url,
            'path' => (string) wp_parse_url($page_url, PHP_URL_PATH),
            'happened_at' => $happened_at,
            'visitor_id' => $this->truncate_or_null($visitor_id, 128),
            'session_id' => $this->truncate_or_null($session_id, 128),
            'referrer' => $this->truncate_or_null($referrer, 2048),
            'utm_source' => $this->truncate_or_null($utm_source, 255),
            'utm_medium' => $this->truncate_or_null($utm_medium, 255),
            'utm_campaign' => $this->truncate_or_null($utm_campaign, 255),
            'title' => $this->truncate_or_null($title, 255),
            'language' => $this->truncate_or_null($language, 24),
            'form_action' => $this->truncate_or_null($form_action, 2048),
            'form_method' => $this->truncate_or_null($form_method ? strtoupper($form_method) : null, 16),
            'form_id' => $this->truncate_or_null($form_id, 128),
            'form_name' => $this->truncate_or_null($form_name, 128),
            'contact_email' => $this->truncate_or_null($contact_email, 255),
            'contact_phone' => $this->truncate_or_null($contact_phone, 32),
            'contact_first_name' => $this->truncate_or_null($contact_first_name, 120),
            'contact_last_name' => $this->truncate_or_null($contact_last_name, 120),
            'source_automation_id' => $this->truncate_or_null($source_automation_id, 64),
            'source_automation_item_id' => $this->truncate_or_null($source_automation_item_id, 64),
            'source_term_id' => $this->truncate_or_null($source_term_id, 64),
            'source_batch_id' => $this->truncate_or_null($source_batch_id, 64),
            'source_post_id' => $this->truncate_or_null($source_post_id, 100),
            'source_post_type' => $this->truncate_or_null($source_post_type, 64),
            'source_post_slug' => $this->truncate_or_null($source_post_slug, 128),
        );
    }

    /**
     * Validate signed token delivered by wp_localize_script.
     */
    private function validate_signed_token($project_id, $payload)
    {
        $token_day = isset($payload['token_day']) ? sanitize_text_field($payload['token_day']) : '';
        $token = isset($payload['token']) ? sanitize_text_field($payload['token']) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $token_day) || empty($token)) {
            return false;
        }

        $allowed_days = array(
            gmdate('Y-m-d'),
            gmdate('Y-m-d', time() - DAY_IN_SECONDS),
            gmdate('Y-m-d', time() + DAY_IN_SECONDS),
        );

        if (!in_array($token_day, $allowed_days, true)) {
            return false;
        }

        $expected = $this->build_signed_token($project_id, $token_day);
        return hash_equals($expected, $token);
    }

    /**
     * Sign a short-lived day token with project and site context.
     */
    private function build_signed_token($project_id, $token_day)
    {
        $site = untrailingslashit(home_url('/'));
        $material = $site . '|' . $project_id . '|' . $token_day;

        return hash_hmac('sha256', $material, wp_salt('auth'));
    }

    /**
     * Basic same-origin control for public endpoint.
     */
    private function is_same_origin_request()
    {
        $site_host = $this->normalize_host(wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (empty($site_host)) {
            return false;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';

        $origin_host = $this->normalize_host(wp_parse_url($origin, PHP_URL_HOST));
        if (!empty($origin_host) && $origin_host === $site_host) {
            return true;
        }

        $referer_host = $this->normalize_host(wp_parse_url($referer, PHP_URL_HOST));
        if (!empty($referer_host) && $referer_host === $site_host) {
            return true;
        }

        return false;
    }

    /**
     * Trivial per-IP+UA rate limiter.
     */
    private function is_rate_limited()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown';
        $key = 'kaigen_track_rl_' . md5($ip . '|' . $ua);

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return true;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }

    private function is_tracking_enabled()
    {
        $settings = get_option('kaigen_settings', array());

        if (!isset($settings['tracking_enabled'])) {
            return true;
        }

        return (int) $settings['tracking_enabled'] === 1;
    }

    private function is_valid_event_id($event_id)
    {
        if (!is_string($event_id) || $event_id === '') {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $event_id);
    }

    private function is_valid_payload_version($payload_version)
    {
        if (!is_string($payload_version) || $payload_version === '') {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9._:-]{1,64}$/', $payload_version);
    }

    private function is_same_site_url($url)
    {
        $url_host = $this->normalize_host(wp_parse_url($url, PHP_URL_HOST));
        $site_host = $this->normalize_host(wp_parse_url(home_url('/'), PHP_URL_HOST));

        return !empty($url_host) && !empty($site_host) && $url_host === $site_host;
    }

    private function normalize_host($host)
    {
        $value = strtolower((string) $host);
        if (strpos($value, 'www.') === 0) {
            return substr($value, 4);
        }

        return $value;
    }

    private function truncate_or_null($value, $max_length)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) <= $max_length) {
            return $value;
        }

        return substr($value, 0, $max_length);
    }

    private function is_valid_email($email)
    {
        if (!is_string($email) || $email === '') {
            return false;
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private function normalize_phone($phone)
    {
        if (!is_string($phone) || $phone === '') {
            return null;
        }

        if (!preg_match('/(?:\\+?\\d[\\d().\\s-]{6,}\\d)/', $phone, $matches)) {
            return null;
        }

        $cleaned = preg_replace('/[^\\d+]/', '', $matches[0]);
        $digits = preg_replace('/[^\\d]/', '', $cleaned);
        if (!is_string($digits) || strlen($digits) < 8 || strlen($digits) > 16) {
            return null;
        }

        return $cleaned;
    }
}
