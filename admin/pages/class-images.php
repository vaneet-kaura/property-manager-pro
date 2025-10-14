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
        add_action('admin_init', array($this, 'handle_admin_actions'));

        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
    }
    

    public function handle_admin_actions() {
		if (!isset($_GET['page']) || strpos($_GET['page'], 'property-manager-images') === false) {
            return;
        }
		
        // Handle POST actions with proper security
        if (isset($_POST['action']) && isset($_POST['_wpnonce'])) {
            
            // SECURITY: Verify nonce
            if (!wp_verify_nonce($_POST['_wpnonce'], 'image_management')) {
                wp_die(
                    __('Security check failed. Please refresh the page and try again.', 'property-manager-pro'),
                    __('Security Error', 'property-manager-pro'),
                    array('response' => 403)
                );
            }
            
            // SECURITY: Sanitize action
            $action = sanitize_key($_POST['action']);
            $image_downloader = PropertyManager_ImageDownloader::get_instance();

            switch ($action) {
                case 'process_all':
                    $processed = $image_downloader->process_pending_images(50);
                    $this->add_admin_notice('success', sprintf(
                        __('%d images processed successfully.', 'property-manager-pro'),
                        absint($processed)
                    ));
                    break;
                    
                case 'retry_failed':
                    $retried = $image_downloader->retry_failed_downloads(20);
                    $this->add_admin_notice('success', sprintf(
                        __('%d failed images queued for retry.', 'property-manager-pro'),
                        absint($retried)
                    ));
                    break;
                    
                case 'cleanup_orphaned':
                    $deleted = $image_downloader->cleanup_orphaned_attachments();
                    $this->add_admin_notice('success', sprintf(
                        __('%d orphaned attachments cleaned up.', 'property-manager-pro'),
                        absint($deleted)
                    ));
                    break;
                    
                default:
                    $this->add_admin_notice('error', __('Invalid action specified.', 'property-manager-pro'),);
                    break;
            }
            
            // Clear dashboard stats cache
            delete_transient('property_manager_dashboard_stats');

            wp_redirect(add_query_arg(
                array(
                    'page' => 'property-manager-images',
                    'message' => 'deleted'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }

    /**
     * Add admin notice
     */
    private function add_admin_notice($type, $message) {
        $notices = get_transient('property_manager_admin_notices');
        
        if (!is_array($notices)) {
            $notices = array();
        }
        
        $notices[] = array(
            'type' => $type,
            'message' => $message
        );
        
        set_transient('property_manager_admin_notices', $notices, 60);
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $notices = get_transient('property_manager_admin_notices');
        
        if (!is_array($notices) || empty($notices)) {
            return;
        }
        
        foreach ($notices as $notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            ?>
            <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
            <?php
        }
        
        delete_transient('property_manager_admin_notices');
    }
    
    /**
     * Render Images page
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
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $image_stats = $image_downloader->get_image_stats();
        
        // Get sample pending/failed images
        global $wpdb;
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // SECURITY: Use $wpdb->prepare even for static queries for consistency
        $pending_images = $wpdb->get_results($wpdb->prepare("
            SELECT i.*, p.title as property_title, p.ref as property_ref 
            FROM $images_table i 
            LEFT JOIN $properties_table p ON i.property_id = p.id 
            WHERE i.download_status = %s 
            ORDER BY i.created_at DESC 
            LIMIT %d
        ", 'pending', 10));
        
        $failed_images = $wpdb->get_results($wpdb->prepare("
            SELECT i.*, p.title as property_title, p.ref as property_ref 
            FROM $images_table i 
            LEFT JOIN $properties_table p ON i.property_id = p.id 
            WHERE i.download_status = %s 
            ORDER BY i.updated_at DESC 
            LIMIT %d
        ", 'failed', 10));
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('property_manager_images'); ?>
            
            <div class="image-stats-cards">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format_i18n($image_stats['total']); ?></div>
                    <div class="stats-label"><?php esc_html_e('Total Images', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card downloaded">
                    <div class="stats-number"><?php echo number_format_i18n($image_stats['downloaded']); ?></div>
                    <div class="stats-label"><?php esc_html_e('Downloaded', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card pending">
                    <div class="stats-number"><?php echo number_format_i18n($image_stats['pending']); ?></div>
                    <div class="stats-label"><?php esc_html_e('Pending', 'property-manager-pro'); ?></div>
                </div>
                <div class="stats-card failed">
                    <div class="stats-number"><?php echo number_format_i18n($image_stats['failed']); ?></div>
                    <div class="stats-label"><?php esc_html_e('Failed', 'property-manager-pro'); ?></div>
                </div>
            </div>
            
            <div class="image-actions-section">
                <h2><?php esc_html_e('Image Actions', 'property-manager-pro'); ?></h2>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('image_management'); ?>
                    <input type="hidden" name="action" value="process_all">
                    <input type="submit" class="button button-primary" 
                           value="<?php esc_attr_e('Process Pending Images', 'property-manager-pro'); ?>"
                           <?php disabled($image_stats['pending'], 0); ?>>
                </form>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('image_management'); ?>
                    <input type="hidden" name="action" value="retry_failed">
                    <input type="submit" class="button button-secondary" 
                           value="<?php esc_attr_e('Retry Failed Images', 'property-manager-pro'); ?>"
                           <?php disabled($image_stats['failed'], 0); ?>>
                </form>
                
                <form method="post" style="display: inline-block;" 
                      onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete orphaned attachments? This action cannot be undone.', 'property-manager-pro')); ?>');">
                    <?php wp_nonce_field('image_management'); ?>
                    <input type="hidden" name="action" value="cleanup_orphaned">
                    <input type="submit" class="button button-secondary" 
                           value="<?php esc_attr_e('Cleanup Orphaned Attachments', 'property-manager-pro'); ?>">
                </form>
            </div>
            
            <?php if (!empty($pending_images)): ?>
            <div class="pending-images-section" style="margin-top: 30px;">
                <h2><?php esc_html_e('Pending Images (Sample)', 'property-manager-pro'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Property', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Image URL', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Created', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_images as $image): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($image->property_title ?: $image->property_ref); ?></strong>
                                <br><small><?php echo sprintf(esc_html__('ID: %d', 'property-manager-pro'), absint($image->property_id)); ?></small>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($image->original_url); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html(wp_trim_words($image->original_url, 8, '...')); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $image->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($failed_images)): ?>
            <div class="failed-images-section" style="margin-top: 30px;">
                <h2><?php esc_html_e('Failed Images (Sample)', 'property-manager-pro'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Property', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Image URL', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Error', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Failed At', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_images as $image): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($image->property_title ?: $image->property_ref); ?></strong>
                                <br><small><?php echo sprintf(esc_html__('ID: %d', 'property-manager-pro'), absint($image->property_id)); ?></small>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($image->original_url); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html(wp_trim_words($image->original_url, 8, '...')); ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($image->error_message)): ?>
                                    <span class="error-message"><?php echo esc_html(wp_trim_words($image->error_message, 10)); ?></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $image->updated_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <style>
                .image-stats-cards {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin: 20px 0;
                }
                .stats-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    text-align: center;
                }
                .stats-number {
                    font-size: 32px;
                    font-weight: bold;
                    color: #2271b1;
                }
                .stats-card.downloaded .stats-number {
                    color: #00a32a;
                }
                .stats-card.pending .stats-number {
                    color: #dba617;
                }
                .stats-card.failed .stats-number {
                    color: #d63638;
                }
                .stats-label {
                    margin-top: 8px;
                    color: #646970;
                }
                .image-actions-section {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    margin: 20px 0;
                }
                .error-message {
                    color: #d63638;
                    font-size: 0.9em;
                }
            </style>
        </div>
        <?php
    }
}