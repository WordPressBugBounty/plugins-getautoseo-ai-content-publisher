<?php
/**
 * AutoSEO Admin Dashboard Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get dashboard stats
$autoseo_stats = AutoSEO_Admin::get_dashboard_stats();
$autoseo_api_connection = empty(get_option('autoseo_api_key')) ? false : true;
?>

<div class="wrap" id="autoseo-dashboard">
    <div class="autoseo-header">
        <div class="autoseo-title">
            <h1><?php esc_html_e('AutoSEO Dashboard', 'getautoseo-ai-content-publisher'); ?></h1>
        </div>
    </div>

    <?php 
    // Show success message for auto-verification
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['auto_verified']) && $_GET['auto_verified'] === '1'): 
        $autoseo_current_cat = get_option('autoseo_post_category', '1');
        $autoseo_cat_obj = get_category($autoseo_current_cat);
        $autoseo_cat_name = ($autoseo_cat_obj && !is_wp_error($autoseo_cat_obj)) ? $autoseo_cat_obj->name : __('Uncategorized', 'getautoseo-ai-content-publisher');
    ?>
    <div style="margin: 15px 0; padding: 20px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px;">
        <p style="margin: 0 0 12px; font-size: 15px;">
            <strong>🎉 <?php esc_html_e('Connected automatically!', 'getautoseo-ai-content-publisher'); ?></strong>
            <?php esc_html_e('Your WordPress site is now linked to AutoSEO. Articles will sync automatically.', 'getautoseo-ai-content-publisher'); ?>
        </p>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <label for="autoseo-dashboard-category" style="font-weight: 600; color: #374151; font-size: 14px; white-space: nowrap;">
                <?php esc_html_e('Articles will be published in:', 'getautoseo-ai-content-publisher'); ?>
            </label>
            <?php wp_dropdown_categories(array(
                'name' => 'autoseo_dashboard_category',
                'id' => 'autoseo-dashboard-category',
                'selected' => $autoseo_current_cat,
                'hide_empty' => false,
                'show_option_none' => __('Select Category', 'getautoseo-ai-content-publisher'),
                'class' => 'autoseo-dash-cat-select',
            )); ?>
            <span id="autoseo-cat-save-status" style="font-size: 13px; color: #059669; display: none;">✓ <?php esc_html_e('Saved', 'getautoseo-ai-content-publisher'); ?></span>
        </div>
        <p style="margin: 8px 0 0; color: #9ca3af; font-size: 12px;">
            <?php esc_html_e('You can change this later in Settings.', 'getautoseo-ai-content-publisher'); ?>
        </p>
    </div>
    <style>.autoseo-dash-cat-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; cursor: pointer; } .autoseo-dash-cat-select:focus { border-color: #3b82f6; outline: none; }</style>
    <script>
    (function() {
        var select = document.getElementById('autoseo-dashboard-category');
        var status = document.getElementById('autoseo-cat-save-status');
        if (!select) return;
        select.addEventListener('change', function() {
            var categoryId = select.value;
            if (!categoryId || categoryId === '-1' || categoryId === '0') return;
            status.style.display = 'none';
            var formData = new FormData();
            formData.append('action', 'autoseo_save_post_category');
            formData.append('nonce', '<?php echo esc_js(wp_create_nonce('autoseo_ajax_nonce')); ?>');
            formData.append('category_id', categoryId);
            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST', body: formData, credentials: 'same-origin'
            }).then(function() {
                status.style.display = 'inline';
            });
        });
    })();
    </script>
    <?php 
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    elseif (isset($_GET['setup']) && $_GET['setup'] === 'complete'): 
    ?>
    <div class="notice notice-success is-dismissible" style="margin: 15px 0; padding: 12px 15px;">
        <p style="margin: 0; font-size: 14px;">
            <strong>✅ <?php esc_html_e('Setup complete!', 'getautoseo-ai-content-publisher'); ?></strong>
            <?php esc_html_e('Your AutoSEO plugin is ready. Articles will sync automatically.', 'getautoseo-ai-content-publisher'); ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    $autoseo_sync_error = AutoSEO_Scheduler::get_sync_error();
    if (is_array($autoseo_sync_error) && !empty($autoseo_sync_error['message'])):
        $friendly = !empty($autoseo_sync_error['friendly_message']) ? $autoseo_sync_error['friendly_message'] : $autoseo_sync_error['message'];
        $error_time = !empty($autoseo_sync_error['timestamp']) ? human_time_diff(strtotime($autoseo_sync_error['timestamp']), current_time('timestamp')) . ' ' . esc_html__('ago', 'getautoseo-ai-content-publisher') : '';
    ?>
    <div class="notice notice-error" style="margin: 15px 0; padding: 12px 15px; border-left-color: #dc3232;">
        <p style="margin: 0 0 6px; font-size: 14px;">
            <strong>⚠ <?php esc_html_e('Article sync is failing', 'getautoseo-ai-content-publisher'); ?></strong>
        </p>
        <p style="margin: 0 0 6px; font-size: 13px;">
            <?php echo esc_html($friendly); ?>
        </p>
        <?php if ($error_time): ?>
        <p style="margin: 0; font-size: 12px; color: #666;">
            <?php
            /* translators: %s: human-readable time difference (e.g. "2 hours ago") */
            printf(esc_html__('Last error: %s', 'getautoseo-ai-content-publisher'), esc_html($error_time));
            ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="autoseo-stats-grid">
        <div class="autoseo-stat-card">
            <div class="autoseo-stat-icon">
                <span class="dashicons dashicons-media-document"></span>
            </div>
            <div class="autoseo-stat-content">
                <div class="autoseo-stat-number"><?php echo number_format($autoseo_stats['total_synced']); ?></div>
                <div class="autoseo-stat-label"><?php esc_html_e('Total Articles', 'getautoseo-ai-content-publisher'); ?></div>
            </div>
        </div>

        <div class="autoseo-stat-card">
            <div class="autoseo-stat-icon">
                <span class="dashicons dashicons-yes"></span>
            </div>
            <div class="autoseo-stat-content">
                <div class="autoseo-stat-number"><?php echo number_format($autoseo_stats['published']); ?></div>
                <div class="autoseo-stat-label"><?php esc_html_e('Published', 'getautoseo-ai-content-publisher'); ?></div>
            </div>
        </div>

        <div class="autoseo-stat-card">
            <div class="autoseo-stat-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="autoseo-stat-content">
                <div class="autoseo-stat-number"><?php echo number_format($autoseo_stats['pending']); ?></div>
                <div class="autoseo-stat-label"><?php esc_html_e('Pending', 'getautoseo-ai-content-publisher'); ?></div>
            </div>
        </div>

        <div class="autoseo-stat-card">
            <div class="autoseo-stat-icon">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="autoseo-stat-content">
                <div class="autoseo-stat-number">
                    <?php echo $autoseo_stats['last_sync'] ? esc_html(human_time_diff(strtotime($autoseo_stats['last_sync']), current_time('timestamp'))) . ' ' . esc_html__('ago', 'getautoseo-ai-content-publisher') : esc_html__('Never', 'getautoseo-ai-content-publisher'); ?>
                </div>
                <div class="autoseo-stat-label"><?php esc_html_e('Last Sync', 'getautoseo-ai-content-publisher'); ?></div>
            </div>
        </div>
    </div>

    <!-- Recent Articles -->
    <div class="autoseo-recent-articles">
        <div class="autoseo-section-header">
            <h2><?php esc_html_e('Recent Articles', 'getautoseo-ai-content-publisher'); ?></h2>
        </div>

        <?php if (!empty($autoseo_stats['recent_articles'])): ?>
        <div class="autoseo-articles-table">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'getautoseo-ai-content-publisher'); ?></th>
                        <th><?php esc_html_e('Status', 'getautoseo-ai-content-publisher'); ?></th>
                        <th><?php esc_html_e('Synced', 'getautoseo-ai-content-publisher'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($autoseo_stats['recent_articles'] as $autoseo_article): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($autoseo_article->title); ?></strong>
                        </td>
                        <td>
                            <span class="autoseo-status autoseo-status-<?php echo esc_attr($autoseo_article->status); ?>">
                                <?php echo esc_html(ucfirst($autoseo_article->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html(human_time_diff(strtotime($autoseo_article->synced_at), current_time('timestamp'))); ?> ago</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="autoseo-empty-state">
            <div class="autoseo-empty-icon">
                <span class="dashicons dashicons-media-document"></span>
            </div>
            <h3><?php esc_html_e('No articles yet', 'getautoseo-ai-content-publisher'); ?></h3>
            <p><?php esc_html_e('Articles from AutoSEO will appear here once synced.', 'getautoseo-ai-content-publisher'); ?></p>
            <?php if ($autoseo_api_connection): ?>
            <button id="sync-first-articles-btn" class="button button-primary">
                <?php esc_html_e('Sync Your First Articles', 'getautoseo-ai-content-publisher'); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="autoseo-quick-actions" style="margin-top: 40px;">
        <div class="autoseo-section-header">
            <h2><?php esc_html_e('Quick Actions', 'getautoseo-ai-content-publisher'); ?></h2>
        </div>

        <div class="autoseo-stats-grid">
            <?php if ($autoseo_api_connection): ?>
            <div class="autoseo-stat-card">
                <div class="autoseo-stat-icon">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="autoseo-stat-content">
                    <div class="autoseo-stat-label"><?php esc_html_e('Sync Articles', 'getautoseo-ai-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Import latest articles from AutoSEO', 'getautoseo-ai-content-publisher'); ?></p>
                    <button id="quick-sync-btn" class="button button-primary">
                        <?php esc_html_e('Sync Now', 'getautoseo-ai-content-publisher'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="autoseo-stat-card">
                <div class="autoseo-stat-icon">
                    <span class="dashicons dashicons-admin-settings"></span>
                </div>
                <div class="autoseo-stat-content">
                    <div class="autoseo-stat-label"><?php esc_html_e('Settings', 'getautoseo-ai-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Configure AutoSEO integration', 'getautoseo-ai-content-publisher'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=autoseo-settings')); ?>" class="button button-secondary">
                        <?php esc_html_e('Configure', 'getautoseo-ai-content-publisher'); ?>
                    </a>
                </div>
            </div>

            <div class="autoseo-stat-card">
                <div class="autoseo-stat-icon">
                    <span class="dashicons dashicons-admin-site-alt3"></span>
                </div>
                <div class="autoseo-stat-content">
                    <div class="autoseo-stat-label"><?php esc_html_e('AutoSEO Dashboard', 'getautoseo-ai-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Manage your content', 'getautoseo-ai-content-publisher'); ?></p>
                    <a href="https://getautoseo.com/dashboard" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                        <?php esc_html_e('Open Dashboard', 'getautoseo-ai-content-publisher'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Article Detail Modal -->
    <div id="autoseo-article-modal" class="autoseo-modal" style="display: none;">
        <div class="autoseo-modal-content">
            <div class="autoseo-modal-header">
                <h2 id="modal-title"><?php esc_html_e('Article Details', 'getautoseo-ai-content-publisher'); ?></h2>
                <button class="autoseo-modal-close">&times;</button>
            </div>
            <div class="autoseo-modal-body" id="modal-content">
                <!-- Article content will be loaded here -->
            </div>
            <div class="autoseo-modal-footer">
                <button id="modal-publish-btn" class="button button-primary"><?php esc_html_e('Publish Article', 'getautoseo-ai-content-publisher'); ?></button>
                <button class="button autoseo-modal-close"><?php esc_html_e('Close', 'getautoseo-ai-content-publisher'); ?></button>
            </div>
        </div>
    </div>
</div>
