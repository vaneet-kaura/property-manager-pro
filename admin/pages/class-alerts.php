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
        add_action('admin_init', array($this, 'handle_alert_actions'));
    }
    
    /**
     * Handle alert management actions
     */
    public function handle_alert_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle bulk delete
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_alerts' && isset($_POST['alerts'])) {
            check_admin_referer('property_manager_bulk_alerts');
            $this->bulk_delete_alerts($_POST['alerts']);
        }
        
        // Handle single alert delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete_alert' && isset($_GET['alert_id'])) {
            check_admin_referer('delete_alert_' . intval($_GET['alert_id']));
            $this->delete_single_alert(intval($_GET['alert_id']));
        }
    }
    
    /**
     * Bulk delete alerts
     */
    private function bulk_delete_alerts($alert_ids) {
        if (!is_array($alert_ids)) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pm_property_alerts';
        
        foreach ($alert_ids as $alert_id) {
            $alert_id = intval($alert_id);
            $wpdb->delete($table, array('id' => $alert_id), array('%d'));
        }
        
        wp_redirect(add_query_arg('alerts_deleted', count($alert_ids), admin_url('admin.php?page=property-manager-alerts')));
        exit;
    }
    
    /**
     * Delete single alert
     */
    private function delete_single_alert($alert_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pm_property_alerts';
        
        $result = $wpdb->delete($table, array('id' => $alert_id), array('%d'));
        
        if ($result) {
            wp_redirect(add_query_arg('alert_deleted', '1', admin_url('admin.php?page=property-manager-alerts')));
        } else {
            wp_redirect(add_query_arg('alert_delete_failed', '1', admin_url('admin.php?page=property-manager-alerts')));
        }
        exit;
    }
    
    /**
     * Render Alerts page
     */
    public function render() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'pm_property_alerts';
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $total_alerts = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        if ($total_alerts === null) {
            $total_alerts = 0;
        }
        
        $total_pages = ceil($total_alerts / $per_page);
        
        // Get alerts with error handling
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        // Check for database errors
        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Database error occurred while fetching alerts.', 'property-manager-pro') . '</p></div>';
            error_log('Property Manager: Database error in alerts page - ' . $wpdb->last_error);
        }
        
        // Success messages
        if (isset($_GET['alerts_deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(esc_html__('%d alert(s) deleted successfully.', 'property-manager-pro'), intval($_GET['alerts_deleted'])) . 
                 '</p></div>';
        }
        
        if (isset($_GET['alert_deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Alert deleted successfully.', 'property-manager-pro') . 
                 '</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Property Alerts', 'property-manager-pro'); ?></h1>
            
            <?php if ($total_alerts > 0): ?>
            <form method="post" action="">
                <?php wp_nonce_field('property_manager_bulk_alerts'); ?>
                <input type="hidden" name="action" value="bulk_delete_alerts">
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value=""><?php _e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="bulk_delete_alerts"><?php _e('Delete', 'property-manager-pro'); ?></option>
                        </select>
                        <button type="submit" class="button action" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete selected alerts?', 'property-manager-pro'); ?>')">
                            <?php _e('Apply', 'property-manager-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" id="select-all-alerts">
                            </td>
                            <th><?php _e('Email', 'property-manager-pro'); ?></th>
                            <th><?php _e('Frequency', 'property-manager-pro'); ?></th>
                            <th><?php _e('Status', 'property-manager-pro'); ?></th>
                            <th><?php _e('Verified', 'property-manager-pro'); ?></th>
                            <th><?php _e('Last Sent', 'property-manager-pro'); ?></th>
                            <th><?php _e('Created', 'property-manager-pro'); ?></th>
                            <th><?php _e('Actions', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($alerts)): ?>
                            <?php foreach ($alerts as $alert): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="alerts[]" value="<?php echo intval($alert->id); ?>">
                                </th>
                                <td><?php echo esc_html($alert->email); ?></td>
                                <td><?php echo esc_html(ucfirst($alert->frequency)); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($alert->status); ?>">
                                        <?php echo esc_html(ucfirst($alert->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($alert->email_verified): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;" title="<?php esc_attr_e('Verified', 'property-manager-pro'); ?>"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-no-alt" style="color: red;" title="<?php esc_attr_e('Not Verified', 'property-manager-pro'); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    echo $alert->last_sent ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($alert->last_sent))) : esc_html__('Never', 'property-manager-pro'); 
                                    ?>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($alert->created_at))); ?></td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(
                                        add_query_arg(array(
                                            'action' => 'delete_alert',
                                            'alert_id' => $alert->id
                                        )), 
                                        'delete_alert_' . $alert->id
                                    ); ?>" 
                                    class="button button-small button-link-delete"
                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this alert?', 'property-manager-pro'); ?>')">
                                        <?php _e('Delete', 'property-manager-pro'); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8"><?php _e('No property alerts found.', 'property-manager-pro'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>
            <?php else: ?>
                <p><?php _e('No property alerts have been created yet.', 'property-manager-pro'); ?></p>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#select-all-alerts').on('click', function() {
                $('input[name="alerts[]"]').prop('checked', this.checked);
            });
        });
        </script>
        
        <style>
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-paused {
            background: #fff3cd;
            color: #856404;
        }
        .status-unsubscribed {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
}