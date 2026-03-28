<?php
/**
 * AutoSEO Admin Settings Template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap" id="autoseo-settings">
    <h1><?php esc_html_e('AutoSEO Settings', 'getautoseo-ai-content-publisher'); ?></h1>

    <?php
    $autoseo_sync_error = AutoSEO_Scheduler::get_sync_error();
    if (is_array($autoseo_sync_error) && !empty($autoseo_sync_error['message'])):
        $friendly = !empty($autoseo_sync_error['friendly_message']) ? $autoseo_sync_error['friendly_message'] : $autoseo_sync_error['message'];
    ?>
    <div class="notice notice-error" style="margin: 15px 0; padding: 12px 15px; border-left-color: #dc3232;">
        <p style="margin: 0 0 6px; font-size: 14px;">
            <strong>⚠ <?php esc_html_e('Article sync is failing', 'getautoseo-ai-content-publisher'); ?></strong>
        </p>
        <p style="margin: 0; font-size: 13px;">
            <?php echo esc_html($friendly); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="autoseo-settings-content">
        <form method="post" action="options.php">
            <?php settings_fields('autoseo_settings'); ?>

            <!-- API Configuration -->
            <div class="autoseo-info-box">
                <h3><?php esc_html_e('API Configuration', 'getautoseo-ai-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('API Key', 'getautoseo-ai-content-publisher'); ?></th>
                        <td>
                            <input type="text"
                                   name="autoseo_api_key"
                                   value="<?php echo esc_attr(get_option('autoseo_api_key')); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_html_e('Your AutoSEO API Key', 'getautoseo-ai-content-publisher'); ?>" />
                            <p class="description">
                                <?php esc_html_e('Get your API key from your AutoSEO dashboard.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="autoseo-connection-test">
                    <button type="button" id="test-api-connection" class="button button-secondary">
                        <?php esc_html_e('Test API Connection', 'getautoseo-ai-content-publisher'); ?>
                    </button>
                    <span id="connection-status"></span>
                </div>
            </div>

            <!-- Publishing Settings -->
            <div class="autoseo-info-box">
                <h3><?php esc_html_e('Publishing Settings', 'getautoseo-ai-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Default Category', 'getautoseo-ai-content-publisher'); ?></th>
                        <td>
                            <?php wp_dropdown_categories(array(
                                'name' => 'autoseo_post_category',
                                'selected' => get_option('autoseo_post_category', '1'),
                                'hide_empty' => false,
                                'show_option_none' => __('Select Category', 'getautoseo-ai-content-publisher'),
                            )); ?>
                            <p class="description">
                                <?php esc_html_e('Default category for new AutoSEO articles. If you change an article\'s category in WordPress, it will be preserved on future updates.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Default Author', 'getautoseo-ai-content-publisher'); ?></th>
                        <td>
                            <?php wp_dropdown_users(array(
                                'name' => 'autoseo_author_id',
                                'selected' => get_option('autoseo_author_id', get_current_user_id()),
                                'show_option_none' => __('Select Author', 'getautoseo-ai-content-publisher'),
                            )); ?>
                            <p class="description">
                                <?php esc_html_e('Default author for AutoSEO articles.', 'getautoseo-ai-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Debug Settings -->
            <div class="autoseo-info-box">
                <h3><?php esc_html_e('Debug Information', 'getautoseo-ai-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Debug Logging', 'getautoseo-ai-content-publisher'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="autoseo_debug_mode" value="0" />
                                <input type="checkbox"
                                       name="autoseo_debug_mode"
                                       value="1"
                                       <?php checked(get_option('autoseo_debug_mode'), '1'); ?> />
                                <?php esc_html_e('Enable debug logging', 'getautoseo-ai-content-publisher'); ?>
                            </label>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: log file path */
                                    esc_html__('Log file: %s', 'getautoseo-ai-content-publisher'),
                                    '<code>' . esc_html(WP_CONTENT_DIR . '/debug.log') . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(__('Save Settings', 'getautoseo-ai-content-publisher')); ?>
        </form>

        <!-- System Information & Maintenance (outside the form) -->
        <div class="autoseo-info-box">
            <h3><?php esc_html_e('System Information', 'getautoseo-ai-content-publisher'); ?></h3>
            <table class="widefat">
                <tr>
                    <td><strong><?php esc_html_e('Plugin Version', 'getautoseo-ai-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(AUTOSEO_VERSION); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('WordPress Version', 'getautoseo-ai-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('PHP Version', 'getautoseo-ai-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
            </table>
        </div>

        <div class="autoseo-info-box">
            <h3><?php esc_html_e('Maintenance Actions', 'getautoseo-ai-content-publisher'); ?></h3>
            <p><?php esc_html_e('Use these actions carefully as they cannot be undone.', 'getautoseo-ai-content-publisher'); ?></p>
            <div class="autoseo-actions">
                <button type="button" id="reset-settings" class="button button-secondary">
                    <?php esc_html_e('Reset All Settings', 'getautoseo-ai-content-publisher'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$autoseo_settings_inline_css = '
.autoseo-settings-content {
    margin-top: 20px;
    max-width: 800px;
}

.autoseo-info-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.autoseo-info-box h3 {
    margin: 0 0 15px 0;
    color: #1d2327;
    font-size: 1.2em;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.autoseo-info-box table.widefat {
    border: none;
    margin: 0;
}

.autoseo-info-box table.widefat tr {
    border-bottom: 1px solid #f0f0f0;
}

.autoseo-info-box table.widefat td {
    padding: 8px 0;
    border: none;
}

.autoseo-info-box table.widefat td:first-child {
    font-weight: 600;
    width: 200px;
}

.form-table th {
    width: 200px;
}

.autoseo-connection-test {
    margin: 15px 0 0 0;
    padding: 15px;
    background: #f8f9fe;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.autoseo-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

@media (max-width: 768px) {
    .autoseo-settings-content {
        max-width: 100%;
    }

    .autoseo-connection-test {
        flex-direction: column;
        align-items: flex-start;
    }
}
';
wp_add_inline_style('getautoseo-admin', $autoseo_settings_inline_css);
?>
