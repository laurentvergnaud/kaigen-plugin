<?php
/**
 * Run by WordPress when the plugin is deleted (1.9.0, ADR 0152): the copies of pages Tamiro's writes kept
 * (`_kaigen_zones_backup_<id>`) leave with it, and so does their daily purge. An update replaces the files
 * in place and never runs this.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/zones/class-kaigen-zones-copies.php';

global $wpdb;
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like(Kaigen_Zones_Copies::PREFIX) . '%'
));
foreach ((array) $ids as $meta_id) {
    delete_metadata_by_mid('post', intval($meta_id));
}
wp_clear_scheduled_hook(Kaigen_Zones_Copies::PURGE_HOOK);
