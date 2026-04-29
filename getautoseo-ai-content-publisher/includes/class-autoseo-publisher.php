<?php
/**
 * AutoSEO Publisher
 * 
 * Handles publishing of synced articles to WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEO_Publisher {

    private static $batch_mode = false;
    private static $batched_webhooks = array();

    public static function start_batch() {
        if (self::$batch_mode) {
            return; // Already batching — don't reset collected webhooks
        }
        self::$batch_mode = true;
        self::$batched_webhooks = array();
    }

    public static function is_batching() {
        return self::$batch_mode;
    }

    public static function get_batched_webhooks() {
        return self::$batched_webhooks;
    }

    public static function add_to_batch($webhook_data) {
        self::$batched_webhooks[] = $webhook_data;
    }

    public static function end_batch() {
        $webhooks = self::$batched_webhooks;
        self::$batch_mode = false;
        self::$batched_webhooks = array();
        return $webhooks;
    }

    public function get_post_permalink($post_id) {
        return $this->get_published_url($post_id);
    }

    /**
     * Publish an article from the sync table to WordPress
     * 
     * @param int $article_table_id ID from wp_autoseo_articles table
     * @param bool $skip_image_downloads When true, skips outbound image downloads (images pushed separately by server)
     * @return array|WP_Error
     */
    public function publish_article($article_table_id, $skip_image_downloads = false) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';

        // Atomic claim: UPDATE status to 'publishing' only if currently 'pending'.
        // If another process already claimed this article, rows_affected = 0 → bail out.
        // This prevents two concurrent publish_article() calls from both creating WP posts.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET status = 'publishing' WHERE id = %d AND status = 'pending'",
            $article_table_id
        ));

        if (!$claimed) {
            // Either another process is publishing, or the article is already published.
            // Re-read to decide: if it has a post_id, update the existing post instead.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $article = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $article_table_id
            ));

            if ($article && $article->post_id) {
                $existing_post = get_post($article->post_id);
                if ($existing_post && $existing_post->post_status !== 'trash') {
                    return $this->update_existing_article($article_table_id, $article, $existing_post, false, $skip_image_downloads);
                }
            }

            $this->log_debug(sprintf(
                'Publish skipped for article table ID %d: could not claim (status: %s)',
                $article_table_id,
                $article ? $article->status : 'not found'
            ));
            return new WP_Error('publish_skipped', __('Article already being published by another process', 'getautoseo-ai-content-publisher'));
        }

        // Re-read article after claiming
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $article_table_id
        ));

        if (!$article) {
            return new WP_Error('article_not_found', __('Article not found in sync table', 'getautoseo-ai-content-publisher'));
        }

        // Check if article already has a WordPress post linked
        if ($article->post_id) {
            $existing_post = get_post($article->post_id);
            if ($existing_post) {
                if ($existing_post->post_status === 'trash') {
                    $this->log_debug(sprintf(
                        'Article "%s" (ID: %d) - WordPress post %d is trashed, will create new post',
                        $article->title,
                        $article->autoseo_id,
                        $article->post_id
                    ));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $table_name,
                        array('post_id' => null),
                        array('id' => $article_table_id),
                        array('%s'),
                        array('%d')
                    );
                } else {
                    $this->log_debug(sprintf(
                        'Article "%s" (ID: %d) already published - updating WordPress post (Post ID: %d, status: %s)',
                        $article->title,
                        $article->autoseo_id,
                        $article->post_id,
                        $existing_post->post_status
                    ));
                    return $this->update_existing_article($article_table_id, $article, $existing_post, false, $skip_image_downloads);
                }
            }
        }

        // DUPLICATE PREVENTION (meta-based): Check if a post with this autoseo_id already exists
        // This is the most reliable check because _autoseo_article_id is set atomically
        // during wp_insert_post via meta_input, and is immune to title encoding issues
        if (!empty($article->autoseo_id)) {
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
                        'value' => $article->autoseo_id,
                    ),
                ),
            ));
            $existing_post_by_meta = !empty($meta_query->posts) ? $meta_query->posts[0] : null;
            wp_reset_postdata();

            if ($existing_post_by_meta && $existing_post_by_meta->post_status !== 'trash') {
                $wpdb->update(
                    $table_name,
                    array(
                        'post_id' => $existing_post_by_meta->ID,
                        'status' => 'published',
                    ),
                    array('id' => $article_table_id),
                    array('%d', '%s'),
                    array('%d')
                );

                $this->log_debug(sprintf(
                    'Article "%s" (autoseo_id: %s) found existing post by meta (Post ID: %d) - linking and updating',
                    $article->title,
                    $article->autoseo_id,
                    $existing_post_by_meta->ID
                ));

                return $this->update_existing_article($article_table_id, $article, $existing_post_by_meta, false, $skip_image_downloads);
            }
        }

        // DUPLICATE PREVENTION (previous versions): Check if this article replaces a previous version.
        // When users submit feedback, AutoSEO creates a new article version with a new ID.
        // The previous_article_ids array contains all ancestor IDs so we can find the existing
        // WordPress post and update it instead of creating a duplicate.
        if (!empty($article->previous_article_ids)) {
            $prev_ids = is_string($article->previous_article_ids) 
                ? json_decode($article->previous_article_ids, true) 
                : (array) $article->previous_article_ids;
            
            if (!empty($prev_ids)) {
                foreach ($prev_ids as $prev_id) {
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
                    $existing_post_by_prev = !empty($prev_meta_query->posts) ? $prev_meta_query->posts[0] : null;
                    wp_reset_postdata();

                    if ($existing_post_by_prev && $existing_post_by_prev->post_status !== 'trash') {
                        update_post_meta($existing_post_by_prev->ID, '_autoseo_article_id', $article->autoseo_id);

                        $wpdb->update(
                            $table_name,
                            array(
                                'post_id' => $existing_post_by_prev->ID,
                                'status' => 'published',
                            ),
                            array('id' => $article_table_id),
                            array('%d', '%s'),
                            array('%d')
                        );

                        $this->log_debug(sprintf(
                            'Article "%s" (autoseo_id: %s) found existing post via previous version %s (Post ID: %d) - updating meta and content',
                            $article->title,
                            $article->autoseo_id,
                            $prev_id,
                            $existing_post_by_prev->ID
                        ));

                        return $this->update_existing_article($article_table_id, $article, $existing_post_by_prev, false, $skip_image_downloads);
                    }
                }
            }
        }

        // DUPLICATE PREVENTION (title-based): Fallback check for posts without _autoseo_article_id meta
        // Excludes trashed posts so articles can be republished after user deletes old copies
        $title_query = new WP_Query(array(
            'post_type'              => 'post',
            'title'                  => $article->title,
            'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ));
        $existing_post_by_title = !empty($title_query->posts) ? $title_query->posts[0] : null;
        wp_reset_postdata();
        
        if ($existing_post_by_title) {
            $wpdb->update(
                $table_name,
                array(
                    'post_id' => $existing_post_by_title->ID,
                    'status' => 'published',
                ),
                array('id' => $article_table_id),
                array('%d', '%s'),
                array('%d')
            );

            $this->log_debug(sprintf(
                'Article "%s" found existing WordPress post by title (ID: %d) - linking and updating content',
                $article->title,
                $existing_post_by_title->ID
            ));

            return $this->update_existing_article($article_table_id, $article, $existing_post_by_title, false, $skip_image_downloads);
        }

        // Get default settings
        $default_category = get_option('autoseo_post_category', 1);
        $default_author = get_option('autoseo_author_id', 0);
        
        // Ensure we have a valid author - fall back to first admin if not set
        // During cron execution, get_current_user_id() returns 0, which causes failures
        if (empty($default_author) || $default_author == 0) {
            // Get the first administrator as fallback
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $default_author = !empty($admins) ? $admins[0] : 1;
        }

        // Prepare post data
        // Explicitly set post_name (slug) to prevent WordPress from sometimes failing to auto-generate it
        // This ensures proper SEO-friendly URLs instead of fallback ?p=ID format
        $post_data = array(
            'post_title' => $article->title,
            'post_name' => sanitize_title($article->title),
            'post_content' => $article->content,
            'post_excerpt' => $article->excerpt,
            'post_status' => 'publish',
            'post_author' => $default_author,
            'post_category' => array($default_category),
            'post_type' => 'post',
            'meta_input' => array(
                '_autoseo_article_id' => $article->autoseo_id,
                '_autoseo_managed' => '1',
                '_autoseo_webhook_sent' => (string) time(),
            ),
        );
        
        // Set the post date to the intended publication date from AutoSEO
        // This ensures blog posts show the correct date instead of the sync time
        if (!empty($article->intended_published_at)) {
            // intended_published_at is stored in UTC/GMT format
            $post_data['post_date_gmt'] = $article->intended_published_at;
            // Convert GMT to local time for post_date
            $post_data['post_date'] = get_date_from_gmt($article->intended_published_at);
            $this->log_debug(sprintf(
                'Setting post date from AutoSEO: GMT=%s, Local=%s',
                $article->intended_published_at,
                $post_data['post_date']
            ));
        }

        // Handle hero image (preferred for featured image)
        // Skip downloads when images will be pushed separately by the server
        $featured_image_id = null;
        $hero_image_url = null;
        if (!$skip_image_downloads) {
            $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
            if (!empty($article->hero_image_url)) {
                $hero_image_url = $article->hero_image_url;
                $featured_image_id = $this->download_and_attach_image(
                    $article->hero_image_url,
                    $article->title . ' - Hero Image',
                    null,
                    $hero_alt
                );
            } elseif (!empty($article->featured_image_url)) {
                $hero_image_url = $article->featured_image_url;
                $featured_image_id = $this->download_and_attach_image(
                    $article->featured_image_url,
                    $article->title . ' - Featured Image',
                    null,
                    $hero_alt
                );
            }
        } else {
            $this->log_debug('Skipping hero image download - images will be pushed separately by server');
        }

        // Insert the post - pass true to return WP_Error on failure
        AutoSEO_Plugin::allow_content_updates();
        try {
            $post_id = wp_insert_post($post_data, true);
        } finally {
            AutoSEO_Plugin::disallow_content_updates();
        }

        if (is_wp_error($post_id)) {
            $this->log_debug('Failed to create WordPress post: ' . $post_id->get_error_message());
            return $post_id;
        }
        
        // Also check for 0 return (shouldn't happen with true param, but be safe)
        if (empty($post_id)) {
            $this->log_debug('Failed to create WordPress post: wp_insert_post returned empty');
            return new WP_Error('insert_failed', __('Failed to create WordPress post', 'getautoseo-ai-content-publisher'));
        }

        // Some themes/plugins auto-inject page-builder meta on wp_insert_post hooks.
        // Clean it immediately so the post renders via standard post_content.
        $this->clear_page_builder_meta($post_id);

        // Assign WPML language if the plugin is active and article has a language
        if (!empty($article->language)) {
            $this->set_wpml_language($post_id, $article->language);
        }

        // Set featured image if we have one
        if ($featured_image_id) {
            set_post_thumbnail($post_id, $featured_image_id);
            if ($hero_image_url) {
                update_post_meta($post_id, '_autoseo_hero_image_url', $hero_image_url);
            }
            update_post_meta($post_id, '_autoseo_hero_attachment_id', $featured_image_id);
        }

        // Handle infographic image (download and attach to post)
        if (!$skip_image_downloads && !empty($article->infographic_image_url)) {
            $infographic_image_id = $this->download_and_attach_image(
                $article->infographic_image_url,
                $article->title . ' - Infographic',
                $post_id,
                $article->title
            );
            
            if ($infographic_image_id) {
                update_post_meta($post_id, '_autoseo_infographic_image_id', $infographic_image_id);
                update_post_meta($post_id, '_autoseo_infographic_image_url', $article->infographic_image_url);
            }
        } elseif ($skip_image_downloads && !empty($article->infographic_image_url)) {
            $this->log_debug('Skipping infographic image download - images will be pushed separately by server');
        }

        // Handle author box thumbnail — download once and replace URL in content
        if (!$skip_image_downloads) {
            $author_thumb_url = get_option('autoseo_author_box_remote_url', '');
            if (!empty($author_thumb_url)) {
                $this->handle_author_thumbnail($post_id, $author_thumb_url);
            }
        }

        // Store or clear infographic HTML as post meta
        if (!empty($article->infographic_html)) {
            update_post_meta($post_id, '_autoseo_infographic_html', $article->infographic_html);
            $this->bake_infographic_into_content($post_id);
        } else {
            $this->strip_infographic_from_content($post_id);
        }

        // Store keywords as post meta
        if (!empty($article->keywords)) {
            $keywords_array = explode(',', $article->keywords);
            $keywords_array = array_map('trim', $keywords_array);
            update_post_meta($post_id, '_autoseo_keywords', $keywords_array);
        }

        // Store markdown content for LLM-friendly .md URLs
        if (!empty($article->content_markdown)) {
            update_post_meta($post_id, '_autoseo_content_markdown', $article->content_markdown);
        }

        // Store meta description and keywords for SEO
        $this->set_seo_meta_fields($post_id, $article);

        // Set WordPress tags
        $this->set_wordpress_tags($post_id, $article);

        // Update sync table with post_id and published status
        $wpdb->update(
            $table_name,
            array(
                'post_id' => $post_id,
                'status' => 'published',
                'published_at' => current_time('mysql'),
            ),
            array('id' => $article_table_id),
            array('%d', '%s', '%s'),
            array('%d')
        );

        // Get the published URL (handles future/scheduled posts correctly)
        $published_url = $this->get_published_url($post_id);

        // Refresh the webhook_sent timestamp (was initially set via meta_input before
        // wp_insert_post hooks fired, to prevent transition_post_status duplicates)
        update_post_meta($post_id, '_autoseo_webhook_sent', (string) time());

        $webhook_data = array(
            'article_id' => $article->autoseo_id,
            'wordpress_post_id' => $post_id,
            'published_url' => $published_url,
        );

        if (self::$batch_mode) {
            self::$batched_webhooks[] = $webhook_data;
        } else {
            $api = new AutoSEO_API();
            $api->send_webhook('article_published', $webhook_data);
        }

        $this->log_debug(sprintf(
            'Article "%s" published successfully (Post ID: %d, URL: %s, webhook: %s)',
            $article->title,
            $post_id,
            $published_url,
            self::$batch_mode ? 'batched' : 'sent'
        ));

        return array(
            'success' => true,
            'message' => __('Article published successfully', 'getautoseo-ai-content-publisher'),
            'post_id' => $post_id,
            'published_url' => $published_url,
        );
    }

    /**
     * Bake infographic image directly into post_content so it doesn't depend
     * on the_content filter injection (which some themes/page-builders break).
     *
     * The the_content filter (inject_infographic_image_into_content) remains as
     * a backward-compatible fallback — it checks for 'autoseo-infographic-container'
     * in the content and skips if already present.
     */
    public function bake_infographic_into_content($post_id) {
        $infographic_image_id = get_post_meta($post_id, '_autoseo_infographic_image_id', true);
        if (empty($infographic_image_id)) {
            return;
        }

        if (!wp_get_attachment_url($infographic_image_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;

        // Strip any previously baked infographic so we can re-position it
        // if the article content changed during a sync update.
        // Try comment-wrapped version first (robust against nested divs from
        // lazy-loading plugins), then fall back to bare-div regex.
        $content = preg_replace(
            '/<!-- autoseo-infographic -->.*?<!-- \/autoseo-infographic -->\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/<div class="autoseo-infographic-container">.*?<\/div>\s*/s',
            '',
            $content
        );

        // Safety: if the class still appears after stripping (e.g. nested-div
        // edge case), skip injection to avoid duplicates.
        if (strpos($content, 'autoseo-infographic-container') !== false) {
            $this->log_debug(sprintf(
                'Skipped baking infographic for post %d - container class still present after strip',
                $post_id
            ));
            return;
        }

        $infographic_alt = get_post_meta($infographic_image_id, '_wp_attachment_image_alt', true);
        if (empty($infographic_alt)) {
            $infographic_alt = $post->post_title;
        }

        $infographic_html = wp_get_attachment_image($infographic_image_id, 'full', false, array(
            'class' => 'autoseo-infographic-image',
            'alt'   => $infographic_alt,
        ));

        if (empty($infographic_html)) {
            return;
        }

        // Comment markers make future stripping reliable regardless of
        // nested HTML from lazy-loading or image-optimization plugins.
        $infographic_block = '<!-- autoseo-infographic -->'
            . '<div class="autoseo-infographic-container">' . $infographic_html . '</div>'
            . '<!-- /autoseo-infographic -->';

        // Insert before the middle H2 heading (same logic as the_content filter)
        preg_match_all('/<h2[^>]*>.*?<\/h2>/is', $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            $content .= $infographic_block;
        } else {
            $headings = $matches[0];
            $middle_index = (int) floor(count($headings) / 2);
            $insert_position = $headings[$middle_index][1];
            $content = substr($content, 0, $insert_position)
                     . $infographic_block
                     . substr($content, $insert_position);
        }

        AutoSEO_Plugin::allow_content_updates();
        try {
            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $content,
            ));
        } finally {
            AutoSEO_Plugin::disallow_content_updates();
        }

        $this->log_debug(sprintf('Baked infographic into post_content for post %d', $post_id));
    }

    /**
     * Remove any previously baked infographic from post_content and clear
     * related post meta. Called when the server sends null infographic data
     * (user disabled infographics on the site).
     */
    public function strip_infographic_from_content($post_id) {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;
        $original = $content;

        $content = preg_replace(
            '/<!-- autoseo-infographic -->.*?<!-- \/autoseo-infographic -->\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/<div class="autoseo-infographic-container">.*?<\/div>\s*/s',
            '',
            $content
        );

        if ($content !== $original) {
            AutoSEO_Plugin::allow_content_updates();
            try {
                wp_update_post(array(
                    'ID'           => $post_id,
                    'post_content' => $content,
                ));
            } finally {
                AutoSEO_Plugin::disallow_content_updates();
            }
            $this->log_debug(sprintf('Stripped infographic from post_content for post %d', $post_id));
        }

        delete_post_meta($post_id, '_autoseo_infographic_html');
        delete_post_meta($post_id, '_autoseo_infographic_image_id');
        delete_post_meta($post_id, '_autoseo_infographic_image_url');
    }

    /**
     * Download and attach an image from URL
     * 
     * @param string $image_url URL of the image
     * @param string $title Title for the attachment
     * @param int|null $post_id Post ID to attach to (optional)
     * @return int|false Attachment ID or false on failure
     */
    private function download_and_attach_image($image_url, $title = '', $post_id = null, $alt_text = '') {
        if (empty($image_url)) {
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Download the image
        $tmp_file = download_url($image_url);

        if (is_wp_error($tmp_file)) {
            $this->log_debug('Failed to download image: ' . $tmp_file->get_error_message());
            return false;
        }

        // Get file extension from URL
        $file_ext = pathinfo(wp_parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_ext)) {
            $file_ext = 'jpg'; // Default to jpg
        }

        // Prepare file array
        $file_array = array(
            'name' => sanitize_file_name($title . '.' . $file_ext),
            'tmp_name' => $tmp_file,
        );

        // Check for download errors
        if (!file_exists($file_array['tmp_name'])) {
            wp_delete_file($file_array['tmp_name']);
            $this->log_debug('Downloaded image file does not exist');
            return false;
        }

        // Upload the image to WordPress media library
        $attachment_id = media_handle_sideload($file_array, $post_id, $title);

        // Check for handle sideload errors
        if (is_wp_error($attachment_id)) {
            wp_delete_file($file_array['tmp_name']);
            $this->log_debug('Failed to sideload image: ' . $attachment_id->get_error_message());
            return false;
        }

        // Set alt text on the attachment
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return $attachment_id;
    }

    /**
     * Handle author box thumbnail: download once to WP media library,
     * store in options, and replace the remote URL in post content with the local one.
     * Only re-downloads if the source URL has changed.
     */
    private function handle_author_thumbnail($post_id, $remote_url) {
        $stored_source_url = get_option('autoseo_author_thumbnail_source_url', '');
        $stored_local_url = get_option('autoseo_author_thumbnail_local_url', '');
        $stored_attachment_id = get_option('autoseo_author_thumbnail_attachment_id', 0);

        $needs_download = false;

        if (empty($stored_source_url) || $remote_url !== $stored_source_url) {
            $needs_download = true;
        } elseif (!empty($stored_attachment_id) && !wp_get_attachment_url($stored_attachment_id)) {
            $needs_download = true;
            $this->log_debug('Author thumbnail attachment missing — will re-download');
        }

        if ($needs_download) {
            $attachment_id = $this->download_and_attach_image(
                $remote_url,
                'Author Thumbnail',
                null,
                'Author photo'
            );

            if ($attachment_id) {
                $local_url = wp_get_attachment_url($attachment_id);
                update_option('autoseo_author_thumbnail_source_url', $remote_url);
                update_option('autoseo_author_thumbnail_local_url', $local_url);
                update_option('autoseo_author_thumbnail_attachment_id', $attachment_id);
                $stored_local_url = $local_url;
                $this->log_debug(sprintf(
                    'Author thumbnail downloaded (attachment %d) — replacing URL in content',
                    $attachment_id
                ));
            } else {
                $this->log_debug('Failed to download author thumbnail — keeping remote URL');
                return;
            }
        }

        if (!empty($stored_local_url) && $stored_local_url !== $remote_url) {
            $post = get_post($post_id);
            if ($post && strpos($post->post_content, $remote_url) !== false) {
                $updated_content = str_replace($remote_url, $stored_local_url, $post->post_content);
                AutoSEO_Plugin::allow_content_updates();
                try {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $updated_content,
                    ));
                } finally {
                    AutoSEO_Plugin::disallow_content_updates();
                }
                $this->log_debug(sprintf(
                    'Replaced author thumbnail URL in post %d content',
                    $post_id
                ));
            }
        }
    }

    /**
     * Update an existing WordPress post with new article content
     * 
     * @param int $article_table_id ID from wp_autoseo_articles table
     * @param object $article Article data from sync table
     * @param WP_Post $existing_post Existing WordPress post
     * @param bool $skip_webhook Skip sending the article_published webhook (used during bulk sync to avoid timeouts)
     * @param bool $skip_image_downloads When true, skips outbound image downloads (images pushed separately by server)
     * @return array|WP_Error
     */
    public function update_existing_article($article_table_id, $article, $existing_post, $skip_webhook = false, $skip_image_downloads = false) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';
        
        // Validate inputs
        if (!$existing_post || !$existing_post->ID) {
            $this->log_debug('update_existing_article failed: Invalid existing_post object');
            return new WP_Error('invalid_post', __('Invalid existing post object', 'getautoseo-ai-content-publisher'));
        }
        
        if (empty($article->content)) {
            $this->log_debug('update_existing_article failed: Article content is empty');
            return new WP_Error('empty_content', __('Article content is empty', 'getautoseo-ai-content-publisher'));
        }
        
        // Get the author - keep existing author, with fallback to first admin
        $post_author = $existing_post->post_author;
        if (empty($post_author) || $post_author == 0) {
            // Get first administrator as fallback
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $post_author = !empty($admins) ? $admins[0] : 1;
            $this->log_debug(sprintf(
                'Post %d has invalid author, using fallback author ID: %d',
                $existing_post->ID,
                $post_author
            ));
        }

        // Don't set post_category on update - preserve whatever category the user has chosen
        $post_data = array(
            'ID' => $existing_post->ID,
            'post_title' => !empty($article->title) ? $article->title : $existing_post->post_title,
            'post_content' => $article->content,
            'post_excerpt' => isset($article->excerpt) ? $article->excerpt : '',
            'post_author' => $post_author,
            'meta_input' => array(
                '_autoseo_article_id' => $article->autoseo_id,
                '_autoseo_managed' => '1',
            ),
        );

        // Update post date if intended_published_at is provided and differs from current
        if (!empty($article->intended_published_at)) {
            $new_gmt = $article->intended_published_at;
            $current_gmt = $existing_post->post_date_gmt;
            if ($new_gmt !== $current_gmt) {
                $post_data['post_date_gmt'] = $new_gmt;
                $post_data['post_date'] = get_date_from_gmt($new_gmt);
                $this->log_debug(sprintf(
                    'Updating post date for post %d: old GMT=%s, new GMT=%s, new local=%s',
                    $existing_post->ID,
                    $current_gmt,
                    $new_gmt,
                    $post_data['post_date']
                ));
            }
        }

        // IMPORTANT: Never update the slug on existing posts.
        // Changing slugs breaks published URLs, kills SEO value, and causes 404s
        // for anyone who bookmarked or linked to the original URL.

        // If the user has edited this post with a page builder (Elementor, Divi,
        // etc.) since we last published it, respect their changes: skip the
        // content update and keep all page-builder meta intact.
        $page_builder = self::has_page_builder_content($existing_post->ID);

        if ($page_builder) {
            // Only update title and meta — leave post_content, post_excerpt,
            // and all page-builder meta completely untouched.  We avoid
            // wp_update_post here because it merges with the existing row and
            // re-runs content_save_pre (KSES) on the Elementor HTML, which
            // could degrade the stored content over many syncs.
            $title = !empty($article->title) ? $article->title : $existing_post->post_title;
            $wpdb->update(
                $wpdb->posts,
                array('post_title' => $title, 'post_author' => $post_author),
                array('ID' => $existing_post->ID),
                array('%s', '%d'),
                array('%d')
            );
            clean_post_cache($existing_post->ID);
            update_post_meta($existing_post->ID, '_autoseo_article_id', $article->autoseo_id);
            update_post_meta($existing_post->ID, '_autoseo_managed', '1');

            $this->log_debug(sprintf(
                'Post %d has %s edits — preserving user content, updating title + metadata only',
                $existing_post->ID,
                $page_builder
            ));
        } else {
            $this->clear_page_builder_meta($existing_post->ID);
            $this->log_debug(sprintf(
                'Updating WordPress post %d with content length: %d bytes',
                $existing_post->ID,
                strlen($article->content)
            ));

            AutoSEO_Plugin::allow_content_updates();
            try {
                $result = wp_update_post($post_data, true);
            } finally {
                AutoSEO_Plugin::disallow_content_updates();
            }

            if (is_wp_error($result)) {
                $this->log_debug('Failed to update WordPress post: ' . $result->get_error_message());
                return $result;
            }
            
            if (empty($result) || $result === 0) {
                $this->log_debug('wp_update_post returned empty/zero for post ' . $existing_post->ID);
                return new WP_Error('update_failed', __('WordPress post update returned invalid result', 'getautoseo-ai-content-publisher'));
            }
        }
        
        $this->log_debug(sprintf(
            'Successfully updated WordPress post %d',
            $existing_post->ID
        ));

        // Assign WPML language if the plugin is active and article has a language
        if (!empty($article->language)) {
            $this->set_wpml_language($existing_post->ID, $article->language);
        }

        // Handle hero/featured image - ONLY download if URL has ACTUALLY changed
        // When $skip_image_downloads is true, images are pushed separately by the server
        if (!$skip_image_downloads) {
            $current_hero_url = get_post_meta($existing_post->ID, '_autoseo_hero_image_url', true);
            $new_hero_url = !empty($article->hero_image_url) ? $article->hero_image_url : $article->featured_image_url;
            $has_featured_image = has_post_thumbnail($existing_post->ID);
            
            // Validate that the featured image attachment actually exists (file not deleted)
            if ($has_featured_image) {
                $thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                if ($thumbnail_id && !wp_get_attachment_url($thumbnail_id)) {
                    $has_featured_image = false;
                    delete_post_meta($existing_post->ID, '_thumbnail_id');
                    delete_post_meta($existing_post->ID, '_autoseo_hero_image_url');
                    delete_post_meta($existing_post->ID, '_autoseo_hero_attachment_id');
                    $current_hero_url = '';
                    $this->log_debug(sprintf(
                        'Hero image attachment %d for post %d is missing/deleted - will re-download',
                        $thumbnail_id,
                        $existing_post->ID
                    ));
                }
            }
            
            $should_download_hero = false;
            
            if (!empty($new_hero_url)) {
                if (!$has_featured_image) {
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - no featured image exists',
                        $existing_post->ID
                    ));
                } elseif (!empty($current_hero_url) && $new_hero_url !== $current_hero_url) {
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - URL changed from "%s" to "%s"',
                        $existing_post->ID,
                        $current_hero_url,
                        $new_hero_url
                    ));
                } elseif (empty($current_hero_url) && $has_featured_image) {
                    // Post has a featured image but no AutoSEO tracking URL.
                    // The existing thumbnail may have been set by the WordPress theme,
                    // another plugin, or from a pre-existing post matched by title —
                    // not necessarily the correct AutoSEO hero image.
                    // Re-download to ensure the correct hero image is used.
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - featured image exists but no AutoSEO tracking URL (ensuring correct hero image)',
                        $existing_post->ID
                    ));
                } else {
                    // URL unchanged — verify the thumbnail was actually set by AutoSEO.
                    $autoseo_attachment_id = get_post_meta($existing_post->ID, '_autoseo_hero_attachment_id', true);
                    $current_thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                    if (empty($autoseo_attachment_id)) {
                        // Legacy post — no tracking yet (pre-fix). Re-download once to
                        // ensure the thumbnail is actually our hero image and set tracking.
                        $should_download_hero = true;
                        $this->log_debug(sprintf(
                            'Hero image re-download needed for post %d - URL tracked but no verified attachment (legacy post)',
                            $existing_post->ID
                        ));
                    } elseif ((int) $autoseo_attachment_id !== (int) $current_thumbnail_id) {
                        // AutoSEO set the thumbnail, but it was changed afterward
                        // (user manually changed it in WP or a plugin swapped it).
                        // Respect the change — update tracking to match current thumbnail.
                        update_post_meta($existing_post->ID, '_autoseo_hero_attachment_id', $current_thumbnail_id);
                        $this->log_debug(sprintf(
                            'Hero image thumbnail changed externally for post %d - respecting change (was: %s, now: %s)',
                            $existing_post->ID,
                            $autoseo_attachment_id,
                            $current_thumbnail_id
                        ));
                    } else {
                        $this->log_debug(sprintf(
                            'Skipping hero image download for post %d - URL unchanged and thumbnail verified ("%s")',
                            $existing_post->ID,
                            $new_hero_url
                        ));
                    }
                }
            }
            
            if ($should_download_hero) {
                $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
                $featured_image_id = $this->download_and_attach_image(
                    $new_hero_url,
                    $article->title . ' - Hero Image',
                    null,
                    $hero_alt
                );
                
                if ($featured_image_id) {
                    set_post_thumbnail($existing_post->ID, $featured_image_id);
                    update_post_meta($existing_post->ID, '_autoseo_hero_image_url', $new_hero_url);
                    update_post_meta($existing_post->ID, '_autoseo_hero_attachment_id', $featured_image_id);
                }
            } else {
                // Even when not re-downloading, update alt text on existing attachment
                $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
                $thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                if ($thumbnail_id && !empty($hero_alt)) {
                    $existing_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                    if (empty($existing_alt)) {
                        update_post_meta($thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field($hero_alt));
                    }
                }
            }
        } else {
            $this->log_debug(sprintf(
                'Skipping hero image download for post %d - images will be pushed separately by server',
                $existing_post->ID
            ));
        }

        // Handle infographic image - ONLY download if URL has ACTUALLY changed
        if (!$skip_image_downloads) {
        $current_infographic_url = get_post_meta($existing_post->ID, '_autoseo_infographic_image_url', true);
        $current_infographic_id = get_post_meta($existing_post->ID, '_autoseo_infographic_image_id', true);
        
        // Validate that the infographic attachment actually exists (file not deleted)
        if (!empty($current_infographic_id) && !wp_get_attachment_url($current_infographic_id)) {
            $this->log_debug(sprintf(
                'Infographic attachment %d for post %d is missing/deleted - will re-download',
                $current_infographic_id,
                $existing_post->ID
            ));
            delete_post_meta($existing_post->ID, '_autoseo_infographic_image_id');
            delete_post_meta($existing_post->ID, '_autoseo_infographic_image_url');
            $current_infographic_id = '';
            $current_infographic_url = '';
        }
        
        // Determine if we should download the infographic
        $should_download_infographic = false;
        
        if (!empty($article->infographic_image_url)) {
            if (empty($current_infographic_id)) {
                // No infographic exists yet - download it
                $should_download_infographic = true;
                $this->log_debug(sprintf(
                    'Infographic download needed for post %d - no infographic exists',
                    $existing_post->ID
                ));
            } elseif (!empty($current_infographic_url) && $article->infographic_image_url !== $current_infographic_url) {
                // URL has changed from a KNOWN previous value - re-download
                $should_download_infographic = true;
                $this->log_debug(sprintf(
                    'Infographic download needed for post %d - URL changed',
                    $existing_post->ID
                ));
            } elseif (empty($current_infographic_url) && !empty($current_infographic_id)) {
                // Infographic exists but URL meta doesn't (pre-v1.3.5 post)
                // DON'T download, just store the URL
                update_post_meta($existing_post->ID, '_autoseo_infographic_image_url', $article->infographic_image_url);
                $this->log_debug(sprintf(
                    'Skipping infographic download for post %d - infographic exists (pre-v1.3.5 post), storing URL for future tracking',
                    $existing_post->ID
                ));
            } else {
                $this->log_debug(sprintf(
                    'Skipping infographic download for post %d - URL unchanged',
                    $existing_post->ID
                ));
            }
        }
        
        if ($should_download_infographic) {
            $infographic_image_id = $this->download_and_attach_image(
                $article->infographic_image_url,
                $article->title . ' - Infographic',
                $existing_post->ID,
                $article->title
            );
            
            if ($infographic_image_id) {
                update_post_meta($existing_post->ID, '_autoseo_infographic_image_id', $infographic_image_id);
                update_post_meta($existing_post->ID, '_autoseo_infographic_image_url', $article->infographic_image_url);
            }
        }
        } else {
            $this->log_debug(sprintf(
                'Skipping infographic image download for post %d - images will be pushed separately by server',
                $existing_post->ID
            ));
        }

        // Skip content-modifying operations when user has page-builder edits
        if (!$page_builder) {
            // Handle author box thumbnail — download once and replace URL in content
            if (!$skip_image_downloads) {
                $author_thumb_url = get_option('autoseo_author_box_remote_url', '');
                if (!empty($author_thumb_url)) {
                    $this->handle_author_thumbnail($existing_post->ID, $author_thumb_url);
                }
            }

            // Update or clear infographic HTML
            if (!empty($article->infographic_html)) {
                update_post_meta($existing_post->ID, '_autoseo_infographic_html', $article->infographic_html);
                $this->bake_infographic_into_content($existing_post->ID);
            } else {
                $this->strip_infographic_from_content($existing_post->ID);
            }
        }

        // Update keywords
        if (!empty($article->keywords)) {
            $keywords_array = explode(',', $article->keywords);
            $keywords_array = array_map('trim', $keywords_array);
            update_post_meta($existing_post->ID, '_autoseo_keywords', $keywords_array);
        }

        // Update markdown content for LLM-friendly .md URLs
        if (!empty($article->content_markdown)) {
            update_post_meta($existing_post->ID, '_autoseo_content_markdown', $article->content_markdown);
        }

        // Update meta description and keywords for SEO
        $this->set_seo_meta_fields($existing_post->ID, $article);

        // Update WordPress tags
        $this->set_wordpress_tags($existing_post->ID, $article);

        // Update sync table
        $wpdb->update(
            $table_name,
            array(
                'status' => 'published',
                'published_at' => current_time('mysql'),
            ),
            array('id' => $article_table_id),
            array('%s', '%s'),
            array('%d')
        );

        $published_url = $this->get_published_url($existing_post->ID);

        if (!$skip_webhook) {
            $webhook_data = array(
                'article_id' => $article->autoseo_id,
                'wordpress_post_id' => $existing_post->ID,
                'published_url' => $published_url,
            );

            if (self::$batch_mode) {
                self::$batched_webhooks[] = $webhook_data;
            } else {
                $api = new AutoSEO_API();
                $api->send_webhook('article_published', $webhook_data);
            }
        }

        $this->log_debug(sprintf(
            'Article "%s" updated successfully (Post ID: %d, URL: %s, webhook: %s)',
            $article->title,
            $existing_post->ID,
            $published_url,
            $skip_webhook ? 'skipped' : (self::$batch_mode ? 'batched' : 'sent')
        ));

        return array(
            'success' => true,
            'message' => __('Article updated successfully', 'getautoseo-ai-content-publisher'),
            'post_id' => $existing_post->ID,
            'published_url' => $published_url,
            'action' => 'updated',
        );
    }

    /**
     * Publish all pending articles
     * 
     * @param int $limit Maximum number of articles to publish (default: 10)
     * @return array
     */
    public function publish_pending_articles($limit = 10) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'autoseo_articles';
        
        // Get pending articles
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $pending_articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY synced_at ASC LIMIT %d",
            'pending',
            $limit
        ));

        $published_count = 0;
        $errors = array();

        self::start_batch();

        try {
            foreach ($pending_articles as $article) {
                $result = $this->publish_article($article->id);
                
                if (!is_wp_error($result)) {
                    $published_count++;
                } else {
                    $errors[] = sprintf(
                        /* translators: 1: article title, 2: error message */
                        __('Failed to publish "%1$s": %2$s', 'getautoseo-ai-content-publisher'),
                        $article->title,
                        $result->get_error_message()
                    );
                }
            }
        } finally {
            $batched_webhooks = self::end_batch();
        }

        if (!empty($batched_webhooks)) {
            $api = new AutoSEO_API();
            $api->send_webhook('articles_batch_published', array(
                'articles' => $batched_webhooks,
            ));
        }

        return array(
            'success' => true,
            'published_count' => $published_count,
            'errors' => $errors,
        );
    }

    /**
     * Get the published URL for a post, ensuring pretty permalink even for scheduled/future posts.
     * WordPress's get_permalink() returns ?p=ID format for posts with 'future' status,
     * which happens when a post is created with post_status='publish' but a future post_date.
     *
     * @param int $post_id
     * @return string
     */
    private function get_published_url($post_id) {
        $published_url = get_permalink($post_id);

        if (strpos($published_url, '?p=') !== false || strpos($published_url, '?page_id=') !== false) {
            if (get_option('permalink_structure')) {
                $post_obj = get_post($post_id);
                if ($post_obj && !empty($post_obj->post_name)) {
                    $original_status = $post_obj->post_status;
                    $post_obj->post_status = 'publish';
                    $pretty_url = get_permalink($post_obj);
                    $post_obj->post_status = $original_status;

                    if (strpos($pretty_url, '?p=') === false && strpos($pretty_url, '?page_id=') === false) {
                        $this->log_debug(sprintf(
                            'Resolved pretty permalink for post %d (status: %s): %s -> %s',
                            $post_id, $original_status, $published_url, $pretty_url
                        ));
                        $published_url = $pretty_url;
                    }
                }
            }
        }

        return $published_url;
    }

    /**
     * Check whether a post has been edited with a page builder after AutoSEO
     * published it.  Returns the builder name (truthy) or false.
     *
     * On initial publish the plugin clears page-builder meta, so any meta
     * that exists afterward was added by the user intentionally.
     *
     * @param int $post_id WordPress post ID
     * @return string|false Builder name or false
     */
    public static function has_page_builder_content($post_id) {
        // Elementor: check both the JSON data and the edit-mode flag.
        // _elementor_edit_mode is set early in Elementor's save flow (before
        // wp_update_post fires), so it catches the very first save reliably.
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data) && $elementor_data !== '[]') {
            return 'Elementor';
        }
        if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder') {
            return 'Elementor';
        }

        if (get_post_meta($post_id, '_et_pb_use_builder', true) === 'on') {
            return 'Divi';
        }

        if (get_post_meta($post_id, '_wpb_vc_js_status', true) === 'true') {
            return 'WPBakery';
        }

        $fl_data = get_post_meta($post_id, '_fl_builder_data', true);
        if (!empty($fl_data)) {
            return 'Beaver Builder';
        }

        if (get_post_meta($post_id, 'brizy_post_uid', true)) {
            return 'Brizy';
        }

        if (get_post_meta($post_id, 'ct_builder_shortcodes', true)) {
            return 'Oxygen';
        }

        return false;
    }

    /**
     * Remove page-builder metadata so WordPress renders from post_content.
     *
     * Covers Elementor, Divi Builder, WPBakery, Beaver Builder, Brizy, and
     * Oxygen. Only deletes keys that actually exist to avoid unnecessary DB writes.
     *
     * @param int $post_id WordPress post ID
     */
    private function clear_page_builder_meta($post_id) {
        $keys = array(
            // Elementor
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_css',
            // Divi
            '_et_builder_version',
            '_et_pb_use_builder',
            '_et_pb_old_content',
            // WPBakery (Visual Composer)
            '_wpb_vc_js_status',
            // Beaver Builder
            '_fl_builder_data',
            '_fl_builder_data_settings',
            '_fl_builder_draft',
            '_fl_builder_draft_settings',
            '_fl_builder_enabled',
            // Brizy
            'brizy_post_uid',
            'brizy',
            // Oxygen
            'ct_builder_shortcodes',
            'ct_other_template',
        );

        $cleared = array();
        foreach ($keys as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                delete_post_meta($post_id, $key);
                $cleared[] = $key;
            }
        }

        if (!empty($cleared)) {
            $this->log_debug(sprintf(
                'Cleared page-builder meta from post %d: %s',
                $post_id,
                implode(', ', $cleared)
            ));
        }
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('autoseo_debug_mode', '1');
        if ($debug_mode === '1') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging controlled by admin setting
            error_log('[AutoSEO Publisher] ' . $message);
        }
    }

    /**
     * Set WordPress tags for a post
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_wordpress_tags($post_id, $article) {
        // Skip if no tags available
        if (empty($article->wordpress_tags)) {
            return;
        }

        // Parse comma-separated tags
        $tags_array = array_map('trim', explode(',', $article->wordpress_tags));
        $tags_array = array_filter($tags_array); // Remove empty values

        if (empty($tags_array)) {
            return;
        }

        // Set the tags on the post (wp_set_post_tags will create tags if they don't exist)
        $result = wp_set_post_tags($post_id, $tags_array, false); // false = replace existing tags

        if (is_wp_error($result)) {
            $this->log_debug(sprintf(
                'Failed to set tags for post %d: %s',
                $post_id,
                $result->get_error_message()
            ));
        } else {
            $this->log_debug(sprintf(
                'Set %d tags for post %d: %s',
                count($tags_array),
                $post_id,
                implode(', ', $tags_array)
            ));
        }

        // Store the original tags in post meta for reference
        update_post_meta($post_id, '_autoseo_wordpress_tags', $article->wordpress_tags);
    }

    /**
     * Set SEO meta fields for a post
     * Handles both Yoast SEO and custom meta output
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_seo_meta_fields($post_id, $article) {
        // Always store in our custom meta fields (for fallback and custom output)
        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_autoseo_meta_description', $article->meta_description);
        }
        if (!empty($article->meta_keywords)) {
            update_post_meta($post_id, '_autoseo_meta_keywords', $article->meta_keywords);
        }

        // Store FAQ schema for FAQPage JSON-LD output
        if (!empty($article->faq_schema)) {
            update_post_meta($post_id, '_autoseo_faq_schema', $article->faq_schema);
        }

        // Populate the active SEO plugin's native meta fields so the user
        // doesn't have to re-enter title/description/keywords/social images.
        if ($this->is_yoast_active()) {
            $this->set_yoast_meta($post_id, $article);
            $this->log_debug(sprintf(
                'Set Yoast SEO meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        } elseif ($this->is_rank_math_active()) {
            $this->set_rank_math_meta($post_id, $article);
            $this->log_debug(sprintf(
                'Set Rank Math SEO meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        } elseif ($this->is_seopress_active()) {
            $this->set_seopress_meta($post_id, $article);
            $this->log_debug(sprintf(
                'Set SEOPress meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        } else {
            $this->log_debug(sprintf(
                'No supported SEO plugin active - using custom meta for post %d',
                $post_id
            ));
        }
    }

    /**
     * Check if Yoast SEO plugin is active
     * 
     * @return bool
     */
    private function is_yoast_active() {
        return defined('WPSEO_VERSION') || 
               class_exists('WPSEO_Meta') || 
               function_exists('wpseo_init');
    }

    /**
     * Set Yoast SEO meta fields
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_yoast_meta($post_id, $article) {
        $title = get_the_title($post_id);
        if (!empty($title)) {
            update_post_meta($post_id, '_yoast_wpseo_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $article->meta_description);
        }

        // Yoast stores focus keyword in _yoast_wpseo_focuskw
        // Use the article's primary keyword first, fall back to first meta keyword
        $focus_keyphrase = '';
        
        // Primary source: the article's main keyword field
        if (!empty($article->keywords)) {
            // Keywords field may be comma-separated, get the first one
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        
        // Fallback: use first meta keyword if no primary keyword
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        
        if (!empty($focus_keyphrase)) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set Yoast focus keyphrase for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        // Explicitly set the OG image so Yoast includes it in og:image output.
        // Without this, Yoast may not pick up the featured image for social sharing
        // depending on the site's Yoast settings.
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image_url);
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $thumbnail_id);
                update_post_meta($post_id, '_yoast_wpseo_twitter-image', $og_image_url);
                update_post_meta($post_id, '_yoast_wpseo_twitter-image-id', $thumbnail_id);
                $this->log_debug(sprintf(
                    'Set Yoast OG/Twitter image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Check if Rank Math SEO plugin is active
     * 
     * @return bool
     */
    private function is_rank_math_active() {
        return defined('RANK_MATH_VERSION') || 
               class_exists('RankMath') || 
               class_exists('RankMath\\Helper');
    }

    /**
     * Set Rank Math SEO meta fields
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_rank_math_meta($post_id, $article) {
        $title = get_the_title($post_id);
        if (!empty($title)) {
            update_post_meta($post_id, 'rank_math_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, 'rank_math_description', $article->meta_description);
        }

        $focus_keyphrase = '';
        
        if (!empty($article->keywords)) {
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        
        if (!empty($focus_keyphrase)) {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set Rank Math focus keyword for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, 'rank_math_facebook_image', $og_image_url);
                update_post_meta($post_id, 'rank_math_facebook_image_id', $thumbnail_id);
                update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on');
                $this->log_debug(sprintf(
                    'Set Rank Math Facebook image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Check if SEOPress plugin is active
     *
     * @return bool
     */
    private function is_seopress_active() {
        return defined('SEOPRESS_VERSION') ||
               function_exists('seopress_get_service') ||
               class_exists('SEOPress\\Core\\Kernel');
    }

    /**
     * Set SEOPress meta fields so the user doesn't have to re-enter them.
     *
     * SEOPress stores its data in post_meta with the `_seopress_` prefix.
     * We populate the SEO title, meta description, focus keyword, and the
     * Facebook / Twitter social fields so social previews work out of the box.
     *
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_seopress_meta($post_id, $article) {
        $title = get_the_title($post_id);

        if (!empty($title)) {
            update_post_meta($post_id, '_seopress_titles_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_seopress_titles_desc', $article->meta_description);
        }

        $focus_keyphrase = '';
        if (!empty($article->keywords)) {
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        if (!empty($focus_keyphrase)) {
            // SEOPress stores the target keyword as a comma-separated list
            update_post_meta($post_id, '_seopress_analysis_target_kw', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set SEOPress target keyword for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        // Facebook Open Graph fields
        if (!empty($title)) {
            update_post_meta($post_id, '_seopress_social_fb_title', $title);
            update_post_meta($post_id, '_seopress_social_twitter_title', $title);
        }
        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_seopress_social_fb_desc', $article->meta_description);
            update_post_meta($post_id, '_seopress_social_twitter_desc', $article->meta_description);
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, '_seopress_social_fb_img', $og_image_url);
                update_post_meta($post_id, '_seopress_social_fb_img_attachment_id', $thumbnail_id);
                update_post_meta($post_id, '_seopress_social_twitter_img', $og_image_url);
                update_post_meta($post_id, '_seopress_social_twitter_img_attachment_id', $thumbnail_id);
                $this->log_debug(sprintf(
                    'Set SEOPress Facebook/Twitter image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Assign the correct WPML language to a post.
     *
     * Uses WPML's official wpml_set_element_language_details action which
     * handles the icl_translations table, language directories (/en/, /de/, etc.),
     * and all internal WPML bookkeeping automatically.
     *
     * Falls back gracefully: if WPML is not installed or the language code is
     * not configured in WPML, the post is left in the site default language.
     */
    private function set_wpml_language($post_id, $language_code) {
        if (!function_exists('icl_object_id') && !defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $language_code = strtolower(substr($language_code, 0, 2));

        // Verify this language is configured in WPML
        $active_languages = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
        if (!is_array($active_languages) || !isset($active_languages[$language_code])) {
            $this->log_debug(sprintf(
                'WPML: language "%s" is not active in WPML for post %d — skipping',
                $language_code,
                $post_id
            ));
            return;
        }

        // Check current WPML language assignment
        $element_type = 'post_post';
        $current_lang = apply_filters('wpml_element_language_code', null, array(
            'element_id'   => $post_id,
            'element_type' => $element_type,
        ));

        if ($current_lang === $language_code) {
            return;
        }

        do_action('wpml_set_element_language_details', array(
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => false,
            'language_code'        => $language_code,
            'source_language_code' => null,
        ));

        $this->log_debug(sprintf(
            'WPML: assigned language "%s" to post %d (was: %s)',
            $language_code,
            $post_id,
            $current_lang ?: 'default'
        ));
    }
}

/**
 * AutoSEO Meta Output Handler
 * Handles outputting meta tags when no supported SEO plugin is installed
 */
class AutoSEO_Meta_Output {

    /**
     * Initialize meta output hooks
     */
    public static function init() {
        // Only output our own meta tags if no supported SEO plugin handles them
        if (!self::is_seo_plugin_active()) {
            add_action('wp_head', array(__CLASS__, 'output_meta_tags'), 1);
        }

        // FAQ schema runs regardless of SEO plugins (they don't auto-generate FAQPage schema)
        add_action('wp_head', array(__CLASS__, 'output_faq_schema'), 2);
    }

    /**
     * Check if any supported SEO plugin is active.
     * When one is active, we let it handle meta description and Open Graph
     * output so we don't emit duplicate tags.
     *
     * Detects Yoast SEO, Rank Math, SEOPress, All in One SEO (AIOSEO),
     * and The SEO Framework — all of which output their own og:/twitter:
     * meta tags.
     *
     * @return bool
     */
    private static function is_seo_plugin_active() {
        // Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || function_exists('wpseo_init')) {
            return true;
        }
        // Rank Math
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath') || class_exists('RankMath\\Helper')) {
            return true;
        }
        // SEOPress
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service') || class_exists('SEOPress\\Core\\Kernel')) {
            return true;
        }
        // All in One SEO (AIOSEO)
        if (defined('AIOSEO_VERSION') || defined('AIOSEO_FILE') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
            return true;
        }
        // The SEO Framework
        if (defined('THE_SEO_FRAMEWORK_DB_VERSION') || class_exists('The_SEO_Framework\\Load') || function_exists('the_seo_framework')) {
            return true;
        }
        return false;
    }

    /**
     * Output meta description, keywords, and Open Graph tags in wp_head.
     * Only runs when no SEO plugin (Yoast/Rank Math) is active.
     */
    public static function output_meta_tags() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $is_autoseo_managed = get_post_meta($post->ID, '_autoseo_managed', true);
        if (!$is_autoseo_managed) {
            return;
        }

        $meta_description = get_post_meta($post->ID, '_autoseo_meta_description', true);
        $meta_keywords = get_post_meta($post->ID, '_autoseo_meta_keywords', true);

        if (!empty($meta_description)) {
            echo '<meta name="description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }

        if (!empty($meta_keywords)) {
            echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '" />' . "\n";
        }

        // Open Graph tags for Facebook / social sharing
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(get_the_title($post->ID)) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post->ID)) . '" />' . "\n";

        if (!empty($meta_description)) {
            echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }

        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($image_url) {
                echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";

                $image_meta = wp_get_attachment_metadata($thumbnail_id);
                if (!empty($image_meta['width'])) {
                    echo '<meta property="og:image:width" content="' . intval($image_meta['width']) . '" />' . "\n";
                }
                if (!empty($image_meta['height'])) {
                    echo '<meta property="og:image:height" content="' . intval($image_meta['height']) . '" />' . "\n";
                }

                $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                if (!empty($image_alt)) {
                    echo '<meta property="og:image:alt" content="' . esc_attr($image_alt) . '" />' . "\n";
                }
            }
        }

        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";

        // Twitter Card tags (uses same image)
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr(get_the_title($post->ID)) . '" />' . "\n";
        if (!empty($meta_description)) {
            echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }
        if (!empty($image_url)) {
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '" />' . "\n";
        }
    }

    /**
     * Output FAQPage JSON-LD structured data in wp_head for AutoSEO articles.
     * Runs regardless of Yoast since Yoast does not auto-generate FAQPage schema.
     */
    public static function output_faq_schema() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $is_autoseo_managed = get_post_meta($post->ID, '_autoseo_managed', true);
        if (!$is_autoseo_managed) {
            return;
        }

        $faq_schema_raw = get_post_meta($post->ID, '_autoseo_faq_schema', true);
        if (empty($faq_schema_raw)) {
            return;
        }

        $faqs = is_string($faq_schema_raw) ? json_decode($faq_schema_raw, true) : $faq_schema_raw;
        if (empty($faqs) || !is_array($faqs)) {
            return;
        }

        $main_entity = array();
        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }
            $main_entity[] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ),
            );
        }

        if (empty($main_entity)) {
            return;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $main_entity,
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD must be output as-is
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}

// Initialize meta output handler
add_action('init', array('AutoSEO_Meta_Output', 'init'));


