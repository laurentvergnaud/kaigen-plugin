<?php
/**
 * WordPress sync events queue and flusher.
 *
 * Collects post lifecycle events asynchronously and pushes them to Kaigen in batches.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Sync_Events
{
    private static $instance = null;

    private $queue_option_key = 'kaigen_sync_event_queue';
    private $cron_hook = 'kaigen_flush_sync_events';
    private $cron_schedule_key = 'kaigen_every_two_minutes';
    private $batch_size = 20;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('save_post', array($this, 'handle_save_post'), 20, 3);
        add_action('transition_post_status', array($this, 'handle_transition_post_status'), 20, 3);
        add_action('before_delete_post', array($this, 'handle_before_delete_post'), 20, 1);

        add_filter('cron_schedules', array($this, 'register_custom_schedule'));
        add_action('init', array($this, 'ensure_cron_scheduled'));
        add_action($this->cron_hook, array($this, 'flush_sync_events'));
    }

    public function register_custom_schedule($schedules)
    {
        if (!isset($schedules[$this->cron_schedule_key])) {
            $schedules[$this->cron_schedule_key] = array(
                'interval' => 120,
                'display' => __('Every 2 Minutes', 'kaigen-connector'),
            );
        }

        return $schedules;
    }

    public function ensure_cron_scheduled()
    {
        if (!wp_next_scheduled($this->cron_hook)) {
            wp_schedule_event(time() + 120, $this->cron_schedule_key, $this->cron_hook);
        }
    }

    public function unschedule_cron()
    {
        $timestamp = wp_next_scheduled($this->cron_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cron_hook);
        }
    }

    public function handle_save_post($post_id, $post, $update)
    {
        if (!$this->should_track_post($post_id, $post)) {
            return;
        }

        // Only track update/create lifecycle for persisted posts.
        if (!$update && empty($post->post_date_gmt)) {
            return;
        }

        $this->enqueue_upsert_event($post_id);
    }

    public function handle_transition_post_status($new_status, $old_status, $post)
    {
        if (!$post || !$this->should_track_post($post->ID, $post)) {
            return;
        }

        if ($new_status === $old_status) {
            return;
        }

        if ($new_status === 'trash') {
            $this->enqueue_delete_event($post->ID);
            return;
        }

        $this->enqueue_upsert_event($post->ID);
    }

    public function handle_before_delete_post($post_id)
    {
        $post = get_post($post_id);
        if (!$this->should_track_post($post_id, $post)) {
            return;
        }

        $this->enqueue_delete_event($post_id);
    }

    private function should_track_post($post_id, $post)
    {
        if (!$post || !is_object($post)) {
            return false;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return false;
        }

        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        return in_array($post->post_type, $enabled_types, true);
    }

    private function enqueue_upsert_event($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $queue = $this->get_queue();
        $event = array(
            'eventId' => uniqid('wp-upsert-' . $post_id . '-', true),
            'eventType' => 'upsert',
            'wpPostId' => intval($post_id),
            'postType' => strval($post->post_type),
            'status' => strval($post->post_status),
            'modifiedGmt' => get_post_modified_time('c', true, $post),
        );

        $queue[strval($post_id)] = $event;
        $this->save_queue($queue);
    }

    private function enqueue_delete_event($post_id)
    {
        $queue = $this->get_queue();
        $event = array(
            'eventId' => uniqid('wp-delete-' . $post_id . '-', true),
            'eventType' => 'delete',
            'wpPostId' => intval($post_id),
            'deletedAt' => gmdate('c'),
        );

        $queue[strval($post_id)] = $event;
        $this->save_queue($queue);
    }

    public function flush_sync_events()
    {
        $queue = $this->get_queue();
        if (empty($queue)) {
            return;
        }

        $auth = Kaigen_Auth::get_instance();
        $validation = $auth->validate_with_kaigen();

        if (!is_array($validation) || empty($validation['valid']) || empty($validation['project_id'])) {
            return;
        }

        $project_id = $validation['project_id'];
        $api = Kaigen_API::get_instance();
        $content = Kaigen_Content::get_instance();

        $events = array_values($queue);
        $remaining = $queue;

        for ($offset = 0; $offset < count($events); $offset += $this->batch_size) {
            $batch = array_slice($events, $offset, $this->batch_size);
            $batch_payload = array();

            foreach ($batch as $event) {
                $event_type = isset($event['eventType']) ? $event['eventType'] : 'upsert';
                $post_id = isset($event['wpPostId']) ? intval($event['wpPostId']) : 0;

                if ($post_id <= 0) {
                    continue;
                }

                if ($event_type === 'upsert') {
                    $document = $content->build_wordpress_document_v2($post_id, strval($project_id), null, home_url());
                    if (!$document) {
                        continue;
                    }
                    $event['documentV2'] = $document;
                }

                $batch_payload[] = $event;
            }

            if (empty($batch_payload)) {
                continue;
            }

            $sent = $this->send_batch_with_retry($api, $project_id, $batch_payload);
            if (!$sent) {
                // Keep unsent events in queue for next cron run.
                continue;
            }

            foreach ($batch_payload as $event) {
                $key = strval(isset($event['wpPostId']) ? intval($event['wpPostId']) : '');
                if (isset($remaining[$key])) {
                    unset($remaining[$key]);
                }
            }
        }

        $this->save_queue($remaining);
    }

    private function send_batch_with_retry($api, $project_id, $batch)
    {
        $delays = array(2, 6, 15);

        for ($attempt = 0; $attempt < count($delays); $attempt++) {
            $result = $api->send_sync_events($project_id, $batch, 'delta');
            if (!is_wp_error($result)) {
                return true;
            }

            if ($attempt < count($delays) - 1) {
                sleep($delays[$attempt]);
            }
        }

        return false;
    }

    private function get_queue()
    {
        $queue = get_option($this->queue_option_key, array());
        return is_array($queue) ? $queue : array();
    }

    private function save_queue($queue)
    {
        if (!is_array($queue)) {
            $queue = array();
        }

        // Safety cap to avoid infinite growth in case of remote outage.
        if (count($queue) > 1000) {
            $queue = array_slice($queue, -1000, null, true);
        }

        update_option($this->queue_option_key, $queue, false);
    }
}
