<?php
/**
 * AutoSEO Plugin Uninstall
 *
 * This file handles the cleanup when the AutoSEO plugin is uninstalled
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up options
$autoseo_options_to_delete = array(
    'autoseo_api_key',
    'autoseo_auto_publish',
    'autoseo_post_category',
    'autoseo_author_id',
    'autoseo_last_sync',
    'autoseo_debug_mode',
);

foreach ($autoseo_options_to_delete as $autoseo_option) {
    delete_option($autoseo_option);
}

// Clean up database tables
global $wpdb;

$autoseo_tables_to_drop = array(
    $wpdb->prefix . 'autoseo_articles',
    $wpdb->prefix . 'autoseo_settings',
);

foreach ($autoseo_tables_to_drop as $autoseo_table) {
    // Table name is safe - constructed from $wpdb->prefix + hardcoded name
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS " . esc_sql($autoseo_table));
}

// Clean up scheduled events (legacy and current hooks)
wp_clear_scheduled_hook('autoseo_sync_articles');
wp_clear_scheduled_hook('autoseo_auto_sync');
wp_clear_scheduled_hook('autoseo_publish_scheduled_article');

// Clean up any transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autoseo_%'");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_autoseo_%'");

// Clean up post meta
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_autoseo_%'");

// Clean up user meta (if any)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'autoseo_%'");

// Uninstall complete - no logging needed in production
