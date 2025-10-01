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
        // Constructor can remain empty or add hooks if needed
    }
    
    /**
     * Render Inquiries page
     */
    public function render() {
        // Capability check - SECURITY FIX
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                __('Permission Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        global $wpdb;
        
        $inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Handle status update with NONCE verification - SECURITY FIX
        if (isset($_POST['update_status']) && check_admin_referer('update_inquiry_status', 'inquiry_nonce')) {
            $inquiry_id = intval($_POST['inquiry_id']);
            $status = sanitize_text_field($_POST['status']);
            
            // Validate status value - SECURITY FIX
            $allowed_statuses = array('new', 'read', 'replied');
            if (!in_array($status, $allowed_statuses, true)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                     esc_html__('Invalid status value.', 'property-manager-pro') . 
                     '</p></div>';
            } else {
                // Update with error handling - FIX
                $updated = $wpdb->update(
                    $inquiries_table,
                    array(
                        'status' => $status,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $inquiry_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($updated === false) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . 
                         esc_html__('Failed to update inquiry status. Please try again.', 'property-manager-pro') . 
                         '</p></div>';
                } elseif ($updated === 0) {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . 
                         esc_html__('No changes were made to the inquiry status.', 'property-manager-pro') . 
                         '</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         esc_html__('Inquiry status updated successfully.', 'property-manager-pro') . 
                         '</p></div>';
                }
            }
        }
        
        // Handle bulk delete action
        if (isset($_POST['action']) && $_POST['action'] === 'delete' && 
            isset($_POST['inquiry_ids']) && check_admin_referer('bulk_delete_inquiries', 'bulk_nonce')) {
            
            $inquiry_ids = array_map('intval', $_POST['inquiry_ids']);
            
            if (!empty($inquiry_ids)) {
                $placeholders = implode(',', array_fill(0, count($inquiry_ids), '%d'));
                $query = $wpdb->prepare(
                    "DELETE FROM $inquiries_table WHERE id IN ($placeholders)",
                    $inquiry_ids
                );
                
                $deleted = $wpdb->query($query);
                
                if ($deleted === false) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . 
                         esc_html__('Failed to delete inquiries.', 'property-manager-pro') . 
                         '</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         sprintf(
                             esc_html__('%d inquiries deleted successfully.', 'property-manager-pro'),
                             $deleted
                         ) . 
                         '</p></div>';
                }
            }
        }
        
        // Filter by status
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $allowed_filter_statuses = array('new', 'read', 'replied');
        if (!empty($filter_status) && !in_array($filter_status, $allowed_filter_statuses, true)) {
            $filter_status = '';
        }
        
        // Pagination - FIX
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Build WHERE clause
        $where_clause = '';
        $where_values = array();
        
        if (!empty($filter_status)) {
            $where_clause = " WHERE i.status = %s";
            $where_values[] = $filter_status;
        }
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM $inquiries_table i" . $where_clause;
        if (!empty($where_values)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        } else {
            $total_items = $wpdb->get_var($count_query);
        }
        
        $total_pages = ceil($total_items / $per_page);
        
        // Get inquiries with property details - COMPLETE QUERY FIX
        $query = "SELECT 
                    i.id,
                    i.property_id,
                    i.name,
                    i.email,
                    i.phone,
                    i.message,
                    i.status,
                    i.created_at,
                    i.updated_at,
                    p.ref as property_ref,
                    p.type as property_type,
                    p.town as property_town
                FROM $inquiries_table i
                LEFT JOIN $properties_table p ON i.property_id = p.id" . 
                $where_clause . "
                ORDER BY i.created_at DESC
                LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, array($per_page, $offset));
        $inquiries = $wpdb->get_results($wpdb->prepare($query, $query_values));
        
        // Check for database errors
        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Database error occurred. Please contact administrator.', 'property-manager-pro') . 
                 '</p></div>';
            error_log('Property Manager Inquiries Query Error: ' . $wpdb->last_error);
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Property Inquiries', 'property-manager-pro'); ?></h1>
            
            <!-- Filter Form -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" style="display:inline;">
                        <input type="hidden" name="page" value="property-manager-inquiries">
                        <select name="status">
                            <option value=""><?php esc_html_e('All Statuses', 'property-manager-pro'); ?></option>
                            <option value="new" <?php selected($filter_status, 'new'); ?>><?php esc_html_e('New', 'property-manager-pro'); ?></option>
                            <option value="read" <?php selected($filter_status, 'read'); ?>><?php esc_html_e('Read', 'property-manager-pro'); ?></option>
                            <option value="replied" <?php selected($filter_status, 'replied'); ?>><?php esc_html_e('Replied', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'property-manager-pro'); ?>">
                    </form>
                </div>
                
                <!-- Statistics -->
                <div class="alignright">
                    <?php
                    $stats = $wpdb->get_results(
                        "SELECT status, COUNT(*) as count FROM $inquiries_table GROUP BY status"
                    );
                    if ($stats) {
                        echo '<span style="margin-right: 15px;">';
                        foreach ($stats as $stat) {
                            echo '<span class="inquiry-stat">' . 
                                 esc_html(ucfirst($stat->status)) . ': <strong>' . 
                                 absint($stat->count) . '</strong></span> | ';
                        }
                        echo '</span>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- Bulk Actions Form -->
            <form method="post" id="inquiries-filter">
                <?php wp_nonce_field('bulk_delete_inquiries', 'bulk_nonce'); ?>
                <input type="hidden" name="action" value="delete">
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'property-manager-pro'); ?>" 
                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete selected inquiries?', 'property-manager-pro')); ?>');">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th scope="col"><?php esc_html_e('Property', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Contact', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Message', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Date', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($inquiries)): ?>
                            <?php foreach ($inquiries as $inquiry): ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="inquiry_ids[]" value="<?php echo absint($inquiry->id); ?>">
                                    </th>
                                    <td>
                                        <?php if ($inquiry->property_id && $inquiry->property_ref): ?>
                                            <strong><?php echo esc_html($inquiry->property_ref); ?></strong><br>
                                            <small>
                                                <?php echo esc_html($inquiry->property_type); ?> - 
                                                <?php echo esc_html($inquiry->property_town); ?>
                                            </small>
                                        <?php else: ?>
                                            <em><?php esc_html_e('Property deleted', 'property-manager-pro'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($inquiry->name); ?></strong><br>
                                        <a href="mailto:<?php echo esc_attr($inquiry->email); ?>">
                                            <?php echo esc_html($inquiry->email); ?>
                                        </a>
                                        <?php if (!empty($inquiry->phone)): ?>
                                            <br><small><?php echo esc_html($inquiry->phone); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $message = esc_html($inquiry->message);
                                        $short_message = wp_trim_words($message, 20, '...');
                                        echo $short_message;
                                        ?>
                                        <?php if (strlen($inquiry->message) > 100): ?>
                                            <br>
                                            <a href="#" class="show-full-message" data-inquiry-id="<?php echo absint($inquiry->id); ?>">
                                                <?php esc_html_e('Show full message', 'property-manager-pro'); ?>
                                            </a>
                                            <div id="full-message-<?php echo absint($inquiry->id); ?>" class="full-message" style="display:none; margin-top:10px; padding:10px; background:#f9f9f9; border-left:3px solid #0073aa;">
                                                <?php echo nl2br(esc_html($inquiry->message)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($inquiry->status); ?>">
                                            <?php echo esc_html(ucfirst($inquiry->status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $date = mysql2date('Y-m-d H:i', $inquiry->created_at);
                                        echo esc_html($date);
                                        ?>
                                        <br>
                                        <small><?php echo esc_html(human_time_diff(strtotime($inquiry->created_at), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'property-manager-pro'); ?></small>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline-block;">
                                            <?php wp_nonce_field('update_inquiry_status', 'inquiry_nonce'); ?>
                                            <input type="hidden" name="inquiry_id" value="<?php echo absint($inquiry->id); ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <option value="new" <?php selected($inquiry->status, 'new'); ?>>
                                                    <?php esc_html_e('New', 'property-manager-pro'); ?>
                                                </option>
                                                <option value="read" <?php selected($inquiry->status, 'read'); ?>>
                                                    <?php esc_html_e('Read', 'property-manager-pro'); ?>
                                                </option>
                                                <option value="replied" <?php selected($inquiry->status, 'replied'); ?>>
                                                    <?php esc_html_e('Replied', 'property-manager-pro'); ?>
                                                </option>
                                            </select>
                                            <noscript>
                                                <input type="submit" name="update_status" class="button button-small" 
                                                       value="<?php esc_attr_e('Update', 'property-manager-pro'); ?>">
                                            </noscript>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:40px;">
                                    <p><?php esc_html_e('No inquiries found.', 'property-manager-pro'); ?></p>
                                    <?php if (!empty($filter_status)): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-inquiries')); ?>" class="button">
                                            <?php esc_html_e('View All Inquiries', 'property-manager-pro'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Previous', 'property-manager-pro'),
                            'next_text' => __('Next &raquo;', 'property-manager-pro'),
                            'total' => $total_pages,
                            'current' => $page,
                            'type' => 'plain'
                        ));
                        
                        if ($page_links) {
                            echo '<span class="displaying-num">' . 
                                 sprintf(
                                     esc_html__('%s items', 'property-manager-pro'),
                                     number_format_i18n($total_items)
                                 ) . 
                                 '</span>';
                            echo $page_links;
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <style>
                .status-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-new {
                    background: #ffebcd;
                    color: #c87800;
                }
                .status-read {
                    background: #e0f2ff;
                    color: #0073aa;
                }
                .status-replied {
                    background: #d4edda;
                    color: #155724;
                }
                .inquiry-stat {
                    margin-right: 10px;
                }
                .full-message {
                    animation: slideDown 0.3s ease-out;
                }
                @keyframes slideDown {
                    from {
                        opacity: 0;
                        max-height: 0;
                    }
                    to {
                        opacity: 1;
                        max-height: 500px;
                    }
                }
            </style>
            
            <script>
            jQuery(document).ready(function($) {
                // Select all checkbox
                $('#cb-select-all').on('change', function() {
                    $('input[name="inquiry_ids[]"]').prop('checked', this.checked);
                });
                
                // Show full message toggle
                $('.show-full-message').on('click', function(e) {
                    e.preventDefault();
                    var inquiryId = $(this).data('inquiry-id');
                    var fullMessage = $('#full-message-' + inquiryId);
                    
                    if (fullMessage.is(':visible')) {
                        fullMessage.slideUp();
                        $(this).text('<?php echo esc_js(__('Show full message', 'property-manager-pro')); ?>');
                    } else {
                        fullMessage.slideDown();
                        $(this).text('<?php echo esc_js(__('Hide full message', 'property-manager-pro')); ?>');
                    }
                });
            });
            </script>
        </div>
        <?php
    }
}