<?php
/**
 * Plugin Name: GetAutoSEO AI Tool
 * Plugin URI: https://getautoseo.com
 * Description: Automate your SEO content creation and publishing with AI-powered tools. Generate high-quality articles, optimize for search engines, and publish directly to your WordPress site.
 * Version: 1.3.71
 * Author: GetAutoSEO Team
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: getautoseo-ai-content-publisher
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AUTOSEO_VERSION', '1.3.71');
define('AUTOSEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUTOSEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AUTOSEO_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AUTOSEO_PLUGIN_FILE', __FILE__);
// Auto-detect environment: localhost for development, production for live site
$autoseo_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
$autoseo_is_localhost = strpos($autoseo_host, 'localhost') === 0 || strpos($autoseo_host, '127.0.0.1') === 0;

if ($autoseo_is_localhost) {
    // Development environment
    define('AUTOSEO_API_BASE_URL', 'http://localhost:82/api');
} else {
    // Production environment
    define('AUTOSEO_API_BASE_URL', 'https://getautoseo.com/api');
}

/**
 * Main AutoSEO Plugin Class
 */
class AutoSEO_Plugin {

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * API Key for authentication
     */
    private $api_key = '';

    /**
     * API Base URL
     */
    private $api_base_url = '';

    /**
     * When true, the content-protection filter allows post_content changes
     * through to the database. The plugin sets this before its own
     * wp_insert_post / wp_update_post calls and clears it immediately after.
     */
    private static $allow_content_update = false;

    public static function allow_content_updates() {
        self::$allow_content_update = true;
    }

    public static function disallow_content_updates() {
        self::$allow_content_update = false;
    }

    /**
     * Get single instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->set_api_credentials();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron schedules early (needed for adaptive sync)
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        add_action('init', array($this, 'init'));
        add_action('init', array($this, 'ensure_db_schema')); // Run migrations on init (needed for cron context)
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_init', array($this, 'check_plugin_upgrade'));
        add_action('admin_init', array($this, 'maybe_auto_verify_on_activation'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Setup wizard
        add_action('admin_init', array($this, 'check_setup_wizard'));
        add_action('admin_menu', array($this, 'add_setup_menu'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // LLM-friendly .md URL support
        add_action('init', array($this, 'register_md_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_md_url_request'));

        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // AJAX handlers
        add_action('wp_ajax_autoseo_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_autoseo_save_post_category', array($this, 'ajax_save_post_category'));
        add_action('wp_ajax_autoseo_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_autoseo_sync_articles', array($this, 'ajax_sync_articles'));
        add_action('wp_ajax_autoseo_publish_article', array($this, 'ajax_publish_article'));
        add_action('wp_ajax_autoseo_toggle_debug', array($this, 'ajax_toggle_debug'));
        add_action('wp_ajax_autoseo_auto_verify', array($this, 'ajax_attempt_auto_verification'));

        // AutoSEO article edit protection (content read-only, meta boxes remain editable for Rank Math etc.)
        add_action('admin_notices', array($this, 'show_autoseo_edit_notice'));
        add_action('admin_head', array($this, 'add_autoseo_article_styles'));
        add_action('admin_footer', array($this, 'add_autoseo_content_readonly_script'));
        add_filter('post_row_actions', array($this, 'modify_autoseo_article_row_actions'), 10, 2);
        add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_for_autoseo_articles'), 10, 2);

        // Protect AutoSEO post content from being overwritten by any external save
        // (admin editor, REST API, third-party plugins). Only the plugin's own
        // sync/publish/update code bypasses this via the $allow_content_update flag.
        add_filter('wp_insert_post_data', array($this, 'protect_autoseo_content_on_admin_save'), 10, 2);

        // Add SVG/path to TinyMCE valid elements so social icons display in the editor
        add_filter('tiny_mce_before_init', array($this, 'add_svg_to_tinymce_valid_elements'));

        // Prevent trashing/deleting AutoSEO articles from WP admin (WP 5.5+ short-circuit filters)
        add_filter('pre_trash_post', array($this, 'prevent_autoseo_article_trashing'), 10, 3);
        add_filter('pre_delete_post', array($this, 'prevent_autoseo_article_deleting'), 10, 3);

        // Add 'autoseo' CSS class to post container and body for AutoSEO-managed posts
        add_filter('post_class', array($this, 'add_autoseo_post_class'), 10, 3);
        add_filter('body_class', array($this, 'add_autoseo_body_class'));

        // Inject infographic image into post content
        add_filter('the_content', array($this, 'inject_infographic_image_into_content'), 10);

        // Fix Key Takeaways HTML structure (runs earliest to fix stray </div> before other filters)
        add_filter('the_content', array($this, 'fix_key_takeaways_structure'), 3);

        // Add anchor IDs to headings for TOC linking (runs early to ensure IDs exist)
        add_filter('the_content', array($this, 'add_heading_anchor_ids'), 5);

        // Allow YouTube iframes and SVG icons in post content (WordPress strips these by default)
        add_filter('wp_kses_allowed_html', array($this, 'allow_autoseo_html_elements'), 10, 2);

        // Allow display/flex CSS properties in inline styles (WordPress strips them by default)
        add_filter('safe_style_css', array($this, 'allow_additional_css_properties'));

        // Hook for when posts are published (including manual publishing in WP admin)
        add_action('transition_post_status', array($this, 'handle_post_status_transition'), 10, 3);

        // Hook for when a published post's permalink changes (user edits slug)
        add_action('post_updated', array($this, 'handle_post_permalink_change'), 10, 3);

        // Deferred rewrite rules flush (set during activation to avoid .htaccess issues on slow hosts)
        add_action('admin_init', array($this, 'maybe_flush_rewrite_rules'));
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Load admin classes
        require_once AUTOSEO_PLUGIN_DIR . 'includes/class-autoseo-admin.php';
        require_once AUTOSEO_PLUGIN_DIR . 'includes/class-autoseo-api.php';
        require_once AUTOSEO_PLUGIN_DIR . 'includes/class-autoseo-publisher.php';
        require_once AUTOSEO_PLUGIN_DIR . 'includes/class-autoseo-scheduler.php';
        require_once AUTOSEO_PLUGIN_DIR . 'includes/class-autoseo-notifications.php';

        // Initialize classes
        new AutoSEO_Admin();
        new AutoSEO_API();
        new AutoSEO_Publisher();
        new AutoSEO_Scheduler();
        new AutoSEO_Notifications();
    }

    /**
     * Set API credentials from settings
     */
    private function set_api_credentials() {
        $this->api_key = get_option('autoseo_api_key', '');
        $this->api_base_url = AUTOSEO_API_BASE_URL;
    }

    /**
     * Add custom cron schedules for adaptive sync
     * 
     * Sync frequency is adaptive based on time since API key was set:
     * - First 10 minutes: every 1 minute
     * - 10-60 minutes: every 5 minutes  
     * - After 60 minutes: hourly
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
     * Initialize plugin
     */
    public function init() {
        // Text domain is automatically loaded by WordPress.org for plugins hosted there
        // No need to call load_plugin_textdomain() as of WordPress 4.6+

        // Check if user can manage options (admin)
        if (current_user_can('manage_options')) {
            // Add settings link to plugins page
            add_filter('plugin_action_links_' . AUTOSEO_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        }
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings with sanitization callbacks
        register_setting('autoseo_settings', 'autoseo_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('autoseo_settings', 'autoseo_post_category', array(
            'type' => 'string',
            'sanitize_callback' => 'absint',
        ));
        register_setting('autoseo_settings', 'autoseo_author_id', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ));
        register_setting('autoseo_settings', 'autoseo_debug_mode', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // Add settings sections
        add_settings_section(
            'autoseo_api_settings',
            __('API Configuration', 'getautoseo-ai-content-publisher'),
            array($this, 'api_settings_section_callback'),
            'autoseo_settings'
        );

        add_settings_section(
            'autoseo_publishing_settings',
            __('Publishing Settings', 'getautoseo-ai-content-publisher'),
            array($this, 'publishing_settings_section_callback'),
            'autoseo_settings'
        );

        add_settings_section(
            'autoseo_debug_settings',
            __('Debug Settings', 'getautoseo-ai-content-publisher'),
            array($this, 'debug_settings_section_callback'),
            'autoseo_settings'
        );

        // Add settings fields
        add_settings_field(
            'autoseo_api_key',
            __('API Key', 'getautoseo-ai-content-publisher'),
            array($this, 'api_key_field_callback'),
            'autoseo_settings',
            'autoseo_api_settings'
        );




        add_settings_field(
            'autoseo_post_category',
            __('Default Category', 'getautoseo-ai-content-publisher'),
            array($this, 'post_category_field_callback'),
            'autoseo_settings',
            'autoseo_publishing_settings'
        );

        add_settings_field(
            'autoseo_author_id',
            __('Default Author', 'getautoseo-ai-content-publisher'),
            array($this, 'author_field_callback'),
            'autoseo_settings',
            'autoseo_publishing_settings'
        );

        add_settings_field(
            'autoseo_debug_mode',
            __('Debug Mode', 'getautoseo-ai-content-publisher'),
            array($this, 'debug_mode_field_callback'),
            'autoseo_settings',
            'autoseo_debug_settings'
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('AutoSEO', 'getautoseo-ai-content-publisher'),
            __('AutoSEO', 'getautoseo-ai-content-publisher'),
            'manage_options',
            'autoseo',
            array($this, 'admin_page'),
            'dashicons-admin-site-alt3',
            30
        );

        add_submenu_page(
            'autoseo',
            __('Dashboard', 'getautoseo-ai-content-publisher'),
            __('Dashboard', 'getautoseo-ai-content-publisher'),
            'manage_options',
            'autoseo',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'autoseo',
            __('Settings', 'getautoseo-ai-content-publisher'),
            __('Settings', 'getautoseo-ai-content-publisher'),
            'manage_options',
            'autoseo-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Load on AutoSEO admin pages OR on posts list/edit pages (for article marking)
        $load_on_pages = array(
            'edit.php',           // Posts list page
            'post.php',           // Single post edit page
            'post-new.php'        // New post page
        );
        
        $is_autoseo_page = (strpos($hook, 'autoseo') !== false);
        $is_posts_page = in_array($hook, $load_on_pages, true);
        
        if (!$is_autoseo_page && !$is_posts_page) {
            return;
        }

        wp_enqueue_script(
            'getautoseo-admin',
            AUTOSEO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            AUTOSEO_VERSION,
            true
        );

        wp_enqueue_style(
            'getautoseo-admin',
            AUTOSEO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AUTOSEO_VERSION
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script('getautoseo-admin', 'autoseo_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('autoseo_ajax_nonce'),
            'debug_mode' => get_option('autoseo_debug_mode', '1'), // Default to enabled
            'strings' => array(
                'connecting' => __('Connecting...', 'getautoseo-ai-content-publisher'),
                'connected' => __('Connected!', 'getautoseo-ai-content-publisher'),
                'error' => __('Error occurred', 'getautoseo-ai-content-publisher'),
                'syncing' => __('Syncing articles...', 'getautoseo-ai-content-publisher'),
                'publishing' => __('Publishing article...', 'getautoseo-ai-content-publisher'),
            )
        ));
        
        // Add inline script for marking AutoSEO articles on posts list page
        if ($hook === 'edit.php') {
            $inline_js = '
                jQuery(document).ready(function($) {
                    // Mark AutoSEO articles in the posts list
                    $(".wp-list-table tbody tr").each(function() {
                        var $row = $(this);
                        var postId = $row.attr("id");
                        if (postId) {
                            postId = postId.replace("post-", "");
                            
                            // Check if this post has AutoSEO meta (we add this via PHP)
                            if ($row.find(".autoseo-managed").length > 0) {
                                $row.attr("data-autoseo-article", "true");
                                
                                // Disable edit link
                                $row.find(".row-actions .edit a").addClass("autoseo-edit-disabled").click(function(e) {
                                    e.preventDefault();
                                    alert("This article is managed by AutoSEO. Please edit it on your AutoSEO dashboard.");
                                    return false;
                                });
                            }
                        }
                    });
                });
            ';
            wp_add_inline_script('getautoseo-admin', $inline_js);
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        // Check if current post is an AutoSEO article
        global $post;
        
        $is_autoseo_article = false;
        if ($post && isset($post->ID)) {
            $autoseo_article_id = get_post_meta($post->ID, '_autoseo_article_id', true);
            $is_autoseo_article = !empty($autoseo_article_id);
        }
        
        // Always load frontend CSS for AutoSEO articles (for infographic support)
        if ($is_autoseo_article || is_singular('post')) {
            wp_enqueue_style(
                'getautoseo-frontend',
                AUTOSEO_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                AUTOSEO_VERSION
            );
        }

        // Only load AutoSEO specific scripts if shortcode is present or on specific pages
        if (!has_shortcode($post->post_content ?? '', 'getautoseo') &&
            !is_page('autoseo-dashboard')) {
            return;
        }

        wp_enqueue_script(
            'getautoseo-frontend',
            AUTOSEO_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            AUTOSEO_VERSION,
            true
        );
    }


    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=autoseo-settings') . '">' . __('Settings', 'getautoseo-ai-content-publisher') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables if needed
        $this->create_tables();

        // Set default options
        add_option('autoseo_post_category', '1');
        add_option('autoseo_author_id', get_current_user_id());
        add_option('autoseo_debug_mode', '1'); // Enable debug mode by default

        // If no API key, schedule auto-verification attempt on next admin page load
        // We can't do HTTP requests during activation, so we defer to admin_init
        if (empty(get_option('autoseo_api_key'))) {
            update_option('autoseo_pending_auto_verification', '1');
        }

        // Defer rewrite rules flush to next admin page load to avoid .htaccess
        // corruption on slow/managed hosts (e.g. GoDaddy) where direct flush
        // during activation can timeout or conflict with hosting-level .htaccess rules
        set_transient('autoseo_flush_rewrite_rules', '1', 300);
    }

    /**
     * Flush rewrite rules on next admin page load (deferred from activation).
     * This avoids writing to .htaccess during the activation hook, which can
     * fail or produce corrupt output on managed/slow hosting environments.
     */
    public function maybe_flush_rewrite_rules() {
        if (get_transient('autoseo_flush_rewrite_rules')) {
            delete_transient('autoseo_flush_rewrite_rules');
            flush_rewrite_rules();
        }
    }

    /**
     * Ensure database schema is up to date (runs on every init for cron compatibility)
     * Uses transient to avoid running migrations on every request
     */
    public function ensure_db_schema() {
        // Only run once per hour (transient-based throttling)
        $transient_key = 'autoseo_schema_check_' . AUTOSEO_VERSION;
        if (get_transient($transient_key)) {
            return;
        }
        
        // Run all idempotent migrations (they check if column exists first)
        $this->add_featured_image_column();
        $this->add_hero_image_column();
        $this->add_infographic_column();
        $this->add_infographic_image_column();
        $this->add_meta_description_columns();
        $this->add_wordpress_tags_column();
        $this->add_content_markdown_column();
        $this->add_intended_published_at_column();
        $this->add_faq_schema_column();
        $this->add_hero_image_alt_column();
        $this->add_previous_article_ids_column();
        $this->add_language_column();
        $this->add_settings_created_at_column();
        
        // Set transient to prevent running again for 1 hour
        set_transient($transient_key, '1', HOUR_IN_SECONDS);
    }

    /**
     * Check if plugin needs upgrade (runs database migrations on plugin update)
     */
    public function check_plugin_upgrade() {
        $installed_version = get_option('autoseo_db_version', '1.0.0');
        
        // ALWAYS run idempotent migrations (they check if column exists first)
        // This ensures columns are added even if version was updated before migration code existed
        $this->add_featured_image_column();
        $this->add_hero_image_column();
        $this->add_infographic_column();
        $this->add_infographic_image_column();
        $this->add_meta_description_columns();
        $this->add_wordpress_tags_column();
        $this->add_content_markdown_column();
        $this->add_intended_published_at_column();
        $this->add_faq_schema_column();
        $this->add_hero_image_alt_column();
        $this->add_previous_article_ids_column();
        $this->add_language_column();
        $this->add_settings_created_at_column();
        $this->maybe_convert_tables_to_utf8mb4();
        
        // One-time duplicate cleanup (added in 1.3.43)
        if (version_compare($installed_version, '1.3.43', '<')) {
            $this->cleanup_duplicate_posts();
        }

        // If installed version is less than current, log the upgrade
        if (version_compare($installed_version, AUTOSEO_VERSION, '<')) {
            // Update the stored version
            update_option('autoseo_db_version', AUTOSEO_VERSION);
            
            // Log the upgrade
            if (get_option('autoseo_debug_mode', '1') === '1') {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Plugin upgraded from ' . $installed_version . ' to ' . AUTOSEO_VERSION);
            }
        }
    }

    /**
     * Remove duplicate WordPress posts created by AutoSEO.
     * Groups posts by _autoseo_article_id meta, keeps the newest, trashes the rest.
     */
    private function cleanup_duplicate_posts() {
        global $wpdb, $autoseo_allow_trash;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $duplicates = $wpdb->get_results(
            "SELECT meta_value AS autoseo_id, COUNT(*) AS cnt, GROUP_CONCAT(post_id ORDER BY post_id ASC) AS post_ids
             FROM {$wpdb->postmeta}
             INNER JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
             WHERE meta_key = '_autoseo_article_id'
               AND {$wpdb->posts}.post_status NOT IN ('trash', 'auto-draft')
             GROUP BY meta_value
             HAVING cnt > 1"
        );

        if (empty($duplicates)) {
            return;
        }

        $trashed_count = 0;
        $table_name = $wpdb->prefix . 'autoseo_articles';

        foreach ($duplicates as $dup) {
            $post_ids = array_map('intval', explode(',', $dup->post_ids));
            $keep_id = array_pop($post_ids); // Keep the newest (highest ID)

            $all_trashed = true;
            $autoseo_allow_trash = true;
            foreach ($post_ids as $trash_id) {
                $result = wp_trash_post($trash_id);
                if ($result) {
                    $trashed_count++;
                    $this->log_debug(sprintf(
                        'Duplicate cleanup: trashed post %d (duplicate of autoseo_id %s, keeping post %d)',
                        $trash_id, $dup->autoseo_id, $keep_id
                    ));
                } else {
                    $all_trashed = false;
                    $this->log_debug(sprintf(
                        'Duplicate cleanup: FAILED to trash post %d (autoseo_id %s) — skipping sync table update for this group',
                        $trash_id, $dup->autoseo_id
                    ));
                }
            }
            $autoseo_allow_trash = false;

            // Only update sync table if all duplicates were successfully trashed
            if ($all_trashed) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $table_name,
                    array('post_id' => $keep_id, 'status' => 'published'),
                    array('autoseo_id' => $dup->autoseo_id),
                    array('%d', '%s'),
                    array('%s')
                );
            }
        }

        if ($trashed_count > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[AutoSEO] Duplicate cleanup: trashed %d duplicate posts across %d articles',
                $trashed_count,
                count($duplicates)
            ));
        }
    }

    /**
     * Attempt auto-verification on first admin page load after activation
     * This runs once after plugin activation to try keyless verification
     */
    public function maybe_auto_verify_on_activation() {
        // Check if we have a pending auto-verification
        if (get_option('autoseo_pending_auto_verification') !== '1') {
            return;
        }
        
        // Already have an API key? No need to verify
        if (!empty(get_option('autoseo_api_key'))) {
            delete_option('autoseo_pending_auto_verification');
            return;
        }
        
        // Clear the flag immediately to prevent multiple attempts
        delete_option('autoseo_pending_auto_verification');
        
        $this->log_debug('Attempting auto-verification on first load after activation');
        
        // Attempt auto-verification
        $result = $this->attempt_auto_verification();
        
        if (!is_wp_error($result) && isset($result['success']) && $result['success']) {
            // Success! API key was stored by attempt_auto_verification()
            $this->log_debug('Auto-verification successful on activation!');
            
            // Trigger initial article sync
            $api = new AutoSEO_API();
            $sync_result = $api->sync_articles();
            
            if (!is_wp_error($sync_result)) {
                $this->log_debug('Initial sync completed: ' . ($sync_result['synced_count'] ?? 0) . ' articles');
            }
            
            // Redirect to dashboard with success message
            if (!headers_sent()) {
                wp_safe_redirect(admin_url('admin.php?page=autoseo&auto_verified=1'));
                exit;
            }
        } else {
            // Failed - show the setup wizard
            $this->log_debug('Auto-verification failed on activation: ' . (is_wp_error($result) ? $result->get_error_message() : 'Unknown error'));
            update_option('autoseo_show_setup_wizard', '1');
        }
    }

    /**
     * Check if setup wizard should be shown
     */
    public function check_setup_wizard() {
        if (get_option('autoseo_show_setup_wizard') === '1' && empty(get_option('autoseo_api_key'))) {
            add_action('admin_notices', array($this, 'setup_wizard_notice'));
        }
    }

    /**
     * Add setup menu page
     */
    public function add_setup_menu() {
        if (get_option('autoseo_show_setup_wizard') === '1' && empty(get_option('autoseo_api_key'))) {
            add_menu_page(
                __('AutoSEO Setup', 'getautoseo-ai-content-publisher'),
                __('AutoSEO Setup', 'getautoseo-ai-content-publisher'),
                'manage_options',
                'autoseo-setup',
                array($this, 'setup_page'),
                'dashicons-admin-tools',
                2
            );
        }
    }

    /**
     * Setup wizard notice
     */
    public function setup_wizard_notice() {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page === 'autoseo-setup') {
            return; // Don't show notice on setup page itself
        }
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e('AutoSEO Setup Required', 'getautoseo-ai-content-publisher'); ?></strong><br>
                <?php esc_html_e('Welcome to AutoSEO! Please complete the setup by entering your API key.', 'getautoseo-ai-content-publisher'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=autoseo-setup')); ?>" class="button button-primary" style="margin-left: 10px;">
                    <?php esc_html_e('Complete Setup', 'getautoseo-ai-content-publisher'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Setup page content
     */
    public function setup_page() {
        $setup_complete = false;
        $sync_result = null;
        $synced_count = 0;
        $auto_verify_result = null;
        $show_manual_form = true; // Default to showing manual form
        
        // Check if auto-verification was requested via AJAX (handled separately)
        // Or if manual API key was submitted
        if (isset($_POST['autoseo_api_key']) && isset($_POST['autoseo_setup_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['autoseo_setup_nonce'])), 'autoseo_setup')) {
            $api_key = sanitize_text_field(wp_unslash($_POST['autoseo_api_key']));
            if (!empty($api_key)) {
                update_option('autoseo_api_key', $api_key);
                
                // Track when API key was first set for adaptive sync scheduling
                // Only set if not already set (don't reset on API key change)
                if (!get_option('autoseo_api_key_set_time')) {
                    update_option('autoseo_api_key_set_time', time());
                    
                    // Reschedule sync with aggressive interval for new setup
                    wp_clear_scheduled_hook('autoseo_auto_sync');
                    wp_schedule_event(time(), 'every_minute', 'autoseo_auto_sync');
                }
                
                delete_option('autoseo_show_setup_wizard');
                $setup_complete = true;

                // Automatically sync articles after setup
                $api = new AutoSEO_API();
                $sync_result = $api->sync_articles();
                
                if (!is_wp_error($sync_result) && isset($sync_result['synced_count'])) {
                    $synced_count = $sync_result['synced_count'];
                }
            }
        }

        if ($setup_complete) {
            // Show success confirmation
            ?>
            <div class="wrap">
                <div style="max-width: 600px; margin: 50px auto; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                            <svg style="width: 48px; height: 48px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h1 style="color: #047857; font-size: 2.2em; margin-bottom: 10px;">
                            <?php esc_html_e('Setup Complete!', 'getautoseo-ai-content-publisher'); ?>
                        </h1>
                        <p style="color: #6b7280; font-size: 1.1em; margin-bottom: 30px;">
                            <?php esc_html_e('Your AutoSEO plugin is now configured and ready to use.', 'getautoseo-ai-content-publisher'); ?>
                        </p>
                    </div>

                    <?php if (!is_wp_error($sync_result) && $synced_count > 0): ?>
                    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #047857; font-size: 18px;">
                            🎉 <?php esc_html_e('First Sync Complete!', 'getautoseo-ai-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #374151; font-size: 16px;">
                            <?php
                            /* translators: %d: number of articles synced */
                            printf(esc_html__('Successfully synced <strong>%d article(s)</strong> from your AutoSEO account!', 'getautoseo-ai-content-publisher'), (int) $synced_count);
                            ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #374151; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your dashboard to view and manage synced articles', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Articles are ready to be published to your site', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings and preferences', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Articles will sync automatically going forward', 'getautoseo-ai-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php elseif (!is_wp_error($sync_result) && $synced_count === 0): ?>
                    <div style="background: #fef3c7; border: 2px solid #fbbf24; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #92400e; font-size: 18px;">
                            ℹ️ <?php esc_html_e('No Articles Found', 'getautoseo-ai-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #78350f; font-size: 16px;">
                            <?php esc_html_e('Your AutoSEO account doesn\'t have any articles yet.', 'getautoseo-ai-content-publisher'); ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #78350f; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your AutoSEO dashboard to create your first article', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Once created, articles will automatically sync to WordPress', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings in the meantime', 'getautoseo-ai-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php elseif (is_wp_error($sync_result)): ?>
                    <div style="background: #fef2f2; border: 2px solid #fca5a5; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #991b1b; font-size: 18px;">
                            ⚠️ <?php esc_html_e('Sync Issue', 'getautoseo-ai-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #7f1d1d;">
                            <?php echo esc_html($sync_result->get_error_message()); ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #7f1d1d; line-height: 1.8;">
                            <li><?php esc_html_e('Check your API key is correct in Settings', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Try syncing manually from your dashboard', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Contact support if the issue persists', 'getautoseo-ai-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #047857; font-size: 18px;">
                            ✅ <?php esc_html_e('What\'s Next?', 'getautoseo-ai-content-publisher'); ?>
                        </h3>
                        <ul style="margin: 0; padding-left: 20px; color: #374151; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your AutoSEO dashboard to manage articles', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Sync articles from your AutoSEO account', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings and preferences', 'getautoseo-ai-content-publisher'); ?></li>
                            <li><?php esc_html_e('Start publishing SEO-optimized content automatically', 'getautoseo-ai-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <label for="autoseo-setup-category-manual" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 15px;">
                            <?php esc_html_e('This is the category your articles will be published in:', 'getautoseo-ai-content-publisher'); ?>
                        </label>
                        <?php wp_dropdown_categories(array(
                            'name' => 'autoseo_setup_category_manual',
                            'id' => 'autoseo-setup-category-manual',
                            'selected' => get_option('autoseo_post_category', '1'),
                            'hide_empty' => false,
                            'show_option_none' => __('Select Category', 'getautoseo-ai-content-publisher'),
                            'class' => 'autoseo-category-select',
                        )); ?>
                        <p style="margin: 6px 0 0; color: #9ca3af; font-size: 13px;">
                            <?php esc_html_e('You can change this later in Settings.', 'getautoseo-ai-content-publisher'); ?>
                        </p>
                    </div>

                    <div style="text-align: center;">
                        <button type="button" id="autoseo-manual-continue-btn" onclick="autoseoSaveCategoryAndContinue('manual')" 
                            style="display: inline-block; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; padding: 14px 32px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s;"
                            onmouseover="this.style.transform='translateY(-2px)'"
                            onmouseout="this.style.transform='translateY(0)'">
                            🎯 <?php esc_html_e('Go to AutoSEO Dashboard', 'getautoseo-ai-content-publisher'); ?>
                        </button>
                    </div>

                    <div style="text-align: center; margin-top: 20px;">
                        <p style="color: #9ca3af; font-size: 14px;">
                            <?php esc_html_e('Need help? Contact AutoSEO support for assistance.', 'getautoseo-ai-content-publisher'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <style>
                .autoseo-category-select { width: 100%; padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 15px; background: #fff; cursor: pointer; }
                .autoseo-category-select:focus { border-color: #3b82f6; outline: none; }
            </style>
            <script>
            function autoseoSaveCategoryAndContinue(variant) {
                var select = document.getElementById('autoseo-setup-category-manual');
                var btn = document.getElementById('autoseo-manual-continue-btn');
                var categoryId = select ? select.value : '0';

                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.textContent = '<?php echo esc_js(__('Saving...', 'getautoseo-ai-content-publisher')); ?>';

                if (categoryId && categoryId !== '-1' && categoryId !== '0') {
                    var formData = new FormData();
                    formData.append('action', 'autoseo_save_post_category');
                    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('autoseo_ajax_nonce')); ?>');
                    formData.append('category_id', categoryId);

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }).then(function() {
                        window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
                    }).catch(function() {
                        window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
                    });
                } else {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
                }
            }
            </script>
            <?php
            return;
        }

        // Show setup form with auto-verification
        $site_url = site_url();
        $is_localhost = (strpos($site_url, 'localhost') !== false || strpos($site_url, '127.0.0.1') !== false);
        ?>
        <div class="wrap">
            <div style="max-width: 600px; margin: 50px auto; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #1e40af; font-size: 2.5em; margin-bottom: 10px;">
                        🚀 <?php esc_html_e('Welcome to AutoSEO', 'getautoseo-ai-content-publisher'); ?>
                    </h1>
                    <p style="color: #6b7280; font-size: 1.1em;">
                        <?php esc_html_e('Let\'s get you set up with automated article publishing!', 'getautoseo-ai-content-publisher'); ?>
                    </p>
                </div>

                <?php if (!$is_localhost): ?>
                <!-- Auto-Verification Section -->
                <div id="autoseo-auto-verify-section">
                    <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 2px solid #3b82f6; border-radius: 12px; padding: 24px; margin-bottom: 25px; text-align: center;">
                        <div id="auto-verify-loading" style="display: block;">
                            <div style="margin-bottom: 16px;">
                                <svg style="width: 48px; height: 48px; margin: 0 auto; animation: spin 1s linear infinite;" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #1e40af; font-size: 18px;">
                                <?php esc_html_e('Connecting to AutoSEO...', 'getautoseo-ai-content-publisher'); ?>
                            </h3>
                            <p style="margin: 0; color: #3b82f6; font-size: 14px;">
                                <?php esc_html_e('We\'re trying to verify your plugin automatically. This may take a few seconds.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </div>
                        
                        <div id="auto-verify-success" style="display: none;">
                            <div style="width: 64px; height: 64px; background: #10b981; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                                <svg style="width: 36px; height: 36px; color: white;" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #047857; font-size: 20px;">
                                <?php esc_html_e('Connected Successfully!', 'getautoseo-ai-content-publisher'); ?>
                            </h3>
                            <p id="auto-verify-site-name" style="margin: 0 0 16px 0; color: #059669; font-size: 14px;"></p>

                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-top: 16px; text-align: left;">
                                <label for="autoseo-setup-category" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 14px;">
                                    <?php esc_html_e('This is the category your articles will be published in:', 'getautoseo-ai-content-publisher'); ?>
                                </label>
                                <?php wp_dropdown_categories(array(
                                    'name' => 'autoseo_setup_category',
                                    'id' => 'autoseo-setup-category',
                                    'selected' => get_option('autoseo_post_category', '1'),
                                    'hide_empty' => false,
                                    'show_option_none' => __('Select Category', 'getautoseo-ai-content-publisher'),
                                    'class' => 'autoseo-category-select',
                                )); ?>
                                <p style="margin: 6px 0 0; color: #9ca3af; font-size: 12px;">
                                    <?php esc_html_e('You can change this later in Settings.', 'getautoseo-ai-content-publisher'); ?>
                                </p>
                            </div>

                            <button type="button" id="autoseo-setup-continue-btn" onclick="autoseoSaveCategoryAndContinue()" 
                                style="margin-top: 16px; width: 100%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                                <?php esc_html_e('Continue to Dashboard →', 'getautoseo-ai-content-publisher'); ?>
                            </button>
                        </div>
                        
                        <div id="auto-verify-failed" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <svg style="width: 48px; height: 48px; color: #f59e0b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #92400e; font-size: 16px;">
                                <?php esc_html_e('Auto-Connect Not Available', 'getautoseo-ai-content-publisher'); ?>
                            </h3>
                            <p id="auto-verify-error" style="margin: 0 0 12px 0; color: #b45309; font-size: 14px;"></p>
                            <p style="margin: 0; color: #6b7280; font-size: 13px;">
                                <?php esc_html_e('No problem! Just enter your API key below.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Manual API Key Form (shown if auto-verify fails or for localhost) -->
                <div id="autoseo-manual-form" style="<?php echo $is_localhost ? 'display: block;' : 'display: none;'; ?>">
                    <form method="post" action="">
                        <?php wp_nonce_field('autoseo_setup', 'autoseo_setup_nonce'); ?>

                        <div style="margin-bottom: 25px;">
                            <label for="autoseo_api_key" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">
                                <?php esc_html_e('Your AutoSEO API Key', 'getautoseo-ai-content-publisher'); ?>
                            </label>
                            <input type="text"
                                   id="autoseo_api_key"
                                   name="autoseo_api_key"
                                   value="<?php echo esc_attr(get_option('autoseo_api_key', '')); ?>"
                                   placeholder="Enter your API key here..."
                                   style="width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 16px; transition: border-color 0.2s;"
                                   onfocus="this.style.borderColor='#3b82f6'"
                                   onblur="this.style.borderColor='#e5e7eb'"
                                   required>
                            <p style="margin-top: 8px; color: #6b7280; font-size: 14px;">
                                <?php esc_html_e('You can find your API key in your AutoSEO dashboard under Integrations.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </div>

                        <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 25px;">
                            <h3 style="margin: 0 0 8px 0; color: #0c4a6e; font-size: 16px;">
                                ✅ <?php esc_html_e('What\'s Already Configured', 'getautoseo-ai-content-publisher'); ?>
                            </h3>
                            <ul style="margin: 0; padding-left: 20px; color: #374151;">
                                <li><?php esc_html_e('Articles will be published automatically when synced', 'getautoseo-ai-content-publisher'); ?></li>
                                <li><?php esc_html_e('Plugin verification is ready to use', 'getautoseo-ai-content-publisher'); ?></li>
                                <li><?php esc_html_e('Default category and author settings are configured', 'getautoseo-ai-content-publisher'); ?></li>
                            </ul>
                        </div>

                        <button type="submit"
                                style="width: 100%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; padding: 14px 24px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                            🎯 <?php esc_html_e('Complete Setup & Start Publishing', 'getautoseo-ai-content-publisher'); ?>
                        </button>
                    </form>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <p style="color: #9ca3af; font-size: 14px;">
                        <?php esc_html_e('Need help? Visit our documentation or contact support.', 'getautoseo-ai-content-publisher'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <style>
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            .wrap h1 {
                font-size: 2.5em !important;
                margin-bottom: 10px !important;
            }
            .notice-info {
                border-left-color: #3b82f6 !important;
            }
            .autoseo-category-select {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e5e7eb;
                border-radius: 6px;
                font-size: 15px;
                background: #fff;
                cursor: pointer;
            }
            .autoseo-category-select:focus {
                border-color: #3b82f6;
                outline: none;
            }
        </style>

        <script>
        function autoseoSaveCategoryAndContinue(variant) {
            var selectId = variant === 'manual' ? 'autoseo-setup-category-manual' : 'autoseo-setup-category';
            var btnId = variant === 'manual' ? 'autoseo-manual-continue-btn' : 'autoseo-setup-continue-btn';
            var select = document.getElementById(selectId);
            var btn = document.getElementById(btnId);
            var categoryId = select ? select.value : '0';

            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.textContent = '<?php echo esc_js(__('Saving...', 'getautoseo-ai-content-publisher')); ?>';

            if (categoryId && categoryId !== '-1' && categoryId !== '0') {
                var formData = new FormData();
                formData.append('action', 'autoseo_save_post_category');
                formData.append('nonce', '<?php echo esc_js(wp_create_nonce('autoseo_ajax_nonce')); ?>');
                formData.append('category_id', categoryId);

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).then(function() {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
                }).catch(function() {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
                });
            } else {
                window.location.href = '<?php echo esc_url(admin_url('admin.php?page=autoseo&setup=complete')); ?>';
            }
        }
        </script>
        
        <?php if (!$is_localhost): ?>
        <script>
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                attemptAutoVerification();
            });
            
            function attemptAutoVerification() {
                var loadingEl = document.getElementById('auto-verify-loading');
                var successEl = document.getElementById('auto-verify-success');
                var failedEl = document.getElementById('auto-verify-failed');
                var errorMsgEl = document.getElementById('auto-verify-error');
                var siteNameEl = document.getElementById('auto-verify-site-name');
                var manualFormEl = document.getElementById('autoseo-manual-form');
                var autoVerifySection = document.getElementById('autoseo-auto-verify-section');
                
                // Make the AJAX request
                var formData = new FormData();
                formData.append('action', 'autoseo_auto_verify');
                formData.append('nonce', '<?php echo esc_js(wp_create_nonce('autoseo_ajax_nonce')); ?>');
                
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    
                    if (data.success) {
                        successEl.style.display = 'block';
                        if (data.data && data.data.site_name) {
                            siteNameEl.textContent = 'Connected to: ' + data.data.site_name;
                        }
                    } else {
                        // Failed - show manual form
                        failedEl.style.display = 'block';
                        if (data.data && data.data.message) {
                            errorMsgEl.textContent = data.data.message;
                        } else {
                            errorMsgEl.textContent = 'Could not connect automatically.';
                        }
                        
                        // Show manual form after a short delay
                        setTimeout(function() {
                            manualFormEl.style.display = 'block';
                        }, 500);
                    }
                })
                .catch(function(error) {
                    console.error('Auto-verification error:', error);
                    loadingEl.style.display = 'none';
                    failedEl.style.display = 'block';
                    errorMsgEl.textContent = 'Network error. Please enter your API key manually.';
                    manualFormEl.style.display = 'block';
                });
            }
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear any scheduled events (both legacy and current hooks)
        wp_clear_scheduled_hook('autoseo_sync_articles');
        wp_clear_scheduled_hook('autoseo_auto_sync');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Articles sync table
        $table_name = $wpdb->prefix . 'autoseo_articles';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            autoseo_id varchar(100) NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            excerpt text,
            keywords text,
            meta_description varchar(320) DEFAULT NULL,
            meta_keywords varchar(500) DEFAULT NULL,
            wordpress_tags varchar(500) DEFAULT NULL,
            featured_image_url text,
            hero_image_url text,
            hero_image_alt text,
            infographic_html longtext,
            status varchar(20) DEFAULT 'pending',
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            published_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY autoseo_id (autoseo_id),
            KEY post_id (post_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Add featured_image_url column if it doesn't exist (for existing installations)
        $this->add_featured_image_column();
        
        // Add hero_image_url and infographic_html columns if they don't exist (for existing installations)
        $this->add_hero_image_column();
        $this->add_infographic_column();
        $this->add_infographic_image_column();
        
        // Add meta_description, meta_keywords, and wordpress_tags columns if they don't exist (for existing installations)
        $this->add_meta_description_columns();
        $this->add_wordpress_tags_column();
        
        // Add content_markdown column for LLM-friendly .md URLs
        $this->add_content_markdown_column();
        
        // Add intended_published_at column for correct publication dates
        $this->add_intended_published_at_column();
        
        // Add faq_schema column for FAQ structured data
        $this->add_faq_schema_column();
        
        // Add hero_image_alt column for image alt text
        $this->add_hero_image_alt_column();

        // Add previous_article_ids column for feedback rewrite version tracking
        $this->add_previous_article_ids_column();

        // Add language column for WPML integration
        $this->add_language_column();

        // Settings table for plugin-specific settings
        $settings_table = $wpdb->prefix . 'autoseo_settings';

        $settings_sql = "CREATE TABLE $settings_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        dbDelta($settings_sql);
    }

    /**
     * Add featured_image_url column to existing installations
     */
    private function add_featured_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'featured_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN featured_image_url TEXT AFTER keywords"
            );
        }
    }

    /**
     * Add hero_image_url column to existing installations
     */
    private function add_hero_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'hero_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column after featured_image_url
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN hero_image_url TEXT AFTER featured_image_url"
            );
        }
    }

    /**
     * Add infographic_html column to existing installations
     */
    private function add_infographic_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'infographic_html'
        ));

        if (empty($column_exists)) {
            // Add the column after hero_image_url
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN infographic_html LONGTEXT AFTER hero_image_url"
            );
        }
    }

    /**
     * Add infographic_image_url column to existing installations
     */
    private function add_infographic_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'infographic_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column after infographic_html
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN infographic_image_url TEXT AFTER infographic_html"
            );
        }
    }

    /**
     * Add meta_description and meta_keywords columns to existing installations
     */
    private function add_meta_description_columns() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if meta_description column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $meta_desc_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'meta_description'
        ));

        if (empty($meta_desc_exists)) {
            // Add the meta_description column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN meta_description VARCHAR(320) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add meta_description column: ' . $wpdb->last_error);
            }
        }

        // Check if meta_keywords column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $meta_keywords_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'meta_keywords'
        ));

        if (empty($meta_keywords_exists)) {
            // Add the meta_keywords column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN meta_keywords VARCHAR(500) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add meta_keywords column: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Add wordpress_tags column to existing installations
     */
    private function add_wordpress_tags_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if wordpress_tags column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'wordpress_tags'
        ));

        if (empty($column_exists)) {
            // Add the wordpress_tags column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN wordpress_tags VARCHAR(500) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add wordpress_tags column: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Add content_markdown column to existing installations
     * This stores the original markdown content for LLM-friendly .md URLs
     */
    private function add_content_markdown_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if content_markdown column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'content_markdown'
        ));

        if (empty($column_exists)) {
            // Add the content_markdown column (LONGTEXT to match content column)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN content_markdown LONGTEXT"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add content_markdown column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added content_markdown column for LLM-friendly .md URLs');
            }
        }
    }

    /**
     * Add intended_published_at column to existing installations
     * This column stores the publication date from AutoSEO so WordPress posts
     * can be created with the correct date instead of the sync time
     */
    private function add_intended_published_at_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Check if intended_published_at column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'intended_published_at'
        ));

        if (empty($column_exists)) {
            // Add the intended_published_at column (DATETIME to store the intended publication date)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN intended_published_at DATETIME DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add intended_published_at column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added intended_published_at column for correct publication dates');
            }
        }
    }

    /**
     * Add faq_schema column to existing installations.
     * Stores FAQPage structured data (JSON) for JSON-LD output.
     */
    private function add_faq_schema_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'faq_schema'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN faq_schema LONGTEXT DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add faq_schema column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added faq_schema column for FAQ structured data');
            }
        }
    }

    /**
     * Add hero_image_alt column for image alt text
     */
    private function add_hero_image_alt_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'hero_image_alt'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN hero_image_alt TEXT DEFAULT NULL AFTER hero_image_url"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Failed to add hero_image_alt column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added hero_image_alt column for image alt text');
            }
        }
    }

    /**
     * Add previous_article_ids column for tracking feedback rewrite version chains.
     * When users rewrite articles via feedback, a new article ID is created.
     * This column stores the chain of previous IDs so the plugin can match
     * the new version to the existing WordPress post.
     */
    private function add_previous_article_ids_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'previous_article_ids'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN previous_article_ids TEXT DEFAULT NULL"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added previous_article_ids column to articles table');
            }
        }
    }

    /**
     * Add language column for WPML integration.
     * Stores the article language code (e.g. 'en', 'de', 'pl', 'it') so the
     * plugin can automatically assign the correct WPML language after publishing.
     */
    private function add_language_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'language'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN language VARCHAR(10) DEFAULT NULL"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added language column for WPML integration');
            }
        }
    }

    /**
     * Ensure the autoseo_settings table has a created_at column.
     * Older installations may have been created without it, causing the sync lock
     * cleanup query to fail silently and permanently block syncs.
     */
    private function add_settings_created_at_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_settings';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'created_at'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[AutoSEO] Added created_at column to settings table');

                // Clear any stuck sync locks from before this migration
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->delete($table_name, array('setting_key' => 'sync_lock'), array('%s'));
            }
        }
    }

    /**
     * Convert autoseo_articles table to utf8mb4 if the database supports it.
     * utf8mb4 allows 4-byte characters (emoji, some CJK, etc.) that utf8 rejects.
     */
    private function maybe_convert_tables_to_utf8mb4() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Only attempt if WordPress itself uses utf8mb4
        $wp_charset = $wpdb->charset;
        if (empty($wp_charset) || strpos($wp_charset, 'utf8mb4') === false) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
        if (!$row || !isset($row->Collation)) {
            return;
        }

        if (strpos($row->Collation, 'utf8mb4') !== false) {
            return;
        }

        $collate = $wpdb->collate;
        if (empty($collate)) {
            $collate = 'utf8mb4_unicode_ci';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query(
            "ALTER TABLE " . esc_sql($table_name) . " CONVERT TO CHARACTER SET utf8mb4 COLLATE " . esc_sql($collate)
        );

        if ($result !== false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[AutoSEO] Converted ' . $table_name . ' to utf8mb4 for full Unicode support');
        }
    }

    /**
     * Main admin page
     */
    public function admin_page() {
        include AUTOSEO_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    /**
     * Settings page
     */
    public function settings_page() {
        include AUTOSEO_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    // Settings callbacks
    public function api_settings_section_callback() {
        echo '<p>' . esc_html__('Configure your AutoSEO API connection settings.', 'getautoseo-ai-content-publisher') . '</p>';
    }

    public function publishing_settings_section_callback() {
        echo '<p>' . esc_html__('Configure how articles from AutoSEO should be published on your site.', 'getautoseo-ai-content-publisher') . '</p>';
    }

    public function api_key_field_callback() {
        $value = get_option('autoseo_api_key');
        echo '<input type="text" name="autoseo_api_key" value="' . esc_attr($value) . '" class="regular-text" placeholder="Your AutoSEO API Key">';
        echo '<p class="description">' . esc_html__('Get your API key from your AutoSEO dashboard.', 'getautoseo-ai-content-publisher') . '</p>';
    }




    public function post_category_field_callback() {
        $value = get_option('autoseo_post_category', '1');
        wp_dropdown_categories(array(
            'name' => 'autoseo_post_category',
            'selected' => $value,
            'hide_empty' => false,
            'show_option_none' => __('Select Category', 'getautoseo-ai-content-publisher'),
        ));
    }

    public function author_field_callback() {
        $value = get_option('autoseo_author_id', get_current_user_id());
        wp_dropdown_users(array(
            'name' => 'autoseo_author_id',
            'selected' => $value,
            'show_option_none' => __('Select Author', 'getautoseo-ai-content-publisher'),
        ));
    }

    /**
     * Debug settings section callback
     */
    public function debug_settings_section_callback() {
        echo '<p>' . esc_html__('Configure debugging options for troubleshooting.', 'getautoseo-ai-content-publisher') . '</p>';
    }

    /**
     * Debug mode field callback
     */
    public function debug_mode_field_callback() {
        $value = get_option('autoseo_debug_mode', '1'); // Default to enabled
        echo '<input type="checkbox" name="autoseo_debug_mode" value="1" ' . checked(1, $value, false) . ' id="autoseo_debug_mode">';
        echo '<label for="autoseo_debug_mode">' . esc_html__('Enable debug logging to browser console', 'getautoseo-ai-content-publisher') . '</label>';
        echo '<p class="description">' . esc_html__('Debug information will be logged to your browser\'s console when enabled.', 'getautoseo-ai-content-publisher') . '</p>';
    }

    // AJAX handlers
    public function ajax_save_settings() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'getautoseo-ai-content-publisher'));
        }

        // Save settings logic here
        wp_send_json_success(array('message' => __('Settings saved successfully', 'getautoseo-ai-content-publisher')));
    }

    public function ajax_save_post_category() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'getautoseo-ai-content-publisher')));
        }

        $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
        if ($category_id > 0 && term_exists($category_id, 'category')) {
            update_option('autoseo_post_category', $category_id);
            wp_send_json_success(array('message' => __('Category saved', 'getautoseo-ai-content-publisher')));
        }

        wp_send_json_error(array('message' => __('Invalid category', 'getautoseo-ai-content-publisher')));
    }

    public function ajax_test_connection() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'getautoseo-ai-content-publisher'));
        }

        // Test connection logic here
        wp_send_json_success(array('message' => __('Connection successful', 'getautoseo-ai-content-publisher')));
    }

    public function ajax_sync_articles() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'getautoseo-ai-content-publisher'));
        }

        try {
            // Initialize API class
            $api = new AutoSEO_API();
            
            // Call the sync method
            $result = $api->sync_articles();
            
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                AutoSEO_Scheduler::store_sync_error($error_message);
                $stored_error = AutoSEO_Scheduler::get_sync_error();
                wp_send_json_error(array(
                    'message' => $error_message,
                    'friendly_message' => is_array($stored_error) ? ($stored_error['friendly_message'] ?? null) : null,
                ));
                return;
            }
            
            AutoSEO_Scheduler::clear_sync_error();
            wp_send_json_success(array(
                'message' => $result['message'],
                'synced_count' => $result['synced_count'],
                'errors' => $result['errors']
            ));
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            AutoSEO_Scheduler::store_sync_error($error_message);
            $stored_error = AutoSEO_Scheduler::get_sync_error();
            wp_send_json_error(array(
                'message' => 'Sync failed: ' . $error_message,
                'friendly_message' => is_array($stored_error) ? ($stored_error['friendly_message'] ?? null) : null,
            ));
        }
    }

    public function ajax_publish_article() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'getautoseo-ai-content-publisher'));
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!$article_id) {
            wp_send_json_error(array('message' => __('Article ID is required.', 'getautoseo-ai-content-publisher')));
            return;
        }

        try {
            // Use the publisher class to publish the article
            $publisher = new AutoSEO_Publisher();
            $result = $publisher->publish_article($article_id);

            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            } else {
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'post_id' => $result['post_id']
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Failed to publish article: ', 'getautoseo-ai-content-publisher') . $e->getMessage()));
        }
    }

    /**
     * AJAX handler for toggling debug mode
     */
    public function ajax_toggle_debug() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'getautoseo-ai-content-publisher')));
            return;
        }

        $enabled = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '0';
        
        update_option('autoseo_debug_mode', $enabled);

        wp_send_json_success(array(
            'message' => __('Debug mode updated successfully', 'getautoseo-ai-content-publisher'),
            'enabled' => $enabled
        ));
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Handshake endpoint for keyless verification (PUBLIC - no auth required)
        // This is called by the AutoSEO backend to verify the plugin is installed
        register_rest_route('autoseo/v1', '/handshake', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_handshake_callback'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('autoseo/v1', '/force-republish', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_force_republish'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
            'args' => array(
                'article_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'AutoSEO article ID',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Trigger sync endpoint - allows AutoSEO server to trigger immediate article sync.
        // Supports push mode: if 'articles' array is included, they are processed directly
        // without the plugin needing to make an outbound API call.
        register_rest_route('autoseo/v1', '/trigger-sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_trigger_sync'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
            'args' => array(
                'auto_publish' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Whether to auto-publish synced articles (if site has auto-publish enabled)',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
                'articles' => array(
                    'required' => false,
                    'type' => 'array',
                    'default' => null,
                    'description' => 'Articles pushed from the server for direct processing (bypasses outbound API call)',
                    'validate_callback' => function ($value) {
                        if ($value === null) {
                            return true;
                        }
                        if (!is_array($value)) {
                            return new WP_Error('invalid_articles', 'articles must be an array');
                        }
                        foreach ($value as $article) {
                            if (!is_array($article) || empty($article['id']) || empty($article['title'])) {
                                return new WP_Error('invalid_article', 'Each article must have id and title');
                            }
                        }
                        return true;
                    },
                ),
            ),
        ));

        // Push image endpoint - allows AutoSEO server to push images directly to WP media library.
        // Used after push-mode article sync to attach hero/infographic images without the WP server
        // needing to make outbound HTTPS requests to download them.
        register_rest_route('autoseo/v1', '/push-image', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_push_image'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
        ));
    }

    /**
     * Permission callback for REST API
     * Validates the API key and optional HMAC signature from request headers.
     */
    public function rest_api_permission_check($request) {
        $token = $this->extract_api_token($request);

        if (empty($token)) {
            $this->log_auth_failure('missing_auth_header', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Authorization header is required', 'getautoseo-ai-content-publisher'),
                array('status' => 401)
            );
        }

        $stored_api_key = get_option('autoseo_api_key', '');
        
        if (empty($stored_api_key) || !hash_equals($stored_api_key, $token)) {
            $this->log_auth_failure('invalid_api_key', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Invalid API key', 'getautoseo-ai-content-publisher'),
                array('status' => 401)
            );
        }

        // HMAC signature verification (mandatory)
        $signature = $request->get_header('X-AutoSEO-Signature');
        if (empty($signature)) {
            $query_params = $request->get_query_params();
            $signature = isset($query_params['_autoseo_sig']) ? $query_params['_autoseo_sig'] : '';
        }

        if (empty($signature)) {
            $this->log_auth_failure('missing_hmac_signature', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Request signature is required', 'getautoseo-ai-content-publisher'),
                array('status' => 401)
            );
        }

        $content_type = $request->get_header('Content-Type') ?? '';
        if (stripos($content_type, 'multipart/form-data') !== false) {
            $params = $request->get_params();
            unset($params['file'], $params['_autoseo_sig']);
            ksort($params);
            $signing_payload = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $signing_payload = $request->get_body();
        }

        $expected = hash_hmac('sha256', $signing_payload, $stored_api_key);
        if (!hash_equals($expected, $signature)) {
            $this->log_auth_failure('invalid_hmac_signature', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Invalid request signature', 'getautoseo-ai-content-publisher'),
                array('status' => 401)
            );
        }

        // Rate limiting per endpoint
        $rate_limit_result = $this->check_rest_rate_limit($request);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }

        return true;
    }

    /**
     * Extract the API bearer token from the request, trying multiple sources.
     * Apache CGI/FastCGI often strips the Authorization header, so we check
     * several fallback locations.
     */
    private function extract_api_token($request) {
        // 1. Standard WP REST Request header (works when server passes it through)
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return $matches[1];
        }

        // 2. Custom header fallback (never stripped by Apache)
        $custom_key = $request->get_header('X-AutoSEO-API-Key');
        if (!empty($custom_key)) {
            return $custom_key;
        }

        // 3. REDIRECT_HTTP_AUTHORIZATION (set by some Apache RewriteRule configs)
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $redirect_auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(.+)$/i', $redirect_auth, $matches)) {
                return $matches[1];
            }
        }

        // 4. HTTP_AUTHORIZATION directly from $_SERVER (some setups)
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $server_auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(.+)$/i', $server_auth, $matches)) {
                return $matches[1];
            }
        }

        // 5. apache_request_headers() / getallheaders() as last resort
        if (function_exists('getallheaders')) {
            $all_headers = getallheaders();
            if (is_array($all_headers)) {
                foreach ($all_headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0 && preg_match('/^Bearer\s+(.+)$/i', $value, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        // 6. POST body fallback — some hosting configs strip ALL headers
        // (including custom X- headers). The POST body is never stripped.
        $body_key = $request->get_param('autoseo_api_key');
        if (!empty($body_key)) {
            return $body_key;
        }

        return '';
    }

    /**
     * Log failed authentication attempts for security monitoring.
     */
    private function log_auth_failure($reason, $request) {
        $ip = $this->get_client_ip();
        $endpoint = $request->get_route();
        $user_agent = $request->get_header('User-Agent') ?? 'unknown';
        $user_agent = preg_replace('/[\r\n\t]/', ' ', substr($user_agent, 0, 100));

        error_log(sprintf(
            '[AutoSEO Security] Auth failure: reason=%s, ip=%s, endpoint=%s, ua=%s',
            $reason,
            $ip,
            $endpoint,
            $user_agent
        ));

        $transient_key = 'autoseo_auth_fail_' . md5($ip);
        $fail_count = (int) get_transient($transient_key);
        set_transient($transient_key, $fail_count + 1, 3600);

        if ($fail_count + 1 >= 10) {
            error_log(sprintf(
                '[AutoSEO Security] WARNING: %d failed auth attempts from IP %s in the last hour',
                $fail_count + 1,
                $ip
            ));
        }
    }

    /**
     * Get the client IP address, respecting common proxy headers.
     */
    private function get_client_ip() {
        $headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }

    /**
     * Check rate limits for REST API endpoints using transients.
     * Returns true if within limits, WP_Error if exceeded.
     */
    private function check_rest_rate_limit($request) {
        $route = $request->get_route();

        $limits = array(
            '/autoseo/v1/trigger-sync'    => array('max' => 10, 'window' => 60),
            '/autoseo/v1/force-republish' => array('max' => 10, 'window' => 60),
            '/autoseo/v1/push-image'      => array('max' => 60, 'window' => 60),
        );

        if (!isset($limits[$route])) {
            return true;
        }

        $limit = $limits[$route];
        $ip = $this->get_client_ip();
        $key = 'autoseo_rl_' . md5($route . '_' . $ip);

        $data = get_transient($key);
        if ($data === false) {
            set_transient($key, array('count' => 1, 'started' => time()), $limit['window']);
            return true;
        }

        if ($data['count'] >= $limit['max']) {
            $this->log_auth_failure('rate_limit_exceeded', $request);
            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    /* translators: %1$d: maximum number of requests allowed, %2$d: time window in seconds */
                    __('Rate limit exceeded. Max %1$d requests per %2$d seconds.', 'getautoseo-ai-content-publisher'),
                    $limit['max'],
                    $limit['window']
                ),
                array('status' => 429)
            );
        }

        $data['count']++;
        $remaining = $limit['window'] - (time() - $data['started']);
        if ($remaining > 0) {
            set_transient($key, $data, $remaining);
        }

        return true;
    }

    /**
     * REST API handler for handshake verification callback
     * 
     * This endpoint is called by the AutoSEO backend to verify the plugin is installed.
     * It's a PUBLIC endpoint - the security is that only the real WordPress site can respond.
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_handshake_callback($request) {
        // Get the challenge token from the request header
        $challenge_token = $request->get_header('X-AutoSEO-Challenge');
        $site_id = $request->get_header('X-AutoSEO-Site-ID');
        
        $this->log_debug("Handshake callback received from AutoSEO backend");
        $this->log_debug("Challenge token: " . ($challenge_token ? substr($challenge_token, 0, 10) . '...' : 'none'));
        $this->log_debug("Site ID: " . ($site_id ?: 'none'));

        // Verify the request is from AutoSEO backend (check User-Agent)
        $user_agent = $request->get_header('User-Agent');
        if (strpos($user_agent, 'AutoSEO-Handshake') === false) {
            $this->log_debug("Handshake rejected - invalid User-Agent: " . $user_agent);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid request origin',
            ), 403);
        }

        if (empty($challenge_token)) {
            $this->log_debug("Handshake rejected - no challenge token");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Challenge token required',
            ), 400);
        }

        // Return the challenge token to prove we received it
        // Also include plugin version and site URL for verification
        $response = array(
            'success' => true,
            'challenge_response' => $challenge_token,
            'plugin_version' => AUTOSEO_VERSION,
            'site_url' => site_url(),
            'wordpress_version' => get_bloginfo('version'),
        );

        $this->log_debug("Handshake successful - returning challenge response");

        return new WP_REST_Response($response, 200);
    }

    /**
     * Attempt automatic verification via handshake
     * 
     * Called when the plugin is activated or when API key is empty.
     * Makes a request to the AutoSEO backend which will callback to verify.
     * 
     * @return array|WP_Error Result of the verification attempt
     */
    public function attempt_auto_verification() {
        $site_url = site_url();
        
        $this->log_debug("Attempting auto-verification for: " . $site_url);

        // Don't attempt for localhost or local development environments (backend can't reach them)
        $local_patterns = array('localhost', '127.0.0.1', '.local', '.test', '.dev', '192.168.', '10.0.', '172.16.');
        foreach ($local_patterns as $pattern) {
            if (strpos($site_url, $pattern) !== false) {
                $this->log_debug("Auto-verification skipped - local development environment detected: " . $pattern);
                return new WP_Error('localhost', 'Auto-verification is not available for local development environments. Please enter your API key manually.');
            }
        }

        $handshake_url = AUTOSEO_API_BASE_URL . '/plugin/initiate-handshake';

        $response = wp_remote_post($handshake_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-AutoSEO-Plugin-Version' => AUTOSEO_VERSION,
            ),
            'body' => wp_json_encode(array(
                'site_url' => $site_url,
            )),
            'timeout' => 30, // Longer timeout - backend needs to callback to us
        ));

        if (is_wp_error($response)) {
            $this->log_debug("Auto-verification failed - request error: " . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $this->log_debug("Auto-verification response - status: " . $status_code);
        $this->log_debug("Auto-verification response body: " . substr($body, 0, 500));

        if ($status_code === 200 && isset($data['success']) && $data['success'] === true) {
            // Success! We received the API key
            if (isset($data['api_key'])) {
                // Store the API key
                update_option('autoseo_api_key', sanitize_text_field($data['api_key']));
                
                // Store verification timestamp
                update_option('autoseo_auto_verified_at', current_time('mysql'));
                
                // Track when API key was set for adaptive sync
                if (!get_option('autoseo_api_key_set_time')) {
                    update_option('autoseo_api_key_set_time', time());
                    
                    // Schedule aggressive sync for new setup
                    wp_clear_scheduled_hook('autoseo_auto_sync');
                    wp_schedule_event(time(), 'every_minute', 'autoseo_auto_sync');
                }
                
                // Hide setup wizard
                delete_option('autoseo_show_setup_wizard');

                $this->log_debug("Auto-verification successful! API key received and stored.");

                return array(
                    'success' => true,
                    'message' => 'Plugin verified automatically!',
                    'site_name' => $data['site_name'] ?? '',
                );
            }
        }

        // Failed - return error with details
        $error_message = isset($data['message']) ? $data['message'] : 'Auto-verification failed';
        $requires_api_key = isset($data['requires_api_key']) ? $data['requires_api_key'] : true;

        $this->log_debug("Auto-verification failed: " . $error_message);

        return new WP_Error(
            'verification_failed',
            $error_message,
            array('requires_api_key' => $requires_api_key)
        );
    }

    /**
     * AJAX handler for attempting auto-verification
     */
    public function ajax_attempt_auto_verification() {
        check_ajax_referer('autoseo_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'getautoseo-ai-content-publisher')));
            return;
        }

        $result = $this->attempt_auto_verification();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'requires_api_key' => true,
            ));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * REST API handler for force republishing an article
     * This will publish an article even if it was trashed or deleted
     */
    public function rest_force_republish($request) {
        global $wpdb;

        $autoseo_id = $request->get_param('article_id');
        $table_name = $wpdb->prefix . 'autoseo_articles';

        $this->log_debug("Force republish requested for article ID: {$autoseo_id}");

        // First, sync the latest article data from AutoSEO API
        $api = new AutoSEO_API();
        $sync_result = $api->sync_articles(true); // Force resync to get latest content

        if (is_wp_error($sync_result)) {
            $this->log_debug("Force republish failed - sync error: " . $sync_result->get_error_message());
            return new WP_Error(
                'sync_failed',
                __('Failed to sync article from AutoSEO', 'getautoseo-ai-content-publisher'),
                array('status' => 500)
            );
        }

        // Get the article from sync table
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE autoseo_id = %s",
            $autoseo_id
        ));

        if (!$article) {
            $this->log_debug("Force republish failed - article not found in sync table: {$autoseo_id}");
            return new WP_Error(
                'article_not_found',
                __('Article not found', 'getautoseo-ai-content-publisher'),
                array('status' => 404)
            );
        }

        // Check if post exists and restore from trash if needed
        if ($article->post_id) {
            $existing_post = get_post($article->post_id);
            
            if ($existing_post) {
                // Post exists - check if it's in trash
                if ($existing_post->post_status === 'trash') {
                    $this->log_debug("Restoring post {$article->post_id} from trash");
                    
                    // Restore from trash
                    wp_untrash_post($article->post_id);
                }
                
                // Update and republish the existing post
                $publisher = new AutoSEO_Publisher();
                $result = $publisher->publish_article($article->id);
                
                if (is_wp_error($result)) {
                    $this->log_debug("Force republish failed - publish error: " . $result->get_error_message());
                    return new WP_Error(
                        'publish_failed',
                        $result->get_error_message(),
                        array('status' => 500)
                    );
                }

                $this->log_debug("Force republish successful - updated existing post {$article->post_id}");
                
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => __('Article republished successfully', 'getautoseo-ai-content-publisher'),
                    'post_id' => $result['post_id'],
                    'published_url' => $result['published_url'],
                    'action' => 'republished',
                ));
            }
        }

        // Post doesn't exist - clear the post_id and create new
        $wpdb->update(
            $table_name,
            array('post_id' => null, 'status' => 'pending'),
            array('id' => $article->id),
            array('%d', '%s'),
            array('%d')
        );

        // Publish as new post
        $publisher = new AutoSEO_Publisher();
        $result = $publisher->publish_article($article->id);
        
        if (is_wp_error($result)) {
            $this->log_debug("Force republish failed - create new post error: " . $result->get_error_message());
            return new WP_Error(
                'publish_failed',
                $result->get_error_message(),
                array('status' => 500)
            );
        }

        $this->log_debug("Force republish successful - created new post {$result['post_id']}");

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Article published successfully', 'getautoseo-ai-content-publisher'),
            'post_id' => $result['post_id'],
            'published_url' => $result['published_url'],
            'action' => 'created',
        ));
    }

    /**
     * REST API endpoint to trigger article sync from AutoSEO server
     * This allows the server to push sync requests rather than waiting for cron
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_trigger_sync($request) {
        $auto_publish = $request->get_param('auto_publish');
        $pushed_articles = $request->get_param('articles');
        $deleted_article_ids = $request->get_param('deleted_article_ids');
        
        $has_pushed = !empty($pushed_articles) && is_array($pushed_articles);
        $has_deletions = !empty($deleted_article_ids) && is_array($deleted_article_ids);
        $mode = $has_pushed ? 'push' : 'pull';
        $article_count = $has_pushed ? count($pushed_articles) : 0;

        $max_articles_per_sync = 50;
        if ($has_pushed && $article_count > $max_articles_per_sync) {
            $this->log_debug("Trigger sync rejected - too many articles: {$article_count} (max {$max_articles_per_sync})");
            return new WP_Error(
                'payload_too_large',
                sprintf('Maximum %d articles per sync request', $max_articles_per_sync),
                array('status' => 413)
            );
        }

        $this->log_debug("Trigger sync requested from AutoSEO server (mode: {$mode}, auto_publish: " . ($auto_publish ? 'true' : 'false') . ", articles: {$article_count} , deletions: " . ($has_deletions ? count($deleted_article_ids) : 0) . ")");

        try {
            $api = new AutoSEO_API();
            $sync_result = $api->sync_articles(
                true,
                $has_pushed ? $pushed_articles : null,
                $has_deletions ? $deleted_article_ids : null
            );

            if (is_wp_error($sync_result)) {
                $error_message = $sync_result->get_error_message();
                $this->log_debug("Trigger sync failed - sync error: " . $error_message);
                AutoSEO_Scheduler::store_sync_error($error_message);
                return new WP_Error(
                    'sync_failed',
                    $error_message,
                    array('status' => 500)
                );
            }

            $synced_count = $sync_result['synced_count'] ?? 0;
            $publish_errors = array();

            // sync_articles() already publishes pending articles during the sync loop,
            // so no separate publish pass is needed here. A previous version ran a second
            // publish pass which caused duplicate WordPress posts via race conditions.

            AutoSEO_Scheduler::clear_sync_error();
            $this->log_debug("Trigger sync completed - synced: {$synced_count}");

            $message = sprintf(
                /* translators: %d: number of articles synced */
                __('Sync completed: %d articles synced', 'getautoseo-ai-content-publisher'),
                $synced_count
            );

            return rest_ensure_response(array(
                'success' => true,
                'message' => $message,
                'synced_count' => $synced_count,
                'published_count' => $synced_count,
                'sync_errors' => $sync_result['errors'] ?? array(),
                'publish_errors' => $publish_errors,
            ));

        } catch (Exception $e) {
            $this->log_debug("Trigger sync exception: " . $e->getMessage());
            return new WP_Error(
                'sync_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * REST API handler for pushing images directly to the WP media library.
     *
     * Receives a file upload with metadata, finds the corresponding WP post
     * by AutoSEO article ID, and attaches the image as hero (featured) or infographic.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_push_image($request) {
        $files = $request->get_file_params();
        $params = $request->get_params();

        $article_id = isset($params['article_id']) ? intval($params['article_id']) : 0;
        $image_type = isset($params['image_type']) ? sanitize_text_field($params['image_type']) : '';
        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        $original_url = isset($params['original_url']) ? esc_url_raw($params['original_url']) : '';

        if (empty($article_id) || empty($image_type) || empty($files['file'])) {
            return new WP_Error('missing_params', 'article_id, image_type, and file are required', array('status' => 400));
        }

        if (!in_array($image_type, array('hero', 'infographic'), true)) {
            return new WP_Error('invalid_image_type', 'image_type must be "hero" or "infographic"', array('status' => 400));
        }

        $max_file_size = 10 * 1024 * 1024; // 10 MB
        $file_size = $files['file']['size'] ?? 0;
        if ($file_size > $max_file_size) {
            $this->log_debug(sprintf('Push image rejected - file too large: %s (max %s)', size_format($file_size), size_format($max_file_size)));
            return new WP_Error('file_too_large', sprintf('File size %s exceeds maximum of %s', size_format($file_size), size_format($max_file_size)), array('status' => 413));
        }

        $this->log_debug(sprintf(
            'Push image received: article_id=%d, type=%s, file_size=%s',
            $article_id,
            $image_type,
            size_format($file_size)
        ));

        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_autoseo_article_id' AND meta_value = %s LIMIT 1",
            $article_id
        ));

        if (!$post_id) {
            $this->log_debug(sprintf('Push image: no WP post found for article ID %d', $article_id));
            return new WP_Error('post_not_found', 'No WordPress post found for article ID ' . $article_id, array('status' => 404));
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'WordPress post ' . $post_id . ' not found', array('status' => 404));
        }

        // For hero images, skip if the post already has a valid featured image with the same URL
        // AND the thumbnail was actually set by AutoSEO (verified via _autoseo_hero_attachment_id)
        if ($image_type === 'hero' && has_post_thumbnail($post_id)) {
            $current_url = get_post_meta($post_id, '_autoseo_hero_image_url', true);
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $autoseo_attachment_id = get_post_meta($post_id, '_autoseo_hero_attachment_id', true);
            $thumbnail_verified = !empty($autoseo_attachment_id) && (int) $autoseo_attachment_id === (int) $thumbnail_id;
            if ($current_url === $original_url && $thumbnail_id && wp_get_attachment_url($thumbnail_id) && $thumbnail_verified) {
                $this->log_debug(sprintf('Push image: hero image already attached and verified for post %d, skipping', $post_id));
                return rest_ensure_response(array(
                    'success' => true,
                    'skipped' => true,
                    'attachment_id' => intval($thumbnail_id),
                    'post_id' => intval($post_id),
                    'image_type' => $image_type,
                ));
            }
        }

        // For infographic images, skip if already attached with the same URL
        if ($image_type === 'infographic') {
            $current_url = get_post_meta($post_id, '_autoseo_infographic_image_url', true);
            $current_id = get_post_meta($post_id, '_autoseo_infographic_image_id', true);
            if ($current_url === $original_url && $current_id && wp_get_attachment_url($current_id)) {
                $this->log_debug(sprintf('Push image: infographic already attached for post %d, skipping', $post_id));
                return rest_ensure_response(array(
                    'success' => true,
                    'skipped' => true,
                    'attachment_id' => intval($current_id),
                    'post_id' => intval($post_id),
                    'image_type' => $image_type,
                ));
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $upload_overrides = array('test_form' => false);
        $uploaded_file = wp_handle_upload($files['file'], $upload_overrides);

        if (isset($uploaded_file['error'])) {
            $this->log_debug('Push image upload failed: ' . $uploaded_file['error']);
            return new WP_Error('upload_failed', $uploaded_file['error'], array('status' => 500));
        }

        $attachment_data = array(
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => $title ?: sanitize_file_name(basename($uploaded_file['file'])),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment_data, $uploaded_file['file'], $post_id);

        if (is_wp_error($attachment_id)) {
            $this->log_debug('Push image attachment creation failed: ' . $attachment_id->get_error_message());
            return new WP_Error('attachment_failed', $attachment_id->get_error_message(), array('status' => 500));
        }

        $attach_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // Set alt text on the attachment using post title
        $alt_text = $post ? $post->post_title : '';
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        if ($image_type === 'hero') {
            set_post_thumbnail($post_id, $attachment_id);
            if ($original_url) {
                update_post_meta($post_id, '_autoseo_hero_image_url', $original_url);
            }
            update_post_meta($post_id, '_autoseo_hero_attachment_id', $attachment_id);
            $this->log_debug(sprintf('Push image: set hero image (attachment %d) for post %d', $attachment_id, $post_id));

            // Update SEO plugin OG image meta so social sharing uses this image
            $og_image_url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($og_image_url) {
                if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || function_exists('wpseo_init')) {
                    update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image_url);
                    update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id);
                    update_post_meta($post_id, '_yoast_wpseo_twitter-image', $og_image_url);
                    update_post_meta($post_id, '_yoast_wpseo_twitter-image-id', $attachment_id);
                    $this->log_debug(sprintf('Push image: set Yoast OG image for post %d', $post_id));
                } elseif (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
                    update_post_meta($post_id, 'rank_math_facebook_image', $og_image_url);
                    update_post_meta($post_id, 'rank_math_facebook_image_id', $attachment_id);
                    update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on');
                    $this->log_debug(sprintf('Push image: set Rank Math OG image for post %d', $post_id));
                } elseif (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
                    update_post_meta($post_id, '_seopress_social_fb_img', $og_image_url);
                    update_post_meta($post_id, '_seopress_social_fb_img_attachment_id', $attachment_id);
                    update_post_meta($post_id, '_seopress_social_twitter_img', $og_image_url);
                    update_post_meta($post_id, '_seopress_social_twitter_img_attachment_id', $attachment_id);
                    $this->log_debug(sprintf('Push image: set SEOPress social image for post %d', $post_id));
                }
            }
        } elseif ($image_type === 'infographic') {
            update_post_meta($post_id, '_autoseo_infographic_image_id', $attachment_id);
            if ($original_url) {
                update_post_meta($post_id, '_autoseo_infographic_image_url', $original_url);
            }
            $this->log_debug(sprintf('Push image: set infographic (attachment %d) for post %d', $attachment_id, $post_id));

            $publisher = new AutoSEO_Publisher();
            $publisher->bake_infographic_into_content($post_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'post_id' => intval($post_id),
            'image_type' => $image_type,
        ));
    }

    /**
     * Log debug message
     */
    private function log_debug($message) {
        $debug_mode = get_option('autoseo_debug_mode', '1');
        if ($debug_mode === '1') {
            error_log('[AutoSEO] ' . $message);
        }
    }

    /**
     * Handle post status transitions (e.g., draft -> publish)
     * This catches when users manually publish AutoSEO articles in WP admin
     * 
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function handle_post_status_transition($new_status, $old_status, $post) {
        // Only care about transitions TO 'publish' status
        if ($new_status !== 'publish') {
            return;
        }

        // Only care if it's a new publish (not already published)
        if ($old_status === 'publish') {
            return;
        }

        // Only care about posts (not pages, attachments, etc.)
        if ($post->post_type !== 'post') {
            return;
        }

        // Check if this is an AutoSEO article
        $autoseo_article_id = get_post_meta($post->ID, '_autoseo_article_id', true);
        if (empty($autoseo_article_id)) {
            return; // Not an AutoSEO article
        }

        // Skip if a sync is in progress — the batch will handle all webhooks
        if (class_exists('AutoSEO_Publisher') && AutoSEO_Publisher::is_batching()) {
            if (get_option('autoseo_debug_mode', '0') === '1') {
                error_log(sprintf(
                    'AutoSEO: Skipping article_published webhook for post %d (batch mode active)',
                    $post->ID
                ));
            }
            return;
        }

        // Skip if the plugin already sent a webhook for this article recently
        // (e.g. create_new_post already sent one, and this is WordPress firing
        // transition_post_status when a scheduled/future post goes live)
        $webhook_sent = get_post_meta($post->ID, '_autoseo_webhook_sent', true);
        if (!empty($webhook_sent) && (time() - (int) $webhook_sent) < 900) {
            if (get_option('autoseo_debug_mode', '0') === '1') {
                error_log(sprintf(
                    'AutoSEO: Skipping duplicate article_published webhook for post %d (already sent %ds ago)',
                    $post->ID,
                    time() - (int) $webhook_sent
                ));
            }
            return;
        }

        // Get the published URL
        $published_url = get_permalink($post->ID);

        // Mark webhook as sent to prevent future duplicates
        update_post_meta($post->ID, '_autoseo_webhook_sent', (string) time());

        // Send webhook to AutoSEO API
        $api = new AutoSEO_API();
        $result = $api->send_webhook('article_published', array(
            'article_id' => $autoseo_article_id,
            'wordpress_post_id' => $post->ID,
            'published_url' => $published_url,
        ));

        // Log for debugging
        if (get_option('autoseo_debug_mode', '0') === '1') {
            error_log(sprintf(
                'AutoSEO: Sent article_published webhook for post %d (AutoSEO ID: %s, URL: %s)',
                $post->ID,
                $autoseo_article_id,
                $published_url
            ));
        }
    }

    /**
     * Detect when a published AutoSEO article's permalink changes and notify the API.
     *
     * WordPress fires `post_updated` after a post is saved. We compare the old
     * and new slugs (post_name) to detect permalink edits.
     */
    public function handle_post_permalink_change($post_id, $post_after, $post_before) {
        if ($post_after->post_type !== 'post' || $post_after->post_status !== 'publish') {
            return;
        }

        if ($post_before->post_name === $post_after->post_name) {
            return;
        }

        $autoseo_article_id = get_post_meta($post_id, '_autoseo_article_id', true);
        if (empty($autoseo_article_id)) {
            return;
        }

        $new_url = get_permalink($post_id);
        $old_url_approx = str_replace(
            '/' . $post_after->post_name . '/',
            '/' . $post_before->post_name . '/',
            $new_url
        );

        $api = new AutoSEO_API();
        $api->send_webhook('article_url_updated', array(
            'article_id'        => $autoseo_article_id,
            'wordpress_post_id' => $post_id,
            'published_url'     => $new_url,
            'old_url'           => $old_url_approx,
        ));

        if (get_option('autoseo_debug_mode', '0') === '1') {
            error_log(sprintf(
                'AutoSEO: Permalink changed for post %d (AutoSEO ID: %s), old slug: %s → new slug: %s, new URL: %s',
                $post_id,
                $autoseo_article_id,
                $post_before->post_name,
                $post_after->post_name,
                $new_url
            ));
        }
    }

    /**
     * Check if a post is an AutoSEO article
     */
    private function is_autoseo_article($post_id) {
        $autoseo_article_id = get_post_meta($post_id, '_autoseo_article_id', true);
        return !empty($autoseo_article_id);
    }

    /**
     * Show notice when viewing AutoSEO article in editor
     */
    public function show_autoseo_edit_notice() {
        global $pagenow, $post;

        // Show notice on individual post edit pages for AutoSEO articles
        if (($pagenow === 'post.php' || $pagenow === 'post-new.php') && isset($post->ID)) {
            $post_id = $post->ID;
            if ($this->is_autoseo_article($post_id)) {
                ?>
                <div class="notice notice-info" style="border-left-color: #3498db;">
                    <div style="display: flex; align-items: center; padding: 10px 0;">
                        <div style="margin-right: 15px; font-size: 24px;">ℹ️</div>
                        <div>
                            <h3 style="margin: 0 0 5px 0; color: #2980b9;">
                                <?php esc_html_e('AutoSEO Managed Article', 'getautoseo-ai-content-publisher'); ?>
                            </h3>
                            <p style="margin: 0;">
                                <?php esc_html_e('This article\'s content is managed by AutoSEO. You can edit the title, SEO settings, categories, and tags — any content changes will be ignored on save.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
    }

    /**
     * Make the content editor read-only for AutoSEO articles via JavaScript.
     * Meta boxes (Rank Math, Yoast, categories, tags, etc.) remain fully editable.
     */
    public function add_autoseo_content_readonly_script() {
        global $pagenow, $post;

        if (($pagenow !== 'post.php' && $pagenow !== 'post-new.php') || !isset($post->ID)) {
            return;
        }

        if (!$this->is_autoseo_article($post->ID)) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Content textarea is readonly (text-mode fallback); visual TinyMCE
            // stays editable so Rank Math / Yoast can analyze it for SEO scoring.
            // Server-side filter discards any content changes on save.
            $('#content').prop('readonly', true).css('background-color', '#f5f5f5');

            // Hide the "Move to Trash" link in the editor
            $('#delete-action').hide();
        });
        </script>
        <style>
            /* Subtle indicator that content is managed, without blocking interaction */
            body.post-php .autoseo-readonly-editor #postdivrich {
                position: relative;
            }
            body.post-php .autoseo-readonly-editor #postdivrich::before {
                content: "Content managed by AutoSEO — changes will not be saved";
                display: block;
                background: #e8f4fd;
                color: #2980b9;
                font-size: 12px;
                padding: 6px 12px;
                border-bottom: 1px solid #bee5eb;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('#post-body-content').addClass('autoseo-readonly-editor');
        });
        </script>
        <?php
    }

    /**
     * Add styles for AutoSEO articles in admin
     */
    public function add_autoseo_article_styles() {
        global $pagenow;
        
        if ($pagenow === 'edit.php') {
            // Enqueue inline styles for AutoSEO article styling
            $inline_css = '
                /* Style AutoSEO articles in posts list */
                .post-type-post .wp-list-table tbody tr[data-autoseo-article="true"] {
                    background-color: #f8f9ff;
                    border-left: 4px solid #3498db;
                }
                
                .post-type-post .wp-list-table tbody tr[data-autoseo-article="true"] .row-title {
                    position: relative;
                }
                
                .post-type-post .wp-list-table tbody tr[data-autoseo-article="true"] .row-title:after {
                    content: "🚀 AutoSEO";
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    font-size: 11px;
                    padding: 3px 8px;
                    border-radius: 4px;
                    margin-left: 10px;
                    font-weight: 600;
                    display: inline-block;
                    vertical-align: middle;
                }
                
                .autoseo-edit-disabled {
                    opacity: 0.6;
                    pointer-events: none;
                }
                
                .autoseo-edit-notice {
                    color: #d68910;
                    font-weight: bold;
                }
            ';
            wp_add_inline_style('getautoseo-admin', $inline_css);
        }
    }

    /**
     * Modify row actions for AutoSEO articles
     */
    public function modify_autoseo_article_row_actions($actions, $post) {
        if ($this->is_autoseo_article($post->ID)) {
            // Remove inline/quick edit (content is managed by AutoSEO)
            unset($actions['inline hide-if-no-js']);

            // Remove Trash link to prevent accidental deletion of managed articles
            unset($actions['trash']);
            
            // Keep the Edit link so users can access meta boxes (Rank Math, Yoast, categories, etc.)
            // Add note that it's managed by AutoSEO
            $actions['autoseo_note'] = '<span class="autoseo-edit-notice">' . __('Managed by AutoSEO', 'getautoseo-ai-content-publisher') . '</span>';
            
            // Add hidden marker for JavaScript
            $actions['autoseo_marker'] = '<span class="autoseo-managed" style="display:none;"></span>';
        }
        
        return $actions;
    }

    /**
     * Prevent trashing AutoSEO articles via wp_trash_post().
     * Returns false to short-circuit the trash (WP 5.5+).
     * Allow programmatic trashing from our own sync code (which runs during REST/cron).
     *
     * @param bool|null $trash   Null to proceed, non-null to short-circuit.
     * @param WP_Post   $post   Post being trashed.
     * @param string    $status Previous post status.
     * @return bool|null
     */
    public function prevent_autoseo_article_trashing($trash, $post, $status = '') {
        if (!$post || $post->post_type !== 'post') {
            return $trash;
        }

        // Allow trashing when the AutoSEO sync explicitly requests it
        // (article was deleted from the AutoSEO dashboard)
        global $autoseo_allow_trash;
        if (!empty($autoseo_allow_trash)) {
            return $trash;
        }

        if ($this->is_autoseo_article($post->ID)) {
            $this->log_debug(sprintf('Blocked attempt to trash AutoSEO article (post ID: %d)', $post->ID));
            return false;
        }

        return $trash;
    }

    /**
     * Prevent permanent deletion of AutoSEO articles via wp_delete_post().
     * Returns false to short-circuit the delete (WP 5.5+).
     *
     * @param bool|null $delete       Null to proceed, non-null to short-circuit.
     * @param WP_Post   $post         Post being deleted.
     * @param bool      $force_delete Whether to bypass trash.
     * @return bool|null
     */
    public function prevent_autoseo_article_deleting($delete, $post, $force_delete = false) {
        if (!$post || $post->post_type !== 'post') {
            return $delete;
        }

        if ($this->is_autoseo_article($post->ID)) {
            $this->log_debug(sprintf('Blocked attempt to delete AutoSEO article (post ID: %d)', $post->ID));
            return false;
        }

        return $delete;
    }

    /**
     * Disable Gutenberg editor for AutoSEO articles
     */
    public function disable_gutenberg_for_autoseo_articles($use_block_editor, $post) {
        if (is_object($post) && $this->is_autoseo_article($post->ID)) {
            return false;
        }
        return $use_block_editor;
    }

    /**
     * Preserve AutoSEO post content whenever a managed post is saved by
     * anything OTHER than the plugin itself. TinyMCE, Gutenberg, REST API
     * clients, and third-party plugins can all strip or modify inline SVGs
     * (used for author-box social icons) and other AutoSEO markup.
     *
     * Bypassed when:
     *  - The plugin's own code sets $allow_content_update before writing.
     *  - The post has active page-builder data (Elementor, Divi, etc.),
     *    meaning the user is intentionally managing content with a builder.
     */
    public function protect_autoseo_content_on_admin_save($data, $postarr) {
        if (self::$allow_content_update) {
            return $data;
        }

        if (empty($postarr['ID'])) {
            return $data;
        }

        if (!get_post_meta($postarr['ID'], '_autoseo_managed', true)) {
            return $data;
        }

        // If the user is managing this post with a page builder, let the
        // builder save its own content without interference.
        if (AutoSEO_Publisher::has_page_builder_content($postarr['ID'])) {
            return $data;
        }

        $original_post = get_post($postarr['ID']);
        if ($original_post) {
            $data['post_content'] = $original_post->post_content;
        }

        return $data;
    }

    /**
     * Add SVG and path elements to TinyMCE's valid elements list for AutoSEO
     * posts, so author box social icons render correctly in the Classic Editor
     * instead of being silently stripped during HTML parsing.
     */
    public function add_svg_to_tinymce_valid_elements($init_array) {
        global $post;

        if (!isset($post->ID) || !$this->is_autoseo_article($post->ID)) {
            return $init_array;
        }

        $svg_elements = 'svg[xmlns|width|height|viewBox|viewbox|fill|stroke|class|style|aria-hidden|role],'
            . 'path[d|fill|stroke|stroke-width|stroke-linecap|fill-rule|clip-rule]';

        if (!empty($init_array['extended_valid_elements'])) {
            $init_array['extended_valid_elements'] .= ',' . $svg_elements;
        } else {
            $init_array['extended_valid_elements'] = $svg_elements;
        }

        return $init_array;
    }

    /**
     * Allow AutoSEO HTML elements in post content.
     * WordPress strips iframes, SVGs, etc. by default through wp_kses_post().
     * 
     * @param array  $allowed_tags Array of allowed HTML tags and attributes
     * @param string $context      The context for the allowed tags (e.g., 'post')
     * @return array Modified array of allowed tags
     */
    public function allow_autoseo_html_elements($allowed_tags, $context) {
        if ($context !== 'post') {
            return $allowed_tags;
        }

        // YouTube iframes
        $allowed_tags['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'allow'           => true,
            'title'           => true,
            'style'           => true,
            'class'           => true,
            'loading'         => true,
        );

        // SVG elements for author box social icons
        $allowed_tags['svg'] = array(
            'xmlns'       => true,
            'width'       => true,
            'height'      => true,
            'viewbox'     => true,
            'fill'        => true,
            'stroke'      => true,
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
            'role'        => true,
        );
        $allowed_tags['path'] = array(
            'd'              => true,
            'fill'           => true,
            'stroke'         => true,
            'stroke-width'   => true,
            'stroke-linecap' => true,
            'fill-rule'      => true,
            'clip-rule'      => true,
        );

        // Heading IDs for TOC anchor links (wp_kses strips id during cron-based inserts)
        $heading_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6');
        foreach ($heading_tags as $tag) {
            if (!isset($allowed_tags[$tag])) {
                $allowed_tags[$tag] = array();
            }
            $allowed_tags[$tag]['id'] = true;
            $allowed_tags[$tag]['class'] = true;
        }

        return $allowed_tags;
    }

    /**
     * Allow additional CSS properties in inline styles.
     * WordPress strips display, flex, gap, etc. by default.
     */
    public function allow_additional_css_properties($styles) {
        $styles[] = 'display';
        $styles[] = 'gap';
        $styles[] = 'flex';
        $styles[] = 'flex-shrink';
        $styles[] = 'flex-grow';
        $styles[] = 'flex-direction';
        $styles[] = 'flex-wrap';
        $styles[] = 'align-items';
        $styles[] = 'justify-content';
        $styles[] = 'object-fit';
        $styles[] = 'transition';
        $styles[] = 'letter-spacing';
        $styles[] = 'text-transform';
        $styles[] = 'min-width';
        return $styles;
    }

    /**
     * Add 'autoseo' class to the post container element for AutoSEO-managed posts.
     * Allows users to target AutoSEO articles with custom CSS.
     */
    public function add_autoseo_post_class($classes, $extra_classes, $post_id) {
        if (get_post_meta($post_id, '_autoseo_managed', true)) {
            $classes[] = 'autoseo';
        }
        return $classes;
    }

    /**
     * Add 'autoseo' class to the <body> tag when viewing a single AutoSEO-managed post.
     */
    public function add_autoseo_body_class($classes) {
        if (is_singular('post')) {
            $post = get_post();
            if ($post && get_post_meta($post->ID, '_autoseo_managed', true)) {
                $classes[] = 'autoseo';
            }
        }
        return $classes;
    }

    /**
     * Fix Key Takeaways HTML structure at render time.
     *
     * Older articles may have Key Takeaways without the <div class="key-takeaways"> wrapper,
     * sometimes with a stray </div> that closes the FSE theme's wp-block-post-content container.
     * This filter ensures the wrapper is always present and removes stray closing divs.
     */
    public function fix_key_takeaways_structure($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_autoseo_article_id', true)) {
            return $content;
        }

        // Already properly wrapped — nothing to do
        if (preg_match('/<div[^>]*class="key-takeaways"[^>]*>/i', $content)) {
            return $content;
        }

        // Case A: H2 + <ul>...</ul> + stray </div> — wrap and consume the stray closer
        $replaced = preg_replace(
            '/(<h2[^>]*>[^<]*<\/h2>\s*<ul>)(.*?)(<\/ul>)\s*<\/div>/is',
            '<div class="key-takeaways">$1$2$3</div>',
            $content,
            1,
            $count
        );
        if ($count > 0 && $replaced !== null) {
            return $replaced;
        }

        // Case B: H2 + <ul>...</ul> with no wrapper at all — wrap using known headings
        $known_headings = array(
            'Key Takeaways', 'Puntos Clave', 'Points Cl(?:é|e)s', 'Wichtigste Erkenntnisse',
            'Punti Chiave', 'Principais Conclus(?:õ|o)es', 'Belangrijkste Punten',
            'Najwa(?:ż|z)niejsze Wnioski', 'Ключевые Выводы', '重要なポイント', '关键要点',
            '핵심 요약', 'النقاط الرئيسية', 'נקודות מפתח', '(?:Ö|O)nemli Noktalar',
            'Viktiga Slutsatser', 'Vigtigste Pointer', 'Viktige Punkter',
            'T(?:ä|a)rkeimm(?:ä|a)t Havainnot', 'Legfontosabb Tudnival(?:ó|o)k',
            'Kl(?:í|i)(?:č|c)ov(?:é|e) Poznatky', 'Concluzii Cheie',
            'Ключов(?:і|i) Висновки', 'Βασικά Συμπεράσματα',
            'ประเด็นสำคัญ', '(?:Đ|D)i(?:ể|e)m Ch(?:í|i)nh', 'Poin Penting',
            'Perkara Utama', 'मुख्य बातें',
        );
        $headings_pattern = implode('|', $known_headings);

        $replaced = preg_replace(
            '/(<h2[^>]*>\s*(?:' . $headings_pattern . ')\s*<\/h2>\s*<ul>)(.*?)(<\/ul>)/isu',
            '<div class="key-takeaways">$1$2$3</div>',
            $content,
            1,
            $count
        );
        if ($count > 0 && $replaced !== null) {
            return $replaced;
        }

        return $content;
    }

    /**
     * Add anchor IDs to H2 headings that don't have them.
     * WordPress wp_kses can strip `id` attributes during cron-based wp_insert_post.
     * This filter re-adds them at render time so TOC anchor links work.
     */
    public function add_heading_anchor_ids($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_autoseo_article_id', true)) {
            return $content;
        }

        // Strip empty anchor tags produced when AI-generated markdown []()
        // links survive into HTML (e.g. <a href=""></a>).
        $content = preg_replace('/<a\s+href=["\'][\s]*["\']>\s*<\/a>/i', '', $content);

        return preg_replace_callback(
            '/<h2(?![^>]*\bid=)([^>]*)>(.*?)<\/h2>/is',
            function ($matches) {
                $attributes = $matches[1];
                $headingHtml = $matches[2];
                $headingText = html_entity_decode(wp_strip_all_tags($headingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $slug = $this->generate_heading_slug($headingText);

                if (trim($attributes) === '') {
                    return '<h2 id="' . esc_attr($slug) . '">' . $headingHtml . '</h2>';
                }
                return '<h2 id="' . esc_attr($slug) . '"' . $attributes . '>' . $headingHtml . '</h2>';
            },
            $content
        );
    }

    /**
     * Generate a URL-friendly slug from heading text.
     * Must match the logic in Laravel's ArticleWritingService::generateHeadingSlug()
     */
    private function generate_heading_slug($heading) {
        $slug = mb_strtolower($heading, 'UTF-8');
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        if (empty($slug)) {
            $slug = 'section';
        }

        return $slug;
    }

    /**
     * Inject infographic image into post content
     * Inserts the infographic image before the middle H2 heading (matching Laravel placement)
     */
    public function inject_infographic_image_into_content($content) {
        // Only run on single post pages, inside the main loop
        // The in_the_loop() check prevents themes (e.g. Avada) from triggering
        // this filter in nav menus, related posts, footers, etc.
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        // Content-based guard: check the filtered $content for the container class.
        if (strpos($content, 'autoseo-infographic-container') !== false) {
            return $content;
        }

        // Prevent multiple injections on the same page load
        static $already_injected = array();

        global $post;

        if (isset($already_injected[$post->ID])) {
            return $content;
        }

        // Database guard: if the infographic is baked into the stored post_content,
        // skip runtime injection. Some themes/page-builders modify $content before
        // this filter runs (stripping the baked infographic from the filtered string),
        // but the stored version still renders in the final output — injecting again
        // would duplicate it.
        $raw_content = get_post_field('post_content', $post->ID);
        if (strpos($raw_content, 'autoseo-infographic-container') !== false) {
            $already_injected[$post->ID] = true;
            return $content;
        }
        
        // Check if this is an AutoSEO article
        if (!$this->is_autoseo_article($post->ID)) {
            return $content;
        }

        // Get the infographic image ID
        $infographic_image_id = get_post_meta($post->ID, '_autoseo_infographic_image_id', true);
        
        if (!$infographic_image_id) {
            return $content;
        }

        // Get the alt text from the attachment meta, fall back to post title
        $infographic_alt = get_post_meta($infographic_image_id, '_wp_attachment_image_alt', true);
        if (empty($infographic_alt)) {
            $infographic_alt = get_the_title($post->ID);
        }

        // Get the full image HTML
        $infographic_html = wp_get_attachment_image($infographic_image_id, 'full', false, array(
            'class' => 'autoseo-infographic-image',
            'alt' => $infographic_alt
        ));

        if (!$infographic_html) {
            return $content;
        }

        // Wrap the infographic in a container
        $infographic_container = '<div class="autoseo-infographic-container">' . $infographic_html . '</div>';

        // Insert before the middle H2 heading (matching Laravel ArticleController::insertInfographicHalfway)
        // Use regex to find all H2 headings only (not H3)
        preg_match_all('/<h2[^>]*>.*?<\/h2>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        // If no H2 headings found, just append at the end
        if (empty($matches[0])) {
            return $content . $infographic_container;
        }
        
        // Find the H2 heading closest to the middle
        $headings = $matches[0];
        $middle_index = (int) floor(count($headings) / 2);
        
        // Get the position of the middle H2 heading
        // Note: preg_match_all returns byte offsets, so we need to use substr() here
        // (not mb_substr) because the offset is in bytes. This is actually safe
        // because we're splitting at the exact byte position the regex found.
        $insert_position = $headings[$middle_index][1];
        
        // Insert the infographic before the middle H2 heading
        // Using substr() is safe here because $insert_position is a byte offset from preg_match_all
        $content = substr($content, 0, $insert_position) . 
                   $infographic_container . 
                   substr($content, $insert_position);

        $already_injected[$post->ID] = true;

        return $content;
    }

    /**
     * Register rewrite rules for .md URL support
     * This enables LLM-friendly markdown versions of AutoSEO articles
     * Following the llms.txt specification: https://llmstxt.org/
     */
    public function register_md_rewrite_rules() {
        // Add query var for .md detection
        add_rewrite_tag('%autoseo_md%', '([0-1])');
    }

    /**
     * Handle .md URL requests and serve markdown content
     * When a URL like /blog/my-article.md is requested, serve the markdown version
     */
    public function handle_md_url_request() {
        // Get the current request URI
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        // Check if this is a .md request
        if (!preg_match('/\.md$/', $request_uri)) {
            return;
        }

        // Remove .md suffix to get the original URL
        $original_uri = preg_replace('/\.md$/', '', $request_uri);
        
        // Remove query string if present
        $original_uri = strtok($original_uri, '?');
        
        // Handle subdirectory installations (e.g., /blog/)
        // home_url() already includes the subdirectory, so we need to extract just the slug
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            // Remove the home path prefix from the request URI to avoid duplication
            $home_path = rtrim($home_path, '/');
            if (strpos($original_uri, $home_path) === 0) {
                $original_uri = substr($original_uri, strlen($home_path));
            }
        }
        
        // Try to find the post by URL
        $post_id = url_to_postid(home_url($original_uri));
        
        // If not found, try common permalink variations
        if (!$post_id) {
            // Try without trailing slash
            $post_id = url_to_postid(home_url(rtrim($original_uri, '/')));
        }
        if (!$post_id) {
            // Try with trailing slash
            $post_id = url_to_postid(home_url(trailingslashit($original_uri)));
        }
        
        // Also handle .html.md pattern (per llms.txt spec)
        if (!$post_id && preg_match('/\.html$/', $original_uri)) {
            $original_uri = preg_replace('/\.html$/', '', $original_uri);
            $post_id = url_to_postid(home_url($original_uri));
        }
        
        if (!$post_id) {
            // No matching post found - return 404
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'The requested article was not found.';
            exit;
        }

        // Check if this is an AutoSEO article
        if (!$this->is_autoseo_article($post_id)) {
            // Not an AutoSEO article - return 404 for .md version
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'Markdown version is only available for AutoSEO articles.';
            exit;
        }

        // Get the markdown content
        $markdown_content = get_post_meta($post_id, '_autoseo_content_markdown', true);
        
        if (empty($markdown_content)) {
            // No markdown content stored - return 404
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'Markdown version is not available for this article.';
            exit;
        }

        // Get post data for additional context
        $post = get_post($post_id);
        
        // Set proper headers for markdown
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400'); // Cache for 1 day
        
        // Output the markdown with metadata header (following llms.txt format)
        echo '# ' . esc_html($post->post_title) . "\n\n";
        
        // Add metadata as blockquote (per llms.txt spec)
        $meta_description = get_post_meta($post_id, '_autoseo_meta_description', true);
        if (!empty($meta_description)) {
            echo '> ' . esc_html($meta_description) . "\n\n";
        }
        
        // Add article info
        echo '---' . "\n";
        echo 'Published: ' . get_the_date('Y-m-d', $post_id) . "\n";
        $keywords = get_post_meta($post_id, '_autoseo_keywords', true);
        if (!empty($keywords) && is_array($keywords)) {
            echo 'Keywords: ' . esc_html(implode(', ', $keywords)) . "\n";
        }
        echo 'Source: ' . esc_url(get_permalink($post_id)) . "\n";
        echo '---' . "\n\n";
        
        // Convert HTML to markdown if content appears to be HTML
        if (strpos($markdown_content, '<p>') !== false || strpos($markdown_content, '<h') !== false) {
            $markdown_content = $this->convert_html_to_markdown($markdown_content);
        }
        
        // Output the actual markdown content
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content is intentionally raw
        echo $markdown_content;
        
        exit;
    }

    /**
     * Convert HTML content to Markdown format
     * For LLM-friendly .md URLs following llms.txt specification
     * 
     * @param string $html HTML content to convert
     * @return string Markdown content
     */
    private function convert_html_to_markdown($html) {
        $markdown = $html;
        
        // Normalize line breaks
        $markdown = str_replace(array("\r\n", "\r"), "\n", $markdown);
        
        // Convert headings (h1-h6)
        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $markdown = preg_replace('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is', "\n" . $prefix . ' $1' . "\n", $markdown);
        }
        
        // Convert paragraphs
        $markdown = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $markdown);
        
        // Convert bold
        $markdown = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $markdown);
        $markdown = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $markdown);
        
        // Convert italic
        $markdown = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '*$1*', $markdown);
        $markdown = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '*$1*', $markdown);
        
        // Convert links
        $markdown = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown);
        
        // Convert unordered lists
        $markdown = preg_replace('/<ul[^>]*>(.*?)<\/ul>/is', "\n$1\n", $markdown);
        $markdown = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $markdown);
        
        // Convert ordered lists
        $markdown = preg_replace('/<ol[^>]*>(.*?)<\/ol>/is', "\n$1\n", $markdown);
        // Note: Ordered lists get converted to unordered for simplicity
        
        // Convert blockquotes
        $markdown = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $markdown);
        
        // Convert code blocks
        $markdown = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $markdown);
        $markdown = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $markdown);
        
        // Convert line breaks
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);
        
        // Convert horizontal rules
        $markdown = preg_replace('/<hr\s*\/?>/i', "\n---\n", $markdown);
        
        // Remove remaining HTML tags
        $markdown = wp_strip_all_tags($markdown);
        
        // Decode HTML entities
        $markdown = html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');
        
        // Clean up excessive whitespace
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = trim($markdown);
        
        return $markdown;
    }
}

// Initialize the plugin
function autoseo_init() {
    return AutoSEO_Plugin::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'autoseo_init');

/**
 * Shortcode for displaying AutoSEO content
 */
function autoseo_shortcode($atts) {
    $atts = shortcode_atts(array(
        'type' => 'dashboard',
        'limit' => 10,
    ), $atts);

    ob_start();

    switch ($atts['type']) {
        case 'dashboard':
            include AUTOSEO_PLUGIN_DIR . 'templates/frontend-dashboard.php';
            break;
        case 'articles':
            include AUTOSEO_PLUGIN_DIR . 'templates/frontend-articles.php';
            break;
        default:
            echo '<p>' . esc_html__('Invalid GetAutoSEO shortcode type', 'getautoseo-ai-content-publisher') . '</p>';
    }

    return ob_get_clean();
}
add_shortcode('getautoseo', 'autoseo_shortcode');

/**
 * Activation hook wrapper
 */
function autoseo_activate_plugin() {
    AutoSEO_Plugin::get_instance()->activate();
}
register_activation_hook(__FILE__, 'autoseo_activate_plugin');

/**
 * Deactivation hook wrapper
 */
function autoseo_deactivate_plugin() {
    AutoSEO_Plugin::get_instance()->deactivate();
}
register_deactivation_hook(__FILE__, 'autoseo_deactivate_plugin');
