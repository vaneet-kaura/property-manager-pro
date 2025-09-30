<?php
/**
 * Admin Inquiries Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Inquiries {
    
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
     * Render Inquiries page
     */
    public function render() {
        global $wpdb;
        
        $inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Handle status update
        if (isset($_POST['update_status']) && isset($_POST['inquiry_id']) && isset($_POST['status'])) {
            $inquiry_id = intval($_POST['inquiry_id']);
            $status = sanitize_text_field($_POST['status']);
            
            $wpdb->update(
                $inquiries_table,
                array('status' => $status, 'updated_at' => current_time('mysql')),
                array('id' => $inquiry_id)
            );
            
            echo '<div class="notice notice-success"><p>' . __('Inquiry status updated.', 'property-manager-pro') . '</p></div>';
        }
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get inquiries
        $inquiries = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.title as property_title, p.ref as property_ref 
             FROM $inquiries_table i 
             LEFT JOIN $properties_table p ON i.property_id = p.id 
             ORDER BY i.created_at DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_inquiries = $wpdb->get_var("SELECT COUNT(*) FROM $inquiries_table");
        $total_pages = ceil($total_inquiries / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Inquiries', 'property-manager-pro'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Property', 'property-manager-pro'); ?></th>
                        <th><?php _e('Contact', 'property-manager-pro'); ?></th>
                        <th><?php _e('Message', 'property-manager-pro'); ?></th>
                        <th><?php _e('Status', 'property-manager-pro'); ?></th>
                        <th><?php _e('Date', 'property-manager-pro'); ?></th>
                        <th><?php _e('Actions', 'property-manager-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inquiries)): ?>
                        <?php foreach ($inquiries as $inquiry): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($inquiry->property_title ?: $inquiry->property_ref); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo esc_html($inquiry->name); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr($inquiry->email); ?>"><?php echo esc_html($inquiry->email); ?></a>
                                <?php if ($inquiry->phone): ?>
                                    <br><?php echo esc_html($inquiry->phone); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html(wp_trim_words($inquiry->message, 20)); ?>
                                <?php if (strlen($inquiry->message) > 100): ?>
                                    <br><a href="#" class="show-full-message"><?php _e('Show full message', 'property-manager-pro'); ?></a>
                                    <div class="full-message" style="display:none;">
                                        <?php echo nl2br(esc_html($inquiry->message)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-<?php echo $inquiry->status; ?>">
                                    <?php echo ucfirst($inquiry->status); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($inquiry->created_at)); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="inquiry_id" value="<?php echo $inquiry->id; ?>">
                                    <select name="status">
                                        <option value="new" <?php selected($inquiry->status, 'new'); ?>><?php _e('New', 'property-manager-pro'); ?></option>
                                        <option value="read" <?php selected($inquiry->status, 'read'); ?>><?php _e('Read', 'property-manager-pro'); ?></option>
                                        <option value="replied" <?php selected($inquiry->status, 'replied'); ?>><?php _e('Replied', 'property-manager-pro'); ?></option>
                                    </select>
                                    <input type="submit" name="update_status" class="button button-small" value="<?php _e('Update', 'property-manager-pro'); ?>">
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6"><?php _e('No inquiries found.', 'property-manager-pro'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }    
}