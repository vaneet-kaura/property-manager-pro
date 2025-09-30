<?php
/**
 * Admin Images Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Images {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        
    }
    
    /**
     * Render Images page
     */
    public function render() {
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $image_stats = $image_downloader->get_image_stats();
        
        // Handle actions
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'process_all':
                    $processed = $image_downloader->process_pending_images(50);
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('%d images processed successfully.', 'property-manager-pro'), $processed) . 
                         '</p></div>';
                    break;
                    
                case 'retry_failed':
                    $retried = $image_downloader->retry_failed_downloads(20);
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('%d failed images queued for retry.', 'property-manager-pro'), $retried) . 
                         '</p></div>';
                    break;
                    
                case 'cleanup_orphaned':
                    $deleted = $image_downloader->cleanup_orphaned_attachments();
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('%d orphaned attachments cleaned up.', 'property-manager-pro'), $deleted) . 
                         '</p></div>';
                    break;
            }
            
            // Refresh stats after actions
            $image_stats = $image_downloader->get_image_stats();
        }
        
        // Get some sample pending/failed images
        global $wpdb;
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $pending_images = $wpdb->get_results("
            SELECT i.*, p.title as property_title, p.ref as property_ref 
            FROM $images_table i 
            LEFT JOIN $properties_table p ON i.property_id = p.id 
            WHERE i.download_status = 'pending' 
            ORDER BY i.created_at DESC 
            LIMIT 10
        ");
        
        $failed_images = $wpdb->get_results("
            SELECT i.*, p.title as property_title, p.ref as property_ref 
            FROM $images_table i 
            LEFT JOIN $properties_table p ON i.property_id = p.id 
            WHERE i.download_status = 'failed' 
            ORDER BY i.updated_at DESC 
            LIMIT 10
        ");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Image Management', 'property-manager-pro'); ?></h1>
            
            <div class="image-stats-cards">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($image_stats['total']); ?></div>
                    <div class="stats-label"><?php _e('Total Images', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card downloaded">
                    <div class="stats-number"><?php echo number_format($image_stats['downloaded']); ?></div>
                    <div class="stats-label"><?php _e('Downloaded', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card pending">
                    <div class="stats-number"><?php echo number_format($image_stats['pending']); ?></div>
                    <div class="stats-label"><?php _e('Pending', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card failed">
                    <div class="stats-number"><?php echo number_format($image_stats['failed']); ?></div>
                    <div class="stats-label"><?php _e('Failed', 'property-manager-pro'); ?></div>
                </div>
            </div>
            
            <div class="image-actions-section">
                <h2><?php _e('Image Actions', 'property-manager-pro'); ?></h2>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('image_management'); ?>
                    <input type="hidden" name="action" value="retry_failed">
                    <input type="submit" class="button button-secondary" 
                           value="<?php _e('Retry Failed Images', 'property-manager-pro'); ?>"
                           <?php echo $image_stats['failed'] == 0 ? 'disabled' : ''; ?>>
                </form>
                
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('image_management'); ?>
                    <input type="hidden" name="action" value="cleanup_orphaned">
                    <input type="submit" class="button button-secondary" 
                           value="<?php _e('Cleanup Orphaned Attachments', 'property-manager-pro'); ?>"
                           onclick="return confirm('<?php _e('Are you sure you want to delete orphaned attachments?', 'property-manager-pro'); ?>')">
                </form>
            </div>
            
            <?php if (!empty($pending_images)): ?>
            <div class="pending-images-section">
                <h2><?php _e('Pending Images (Sample)', 'property-manager-pro'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Property', 'property-manager-pro'); ?></th>
                            <th><?php _e('Image URL', 'property-manager-pro'); ?></th>
                            <th><?php _e('Created', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_images as $image): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($image->property_title ?: $image->property_ref); ?></strong>
                                <br><small>ID: <?php echo $image->property_id; ?></small>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($image->original_url); ?>" target="_blank">
                                    <?php echo esc_html(wp_trim_words($image->original_url, 8, '...')); ?>
                                </a>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($image->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($failed_images)): ?>
            <div class="failed-images-section">
                <h2><?php _e('Failed Images (Sample)', 'property-manager-pro'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Property', 'property-manager-pro'); ?></th>
                            <th><?php _e('Image URL', 'property-manager-pro'); ?></th>
                            <th><?php _e('Failed At', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_images as $image): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($image->property_title ?: $image->property_ref); ?></strong>
                                <br><small>ID: <?php echo $image->property_id; ?></small>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($image->original_url); ?>" target="_blank">
                                    <?php echo esc_html(wp_trim_words($image->original_url, 8, '...')); ?>
                                </a>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($image->updated_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }    
}