<?php
/**
 * Admin Import Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Import {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_property_manager_import_feed', array($this, 'ajax_import_feed'));
        
        // Enqueue scripts and styles for this page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Enqueue scripts and styles for import page
     */
    public function enqueue_scripts($hook) {
        // Only load on our import page
        if ($hook !== 'properties_page_property-manager-import') {
            return;
        }
        
        // Enqueue admin styles
        wp_enqueue_style(
            'property-manager-import',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/admin-import.css',
            array(),
            PROPERTY_MANAGER_VERSION
        );
        
        // Enqueue admin scripts
        wp_enqueue_script(
            'property-manager-import',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/admin-import.js',
            array('jquery'),
            PROPERTY_MANAGER_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script(
            'property-manager-import',
            'propertyManagerImport',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('property_manager_import'),
                'i18n' => array(
                    'importing' => __('Importing properties...', 'property-manager-pro'),
                    'success' => __('Import completed successfully!', 'property-manager-pro'),
                    'error' => __('Import failed. Please try again.', 'property-manager-pro'),
                    'confirm' => __('Are you sure you want to start the import? This may take several minutes.', 'property-manager-pro')
                )
            )
        );
    }
    
    /**
     * Render import page
     */
    public function render() {
        // SECURITY: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                __('Permission Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        // Get feed importer instance
        $importer = PropertyManager_FeedImporter::get_instance();
        
        // Get import statistics
        $import_stats = $this->get_import_statistics();
        
        // Get recent import logs
        $recent_imports = $this->get_recent_imports(10);
        
        // Check if import is currently in progress
        $import_in_progress = get_transient('property_manager_import_in_progress');
        
        // Get feed URL from settings
        $options = get_option('property_manager_options', array());
        $feed_url = isset($options['feed_url']) ? $options['feed_url'] : '';
        $feed_configured = !empty($feed_url) && filter_var($feed_url, FILTER_VALIDATE_URL);
        
        ?>
        <div class="wrap property-manager-import-page">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($import_in_progress): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('An import is currently in progress. Please wait for it to complete before starting another import.', 'property-manager-pro'); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!$feed_configured): ?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e('Feed URL is not configured.', 'property-manager-pro'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-settings')); ?>">
                        <?php esc_html_e('Configure it in Settings', 'property-manager-pro'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="import-statistics">
                <h2><?php esc_html_e('Import Statistics', 'property-manager-pro'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format_i18n($import_stats['total_imports']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Total Imports', 'property-manager-pro'); ?></div>
                    </div>
                    <div class="stat-box success">
                        <div class="stat-number"><?php echo number_format_i18n($import_stats['successful_imports']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Successful', 'property-manager-pro'); ?></div>
                    </div>
                    <div class="stat-box failed">
                        <div class="stat-number"><?php echo number_format_i18n($import_stats['failed_imports']); ?></div>
                        <div class="stat-label"><?php esc_html_e('Failed', 'property-manager-pro'); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $import_stats['last_import'] ? esc_html(human_time_diff(strtotime($import_stats['last_import']), current_time('timestamp'))) . ' ' . __('ago', 'property-manager-pro') : __('Never', 'property-manager-pro'); ?></div>
                        <div class="stat-label"><?php esc_html_e('Last Import', 'property-manager-pro'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="import-actions-card">
                <h2><?php esc_html_e('Manual Import', 'property-manager-pro'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Import properties from your configured Kyero XML feed. This process may take several minutes depending on the number of properties.', 'property-manager-pro'); ?>
                </p>
                
                <?php if ($feed_configured): ?>
                <div class="feed-info">
                    <strong><?php esc_html_e('Feed URL:', 'property-manager-pro'); ?></strong>
                    <code><?php echo esc_html(wp_trim_words($feed_url, 10, '...')); ?></code>
                </div>
                <?php endif; ?>
                
                <div class="import-controls">
                    <button type="button" 
                            class="button button-primary button-hero" 
                            id="manual-import-btn"
                            <?php disabled(!$feed_configured || $import_in_progress); ?>>
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Start Import Now', 'property-manager-pro'); ?>
                    </button>
                    
                    <?php if (!$feed_configured): ?>
                    <p class="description error-text">
                        <?php esc_html_e('Please configure your feed URL in settings before importing.', 'property-manager-pro'); ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div id="import-progress" class="import-progress" style="display:none;">
                    <div class="progress-info">
                        <span class="dashicons dashicons-update spin"></span>
                        <span class="progress-text"><?php esc_html_e('Import in progress...', 'property-manager-pro'); ?></span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <p class="progress-description">
                        <?php esc_html_e('Please do not close this page until the import is complete.', 'property-manager-pro'); ?>
                    </p>
                </div>
                
                <div id="import-results" class="import-results" style="display:none;"></div>
            </div>
            
            <?php if (!empty($recent_imports)): ?>
            <div class="recent-imports-section">
                <h2><?php esc_html_e('Recent Imports', 'property-manager-pro'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Status', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Duration', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Results', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_imports as $import): ?>
                        <tr>
                            <td>
                                <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $import->started_at)); ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($import->status); ?>">
                                    <?php echo esc_html(ucfirst($import->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if ($import->completed_at && $import->started_at) {
                                    $duration = strtotime($import->completed_at) - strtotime($import->started_at);
                                    echo esc_html(gmdate('i:s', $duration)) . ' ' . esc_html__('min', 'property-manager-pro');
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($import->status === 'completed'): ?>
                                    <div class="import-results-summary">
                                        <span class="result-item imported">
                                            <?php printf(
                                                esc_html__('Imported: %d', 'property-manager-pro'),
                                                absint($import->properties_imported)
                                            ); ?>
                                        </span>
                                        <span class="result-item updated">
                                            <?php printf(
                                                esc_html__('Updated: %d', 'property-manager-pro'),
                                                absint($import->properties_updated)
                                            ); ?>
                                        </span>
                                        <?php if ($import->properties_failed > 0): ?>
                                        <span class="result-item failed">
                                            <?php printf(
                                                esc_html__('Failed: %d', 'property-manager-pro'),
                                                absint($import->properties_failed)
                                            ); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($import->status === 'failed' && $import->error_message): ?>
                                    <span class="error-message" title="<?php echo esc_attr($import->error_message); ?>">
                                        <?php echo esc_html(wp_trim_words($import->error_message, 10)); ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <style>
                .property-manager-import-page .import-statistics {
                    margin: 20px 0;
                }
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin: 20px 0;
                }
                .stat-box {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    text-align: center;
                }
                .stat-number {
                    font-size: 32px;
                    font-weight: bold;
                    color: #2271b1;
                }
                .stat-box.success .stat-number {
                    color: #00a32a;
                }
                .stat-box.failed .stat-number {
                    color: #d63638;
                }
                .stat-label {
                    margin-top: 8px;
                    color: #646970;
                }
                .import-actions-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    margin: 20px 0;
                }
                .feed-info {
                    margin: 15px 0;
                    padding: 10px;
                    background: #f0f0f1;
                    border-radius: 4px;
                }
                .feed-info code {
                    background: #fff;
                    padding: 2px 6px;
                    border-radius: 2px;
                }
                .import-controls {
                    margin: 20px 0;
                }
                .button-hero .dashicons {
                    line-height: 32px;
                }
                .import-progress {
                    margin: 20px 0;
                    padding: 20px;
                    background: #f0f6fc;
                    border: 1px solid #c3e0f5;
                    border-radius: 4px;
                }
                .progress-info {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 10px;
                }
                .progress-info .dashicons.spin {
                    animation: rotation 2s infinite linear;
                }
                @keyframes rotation {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(359deg); }
                }
                .progress-bar {
                    height: 20px;
                    background: #fff;
                    border: 1px solid #c3e0f5;
                    border-radius: 10px;
                    overflow: hidden;
                }
                .progress-fill {
                    height: 100%;
                    background: #2271b1;
                    transition: width 0.3s ease;
                }
                .import-results {
                    margin: 20px 0;
                    padding: 15px;
                    border-radius: 4px;
                }
                .import-results.success {
                    background: #d5f4e6;
                    border: 1px solid #00a32a;
                }
                .import-results.error {
                    background: #fcf0f1;
                    border: 1px solid #d63638;
                }
                .status-badge {
                    padding: 4px 12px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 500;
                    text-transform: uppercase;
                }
                .status-badge.status-completed {
                    background: #d5f4e6;
                    color: #00a32a;
                }
                .status-badge.status-failed {
                    background: #fcf0f1;
                    color: #d63638;
                }
                .status-badge.status-started {
                    background: #f0f6fc;
                    color: #2271b1;
                }
                .import-results-summary {
                    display: flex;
                    gap: 15px;
                    flex-wrap: wrap;
                }
                .result-item {
                    font-size: 13px;
                }
                .result-item.imported {
                    color: #00a32a;
                }
                .result-item.updated {
                    color: #2271b1;
                }
                .result-item.failed {
                    color: #d63638;
                }
                .error-message {
                    color: #d63638;
                    font-size: 13px;
                }
                .error-text {
                    color: #d63638;
                    font-weight: 500;
                }
                .recent-imports-section {
                    margin-top: 30px;
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * Get import statistics
     */
    private function get_import_statistics() {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_imports,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_imports,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_imports,
                MAX(started_at) as last_import
            FROM $table
            WHERE import_type = 'kyero_feed'
        ", ARRAY_A);
        
        return $stats ? $stats : array(
            'total_imports' => 0,
            'successful_imports' => 0,
            'failed_imports' => 0,
            'last_import' => null
        );
    }
    
    /**
     * Get recent import logs
     */
    private function get_recent_imports($limit = 10) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM $table
            WHERE import_type = 'kyero_feed'
            ORDER BY started_at DESC
            LIMIT %d
        ", $limit));
    }
    
    /**
     * AJAX handler for feed import
     */
    public function ajax_import_feed() {
        // SECURITY: Verify nonce
        check_ajax_referer('property_manager_import', 'nonce');
        
        // SECURITY: Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have sufficient permissions to perform this action.', 'property-manager-pro')
            ));
        }
        
        // Check if import is already in progress
        if (get_transient('property_manager_import_in_progress')) {
            wp_send_json_error(array(
                'message' => __('An import is already in progress. Please wait for it to complete.', 'property-manager-pro')
            ));
        }
        
        // Get importer instance and run import
        $importer = PropertyManager_FeedImporter::get_instance();
        $result = $importer->import_feed(true);
        
        if ($result && is_array($result)) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Import completed successfully! Imported: %d, Updated: %d, Failed: %d, Deactivated: %d', 'property-manager-pro'),
                    absint($result['imported']),
                    absint($result['updated']),
                    absint($result['failed']),
                    absint($result['deactivated'])
                ),
                'data' => array(
                    'imported' => absint($result['imported']),
                    'updated' => absint($result['updated']),
                    'failed' => absint($result['failed']),
                    'deactivated' => absint($result['deactivated'])
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Import failed. Please check the error logs for more details.', 'property-manager-pro')
            ));
        }
    }
}