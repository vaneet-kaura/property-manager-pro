<?php
/**
 * Admin Dashboard Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Dashboard {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_property_manager_process_images', array($this, 'ajax_process_images'));
        add_action('wp_ajax_property_manager_retry_failed_images', array($this, 'ajax_retry_failed_images'));
    }
    
    /**
     * Render dashboard page
     */
    public function render() {
        $property_manager = PropertyManager_Property::get_instance();
        $stats = $property_manager->get_property_stats();
        
        $importer = PropertyManager_FeedImporter::get_instance();
        $import_logs = $importer->get_import_stats(5);
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $image_stats = $image_downloader->get_image_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Manager Dashboard', 'property-manager-pro'); ?></h1>
            
            <div class="property-manager-dashboard">
                <div class="dashboard-widgets">
                    <div class="dashboard-widget">
                        <h3><?php _e('Property Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                                <div class="stat-label"><?php _e('Total Properties', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo $stats['avg_price'] ? '€' . number_format($stats['avg_price']) : 'N/A'; ?></div>
                                <div class="stat-label"><?php _e('Average Price', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($stats['by_type'])): ?>
                        <h4><?php _e('Properties by Type', 'property-manager-pro'); ?></h4>
                        <table class="widefat">
                            <?php foreach ($stats['by_type'] as $type_stat): ?>
                            <tr>
                                <td><?php echo esc_html($type_stat->type); ?></td>
                                <td><?php echo number_format($type_stat->count); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Image Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['total']); ?></div>
                                <div class="stat-label"><?php _e('Total Images', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['downloaded']); ?></div>
                                <div class="stat-label"><?php _e('Downloaded', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['pending']); ?></div>
                                <div class="stat-label"><?php _e('Pending', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['failed']); ?></div>
                                <div class="stat-label"><?php _e('Failed', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($image_stats['pending'] > 0 || $image_stats['failed'] > 0): ?>
                        <div class="image-actions" style="margin-top: 15px;">
                            <?php if ($image_stats['pending'] > 0): ?>
                            <button type="button" class="button button-secondary" id="process-images-btn">
                                <?php _e('Process Pending Images', 'property-manager-pro'); ?>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($image_stats['failed'] > 0): ?>
                            <button type="button" class="button button-secondary" id="retry-failed-images-btn">
                                <?php _e('Retry Failed Images', 'property-manager-pro'); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Recent Import Logs', 'property-manager-pro'); ?></h3>
                        <?php if (!empty($import_logs)): ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'property-manager-pro'); ?></th>
                                    <th><?php _e('Status', 'property-manager-pro'); ?></th>
                                    <th><?php _e('Results', 'property-manager-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($import_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log->started_at)); ?></td>
                                    <td>
                                        <span class="status-<?php echo $log->status; ?>">
                                            <?php echo ucfirst($log->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log->status === 'completed'): ?>
                                            <?php printf(
                                                __('Imported: %d, Updated: %d, Failed: %d', 'property-manager-pro'),
                                                $log->properties_imported,
                                                $log->properties_updated,
                                                $log->properties_failed
                                            ); ?>
                                        <?php elseif ($log->error_message): ?>
                                            <?php echo esc_html($log->error_message); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p><?php _e('No import logs found.', 'property-manager-pro'); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Quick Actions', 'property-manager-pro'); ?></h3>
                        <div class="quick-actions">
                            <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=add'); ?>" class="button button-primary">
                                <?php _e('Add New Property', 'property-manager-pro'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=property-manager-import'); ?>" class="button button-secondary">
                                <?php _e('Import Properties', 'property-manager-pro'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=property-manager-settings'); ?>" class="button button-secondary">
                                <?php _e('Settings', 'property-manager-pro'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            ajax_nonce = '<?php echo wp_create_nonce('property_manager_images'); ?>'
        </script>
        <?php
    }
    
    /**
     * AJAX handler for processing images
     */
    public function ajax_process_images() {
        check_ajax_referer('property_manager_images', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $processed = $image_downloader->process_pending_images(20);
        
        wp_send_json_success(array(
            'processed' => $processed,
            'message' => sprintf(__('%d images processed successfully.', 'property-manager-pro'), $processed)
        ));
    }

    /**
     * AJAX handler for retrying failed images
     */
    public function ajax_retry_failed_images() {
        check_ajax_referer('property_manager_images', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $retried = $image_downloader->retry_failed_downloads(10);
        
        wp_send_json_success(array(
            'retried' => $retried,
            'message' => sprintf(__('%d failed images queued for retry.', 'property-manager-pro'), $retried)
        ));
    }
}