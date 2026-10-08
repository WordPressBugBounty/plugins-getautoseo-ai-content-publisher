<?php
/**
 * AutoSEO Admin
 * 
 * Handles admin-specific functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEO_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        // Admin-specific hooks can be added here
    }

    /**
     * Get dashboard statistics
     * Static method for template compatibility
     * 
     * @return array Dashboard statistics including counts and recent articles
     */
    public static function get_dashboard_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Get counts for different statuses
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $total_synced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $published = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'published'));
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'pending'));
        
        // Get last sync time (most recent synced_at timestamp)
        $last_sync = $wpdb->get_var("SELECT synced_at FROM {$table_name} ORDER BY synced_at DESC LIMIT 1");
        
        // Get recent articles (limit to 10)
        $recent_articles = $wpdb->get_results(
            "SELECT id, autoseo_id, title, status, synced_at, published_at 
             FROM {$table_name} 
             ORDER BY synced_at DESC 
             LIMIT 10"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'total_synced' => $total_synced,
            'published' => $published,
            'pending' => $pending,
            'last_sync' => $last_sync,
            'recent_articles' => !empty($recent_articles) ? $recent_articles : array(),
        );
    }
}

