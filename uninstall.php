<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}boundary_map_entries");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}boundary_map_categories");

$legacy_tables = array(
    $wpdb->prefix . 'ach_entries',
    $wpdb->prefix . 'ach_categories',
);
foreach ($legacy_tables as $legacy_table_name) {
    $wpdb->query("DROP TABLE IF EXISTS {$legacy_table_name}");
}

delete_option('boundary_map_db_version');
delete_option('boundary_map_migrated_to_db');
delete_option('boundary_map_plugin_version');
delete_option('boundary_map_geography_selection');
delete_option('boundary_map_show_sidebar_panel');
delete_option('boundary_map_marker_tag_mode');
delete_option('ach_map_db_version');
delete_option('ach_map_migrated_to_db');
delete_option('ach_map_plugin_version');
delete_option('ach_map_geography_selection');
delete_option('ach_map_show_sidebar_panel');
delete_option('ach_map_marker_tag_mode');

delete_transient('boundary_map_entries');
delete_transient('boundary_map_categories');
delete_transient('ach_map_entries');
delete_transient('ach_map_categories');
