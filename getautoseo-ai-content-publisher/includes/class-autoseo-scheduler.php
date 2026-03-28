<?php
/**
 * AutoSEO Scheduler
 * 
 * Handles scheduled tasks like automatic article syncing
 * 
 * Sync frequency is adaptive based on time since API key was set:
 * - First 10 minutes: every 1 minute
 * - 10-60 minutes: every 5 minutes  
 * - After 60 minutes: hourly
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEO_Scheduler {

    /**
     * Constructor
     */
    public function __construct() {
        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        // Schedule sync with appropriate interval
        $this->schedule_adaptive_sync();

        // Hook into the scheduled event
        add_action('autoseo_auto_sync', array($this, 'run_auto_sync'));
    }

    /**
     * Add custom cron schedules for more frequent syncing
     */
    public function add_custom_cron_schedules($schedules) {
        // Every 1 minute
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('Every Minute', 'getautoseo-ai-content-publisher')
        );
        
        // Every 5 minutes
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'getautoseo-ai-content-publisher')
        );
        
        return $schedules;
    }

    /**
     * Get the appropriate sync interval based on time since API key was set
     * 
     * @return string WordPress cron schedule name
     */
    public function get_adaptive_interval() {
        $api_key_set_time = get_option('autoseo_api_key_set_time', 0);
        
        // If no timestamp stored, use hourly (legacy behavior)
        if (empty($api_key_set_time)) {
            return 'hourly';
        }
        
        $minutes_since_setup = (time() - $api_key_set_time) / 60;
        
        if ($minutes_since_setup < 10) {
            // First 10 minutes: sync every minute
            return 'every_minute';
        } elseif ($minutes_since_setup < 60) {
            // 10-60 minutes: sync every 5 minutes
            return 'every_five_minutes';
        } else {
            // After 60 minutes: sync hourly
            return 'hourly';
        }
    }

    /**
     * Schedule sync with the appropriate adaptive interval
     */
    public function schedule_adaptive_sync() {
        $desired_interval = $this->get_adaptive_interval();
        $current_event = wp_get_scheduled_event('autoseo_auto_sync');
        
        // Check if we need to reschedule
        if ($current_event) {
            // If already scheduled with the correct interval, do nothing
            if ($current_event->schedule === $desired_interval) {
                return;
            }
            // Otherwise, clear and reschedule
            wp_clear_scheduled_hook('autoseo_auto_sync');
        }
        
        // Schedule with the appropriate interval
        wp_schedule_event(time(), $desired_interval, 'autoseo_auto_sync');
        $this->log_debug('Sync scheduled with interval: ' . $desired_interval);
    }

    /**
     * Run automatic article sync
     * 
     * This is called by WordPress cron on a scheduled basis
     */
    public function run_auto_sync() {
        // Only run if API key is configured
        $api_key = get_option('autoseo_api_key', '');
        if (empty($api_key)) {
            $this->log_debug('Auto-sync skipped: No API key configured');
            return;
        }

        // Re-evaluate and reschedule if interval should change
        $this->schedule_adaptive_sync();

        $this->log_debug('Starting automatic article sync');

        try {
            $api = new AutoSEO_API();
            $result = $api->sync_articles();

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                $this->log_debug('Auto-sync failed: ' . $error_message);
                self::store_sync_error($error_message);
            } else {
                $this->log_debug('Auto-sync completed: ' . $result['synced_count'] . ' articles synced');
                
                // Clear connection error on successful API call (individual article errors are different)
                self::clear_sync_error();
                
                // Log errors if any
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->log_debug('Sync error: ' . $error);
                    }
                }
                
                // Send completion webhook with error details for remote diagnosis
                $api->send_webhook('sync_completed', array(
                    'articles_count' => $result['synced_count'],
                    'errors_count' => count($result['errors']),
                    'errors' => array_slice($result['errors'], 0, 10), // Limit to first 10 errors to avoid payload size issues
                ));
            }
        } catch (Exception $e) {
            $this->log_debug('Auto-sync exception: ' . $e->getMessage());
            self::store_sync_error($e->getMessage());
        }
    }

    /**
     * Manually trigger sync (for testing or admin actions)
     * 
     * @param bool $force_resync Force resync all articles
     * @return array|WP_Error
     */
    public function trigger_manual_sync($force_resync = false) {
        $api = new AutoSEO_API();
        return $api->sync_articles($force_resync);
    }

    /**
     * Clear scheduled sync events
     */
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('autoseo_auto_sync');
        $this->log_debug('Scheduled sync events cleared');
    }

    /**
     * Get next scheduled sync time
     * 
     * @return string|null
     */
    public function get_next_sync_time() {
        $timestamp = wp_next_scheduled('autoseo_auto_sync');
        
        if (!$timestamp) {
            return null;
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Store a sync connection error for display in the admin UI
     */
    public static function store_sync_error($error_message) {
        if (empty(trim($error_message))) {
            return;
        }

        $error_data = array(
            'message' => $error_message,
            'timestamp' => current_time('c'),
            'friendly_message' => self::get_friendly_error_message($error_message),
        );
        update_option('autoseo_sync_error', $error_data, false);
    }

    /**
     * Clear the stored sync error (called on successful sync)
     */
    public static function clear_sync_error() {
        delete_option('autoseo_sync_error');
    }

    /**
     * Get the stored sync error, or null if none
     */
    public static function get_sync_error() {
        return get_option('autoseo_sync_error', null);
    }

    /**
     * Translate technical cURL/connection errors into actionable user-facing messages
     */
    private static function get_friendly_error_message($error_message) {
        if (strpos($error_message, 'cURL error 7') !== false || strpos($error_message, 'Failed to connect') !== false) {
            return __('Your hosting provider is blocking connections to getautoseo.com. Please contact your hosting provider and ask them to whitelist outbound connections to getautoseo.com.', 'getautoseo-ai-content-publisher');
        }
        if (strpos($error_message, 'cURL error 28') !== false || strpos($error_message, 'timed out') !== false) {
            return __('The connection to getautoseo.com timed out. This is usually a temporary issue. If it persists, contact your hosting provider.', 'getautoseo-ai-content-publisher');
        }
        if (strpos($error_message, 'cURL error 6') !== false || strpos($error_message, 'Could not resolve') !== false) {
            return __('Your server cannot resolve getautoseo.com. Please check your DNS settings or contact your hosting provider.', 'getautoseo-ai-content-publisher');
        }
        if (strpos($error_message, 'cURL error 35') !== false || strpos($error_message, 'SSL') !== false) {
            return __('There is an SSL/TLS error connecting to getautoseo.com. Please contact your hosting provider to update their SSL certificates.', 'getautoseo-ai-content-publisher');
        }
        return null;
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('autoseo_debug_mode', '1');
        if ($debug_mode === '1') {
            error_log('[AutoSEO Scheduler] ' . $message);
        }
    }
}
