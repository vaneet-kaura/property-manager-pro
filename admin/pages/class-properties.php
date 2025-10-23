<?php
/**
 * Admin Properties Page - Property Manager Pro
 * 
 * Displays all properties with filtering, search, and bulk actions
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Properties {
    
    private static $instance = null;
    
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
     */
    private function __construct() {
        // Actions are handled in class-admin.php
    }
    
    /**
     * Render Properties listing page
     */
    public function render() {
        // SECURITY: Capability check
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                esc_html__('Permission Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        global $wpdb;
        
        // Get properties table
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $images_table = PropertyManager_Database::get_table_name('property_images');
        
        // SECURITY: Sanitize all inputs
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        // Filters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $property_type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Build WHERE clause
        $where_clauses = array();
        $where_params = array();
        
        // Status filter
        if ($status_filter !== 'all') {
            $allowed_statuses = array('active', 'inactive', 'sold', 'rented');
            if (in_array($status_filter, $allowed_statuses, true)) {
                $where_clauses[] = 'p.status = %s';
                $where_params[] = $status_filter;
            }
        }
        
        // Type filter
        if (!empty($property_type_filter)) {
            $where_clauses[] = 'p.property_type = %s';
            $where_params[] = $property_type_filter;
        }
        
        // Search term
        if (!empty($search_term)) {
            $where_clauses[] = '(p.ref LIKE %s OR p.title LIKE %s OR p.town LIKE %s OR p.province LIKE %s)';
            $search_like = '%' . $wpdb->esc_like($search_term) . '%';
            $where_params[] = $search_like;
            $where_params[] = $search_like;
            $where_params[] = $search_like;
            $where_params[] = $search_like;
        }
        
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM {$properties_table} p {$where_sql}";
        if (!empty($where_params)) {
            $count_query = $wpdb->prepare($count_query, ...$where_params);
        }
        $total_items = $wpdb->get_var($count_query);
        
        if ($total_items === null) {
            $total_items = 0;
        }
        
        $total_pages = ceil($total_items / $per_page);
        
        // Get properties with images
        $query = "
            SELECT 
                p.*,
                (SELECT pi.attachment_id FROM {$images_table} pi 
                 WHERE pi.property_id = p.id 
                 ORDER BY pi.sort_order ASC 
                 LIMIT 1) as attachment_id
            FROM {$properties_table} p
            {$where_sql}
            ORDER BY p.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        // Add pagination params to where_params
        $query_params = array_merge($where_params, array($per_page, $offset));
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, ...$query_params);
        } else {
            $query = $wpdb->prepare($query, $per_page, $offset);
        }
        
        $properties = $wpdb->get_results($query);
        
        // Check for database errors
        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Database error occurred while fetching properties.', 'property-manager-pro') . 
                 '</p></div>';
            error_log('Property Manager: Database error in properties page - ' . $wpdb->last_error);
        }
        
        // Get property types for filter
        $property_types = $wpdb->get_col("SELECT DISTINCT property_type FROM {$properties_table} WHERE property_type IS NOT NULL ORDER BY property_type ASC");
        
        // Get status counts
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$properties_table} GROUP BY status",
            OBJECT_K
        );
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('All Properties', 'property-manager-pro'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-import')); ?>" class="page-title-action">
                <?php esc_html_e('Import Properties', 'property-manager-pro'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-edit')); ?>" class="page-title-action">
                <?php esc_html_e('Add New Property', 'property-manager-pro'); ?>
            </a>
            <hr class="wp-header-end">
            
            <!-- Status Tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url(remove_query_arg(array('status', 'paged'))); ?>" 
                       <?php echo ($status_filter === 'all') ? 'class="current"' : ''; ?>>
                        <?php esc_html_e('All', 'property-manager-pro'); ?>
                        <span class="count">(<?php echo esc_html($total_items); ?>)</span>
                    </a> |
                </li>
                <?php
                $statuses = array(
                    'active' => __('Active', 'property-manager-pro'),
                    'inactive' => __('Inactive', 'property-manager-pro'),
                    'sold' => __('Sold', 'property-manager-pro'),
                    'rented' => __('Rented', 'property-manager-pro')
                );
                
                $last_key = array_key_last($statuses);
                foreach ($statuses as $status_key => $status_label) {
                    $count = isset($status_counts[$status_key]) ? intval($status_counts[$status_key]->count) : 0;
                    ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg(array('status' => $status_key), remove_query_arg('paged'))); ?>" 
                           <?php echo ($status_filter === $status_key) ? 'class="current"' : ''; ?>>
                            <?php echo esc_html($status_label); ?>
                            <span class="count">(<?php echo esc_html($count); ?>)</span>
                        </a><?php echo ($status_key !== $last_key) ? ' |' : ''; ?>
                    </li>
                    <?php
                }
                ?>
            </ul>
            
            <!-- Filters and Search -->
            <form method="get" action="">
                <input type="hidden" name="page" value="property-manager-properties">
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <!-- Type Filter -->
                        <select name="type" id="filter-by-type">
                            <option value=""><?php esc_html_e('All Types', 'property-manager-pro'); ?></option>
                            <?php foreach ($property_types as $property_type): ?>
                                <option value="<?php echo esc_attr($property_type); ?>" <?php selected($property_type_filter, $property_type); ?>>
                                    <?php echo esc_html($property_type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'property-manager-pro'); ?>">
                        
                        <?php if ($property_type_filter || $search_term): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties')); ?>" class="button">
                                <?php esc_html_e('Reset', 'property-manager-pro'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Search -->
                    <p class="search-box">
                        <label class="screen-reader-text" for="property-search-input">
                            <?php esc_html_e('Search Properties:', 'property-manager-pro'); ?>
                        </label>
                        <input type="search" 
                               id="property-search-input" 
                               name="s" 
                               value="<?php echo esc_attr($search_term); ?>" 
                               placeholder="<?php esc_attr_e('Search by ref, title, location...', 'property-manager-pro'); ?>">
                        <input type="submit" 
                               id="search-submit" 
                               class="button" 
                               value="<?php esc_attr_e('Search', 'property-manager-pro'); ?>">
                    </p>
                </div>
            </form>
            
            <!-- Bulk Actions Form -->
            <form method="post" id="properties-form">
                <?php wp_nonce_field('bulk-properties'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text">
                            <?php esc_html_e('Select bulk action', 'property-manager-pro'); ?>
                        </label>
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'property-manager-pro'); ?></option>
                            <option value="activate"><?php esc_html_e('Activate', 'property-manager-pro'); ?></option>
                            <option value="deactivate"><?php esc_html_e('Deactivate', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" 
                               class="button action" 
                               value="<?php esc_attr_e('Apply', 'property-manager-pro'); ?>"
                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to perform this bulk action?', 'property-manager-pro')); ?>');">
                    </div>
                    
                    <!-- Pagination Top -->
                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'property-manager-pro')), number_format_i18n($total_items)); ?>
                            </span>
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $paged,
                                'type' => 'plain'
                            ));
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Properties Table -->
                <table class="wp-list-table widefat fixed striped table-view-list">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all-1">
                                    <?php esc_html_e('Select All', 'property-manager-pro'); ?>
                                </label>
                                <input id="cb-select-all-1" type="checkbox">
                            </td>
                            <th scope="col" class="manage-column column-thumbnail" style="width: 80px;">
                                <?php esc_html_e('Image', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-ref">
                                <?php esc_html_e('Reference', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-title column-primary">
                                <?php esc_html_e('Title', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-type">
                                <?php esc_html_e('Type', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-location">
                                <?php esc_html_e('Location', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-price">
                                <?php esc_html_e('Price', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-beds" style="width: 60px;">
                                <?php esc_html_e('Beds', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-baths" style="width: 60px;">
                                <?php esc_html_e('Baths', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-status">
                                <?php esc_html_e('Status', 'property-manager-pro'); ?>
                            </th>
                            <th scope="col" class="manage-column column-date">
                                <?php esc_html_e('Date', 'property-manager-pro'); ?>
                            </th>
                        </tr>
                    </thead>
                    
                    <tbody id="the-list">
                        <?php if (empty($properties)): ?>
                            <tr class="no-items">
                                <td class="colspanchange" colspan="11">
                                    <?php esc_html_e('No properties found.', 'property-manager-pro'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($properties as $property): ?>
                                <?php

                                $options = get_option('property_manager_options', array());
                                $currency_symbol = isset($options['currency_symbol']) ? $options['currency_symbol'] : "";

                                // Prepare property data
                                $property_id = intval($property->id);
                                $property_ref = esc_html($property->ref);
                                $property_title = !empty($property->title) ? esc_html($property->title) : esc_html__('(No title)', 'property-manager-pro');
                                $property_type = esc_html($property->property_type);
                                $property_town = esc_html($property->town);
                                $property_province = esc_html($property->province);
                                $property_price = !empty($property->price) ? ($property->currency == "EUR" ? "&euro;" : $currency_symbol) . number_format_i18n(floatval($property->price)) : '-';
                                $property_beds = !empty($property->beds) ? intval($property->beds) : '-';
                                $property_baths = !empty($property->baths) ? intval($property->baths) : '-';
                                $property_status = esc_html($property->status);
                                $property_date = mysql2date(get_option('date_format'), $property->created_at);
                                
                                // Property URL
                                $property_url = get_option('siteurl') . '/property/' . intval($property->id);
                                
                                // Status badge color
                                $status_colors = array(
                                    'active' => 'background: #46b450; color: white;',
                                    'inactive' => 'background: #999; color: white;',
                                    'sold' => 'background: #dc3232; color: white;',
                                    'rented' => 'background: #00a0d2; color: white;'
                                );
                                $status_style = isset($status_colors[$property->status]) ? $status_colors[$property->status] : '';
                                ?>
                                <tr id="property-<?php echo $property_id; ?>">
                                    <!-- Checkbox -->
                                    <th scope="row" class="check-column">
                                        <label class="screen-reader-text" for="cb-select-<?php echo $property_id; ?>">
                                            <?php printf(esc_html__('Select %s', 'property-manager-pro'), $property_ref); ?>
                                        </label>
                                        <input id="cb-select-<?php echo $property_id; ?>" 
                                               type="checkbox" 
                                               name="property[]" 
                                               value="<?php echo $property_id; ?>">
                                    </th>
                                    
                                    <!-- Thumbnail -->
                                    <td class="column-thumbnail">
                                        <?php if (!empty($property->attachment_id) && intval($property->attachment_id) > 0): ?>
                                            <?php echo wp_get_attachment_image($property->attachment_id, [60, 60], false, ["style" => "width: 60px; height: 60px; object-fit: cover; border-radius: 4px;"]); ?>                                            
                                        <?php else: ?>
                                            <div style="width: 60px; height: 60px; background: #f0f0f1; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                                <span class="dashicons dashicons-camera" style="color: #c3c4c7;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Reference -->
                                    <td class="column-ref">
                                        <strong><?php echo $property_ref; ?></strong>
                                    </td>
                                    
                                    <!-- Title (Primary Column) -->
                                    <td class="column-title column-primary" data-colname="<?php esc_attr_e('Title', 'property-manager-pro'); ?>">
                                        <strong>
                                            <a href="<?php echo esc_url($property_url); ?>" target="_blank" class="row-title">
                                                <?php echo $property_title; ?>
                                            </a>
                                        </strong>
                                        
                                        <!-- Row Actions -->
                                        <div class="row-actions">
                                            <span class="view">
                                                <a href="<?php echo esc_url($property_url); ?>" target="_blank">
                                                    <?php esc_html_e('View', 'property-manager-pro'); ?>
                                                </a> |
                                            </span>
                                            <span class="edit">
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-edit&property_id=' . $property_id)); ?>">
                                                    <?php esc_html_e('Edit', 'property-manager-pro'); ?>
                                                </a> |
                                            </span>
                                            <span class="trash">
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . $property_id), 'property_action_delete')); ?>" 
                                                   class="submitdelete" 
                                                   onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this property?', 'property-manager-pro')); ?>');">
                                                    <?php esc_html_e('Delete', 'property-manager-pro'); ?>
                                                </a>
                                            </span>
                                        </div>
                                        
                                        <button type="button" class="toggle-row">
                                            <span class="screen-reader-text"><?php esc_html_e('Show more details', 'property-manager-pro'); ?></span>
                                        </button>
                                    </td>
                                    
                                    <!-- Type -->
                                    <td class="column-type" data-colname="<?php esc_attr_e('Type', 'property-manager-pro'); ?>">
                                        <?php echo $property_type; ?>
                                    </td>
                                    
                                    <!-- Location -->
                                    <td class="column-location" data-colname="<?php esc_attr_e('Location', 'property-manager-pro'); ?>">
                                        <?php echo $property_town . ', ' . $property_province; ?>
                                    </td>
                                    
                                    <!-- Price -->
                                    <td class="column-price" data-colname="<?php esc_attr_e('Price', 'property-manager-pro'); ?>">
                                        <strong><?php echo $property_price; ?></strong>
                                    </td>
                                    
                                    <!-- Beds -->
                                    <td class="column-beds" data-colname="<?php esc_attr_e('Beds', 'property-manager-pro'); ?>">
                                        <?php echo $property_beds; ?>
                                    </td>
                                    
                                    <!-- Baths -->
                                    <td class="column-baths" data-colname="<?php esc_attr_e('Baths', 'property-manager-pro'); ?>">
                                        <?php echo $property_baths; ?>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td class="column-status" data-colname="<?php esc_attr_e('Status', 'property-manager-pro'); ?>">
                                        <span style="padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; <?php echo $status_style; ?>">
                                            <?php echo strtoupper($property_status); ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Date -->
                                    <td class="column-date" data-colname="<?php esc_attr_e('Date', 'property-manager-pro'); ?>">
                                        <?php echo $property_date; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all-2">
                                    <?php esc_html_e('Select All', 'property-manager-pro'); ?>
                                </label>
                                <input id="cb-select-all-2" type="checkbox">
                            </td>
                            <th scope="col"><?php esc_html_e('Image', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Reference', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Title', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Type', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Location', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Price', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Beds', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Baths', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'property-manager-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Date', 'property-manager-pro'); ?></th>
                        </tr>
                    </tfoot>
                </table>
                
                <!-- Pagination Bottom -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'property-manager-pro')), number_format_i18n($total_items)); ?>
                            </span>
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $paged,
                                'type' => 'plain'
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}