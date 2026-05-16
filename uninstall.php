<?php
/**
 * Optional uninstall cleanup for Freego WP Accessibility Assistant.
 *
 * Deactivation is intentionally non-destructive. This file only removes data
 * when the administrator enabled "Delete plugin data when uninstalling".
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$delete_data = (bool) get_option('freego_wp_delete_data_on_uninstall', false);
if (!$delete_data) {
    exit;
}

global $wpdb;

$table = $wpdb->prefix . 'freego_wp_issues';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('freego_wp_db_version');
delete_option('freego_wp_aggressive_repair');
delete_option('freego_wp_target_level');
delete_option('freego_wp_delete_data_on_uninstall');
delete_site_transient('freego_wp_github_latest_release');

$meta_keys = [
    '_freego_wp_captions_url',
    '_freego_wp_transcript',
    '_freego_wp_open_format_url',
];

foreach ($meta_keys as $meta_key) {
    delete_metadata('post', 0, $meta_key, '', true);
}
