<?php
/**
 * Audience measurement handler (plugin 1.5.0, ADR 0075; goals since 1.6.0, ADR 0104).
 *
 * Tamiro measures the site with Umami, an instance Tamiro runs. The plugin serves Umami's tracker
 * under the site's own domain and relays its collect calls to the instance, so that the measure is
 * first-party: nothing on the page points at a measurement host. The website id and the instance
 * URL come from Tamiro, read once and kept in the settings; the plugin never decides them.
 * The native tracker of the versions before (pageviews sent to Tamiro's own tables) is gone.
 * Since 1.6.0 the plugin also poses the measurement goals the client validated in Tamiro: the
 * capture rules are pushed by Tamiro at each decision (`POST /measure/goals`, 1.6.2) and read again
 * every hour, printed for `public/js/kaigen-goals.js`, and what that script finds on a page is
 * told back to Tamiro. Since 1.6.2 the tracker is written as a file under uploads, served by the
 * web server without PHP; the REST route only stands in when the file cannot be written.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Tracker
{

    private static $instance = null;
    private $namespace = 'kaigen/v1';

    // No file extension on the route (1.6.1): a host's web server treats `/m.js` as a static file
    // and answers 404 before WordPress (seen on Faaaster hosting on 2026-10-16).
    const SCRIPT_ROUTE = '/m/script';
    const SCRIPT_PATH = '/m/script';
    const HOST_PATH = '/m';
    const COLLECT_ROUTE = '/m/api/send';
    const SCRIPT_TRANSIENT = 'kaigen_measure_script';
    const SCRIPT_TTL = DAY_IN_SECONDS;
    const RATE_LIMIT_PER_MINUTE = 120;
    const CONFIG_RETRY_SECONDS = 10 * MINUTE_IN_SECONDS;
    const CONFIG_MAX_RETRIES = 10;
    const RELAY_TIMEOUT = 5;
    const GOALS_ROUTE = '/m/goals';
    const GOALS_PATH = '/m/goals';
    const GOALS_OPTION = 'kaigen_measure_goals';
    const GOALS_TTL = HOUR_IN_SECONDS;
    const GOALS_PUSH_ROUTE = '/measure/goals';
    const SCRIPT_FILE_DIR = 'kaigen';
    const SCRIPT_FILE_NAME = 'm.js';
    const GOALS_REPORT_LOCK = 'kaigen_goals_report_lock';
    const GOALS_REPORT_MIN_SECONDS = 10 * MINUTE_IN_SECONDS;

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
        add_action('wp_head', array($this, 'print_tag'), 20);
        add_filter('rest_pre_serve_request', array($this, 'serve_script_raw'), 10, 4);
    }

    /**
     * The two public routes: the tracker script, and the collect endpoint Umami's script posts to
     * (`data-host-url` + `/api/send`).
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, self::SCRIPT_ROUTE, array(
            'methods' => 'GET',
            'callback' => array($this, 'serve_script'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route($this->namespace, self::COLLECT_ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'relay'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route($this->namespace, self::GOALS_ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'report_goals'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route($this->namespace, self::GOALS_PUSH_ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_goals'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    /**
     * Tamiro's own calls, authenticated like the publication routes.
     */
    public function check_permission()
    {
        return Kaigen_Auth::get_instance()->verify_incoming_request();
    }

    /**
     * The active rules pushed by Tamiro at each decision (1.6.2): the whole list replaces the stored
     * one at once, instead of waiting for the hourly read.
     */
    public function receive_goals($request)
    {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body) || !isset($body['goals']) || !is_array($body['goals'])) {
            return new WP_Error('kaigen_goals_bad_payload', __('Invalid payload', 'kaigen-connector'), array('status' => 400));
        }
        $next = array(
            'goals' => $this->sanitize_goals($body['goals']),
            'version' => isset($body['version']) && is_string($body['version']) ? sanitize_text_field($body['version']) : '',
            'fetched_at' => time(),
        );
        update_option(self::GOALS_OPTION, $next, false);
        return new WP_REST_Response(array('stored' => count($next['goals']), 'version' => $next['version']), 200);
    }

    /**
     * The tag on every public page: Umami's script served by this site, posting to this site.
     */
    public function print_tag()
    {
        if (!$this->is_tracking_enabled()) {
            return;
        }

        if (is_admin() || is_feed() || is_preview()) {
            return;
        }

        $config = $this->measure_config();
        if (!$config) {
            return;
        }

        $script = $this->script_url($config);
        $host = untrailingslashit(rest_url($this->namespace . self::HOST_PATH));

        printf(
            '<script defer src="%s" data-website-id="%s" data-host-url="%s"></script>' . "\n",
            esc_url($script),
            esc_attr($config['website_id']),
            esc_url($host)
        );

        $goals = $this->goals();
        if (empty($goals['goals'])) {
            return;
        }
        $payload = array(
            'goals' => $goals['goals'],
            'version' => isset($goals['version']) ? (string) $goals['version'] : '',
            'endpoint' => rest_url($this->namespace . self::GOALS_PATH),
        );
        printf(
            '<script>window.kaigenGoals = %s;</script>' . "\n" . '<script defer src="%s"></script>' . "\n",
            wp_json_encode($payload),
            esc_url(KAIGEN_PLUGIN_URL . 'public/js/kaigen-goals.js?ver=' . rawurlencode(KAIGEN_VERSION . '-' . md5((string) $payload['version'])))
        );
    }

    /**
     * The goals the client validated, as Tamiro last gave them: pushed by Tamiro at each decision
     * (`receive_goals`), read again every hour; on a failure the last ones stand until the next read.
     */
    private function goals()
    {
        $stored = get_option(self::GOALS_OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $fetched_at = isset($stored['fetched_at']) ? (int) $stored['fetched_at'] : 0;
        if ($fetched_at > 0 && (time() - $fetched_at) < self::GOALS_TTL) {
            return $stored;
        }
        return $this->refresh_goals(array(), $stored);
    }

    /**
     * Tell Tamiro what the page found for each rule, and take the rules it answers with.
     */
    private function refresh_goals($matches, $stored)
    {
        $api = Kaigen_API::get_instance();
        $response = $api->report_goal_matches($matches);
        $next = is_array($stored) ? $stored : array();
        $next['fetched_at'] = time();
        if (!is_wp_error($response) && is_array($response) && isset($response['goals']) && is_array($response['goals'])) {
            $next['goals'] = $this->sanitize_goals($response['goals']);
            $next['version'] = isset($response['version']) && is_string($response['version']) ? sanitize_text_field($response['version']) : '';
        }
        update_option(self::GOALS_OPTION, $next, false);
        return $next;
    }

    private function sanitize_goals($goals)
    {
        $out = array();
        foreach ($goals as $goal) {
            if (!is_array($goal) || empty($goal['name']) || !is_string($goal['name']) || !preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $goal['name'])) {
                continue;
            }
            $kind = isset($goal['kind']) && is_string($goal['kind']) ? $goal['kind'] : '';
            if (!in_array($kind, array('page_reached', 'click', 'form_submit'), true)) {
                continue;
            }
            $rule = isset($goal['rule']) && is_array($goal['rule']) ? $goal['rule'] : array();
            $clean = array();
            foreach (array('urlPattern', 'text', 'hrefPattern', 'selector', 'formSelector') as $field) {
                $clean[$field] = isset($rule[$field]) && is_string($rule[$field]) && $rule[$field] !== '' ? substr(wp_strip_all_tags($rule[$field]), 0, 500) : null;
            }
            $out[] = array('name' => $goal['name'], 'kind' => $kind, 'rule' => $clean);
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }

    /**
     * What a page found for each rule, from `kaigen-goals.js`: same origin, rate limited, passed to
     * Tamiro at most once every ten minutes (a sighting lost is told again by the next session).
     */
    public function report_goals($request)
    {
        if (!$this->is_tracking_enabled()) {
            return new WP_Error('kaigen_measure_disabled', __('Measurement is disabled', 'kaigen-connector'), array('status' => 403));
        }
        if (!$this->is_same_origin_request()) {
            return new WP_Error('kaigen_measure_origin', __('Invalid request origin', 'kaigen-connector'), array('status' => 403));
        }
        if ($this->is_rate_limited()) {
            return new WP_Error('kaigen_measure_rate_limited', __('Rate limit exceeded', 'kaigen-connector'), array('status' => 429));
        }
        $body = json_decode($request->get_body(), true);
        $matches = array();
        if (is_array($body) && isset($body['matches']) && is_array($body['matches'])) {
            foreach ($body['matches'] as $match) {
                if (!is_array($match) || empty($match['name']) || !is_string($match['name']) || !preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $match['name'])) {
                    continue;
                }
                $matches[] = array('name' => $match['name'], 'count' => isset($match['count']) ? max(0, (int) $match['count']) : 0);
                if (count($matches) >= 100) {
                    break;
                }
            }
        }
        if (empty($matches) || get_transient(self::GOALS_REPORT_LOCK)) {
            return new WP_REST_Response(array('accepted' => false), 202);
        }
        set_transient(self::GOALS_REPORT_LOCK, 1, self::GOALS_REPORT_MIN_SECONDS);
        $this->refresh_goals($matches, get_option(self::GOALS_OPTION, array()));
        return new WP_REST_Response(array('accepted' => true), 200);
    }

    /**
     * The website id and the instance URL, from the settings, or asked to Tamiro when missing.
     */
    private function measure_config()
    {
        $settings = get_option('kaigen_settings', array());
        $project_id = isset($settings['project_id']) ? sanitize_text_field($settings['project_id']) : '';
        if (empty($project_id)) {
            return null;
        }

        $auth = Kaigen_Auth::get_instance();
        if (!$auth->get_api_key()) {
            return null;
        }

        $website_id = isset($settings['umami_website_id']) ? sanitize_text_field($settings['umami_website_id']) : '';
        $base_url = isset($settings['umami_base_url']) ? esc_url_raw($settings['umami_base_url']) : '';
        if (!empty($website_id) && !empty($base_url)) {
            return array('website_id' => $website_id, 'base_url' => untrailingslashit($base_url));
        }

        return $this->load_measure_config($settings);
    }

    /**
     * Ask Tamiro for the measurement configuration; a failure is retried a few times, ten minutes
     * apart, then left alone until the settings change.
     */
    private function load_measure_config($settings)
    {
        if (!$this->should_retry_config($settings)) {
            return null;
        }

        $api = Kaigen_API::get_instance();
        $response = $api->get_measure_config();

        $settings['umami_config_attempted_at'] = gmdate('c');

        if (!is_wp_error($response) && !empty($response['websiteId']) && is_string($response['websiteId']) && !empty($response['baseUrl']) && is_string($response['baseUrl'])) {
            $settings['umami_website_id'] = sanitize_text_field($response['websiteId']);
            $settings['umami_base_url'] = esc_url_raw($response['baseUrl']);
            unset($settings['umami_config_error']);
            unset($settings['umami_config_attempted_at']);
            unset($settings['umami_config_retry_count']);
            delete_transient(self::SCRIPT_TRANSIENT);
        } elseif (is_wp_error($response)) {
            $settings['umami_config_error'] = $response->get_error_message();
            $settings['umami_config_retry_count'] = $this->config_retry_count($settings) + 1;
        } else {
            $settings['umami_config_error'] = __('The measurement configuration was not returned by Tamiro', 'kaigen-connector');
            $settings['umami_config_retry_count'] = $this->config_retry_count($settings) + 1;
        }

        update_option('kaigen_settings', $settings);

        if (empty($settings['umami_website_id']) || empty($settings['umami_base_url'])) {
            return null;
        }

        return array('website_id' => $settings['umami_website_id'], 'base_url' => untrailingslashit($settings['umami_base_url']));
    }

    private function should_retry_config($settings)
    {
        if ($this->config_retry_count($settings) >= self::CONFIG_MAX_RETRIES) {
            return false;
        }

        $attempted_at = isset($settings['umami_config_attempted_at']) ? strtotime($settings['umami_config_attempted_at']) : false;
        if ($attempted_at && (time() - $attempted_at) < self::CONFIG_RETRY_SECONDS) {
            return false;
        }

        return true;
    }

    private function config_retry_count($settings)
    {
        return isset($settings['umami_config_retry_count']) ? (int) $settings['umami_config_retry_count'] : 0;
    }

    /**
     * Umami's tracker as a file under uploads (1.6.2), rewritten once a day, served by the web
     * server without PHP; when the file cannot be written, the REST route serves it instead.
     */
    private function script_url($config)
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl'])) {
            return rest_url($this->namespace . self::SCRIPT_PATH);
        }
        $dir = trailingslashit($upload['basedir']) . self::SCRIPT_FILE_DIR;
        $file = $dir . '/' . self::SCRIPT_FILE_NAME;
        $url = trailingslashit($upload['baseurl']) . self::SCRIPT_FILE_DIR . '/' . self::SCRIPT_FILE_NAME;
        $mtime = file_exists($file) ? (int) filemtime($file) : 0;
        if ($mtime > 0 && (time() - $mtime) < self::SCRIPT_TTL && filesize($file) > 0) {
            return $url;
        }
        $script = $this->read_script($config);
        if (!is_string($script)) {
            return $mtime > 0 ? $url : rest_url($this->namespace . self::SCRIPT_PATH);
        }
        if (!wp_mkdir_p($dir) || file_put_contents($file, $script) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a cached copy of Umami's tracker, next to the media.
            return rest_url($this->namespace . self::SCRIPT_PATH);
        }
        return $url;
    }

    /**
     * Umami's tracker read on the instance, kept a day in a transient.
     */
    private function read_script($config)
    {
        $script = get_transient(self::SCRIPT_TRANSIENT);
        if (is_string($script) && $script !== '') {
            return $script;
        }
        $response = wp_remote_get($config['base_url'] . '/script.js', array(
            'timeout' => 10,
            'user-agent' => 'Tamiro WordPress plugin ' . KAIGEN_VERSION,
        ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $script = wp_remote_retrieve_body($response);
        if (!is_string($script) || $script === '') {
            return null;
        }
        set_transient(self::SCRIPT_TRANSIENT, $script, self::SCRIPT_TTL);
        return $script;
    }

    /**
     * Umami's tracker served from here, for a site whose uploads cannot be written.
     */
    public function serve_script()
    {
        $config = $this->measure_config();
        if (!$config) {
            return new WP_Error('kaigen_measure_not_configured', __('Measurement is not configured', 'kaigen-connector'), array('status' => 404));
        }

        $script = $this->read_script($config);
        if (!is_string($script)) {
            return new WP_Error('kaigen_measure_script_unavailable', __('The measurement script could not be read', 'kaigen-connector'), array('status' => 503));
        }

        return new WP_REST_Response($script, 200);
    }

    /**
     * The script route answers JavaScript, not JSON: the REST server is asked to let the raw body through.
     */
    public function serve_script_raw($served, $result, $request, $server)
    {
        if ($served || !($request instanceof WP_REST_Request) || $request->get_route() !== '/' . $this->namespace . self::SCRIPT_PATH) {
            return $served;
        }
        if (!($result instanceof WP_HTTP_Response)) {
            return $served;
        }
        $data = $result->get_data();
        if (!is_string($data)) {
            return $served;
        }

        $server->send_header('Content-Type', 'application/javascript; charset=utf-8');
        $server->send_header('Cache-Control', 'public, max-age=3600');
        echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Umami's own script, served as read.

        return true;
    }

    /**
     * One collect call of Umami's script, passed to the instance with the visitor's address and
     * browser (the session is their hash on Umami's side) and the session token both ways.
     */
    public function relay($request)
    {
        if (!$this->is_tracking_enabled()) {
            return new WP_Error('kaigen_measure_disabled', __('Measurement is disabled', 'kaigen-connector'), array('status' => 403));
        }

        if (!$this->is_same_origin_request()) {
            return new WP_Error('kaigen_measure_origin', __('Invalid request origin', 'kaigen-connector'), array('status' => 403));
        }

        if ($this->is_rate_limited()) {
            return new WP_Error('kaigen_measure_rate_limited', __('Rate limit exceeded', 'kaigen-connector'), array('status' => 429));
        }

        $config = $this->measure_config();
        if (!$config) {
            return new WP_Error('kaigen_measure_not_configured', __('Measurement is not configured', 'kaigen-connector'), array('status' => 404));
        }

        $body = $request->get_body();
        if (!is_string($body) || $body === '' || strlen($body) > 16384) {
            return new WP_Error('kaigen_measure_bad_payload', __('Invalid payload', 'kaigen-connector'), array('status' => 400));
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->visitor_user_agent(),
            'X-Forwarded-For' => $this->visitor_ip(),
        );
        $cache = $request->get_header('x-umami-cache');
        if (is_string($cache) && $cache !== '') {
            $headers['x-umami-cache'] = sanitize_text_field($cache);
        }

        $response = wp_remote_post($config['base_url'] . '/api/send', array(
            'timeout' => self::RELAY_TIMEOUT,
            'headers' => $headers,
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('kaigen_measure_relay_failed', __('The measurement could not be relayed', 'kaigen-connector'), array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response(is_array($decoded) ? $decoded : array(), $code > 0 ? $code : 502);
    }

    /**
     * The visitor's address as the server sees it, the first of a forwarded chain when a proxy
     * stands before WordPress.
     */
    private function visitor_ip()
    {
        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '';
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            if (!empty($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP)) {
                return $parts[0];
            }
        }
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    private function visitor_user_agent()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return $ua !== '' ? $ua : 'Mozilla/5.0 (unknown)';
    }

    /**
     * Basic same-origin control for the public collect endpoint.
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

    private function normalize_host($host)
    {
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower($host);
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Trivial per-IP+UA rate limiter.
     */
    private function is_rate_limited()
    {
        $key = 'kaigen_track_rl_' . md5($this->visitor_ip() . '|' . $this->visitor_user_agent());

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
}
