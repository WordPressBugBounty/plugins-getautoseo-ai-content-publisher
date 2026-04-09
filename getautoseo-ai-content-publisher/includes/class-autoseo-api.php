<?php
/**
 * AutoSEO API Client
 * 
 * Handles communication with the AutoSEO API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEO_API {

    /**
     * API Base URL
     */
    private $api_base_url;

    /**
     * API Key
     */
    private $api_key;

    /**
     * Whether the sync table supports 4-byte UTF-8 (utf8mb4)
     */
    private $db_supports_utf8mb4 = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_base_url = AUTOSEO_API_BASE_URL;
        $this->api_key = get_option('autoseo_api_key', '');
    }

    /**
     * Check if the autoseo_articles table supports utf8mb4 (4-byte Unicode like emoji).
     * Caches the result per request.
     */
    private function check_utf8mb4_support() {
        if ($this->db_supports_utf8mb4 !== null) {
            return $this->db_supports_utf8mb4;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'autoseo_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
        if ($row && isset($row->Collation)) {
            $this->db_supports_utf8mb4 = (strpos($row->Collation, 'utf8mb4') !== false);
        } else {
            $this->db_supports_utf8mb4 = false;
        }

        return $this->db_supports_utf8mb4;
    }

    /**
     * Strip 4-byte Unicode characters (emoji etc.) from text when the database
     * uses utf8 instead of utf8mb4. MySQL utf8 only supports up to 3-byte chars.
     */
    private function sanitize_for_db($text) {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        if ($this->check_utf8mb4_support()) {
            return $text;
        }

        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * Sync articles from AutoSEO API
     * 
     * @param bool $force_resync Force resync all articles (ignores last sync timestamp)
     * @param array|null $pushed_articles Articles pushed directly from the server (bypasses API call)
     * @return array|WP_Error
     */
    public function sync_articles($force_resync = false, $pushed_articles = null, $deleted_article_ids = null) {
        global $wpdb;

        if (empty($this->api_key) && $pushed_articles === null) {
            return new WP_Error('no_api_key', __('API key is not configured', 'getautoseo-ai-content-publisher'));
        }

        // Prevent concurrent sync operations (race condition → duplicate posts).
        // Uses a DB row lock: INSERT succeeds only for the first caller; others bail out.
        $lock_table = $wpdb->prefix . 'autoseo_settings';
        $lock_key   = 'sync_lock';
        $lock_value = time() . '|' . wp_generate_uuid4();
        $lock_max_age = 300; // seconds

        // Clean up expired locks using TWO strategies for robustness:
        // 1. Parse timestamp from lock value (works even without created_at column)
        // 2. Fall back to created_at column if available
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_lock = $wpdb->get_row($wpdb->prepare(
            "SELECT id, setting_value FROM {$lock_table} WHERE setting_key = %s LIMIT 1",
            $lock_key
        ));

        if ($existing_lock) {
            $should_clear = false;
            $lock_parts = explode('|', $existing_lock->setting_value, 2);

            if (is_numeric($lock_parts[0]) && (time() - intval($lock_parts[0])) > $lock_max_age) {
                $should_clear = true;
                $this->log_debug(sprintf(
                    'Clearing expired sync lock (age: %ds, max: %ds)',
                    time() - intval($lock_parts[0]),
                    $lock_max_age
                ));
            } elseif (!is_numeric($lock_parts[0])) {
                // Legacy lock without timestamp — clear unconditionally since
                // we can't determine its age (created_at column may not exist)
                $should_clear = true;
                $this->log_debug('Clearing legacy sync lock (no embedded timestamp)');
            }

            if ($should_clear) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->delete($lock_table, array('id' => $existing_lock->id), array('%d'));
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lock_acquired = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$lock_table} (setting_key, setting_value) VALUES (%s, %s)",
            $lock_key,
            $lock_value
        ));

        if (!$lock_acquired) {
            $this->log_debug('Sync skipped: another sync operation is already in progress');
            return array(
                'success' => true,
                'message' => __('Sync skipped: already in progress', 'getautoseo-ai-content-publisher'),
                'synced_count' => 0,
                'errors' => array(),
            );
        }

        try {
            return $this->do_sync_articles($force_resync, $pushed_articles, $deleted_article_ids);
        } finally {
            // Release lock
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->delete($lock_table, array('setting_key' => $lock_key, 'setting_value' => $lock_value), array('%s', '%s'));
        }
    }

    /**
     * Internal sync implementation (called after lock is acquired)
     *
     * @param bool $force_resync Force resync all articles
     * @param array|null $pushed_articles Articles pushed directly from the server (bypasses API call)
     * @return array|WP_Error
     */
    private function do_sync_articles($force_resync = false, $pushed_articles = null, $deleted_article_ids_from_trigger = null) {
        global $wpdb;

        // In push mode, the server pushes images separately after articles are processed.
        // Skip outbound image downloads to avoid timeouts on servers with restricted connectivity.
        $is_push_mode = ($pushed_articles !== null && is_array($pushed_articles));

        $deleted_article_ids = is_array($deleted_article_ids_from_trigger) ? $deleted_article_ids_from_trigger : array();

        if ($is_push_mode) {
            $this->log_debug(sprintf('Processing %d pushed articles from server (no API call needed, images will be pushed separately)', count($pushed_articles)));
            $articles = $pushed_articles;
        } else {
            // Standard pull: fetch articles from the AutoSEO API
            $last_sync = $force_resync ? null : get_option('autoseo_last_sync_time');
            
            $url = $this->api_base_url . '/articles/sync';
            if ($last_sync) {
                $url .= '?since=' . urlencode($last_sync);
            }

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                    'X-AutoSEO-Plugin-Version' => AUTOSEO_VERSION,
                    'X-WordPress-Site-URL' => site_url(),
                ),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_message = isset($data['message']) ? $data['message'] : __('API request failed', 'getautoseo-ai-content-publisher');
                return new WP_Error('api_error', $error_message, array('status' => $status_code));
            }

            if (!isset($data['articles']) || !is_array($data['articles'])) {
                return new WP_Error('invalid_response', __('Invalid API response format', 'getautoseo-ai-content-publisher'));
            }

            $articles = $data['articles'];
            $api_deleted_ids = isset($data['deleted_article_ids']) && is_array($data['deleted_article_ids'])
                ? $data['deleted_article_ids']
                : array();
            $deleted_article_ids = array_unique(array_merge($deleted_article_ids, $api_deleted_ids));
        }
        $table_name = $wpdb->prefix . 'autoseo_articles';
        $synced_count = 0;
        $errors = array();

        // Trash WordPress posts for articles deleted from the AutoSEO dashboard
        if (!empty($deleted_article_ids)) {
            $this->trash_deleted_articles($deleted_article_ids, $table_name);
        }

        // Save sync time BEFORE processing to prevent timeout-induced loops.
        // If the plugin times out mid-processing, the next sync will use 'since'
        // and only fetch changed articles instead of re-fetching everything.
        update_option('autoseo_last_sync_time', current_time('c'));

        // Recover articles stuck in 'publishing' status (process crashed mid-publish).
        // Reset to 'pending' if they've been in 'publishing' for over 300 seconds.
        // Increased from 60s to 300s to match sync lock timeout and prevent
        // resetting articles while image downloads are still in progress.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET status = 'pending' WHERE status = 'publishing' AND synced_at < %s",
            gmdate('Y-m-d H:i:s', time() - 300)
        ));

        // Check if this is the first sync (no articles in table yet)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $is_first_sync = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}") == 0;

        // Pre-check column existence so INSERT/UPDATE never references
        // a column the local DB table doesn't have yet (handles upgrades
        // where the schema migration hasn't run).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $has_language_col = !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'language'
        )));

        AutoSEO_Publisher::start_batch();

        try {

        foreach ($articles as $article) {
            try {
                // Validate required fields
                if (empty($article['id']) || empty($article['title'])) {
                    $errors[] = __('Article missing required fields (id or title)', 'getautoseo-ai-content-publisher');
                    continue;
                }

                // Pre-compute previous_article_ids JSON for storage
                $previous_article_ids_json = null;
                if (!empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                    $previous_article_ids_json = wp_json_encode($article['previous_article_ids']);
                }

                // Check if article already exists in our sync table
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE autoseo_id = %s",
                    $article['id']
                ));

                // DUPLICATE PREVENTION (meta-based): Check if a post with this autoseo_id already exists via post meta
                // This is the most reliable check - immune to title encoding issues and sync table resets
                if ($is_first_sync || !$existing) {
                    $meta_query = new WP_Query(array(
                        'post_type'              => 'post',
                        'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                        'posts_per_page'         => 1,
                        'no_found_rows'          => true,
                        'ignore_sticky_posts'    => true,
                        'update_post_term_cache' => false,
                        'update_post_meta_cache' => false,
                        'meta_query'             => array(
                            array(
                                'key'   => '_autoseo_article_id',
                                'value' => $article['id'],
                            ),
                        ),
                    ));
                    $existing_post = !empty($meta_query->posts) ? $meta_query->posts[0] : null;
                    wp_reset_postdata();

                    // Fallback: check previous article versions (feedback rewrites create new IDs)
                    $matched_via_previous_version = false;
                    if (!$existing_post && !empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                        foreach ($article['previous_article_ids'] as $prev_id) {
                            $prev_meta_query = new WP_Query(array(
                                'post_type'              => 'post',
                                'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                                'posts_per_page'         => 1,
                                'no_found_rows'          => true,
                                'ignore_sticky_posts'    => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => array(
                                    array(
                                        'key'   => '_autoseo_article_id',
                                        'value' => (string) $prev_id,
                                    ),
                                ),
                            ));
                            $existing_post = !empty($prev_meta_query->posts) ? $prev_meta_query->posts[0] : null;
                            wp_reset_postdata();

                            if ($existing_post) {
                                update_post_meta($existing_post->ID, '_autoseo_article_id', (string) $article['id']);
                                $matched_via_previous_version = true;
                                $this->log_debug(sprintf(
                                    'Article "%s" (ID: %s) matched existing post via previous version %s (Post ID: %d) - updated meta',
                                    $article['title'],
                                    $article['id'],
                                    $prev_id,
                                    $existing_post->ID
                                ));
                                break;
                            }
                        }
                    }

                    // Fallback: check by title if no meta or previous version match found
                    if (!$existing_post) {
                        $title_query = new WP_Query(array(
                            'post_type'              => 'post',
                            'title'                  => $article['title'],
                            'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                            'posts_per_page'         => 1,
                            'no_found_rows'          => true,
                            'ignore_sticky_posts'    => true,
                            'update_post_term_cache' => false,
                            'update_post_meta_cache' => false,
                        ));
                        $existing_post = !empty($title_query->posts) ? $title_query->posts[0] : null;
                        wp_reset_postdata();
                    }

                    if ($existing_post) {
                        $this->log_debug(sprintf(
                            'Skipping article "%s" (ID: %s) - WordPress post already exists (Post ID: %d)',
                            $article['title'],
                            $article['id'],
                            $existing_post->ID
                        ));
                        
                        if (!$existing) {
                            // Use epoch synced_at for previous version matches as a safety
                            // net: if the immediate content update below fails, the next
                            // sync cycle will retry because api_updated_at > epoch.
                            $linked_synced_at = $matched_via_previous_version
                                ? '2000-01-01 00:00:00'
                                : current_time('mysql');

                            $linked_data = array(
                                    'autoseo_id' => $article['id'],
                                    'post_id' => $existing_post->ID,
                                    'title' => $this->sanitize_for_db($article['title']),
                                    'content' => $this->sanitize_for_db($article['content']),
                                    'content_markdown' => $this->sanitize_for_db($article['content_markdown'] ?? null),
                                    'excerpt' => $this->sanitize_for_db($article['excerpt'] ?? ''),
                                    'keywords' => is_array($article['keywords']) ? implode(',', $article['keywords']) : '',
                                    'meta_description' => $this->sanitize_for_db($article['meta_description'] ?? null),
                                    'meta_keywords' => $article['meta_keywords'] ?? null,
                                    'wordpress_tags' => $article['wordpress_tags'] ?? null,
                                    'featured_image_url' => $article['featured_image_url'] ?? null,
                                    'hero_image_url' => $article['hero_image_url'] ?? null,
                                    'hero_image_alt' => $this->sanitize_for_db($article['hero_image_alt'] ?? null),
                                    'infographic_html' => $this->sanitize_for_db($article['infographic_html'] ?? null),
                                    'infographic_image_url' => $article['infographic_image_url'] ?? null,
                                    'status' => 'linked',
                                    'synced_at' => $linked_synced_at,
                                    'previous_article_ids' => $previous_article_ids_json ?? null,
                            );
                            if ($has_language_col) {
                                $linked_data['language'] = $article['language'] ?? null;
                            }
                            $linked_formats = array_fill(0, count($linked_data), '%s');
                            $linked_formats[1] = '%d'; // post_id
                            $wpdb->insert($table_name, $linked_data, $linked_formats);

                            // Clean up stale sync table rows for replaced article versions
                            if (!empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                                foreach ($article['previous_article_ids'] as $old_id) {
                                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                    $wpdb->delete($table_name, array('autoseo_id' => (string) $old_id), array('%s'));
                                }
                            }

                            // For previous version matches: refresh $existing and fall
                            // through to the update path so the rewritten content is
                            // pushed to WordPress in the same sync cycle.
                            if ($matched_via_previous_version) {
                                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                $existing = $wpdb->get_row($wpdb->prepare(
                                    "SELECT * FROM {$table_name} WHERE autoseo_id = %s",
                                    $article['id']
                                ));
                            }
                        }
                        
                        if (!$matched_via_previous_version) {
                            continue;
                        }
                    }
                }

                // Skip articles that were trashed by the WordPress user.
                // They stay in 'trashed' status until explicitly re-published
                // from the AutoSEO dashboard (which would reset them to 'pending').
                if ($existing && $existing->status === 'trashed') {
                    $this->log_debug(sprintf(
                        'Skipping trashed article "%s" (trashed by WordPress user)',
                        $article['title']
                    ));
                    $synced_count++;
                    continue;
                }

                // Check if article already published to WordPress (has a post_id)
                // Also treat 'publishing' status as already-in-progress to avoid race conditions
                $is_already_published = $existing && (!empty($existing->post_id) || $existing->status === 'publishing');
                
                // Prepare article data
                // If already published, keep 'published' status to avoid re-triggering publish_article()
                // which could create duplicates if the title has changed
                
                // Parse the intended publication date from the API
                // This is the date the article should show as published on WordPress
                $intended_published_at = null;
                if (!empty($article['published_at'])) {
                    // Convert ISO 8601 date to MySQL format in WordPress timezone
                    $timestamp = strtotime($article['published_at']);
                    if ($timestamp) {
                        $intended_published_at = gmdate('Y-m-d H:i:s', $timestamp);
                    }
                }
                
                $article_data = array(
                    'autoseo_id' => $article['id'],
                    'title' => $this->sanitize_for_db($article['title']),
                    'content' => $this->sanitize_for_db($article['content']),
                    'content_markdown' => $this->sanitize_for_db($article['content_markdown'] ?? null),
                    'excerpt' => $this->sanitize_for_db($article['excerpt'] ?? ''),
                    'keywords' => is_array($article['keywords']) ? implode(',', $article['keywords']) : '',
                    'meta_description' => $this->sanitize_for_db($article['meta_description'] ?? null),
                    'meta_keywords' => $article['meta_keywords'] ?? null,
                    'wordpress_tags' => $article['wordpress_tags'] ?? null,
                    'featured_image_url' => $article['featured_image_url'] ?? null,
                    'hero_image_url' => $article['hero_image_url'] ?? null,
                    'hero_image_alt' => $this->sanitize_for_db($article['hero_image_alt'] ?? null),
                    'infographic_html' => $this->sanitize_for_db($article['infographic_html'] ?? null),
                    'infographic_image_url' => $article['infographic_image_url'] ?? null,
                    'status' => $is_already_published ? 'published' : 'pending',
                    'synced_at' => current_time('mysql'),
                    'intended_published_at' => $intended_published_at,
                    'faq_schema' => isset($article['faq_schema']) ? wp_json_encode($article['faq_schema']) : null,
                    'previous_article_ids' => $previous_article_ids_json,
                );
                if ($has_language_col) {
                    $article_data['language'] = $article['language'] ?? null;
                }

                if ($existing) {
                    // For already-published articles, preserve old synced_at during the
                    // initial DB update. Only bump it after successful processing.
                    // This ensures failed updates (e.g. image download timeout) are
                    // retried on the next sync instead of being permanently skipped.
                    $update_data = $article_data;
                    if ($is_already_published) {
                        $update_data['synced_at'] = $existing->synced_at;
                    }

                    // Update existing article in sync table
                    $update_formats = array_fill(0, count($update_data), '%s');
                    $update_result = $wpdb->update(
                        $table_name,
                        $update_data,
                        array('autoseo_id' => $article['id']),
                        $update_formats,
                        array('%s')
                    );
                    
                    if ($update_result === false) {
                        $this->log_debug('Database update failed: ' . $wpdb->last_error);
                        $errors[] = sprintf('Database update failed for article "%s": %s', $article['title'], $wpdb->last_error);
                        continue;
                    }
                    
                    // If already published, check that the WP post is still alive,
                    // then update it only if content has changed.
                    if ($is_already_published) {
                        // FIRST: verify the WordPress post still exists and isn't trashed.
                        // This must run before the skip-unchanged check, otherwise trashed
                        // posts are never detected and the article stays in limbo forever.
                        $wp_post = get_post($existing->post_id);
                        if (!$wp_post || $wp_post->post_status === 'trash') {
                            // User (or another plugin) trashed/deleted this post.
                            // Respect that decision: mark as trashed in our table and notify
                            // AutoSEO so the dashboard reflects the correct state.
                            // Do NOT republish — if the user wants it back, they can
                            // restore from trash or re-publish from the AutoSEO dashboard.
                            $this->log_debug(sprintf(
                                'WordPress post %d for article "%s" was trashed/deleted by user, marking as trashed',
                                $existing->post_id,
                                $article['title']
                            ));
                            $wpdb->update(
                                $table_name,
                                array('post_id' => null, 'status' => 'trashed'),
                                array('id' => $existing->id),
                                array('%s', '%s'),
                                array('%d')
                            );

                            $this->send_webhook('article_trashed', array(
                                'article_id' => $article['id'],
                            ));

                            $synced_count++;
                            continue;
                        } else {
                            // If the API reports it never received our published URL,
                            // re-send the webhook so published_url gets set
                            $needs_url_confirmation = !empty($article['needs_url_confirmation']);

                            // Post is alive -- skip update if article content hasn't changed.
                            // API updated_at is UTC ISO 8601; synced_at is WordPress local time, so convert to UTC for comparison.
                            $api_updated_at = !empty($article['updated_at']) ? strtotime($article['updated_at']) : 0;
                            $synced_at_utc = !empty($existing->synced_at) ? strtotime(get_gmt_from_date($existing->synced_at)) : 0;

                            if ($api_updated_at > 0 && $synced_at_utc > 0 && $api_updated_at <= $synced_at_utc) {
                                if ($needs_url_confirmation) {
                                    $publisher = new AutoSEO_Publisher();
                                    $published_url = $publisher->get_post_permalink($wp_post->ID);
                                    $webhook_data = array(
                                        'article_id' => $article['id'],
                                        'wordpress_post_id' => $wp_post->ID,
                                        'published_url' => $published_url,
                                    );
                                    if (AutoSEO_Publisher::is_batching()) {
                                        AutoSEO_Publisher::add_to_batch($webhook_data);
                                    } else {
                                        $this->send_webhook('article_published', $webhook_data);
                                    }
                                    $this->log_debug(sprintf(
                                        'Re-sending URL confirmation webhook for article "%s" (URL: %s)',
                                        $article['title'],
                                        $published_url
                                    ));
                                } else {
                                    $this->log_debug(sprintf(
                                        'Skipping unchanged article "%s" (API updated_at: %s, synced_at: %s UTC)',
                                        $article['title'],
                                        $article['updated_at'] ?? 'unknown',
                                        gmdate('Y-m-d H:i:s', $synced_at_utc)
                                    ));
                                }
                                $synced_count++;
                                continue;
                            }

                            $skip_webhook = !$needs_url_confirmation;
                            $publisher = new AutoSEO_Publisher();
                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $refreshed_article = $wpdb->get_row($wpdb->prepare(
                                "SELECT * FROM {$table_name} WHERE id = %d",
                                $existing->id
                            ));
                            $update_result = $publisher->update_existing_article($existing->id, $refreshed_article, $wp_post, $skip_webhook, $is_push_mode);
                            if (!is_wp_error($update_result)) {
                                // In push mode, images are pushed separately by the server
                                // after the trigger-sync response, so skip asset checks.
                                $assets_complete = true;
                                if (!$is_push_mode) {
                                    if (!empty($article['infographic_image_url']) && !get_post_meta($wp_post->ID, '_autoseo_infographic_image_id', true)) {
                                        $assets_complete = false;
                                        $this->log_debug(sprintf(
                                            'Infographic download incomplete for post %d ("%s") - will retry on next sync',
                                            $wp_post->ID,
                                            $article['title']
                                        ));
                                    }
                                    if (!empty($article['hero_image_url']) && !has_post_thumbnail($wp_post->ID)) {
                                        $assets_complete = false;
                                        $this->log_debug(sprintf(
                                            'Hero image download incomplete for post %d ("%s") - will retry on next sync',
                                            $wp_post->ID,
                                            $article['title']
                                        ));
                                    }
                                }

                                if ($assets_complete) {
                                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                    $wpdb->update(
                                        $table_name,
                                        array('synced_at' => current_time('mysql')),
                                        array('id' => $existing->id),
                                        array('%s'),
                                        array('%d')
                                    );
                                }
                                $synced_count++;
                                $this->log_debug(sprintf(
                                    'Updated existing WordPress post %d for article "%s"%s',
                                    $existing->post_id,
                                    $article['title'],
                                    $assets_complete ? '' : ' (assets incomplete, synced_at preserved for retry)'
                                ));
                            } else {
                                $this->log_debug(sprintf(
                                    'Failed to update article "%s" - synced_at preserved for retry on next sync',
                                    $article['title']
                                ));
                                $errors[] = sprintf(
                                    /* translators: 1: article title, 2: error message */
                                    __('Failed to update article "%1$s": %2$s', 'getautoseo-ai-content-publisher'),
                                    $article['title'],
                                    $update_result->get_error_message()
                                );
                            }
                        }
                    }
                } else {
                    // Insert new article
                    $insert_formats = array_fill(0, count($article_data), '%s');
                    $insert_result = $wpdb->insert(
                        $table_name,
                        $article_data,
                        $insert_formats
                    );
                    
                    if ($insert_result === false) {
                        $this->log_debug('Database insert failed: ' . $wpdb->last_error);
                        $errors[] = sprintf('Database insert failed for article "%s": %s', $article['title'], $wpdb->last_error);
                        continue;
                    }
                }

                // Store author box thumbnail URL from API as a site-level option
                if (array_key_exists('author_box_thumbnail_url', $article)) {
                    update_option('autoseo_author_box_remote_url', $article['author_box_thumbnail_url'] ?: '');
                }

                // Auto-publish NEW articles (not already published ones)
                if ($article_data['status'] === 'pending') {
                    $article_table_id = $existing ? $existing->id : $wpdb->insert_id;
                    
                    $publisher = new AutoSEO_Publisher();
                    $publish_result = $publisher->publish_article($article_table_id, $is_push_mode);
                    
                    if (!is_wp_error($publish_result)) {
                        $synced_count++;
                    } else {
                        $errors[] = sprintf(
                            /* translators: 1: article title, 2: error message */
                            __('Failed to publish article "%1$s": %2$s', 'getautoseo-ai-content-publisher'),
                            $article['title'],
                            $publish_result->get_error_message()
                        );
                    }
                }

            } catch (Exception $e) {
                $errors[] = sprintf(
                    /* translators: 1: article title, 2: error message */
                    __('Error syncing article "%1$s": %2$s', 'getautoseo-ai-content-publisher'),
                    $article['title'] ?? 'Unknown',
                    $e->getMessage()
                );
            }
        }

        } finally {
            $batched_webhooks = AutoSEO_Publisher::end_batch();
        }

        if (!empty($batched_webhooks)) {
            $this->send_webhook('articles_batch_published', array(
                'articles' => $batched_webhooks,
            ));
            $this->log_debug(sprintf('Sent batched webhook for %d articles', count($batched_webhooks)));
        }

        return array(
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of articles synced */
                __('%d article(s) synced successfully', 'getautoseo-ai-content-publisher'),
                $synced_count
            ),
            'synced_count' => $synced_count,
            'errors' => $errors,
        );
    }

    /**
     * Trash WordPress posts for articles deleted from the AutoSEO dashboard.
     *
     * @param array  $deleted_article_ids AutoSEO article IDs that were deleted
     * @param string $table_name          wp_autoseo_articles table name
     */
    private function trash_deleted_articles($deleted_article_ids, $table_name) {
        global $wpdb;

        foreach ($deleted_article_ids as $deleted_id) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE autoseo_id = %s",
                (string) $deleted_id
            ));

            if (!$existing) {
                continue;
            }

            if ($existing->status === 'trashed') {
                $this->log_debug(sprintf('Article %s already trashed locally, skipping', $deleted_id));
                continue;
            }

            if (!empty($existing->post_id)) {
                $wp_post = get_post($existing->post_id);
                if ($wp_post && $wp_post->post_status !== 'trash') {
                    // Bypass the plugin's own trash-prevention hook
                    global $autoseo_allow_trash;
                    $autoseo_allow_trash = true;
                    wp_trash_post($existing->post_id);
                    $autoseo_allow_trash = false;
                    $this->log_debug(sprintf(
                        'Trashed WordPress post %d for deleted article %s ("%s")',
                        $existing->post_id,
                        $deleted_id,
                        $existing->title
                    ));
                }
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->update(
                $table_name,
                array('post_id' => null, 'status' => 'trashed'),
                array('id' => $existing->id),
                array('%s', '%s'),
                array('%d')
            );
        }
    }

    /**
     * Test API connection
     * 
     * @return array|WP_Error
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('API key is not configured', 'getautoseo-ai-content-publisher'));
        }

        $url = $this->api_base_url . '/articles/sync?limit=1';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-AutoSEO-Plugin-Version' => AUTOSEO_VERSION,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return array(
                'success' => true,
                'message' => __('Connection successful!', 'getautoseo-ai-content-publisher'),
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $error_message = isset($data['message']) ? $data['message'] : __('Connection failed', 'getautoseo-ai-content-publisher');

        return new WP_Error('connection_failed', $error_message, array('status' => $status_code));
    }

    /**
     * Send webhook to AutoSEO API
     * 
     * @param string $event Event name
     * @param array $data Event data
     * @return bool|WP_Error
     */
    public function send_webhook($event, $data = array()) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('API key is not configured', 'getautoseo-ai-content-publisher'));
        }

        // Per-article deduplication: skip if we sent the same event for this
        // article_id within the last 15 minutes
        $dedup_key = null;
        if ($event === 'article_published' && !empty($data['article_id'])) {
            $dedup_key = 'autoseo_wh_' . md5($event . '_' . $data['article_id']);
            if (get_transient($dedup_key)) {
                $this->log_debug(sprintf(
                    'Webhook deduplicated: %s for article %s (sent recently)',
                    $event,
                    $data['article_id']
                ));
                return true;
            }
        }

        $url = $this->api_base_url . '/webhooks/wordpress';

        $payload = array(
            'event' => $event,
            'data' => $data,
            'timestamp' => current_time('c'),
            'wordpress_site_url' => site_url(),
        );

        $json_body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $json_body, $this->api_key);

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-AutoSEO-Plugin-Version' => AUTOSEO_VERSION,
                'X-AutoSEO-Signature' => $signature,
            ),
            'body' => $json_body,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            $this->log_debug('Webhook send failed: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            if ($dedup_key) {
                set_transient($dedup_key, time(), 900);
            }
            $this->log_debug('Webhook sent successfully: ' . $event);
            return true;
        }

        $body = wp_remote_retrieve_body($response);
        $this->log_debug('Webhook send failed (status ' . $status_code . '): ' . $body);

        return new WP_Error('webhook_failed', __('Webhook delivery failed', 'getautoseo-ai-content-publisher'), array('status' => $status_code));
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('autoseo_debug_mode', '1');
        if ($debug_mode === '1') {
            error_log('[AutoSEO] ' . $message);
        }
    }
}






