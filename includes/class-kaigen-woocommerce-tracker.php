<?php
/**
 * WooCommerce tracker handler
 * Captures e-commerce conversions and forwards them to Kaigen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_WooCommerce_Tracker
{

    private static $instance = null;
    const TRACK_PAYLOAD_VERSION = 'wp_woocommerce_v1';

    const COOKIE_VISITOR_ID = 'kaigen_visitor_id';
    const COOKIE_SESSION_ID = 'kaigen_session_id';
    const COOKIE_UTM_SOURCE = 'kaigen_utm_source';
    const COOKIE_UTM_MEDIUM = 'kaigen_utm_medium';
    const COOKIE_UTM_CAMPAIGN = 'kaigen_utm_campaign';
    const COOKIE_REFERRER = 'kaigen_referrer';
    const COOKIE_SOURCE_AUTOMATION_ID = 'kaigen_source_automation_id';
    const COOKIE_SOURCE_AUTOMATION_ITEM_ID = 'kaigen_source_automation_item_id';
    const COOKIE_SOURCE_TERM_ID = 'kaigen_source_term_id';
    const COOKIE_SOURCE_BATCH_ID = 'kaigen_source_batch_id';
    const COOKIE_SOURCE_POST_ID = 'kaigen_source_post_id';
    const COOKIE_SOURCE_POST_TYPE = 'kaigen_source_post_type';
    const COOKIE_SOURCE_POST_SLUG = 'kaigen_source_post_slug';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('woocommerce_checkout_create_order', array($this, 'capture_checkout_context'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_checkout_processed'), 10, 3);
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 10, 1);
        add_action('woocommerce_order_refunded', array($this, 'handle_order_refunded'), 10, 2);
    }

    public function capture_checkout_context($order, $data)
    {
        if (!$this->is_tracking_ready() || !is_a($order, 'WC_Order')) {
            return;
        }

        $map = array(
            self::COOKIE_VISITOR_ID => '_kaigen_visitor_id',
            self::COOKIE_SESSION_ID => '_kaigen_session_id',
            self::COOKIE_UTM_SOURCE => '_kaigen_utm_source',
            self::COOKIE_UTM_MEDIUM => '_kaigen_utm_medium',
            self::COOKIE_UTM_CAMPAIGN => '_kaigen_utm_campaign',
            self::COOKIE_REFERRER => '_kaigen_referrer',
            self::COOKIE_SOURCE_AUTOMATION_ID => '_kaigen_source_automation_id',
            self::COOKIE_SOURCE_AUTOMATION_ITEM_ID => '_kaigen_source_automation_item_id',
            self::COOKIE_SOURCE_TERM_ID => '_kaigen_source_term_id',
            self::COOKIE_SOURCE_BATCH_ID => '_kaigen_source_batch_id',
            self::COOKIE_SOURCE_POST_ID => '_kaigen_source_post_id',
            self::COOKIE_SOURCE_POST_TYPE => '_kaigen_source_post_type',
            self::COOKIE_SOURCE_POST_SLUG => '_kaigen_source_post_slug',
        );

        foreach ($map as $cookie_key => $meta_key) {
            $value = $this->cookie_value($cookie_key, 512);
            if (!empty($value)) {
                $order->update_meta_data($meta_key, $value);
            }
        }
    }

    public function handle_checkout_processed($order_id, $posted_data, $order)
    {
        if (!$this->is_tracking_ready()) {
            return;
        }

        $wc_order = is_a($order, 'WC_Order') ? $order : wc_get_order($order_id);
        if (!is_a($wc_order, 'WC_Order')) {
            return;
        }

        $this->send_order_event('ecommerce_checkout_started', $wc_order, array(
            'hook' => 'woocommerce_checkout_order_processed',
        ));
    }

    public function handle_payment_complete($order_id)
    {
        if (!$this->is_tracking_ready()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $this->send_order_event('ecommerce_purchase', $order, array(
            'hook' => 'woocommerce_payment_complete',
        ));
    }

    public function handle_order_completed($order_id)
    {
        if (!$this->is_tracking_ready()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $this->send_order_event('ecommerce_purchase', $order, array(
            'hook' => 'woocommerce_order_status_completed',
        ));
    }

    public function handle_order_refunded($order_id, $refund_id)
    {
        if (!$this->is_tracking_ready()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $this->send_order_event('ecommerce_refund', $order, array(
            'hook' => 'woocommerce_order_refunded',
            'refund_id' => $refund_id,
        ));
    }

    private function send_order_event($event_type, $order, $context = array())
    {
        $project_id = $this->project_id();
        if (empty($project_id) || !is_a($order, 'WC_Order')) {
            return;
        }

        $order_id = (string) $order->get_id();
        if ($order_id === '') {
            return;
        }

        $refund_id = isset($context['refund_id']) ? (string) absint($context['refund_id']) : '';
        $event_id = $this->build_event_id($project_id, $event_type, $order_id, $refund_id);

        $visitor_id = $this->meta_or_cookie($order, '_kaigen_visitor_id', self::COOKIE_VISITOR_ID, 128);
        $session_id = $this->meta_or_cookie($order, '_kaigen_session_id', self::COOKIE_SESSION_ID, 128);
        $utm_source = $this->meta_or_cookie($order, '_kaigen_utm_source', self::COOKIE_UTM_SOURCE, 255);
        $utm_medium = $this->meta_or_cookie($order, '_kaigen_utm_medium', self::COOKIE_UTM_MEDIUM, 255);
        $utm_campaign = $this->meta_or_cookie($order, '_kaigen_utm_campaign', self::COOKIE_UTM_CAMPAIGN, 255);
        $referrer = $this->meta_or_cookie($order, '_kaigen_referrer', self::COOKIE_REFERRER, 1024);

        $source_automation_id = $this->meta_or_cookie($order, '_kaigen_source_automation_id', self::COOKIE_SOURCE_AUTOMATION_ID, 128);
        $source_automation_item_id = $this->meta_or_cookie($order, '_kaigen_source_automation_item_id', self::COOKIE_SOURCE_AUTOMATION_ITEM_ID, 128);
        $source_term_id = $this->meta_or_cookie($order, '_kaigen_source_term_id', self::COOKIE_SOURCE_TERM_ID, 128);
        $source_batch_id = $this->meta_or_cookie($order, '_kaigen_source_batch_id', self::COOKIE_SOURCE_BATCH_ID, 128);
        $source_post_id = $this->meta_or_cookie($order, '_kaigen_source_post_id', self::COOKIE_SOURCE_POST_ID, 128);
        $source_post_type = $this->meta_or_cookie($order, '_kaigen_source_post_type', self::COOKIE_SOURCE_POST_TYPE, 64);
        $source_post_slug = $this->meta_or_cookie($order, '_kaigen_source_post_slug', self::COOKIE_SOURCE_POST_SLUG, 128);

        $page_url = $this->event_page_url($order, $event_type);
        if (empty($page_url)) {
            $page_url = home_url('/');
        }

        $order_origin = $this->map_order_origin((string) $order->get_created_via());

        $order_payload = array(
            'order_id' => $order_id,
            'order_key' => $this->truncate_or_null((string) $order->get_order_key(), 128),
            'status' => $this->truncate_or_null((string) $order->get_status(), 64),
            'currency' => $this->truncate_or_null((string) $order->get_currency(), 16),
            'total' => (float) $order->get_total(),
            'subtotal' => (float) $order->get_subtotal(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'discount' => (float) $order->get_discount_total(),
            'coupon_codes' => array_values(array_filter(array_map('strval', (array) $order->get_coupon_codes()))),
            'payment_method' => $this->truncate_or_null((string) $order->get_payment_method(), 64),
            'order_origin' => $order_origin,
        );

        if (!empty($refund_id)) {
            $refund_order = wc_get_order($refund_id);
            if (is_a($refund_order, 'WC_Order_Refund')) {
                $order_payload['refund_id'] = $refund_id;
                $order_payload['refund_amount'] = (float) $refund_order->get_amount();
            } else {
                $order_payload['refund_id'] = $refund_id;
            }
        }

        $payload = array(
            'eventType' => $event_type,
            'payloadVersion' => self::TRACK_PAYLOAD_VERSION,
            'order' => $order_payload,
            'items' => $this->extract_order_items($order),
            'customer' => array(
                'email' => $this->truncate_or_null((string) $order->get_billing_email(), 255),
                'phone' => $this->normalize_phone((string) $order->get_billing_phone()),
                'first_name' => $this->truncate_or_null((string) $order->get_billing_first_name(), 120),
                'last_name' => $this->truncate_or_null((string) $order->get_billing_last_name(), 120),
            ),
            'source' => array(
                'sourceAutomationId' => $source_automation_id,
                'sourceAutomationItemId' => $source_automation_item_id,
                'sourceTermId' => $source_term_id,
                'sourceBatchId' => $source_batch_id,
                'sourcePostId' => $source_post_id,
                'sourcePostType' => $source_post_type,
                'sourcePostSlug' => $source_post_slug,
            ),
            'orderContext' => array(
                'hook' => isset($context['hook']) ? $this->truncate_or_null((string) $context['hook'], 128) : null,
                'created_via' => $this->truncate_or_null((string) $order->get_created_via(), 64),
            ),
        );

        $event = array(
            'projectId' => $project_id,
            'eventId' => $event_id,
            'happenedAt' => gmdate('c'),
            'payloadVersion' => self::TRACK_PAYLOAD_VERSION,
            'visitorId' => $visitor_id,
            'sessionId' => $session_id,
            'pageUrl' => $page_url,
            'referrer' => $referrer,
            'utmSource' => $utm_source,
            'utmMedium' => $utm_medium,
            'utmCampaign' => $utm_campaign,
            'trackingSource' => 'wordpress_woocommerce',
            'consentScope' => 'audience_only',
            'payload' => $payload,
        );

        $api = Kaigen_API::get_instance();
        $response = $api->send_tracking_event($event);

        if (is_wp_error($response)) {
            $api->log_activity('tracking_woocommerce', 'error', array(
                'project_id' => $project_id,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'order_id' => $order_id,
                'error' => $response->get_error_message(),
            ));
            return;
        }

        $api->log_activity('tracking_woocommerce', 'success', array(
            'project_id' => $project_id,
            'event_id' => $event_id,
            'event_type' => $event_type,
            'order_id' => $order_id,
            'order_origin' => $order_origin,
        ));
    }

    private function extract_order_items($order)
    {
        if (!is_a($order, 'WC_Order')) {
            return array();
        }

        $items = array();
        foreach ($order->get_items('line_item') as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $quantity = (int) $item->get_quantity();
            $line_total = (float) $item->get_total();
            $unit_price = $quantity > 0 ? (float) ($line_total / $quantity) : null;

            $product = $item->get_product();
            $sku = '';
            if ($product && method_exists($product, 'get_sku')) {
                $sku = (string) $product->get_sku();
            }

            $categories = array();
            if ($product_id > 0) {
                $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
                if (!is_wp_error($terms) && is_array($terms)) {
                    $categories = array_values(array_filter(array_map('strval', $terms)));
                }
            }

            $items[] = array(
                'product_id' => $product_id > 0 ? $product_id : null,
                'variation_id' => $variation_id > 0 ? $variation_id : null,
                'sku' => $this->truncate_or_null($sku, 128),
                'name' => $this->truncate_or_null((string) $item->get_name(), 255),
                'qty' => $quantity,
                'unit_price' => $unit_price,
                'line_total' => $line_total,
                'categories' => $categories,
            );
        }

        return $items;
    }

    private function event_page_url($order, $event_type)
    {
        if ($event_type === 'ecommerce_checkout_started') {
            if (function_exists('wc_get_checkout_url')) {
                return esc_url_raw(wc_get_checkout_url());
            }

            return esc_url_raw(home_url('/checkout/'));
        }

        if (is_a($order, 'WC_Order') && method_exists($order, 'get_checkout_order_received_url')) {
            $received = $order->get_checkout_order_received_url();
            if (!empty($received)) {
                return esc_url_raw($received);
            }
        }

        if (function_exists('wc_get_checkout_url')) {
            return esc_url_raw(wc_get_checkout_url());
        }

        return esc_url_raw(home_url('/checkout/'));
    }

    private function map_order_origin($created_via)
    {
        $value = strtolower(trim((string) $created_via));

        if ($value === '') {
            return 'unknown';
        }

        if (strpos($value, 'checkout') !== false) {
            return 'checkout';
        }

        if (strpos($value, 'admin') !== false) {
            return 'admin';
        }

        if (strpos($value, 'api') !== false || strpos($value, 'rest') !== false) {
            return 'api';
        }

        if (strpos($value, 'subscription') !== false || strpos($value, 'renewal') !== false) {
            return 'subscription';
        }

        return 'unknown';
    }

    private function build_event_id($project_id, $event_type, $order_id, $extra)
    {
        $material = implode('|', array($project_id, $event_type, $order_id, (string) $extra));
        $hash = hash('sha256', $material);

        return 'wc_' . $event_type . '_' . substr($hash, 0, 48);
    }

    private function project_id()
    {
        $settings = get_option('kaigen_settings', array());
        return isset($settings['project_id']) ? sanitize_text_field($settings['project_id']) : '';
    }

    private function is_tracking_ready()
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        $settings = get_option('kaigen_settings', array());
        $enabled = !isset($settings['tracking_enabled']) || (int) $settings['tracking_enabled'] === 1;
        if (!$enabled) {
            return false;
        }

        $project_id = $this->project_id();
        if (empty($project_id)) {
            return false;
        }

        $auth = Kaigen_Auth::get_instance();
        return (bool) $auth->get_api_key();
    }

    private function cookie_value($name, $max_length)
    {
        if (!isset($_COOKIE[$name])) {
            return null;
        }

        $raw = sanitize_text_field(wp_unslash($_COOKIE[$name]));
        return $this->truncate_or_null($raw, $max_length);
    }

    private function meta_or_cookie($order, $meta_key, $cookie_name, $max_length)
    {
        if (is_a($order, 'WC_Order')) {
            $meta = $order->get_meta($meta_key, true);
            $meta_value = $this->truncate_or_null(sanitize_text_field((string) $meta), $max_length);
            if (!empty($meta_value)) {
                return $meta_value;
            }
        }

        return $this->cookie_value($cookie_name, $max_length);
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

    private function normalize_phone($phone)
    {
        if (!is_string($phone) || $phone === '') {
            return null;
        }

        if (!preg_match('/(?:\+?\d[\d().\s-]{6,}\d)/', $phone, $matches)) {
            return null;
        }

        $cleaned = preg_replace('/[^\d+]/', '', $matches[0]);
        $digits = preg_replace('/[^\d]/', '', $cleaned);
        if (!is_string($digits) || strlen($digits) < 8 || strlen($digits) > 16) {
            return null;
        }

        return $this->truncate_or_null($cleaned, 50);
    }
}
