<?php
/**
 * Admin Dashboard Page - Property Manager Pro
 * 
 * Displays overview statistics and quick actions
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Dashboard {
    
    private static $instance = null;
    
    // Cache duration for statistics (5 minutes)
    private $cache_duration = 300;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     * NOTE: Removed duplicate AJAX handlers - these are handled in class-admin.php
     */
    private function __construct() {
        // No duplicate AJAX handlers
    }
    
    /**
     * Render dashboard page
     */
    public function render() {
        // Security check - verify user has permission
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                esc_html__('Access Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        // Get statistics with caching
        $stats = $this->get_cached_statistics();
        
        if (!$stats) {
            $this->display_error_notice();
            return;
        }
        
        $property_stats = isset($stats['properties']) ? $stats['properties'] : array();        
        $image_stats = isset($stats['images']) ? $stats['images'] : array();
        $import_logs = isset($stats['imports']) ? $stats['imports'] : array();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Property Manager Dashboard', 'property-manager-pro'); ?></h1>
            
            <!-- Quick Actions -->
            <div class="quick-actions" style="margin: 20px 0;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-import')); ?>" class="button button-primary">
                    <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Import Feed', 'property-manager-pro'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties&action=add')); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Add Property', 'property-manager-pro'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-images')); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-format-image" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Manage Images', 'property-manager-pro'); ?>
                </a>
                <button type="button" class="button button-secondary" id="refresh-stats-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_admin_nonce')); ?>">
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Refresh Stats', 'property-manager-pro'); ?>
                </button>
            </div>
            
            <div class="property-manager-dashboard">
                <div class="dashboard-widgets">
                    
                    <!-- Property Statistics Widget -->
                    <div class="dashboard-widget">
                        <h3><?php esc_html_e('Property Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($property_stats['total'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Total Properties', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number">
                                    <?php 
                                    if (!empty($property_stats['avg_price']) && $property_stats['avg_price'] > 0) {
                                        echo esc_html('€' . number_format_i18n($property_stats['avg_price']));
                                    } else {
                                        esc_html_e('N/A', 'property-manager-pro');
                                    }
                                    ?>
                                </div>
                                <div class="stat-label"><?php esc_html_e('Average Price', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($property_stats['active'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Active Properties', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($property_stats['new_this_month'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('New This Month', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($property_stats['by_type']) && is_array($property_stats['by_type'])): ?>
                            <h4><?php esc_html_e('Properties by Type', 'property-manager-pro'); ?></h4>
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th><b><?php esc_html_e('Type', 'property-manager-pro'); ?></b></th>
                                        <th><b><?php esc_html_e('Count', 'property-manager-pro'); ?></b></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($property_stats['by_type'] as $type_stat): ?>
                                        <?php if (is_object($type_stat) && isset($type_stat->type, $type_stat->count)): ?>
                                            <tr>
                                                <td><?php echo esc_html($type_stat->type); ?></td>
                                                <td><?php echo esc_html(number_format_i18n($type_stat->count)); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Image Statistics Widget -->
                    <div class="dashboard-widget">
                        <h3><?php esc_html_e('Image Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($image_stats['total'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Total Images', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($image_stats['downloaded'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Downloaded', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($image_stats['pending'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Pending', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo esc_html(number_format_i18n($image_stats['failed'])); ?></div>
                                <div class="stat-label"><?php esc_html_e('Failed', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($image_stats['pending'] > 0 || $image_stats['failed'] > 0): ?>
                            <div class="image-actions" style="margin-top: 15px;">
                                <?php if ($image_stats['pending'] > 0): ?>
                                    <button type="button" 
                                            class="button button-secondary" 
                                            id="process-images-btn"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_admin_nonce')); ?>">
                                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                                        <?php esc_html_e('Process Pending Images', 'property-manager-pro'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($image_stats['failed'] > 0): ?>
                                    <button type="button" 
                                            class="button button-secondary" 
                                            id="retry-failed-images-btn"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_admin_nonce')); ?>">
                                        <span class="dashicons dashicons-image-rotate" style="margin-top: 3px;"></span>
                                        <?php esc_html_e('Retry Failed Images', 'property-manager-pro'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="image-action-result" style="margin-top: 10px;"></div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Import Logs Widget -->
                    <div class="dashboard-widget">
                        <h3><?php esc_html_e('Recent Import Logs', 'property-manager-pro'); ?></h3>
                        <?php if (!empty($import_logs) && is_array($import_logs)): ?>
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th><b><?php esc_html_e('Date', 'property-manager-pro'); ?></b></th>
                                        <th><b><?php esc_html_e('Status', 'property-manager-pro'); ?></b></th>
                                        <th><b><?php esc_html_e('Results', 'property-manager-pro'); ?></b></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($import_logs as $log): ?>
                                        <?php if (is_object($log) && isset($log->status, $log->started_at)): ?>
                                            <tr>
                                                <td>
                                                    <?php 
                                                    $timestamp = strtotime($log->started_at);
                                                    if ($timestamp !== false) {
                                                        echo esc_html(
                                                            date_i18n(
                                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                                $timestamp
                                                            )
                                                        );
                                                    } else {
                                                        esc_html_e('Invalid date', 'property-manager-pro');
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="status-<?php echo esc_attr(sanitize_html_class($log->status)); ?>">
                                                        <?php echo esc_html($this->get_translated_status($log->status)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($log->status === 'completed'): ?>
                                                        <?php 
                                                        printf(
                                                            esc_html__('Imported: %d, Updated: %d, Failed: %d', 'property-manager-pro'),
                                                            absint($log->properties_imported),
                                                            absint($log->properties_updated),
                                                            absint($log->properties_failed)
                                                        );
                                                        ?>
                                                    <?php elseif (!empty($log->error_message)): ?>
                                                        <?php echo esc_html(wp_trim_words($log->error_message, 10)); ?>
                                                    <?php else: ?>
                                                        <?php esc_html_e('In progress...', 'property-manager-pro'); ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p><?php esc_html_e('No import logs found.', 'property-manager-pro'); ?></p>
                        <?php endif; ?>
                        
                        <p style="margin-top: 15px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-import')); ?>" class="button button-secondary">
                                <?php esc_html_e('View All Imports', 'property-manager-pro'); ?>
                            </a>
                        </p>
                    </div>
                    
                    <!-- User Activity Widget
                    <div class="dashboard-widget">
                        <h3><?php esc_html_e('User Activity', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <?php 
                            $user_stats = $this->get_user_statistics();
                            if ($user_stats):
                            ?>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo esc_html(number_format_i18n($user_stats['total_users'])); ?></div>
                                    <div class="stat-label"><?php esc_html_e('Total Users', 'property-manager-pro'); ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo esc_html(number_format_i18n($user_stats['active_alerts'])); ?></div>
                                    <div class="stat-label"><?php esc_html_e('Active Alerts', 'property-manager-pro'); ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo esc_html(number_format_i18n($user_stats['total_favorites'])); ?></div>
                                    <div class="stat-label"><?php esc_html_e('Favorites', 'property-manager-pro'); ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo esc_html(number_format_i18n($user_stats['inquiries_today'])); ?></div>
                                    <div class="stat-label"><?php esc_html_e('Inquiries Today', 'property-manager-pro'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <p style="margin-top: 15px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-inquiries')); ?>" class="button button-secondary">
                                <?php esc_html_e('View Inquiries', 'property-manager-pro'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-alerts')); ?>" class="button button-secondary">
                                <?php esc_html_e('View Alerts', 'property-manager-pro'); ?>
                            </a>
                        </p>
                    </div>-->
                    
                </div>
            </div>
        </div>        
        <?php
    }
    
    /**
     * Get cached statistics
     */
    private function get_cached_statistics() {
        $cache_key = 'property_manager_dashboard_stats';
        $stats = get_transient($cache_key);
        
        if ($stats !== false) {
            return $stats;
        }
        
        // Gather statistics
        $stats = array(
            'properties' => $this->get_property_statistics(),
            'images' => $this->get_image_statistics(),
            'imports' => $this->get_import_statistics()
        );
        
        // Cache for 5 minutes
        set_transient($cache_key, $stats, $this->cache_duration);
        
        return $stats;
    }
    
    /**
     * Get property statistics
     */
    private function get_property_statistics() {
        try {
            $property_manager = PropertyManager_Property::get_instance();
            $stats = $property_manager->get_property_stats();
            
            if (!is_array($stats)) {
                return $this->get_default_property_stats();
            }
            
            // Ensure all required keys exist
            return array_merge($this->get_default_property_stats(), $stats);
            
        } catch (Exception $e) {
            error_log('Property Manager Pro: Error getting property stats - ' . $e->getMessage());
            return $this->get_default_property_stats();
        }
    }
    
    /**
     * Get image statistics
     */
    private function get_image_statistics() {
        try {
            $image_downloader = PropertyManager_ImageDownloader::get_instance();
            $stats = $image_downloader->get_image_stats();
            
            if (!is_array($stats)) {
                return $this->get_default_image_stats();
            }
            
            return array_merge($this->get_default_image_stats(), $stats);
            
        } catch (Exception $e) {
            error_log('Property Manager Pro: Error getting image stats - ' . $e->getMessage());
            return $this->get_default_image_stats();
        }
    }
    
    /**
     * Get import statistics
     */
    private function get_import_statistics() {
        try {
            $importer = PropertyManager_FeedImporter::get_instance();
            $logs = $importer->get_import_stats(5);
            
            return is_array($logs) ? $logs : array();
            
        } catch (Exception $e) {
            error_log('Property Manager Pro: Error getting import stats - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get user statistics
     */
    private function get_user_statistics() {
        try {
            global $wpdb;
            
            $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
            $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
            $inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
            
            $stats = array();
            
            // Total users
            $stats['total_users'] = count_users();
            $stats['total_users'] = isset($stats['total_users']['total_users']) ? $stats['total_users']['total_users'] : 0;
            
            // Active alerts
            $stats['active_alerts'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM $alerts_table WHERE status = 'active'"
            );
            $stats['active_alerts'] = $stats['active_alerts'] !== null ? absint($stats['active_alerts']) : 0;
            
            // Total favorites
            $stats['total_favorites'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM $favorites_table"
            );
            $stats['total_favorites'] = $stats['total_favorites'] !== null ? absint($stats['total_favorites']) : 0;
            
            // Inquiries today
            $stats['inquiries_today'] = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $inquiries_table WHERE DATE(created_at) = %s",
                    current_time('Y-m-d')
                )
            );
            $stats['inquiries_today'] = $stats['inquiries_today'] !== null ? absint($stats['inquiries_today']) : 0;
            
            return $stats;
            
        } catch (Exception $e) {
            error_log('Property Manager Pro: Error getting user stats - ' . $e->getMessage());
            return array(
                'total_users' => 0,
                'active_alerts' => 0,
                'total_favorites' => 0,
                'inquiries_today' => 0
            );
        }
    }
    
    /**
     * Get default property stats
     */
    private function get_default_property_stats() {
        return array(
            'total' => 0,
            'active' => 0,
            'avg_price' => 0,
            'new_this_month' => 0,
            'by_type' => array()
        );
    }
    
    /**
     * Get default image stats
     */
    private function get_default_image_stats() {
        return array(
            'total' => 0,
            'downloaded' => 0,
            'pending' => 0,
            'failed' => 0
        );
    }
    
    /**
     * Get translated status
     */
    private function get_translated_status($status) {
        $statuses = array(
            'completed' => __('Completed', 'property-manager-pro'),
            'failed' => __('Failed', 'property-manager-pro'),
            'started' => __('Started', 'property-manager-pro'),
            'in_progress' => __('In Progress', 'property-manager-pro'),
            'active' => __('Active', 'property-manager-pro'),
            'inactive' => __('Inactive', 'property-manager-pro'),
            'paused' => __('Paused', 'property-manager-pro')
        );
        
        $status = sanitize_text_field($status);
        return isset($statuses[$status]) ? $statuses[$status] : ucfirst($status);
    }
    
    /**
     * Display error notice
     */
    private function display_error_notice() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Property Manager Dashboard', 'property-manager-pro'); ?></h1>
            <div class="notice notice-error">
                <p><?php esc_html_e('Unable to load dashboard statistics. Please check the error log.', 'property-manager-pro'); ?></p>
            </div>
        </div>
        <?php
    }
}