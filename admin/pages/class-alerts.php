<?php
/**
 * Admin Alerts Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Alerts {
    
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
     * Render Alerts page
     */
    public function render() {
        global $wpdb;
        
        $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get alerts
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $alerts_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_alerts = $wpdb->get_var("SELECT COUNT(*) FROM $alerts_table");
        $total_pages = ceil($total_alerts / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Alerts', 'property-manager-pro'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Email', 'property-manager-pro'); ?></th>
                        <th><?php _e('Frequency', 'property-manager-pro'); ?></th>
                        <th><?php _e('Status', 'property-manager-pro'); ?></th>
                        <th><?php _e('Verified', 'property-manager-pro'); ?></th>
                        <th><?php _e('Last Sent', 'property-manager-pro'); ?></th>
                        <th><?php _e('Created', 'property-manager-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($alerts)): ?>
                        <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td><?php echo esc_html($alert->email); ?></td>
                            <td><?php echo ucfirst($alert->frequency); ?></td>
                            <td>
                                <span class="status-<?php echo $alert->status; ?>">
                                    <?php echo ucfirst($alert->status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($alert->email_verified): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-no-alt" style="color: red;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $alert->last_sent ? date('Y-m-d H:i', strtotime($alert->last_sent)) : __('Never', 'property-manager-pro'); ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($alert->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6"><?php _e('No property alerts found.', 'property-manager-pro'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }    
}