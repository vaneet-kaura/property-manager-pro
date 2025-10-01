<?php
/**
 * Admin Properties Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Properties {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'handle_property_actions'));
    }
    
    /**
     * Handle property actions (delete, bulk delete)
     * SECURITY FIX: Added proper nonce verification
     */
    public function handle_property_actions() {
        // Only process on our admin page
        if (!isset($_GET['page']) || $_GET['page'] !== 'property-manager-properties') {
            return;
        }
        
        // Capability check
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $features_table = PropertyManager_Database::get_table_name('property_features');
        $images_table = PropertyManager_Database::get_table_name('property_images');
        
        // Handle single delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['property_id'])) {
            // Verify nonce
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_property_' . intval($_GET['property_id']))) {
                wp_die(esc_html__('Security check failed', 'property-manager-pro'));
            }
            
            $property_id = intval($_GET['property_id']);
            
            // Delete property and related data
            $deleted = $this->delete_property($property_id);
            
            if ($deleted) {
                $redirect = add_query_arg(array(
                    'page' => 'property-manager-properties',
                    'message' => 'deleted'
                ), admin_url('admin.php'));
            } else {
                $redirect = add_query_arg(array(
                    'page' => 'property-manager-properties',
                    'message' => 'delete_failed'
                ), admin_url('admin.php'));
            }
            
            wp_safe_redirect($redirect);
            exit;
        }
        
        // Handle bulk delete
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['properties'])) {
            // Verify nonce
            if (!check_admin_referer('property_manager_bulk_action', 'property_manager_nonce')) {
                wp_die(esc_html__('Security check failed', 'property-manager-pro'));
            }
            
            $property_ids = array_map('intval', $_POST['properties']);
            $deleted_count = 0;
            
            foreach ($property_ids as $property_id) {
                if ($this->delete_property($property_id)) {
                    $deleted_count++;
                }
            }
            
            $redirect = add_query_arg(array(
                'page' => 'property-manager-properties',
                'message' => 'bulk_deleted',
                'count' => $deleted_count
            ), admin_url('admin.php'));
            
            wp_safe_redirect($redirect);
            exit;
        }
        
        // Handle property save (add/edit)
        if (isset($_POST['save_property']) && check_admin_referer('save_property_action', 'property_nonce')) {
            $this->save_property();
        }
    }
    
    /**
     * Delete a property and all related data
     * 
     * @param int $property_id Property ID to delete
     * @return bool Success status
     */
    private function delete_property($property_id) {
        global $wpdb;
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $features_table = PropertyManager_Database::get_table_name('property_features');
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
        
        // Get property images to delete attachments
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id FROM $images_table WHERE property_id = %d",
            $property_id
        ));
        
        // Delete image attachments from WordPress
        foreach ($images as $image) {
            if ($image->attachment_id) {
                wp_delete_attachment($image->attachment_id, true);
            }
        }
        
        // Delete from all related tables
        $wpdb->delete($images_table, array('property_id' => $property_id), array('%d'));
        $wpdb->delete($features_table, array('property_id' => $property_id), array('%d'));
        $wpdb->delete($favorites_table, array('property_id' => $property_id), array('%d'));
        
        // Don't delete inquiries, just set property_id to NULL
        $wpdb->update(
            $inquiries_table,
            array('property_id' => null),
            array('property_id' => $property_id),
            array('%d'),
            array('%d')
        );
        
        // Delete the property
        $deleted = $wpdb->delete($properties_table, array('id' => $property_id), array('%d'));
        
        return $deleted !== false;
    }
    
    /**
     * Render Properties page
     * SECURITY FIX: Added capability check
     */
    public function render() {
        // Capability check - SECURITY FIX
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                esc_html__('Permission Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
        
        // Display messages
        $this->display_admin_messages();
        
        // Route to appropriate view
        switch ($action) {
            case 'add':
                $this->property_form_page();
                break;
            case 'edit':
                if ($property_id > 0) {
                    $this->property_form_page($property_id);
                } else {
                    $this->properties_list_page();
                }
                break;
            default:
                $this->properties_list_page();
                break;
        }
    }
    
    /**
     * Display admin messages
     * FIX: Added missing method
     */
    private function display_admin_messages() {
        if (!isset($_GET['message'])) {
            return;
        }
        
        $message = sanitize_text_field($_GET['message']);
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        
        switch ($message) {
            case 'saved':
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                     esc_html__('Property saved successfully.', 'property-manager-pro') . 
                     '</p></div>';
                break;
            case 'deleted':
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                     esc_html__('Property deleted successfully.', 'property-manager-pro') . 
                     '</p></div>';
                break;
            case 'bulk_deleted':
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                     sprintf(
                         esc_html__('%d properties deleted successfully.', 'property-manager-pro'),
                         $count
                     ) . 
                     '</p></div>';
                break;
            case 'delete_failed':
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                     esc_html__('Failed to delete property.', 'property-manager-pro') . 
                     '</p></div>';
                break;
            case 'save_failed':
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                     esc_html__('Failed to save property. Please try again.', 'property-manager-pro') . 
                     '</p></div>';
                break;
        }
    }
    
    /**
     * Properties list page
     */
    private function properties_list_page() {
        $property_manager = PropertyManager_Property::get_instance();
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        // Search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Filter by status
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $allowed_statuses = array('active', 'sold', 'rented', 'draft');
        if (!empty($status) && !in_array($status, $allowed_statuses, true)) {
            $status = '';
        }
        
        // Build search arguments
        $search_args = array(
            'page' => $page,
            'per_page' => $per_page,
            'orderby' => 'updated_at',
            'order' => 'DESC'
        );
        
        if (!empty($search)) {
            $search_args['keyword'] = $search;
        }
        
        if (!empty($status)) {
            $search_args['status'] = $status;
        }
        
        $results = $property_manager->search_properties($search_args);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Properties', 'property-manager-pro'); ?></h1>
            
            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties&action=add')); ?>" class="page-title-action">
                <?php esc_html_e('Add New', 'property-manager-pro'); ?>
            </a>
            
            <hr class="wp-header-end">
            
            <!-- Search and Filter Form -->
            <div class="tablenav top">
                <form method="get" class="alignleft" style="margin-right: 10px;">
                    <input type="hidden" name="page" value="property-manager-properties">
                    <select name="status">
                        <option value=""><?php esc_html_e('All Statuses', 'property-manager-pro'); ?></option>
                        <option value="active" <?php selected($status, 'active'); ?>><?php esc_html_e('Active', 'property-manager-pro'); ?></option>
                        <option value="sold" <?php selected($status, 'sold'); ?>><?php esc_html_e('Sold', 'property-manager-pro'); ?></option>
                        <option value="rented" <?php selected($status, 'rented'); ?>><?php esc_html_e('Rented', 'property-manager-pro'); ?></option>
                        <option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Draft', 'property-manager-pro'); ?></option>
                    </select>
                    <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'property-manager-pro'); ?>">
                </form>
                
                <form method="get" class="search-form alignright">
                    <input type="hidden" name="page" value="property-manager-properties">
                    <?php if (!empty($status)): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <p class="search-box">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search properties...', 'property-manager-pro'); ?>">
                        <input type="submit" class="button" value="<?php esc_attr_e('Search', 'property-manager-pro'); ?>">
                    </p>
                </form>
            </div>
            
            <!-- Bulk Actions Form with NONCE - SECURITY FIX -->
            <form method="post">
                <?php wp_nonce_field('property_manager_bulk_action', 'property_manager_nonce'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value=""><?php esc_html_e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="bulk_delete"><?php esc_html_e('Delete', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'property-manager-pro'); ?>" 
                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete selected properties?', 'property-manager-pro')); ?>');">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <th><?php esc_html_e('Title', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Type', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Location', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Price', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Beds/Baths', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Status', 'property-manager-pro'); ?></th>
                            <th><?php esc_html_e('Updated', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results['properties'])): ?>
                            <?php foreach ($results['properties'] as $property): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="properties[]" value="<?php echo absint($property->id); ?>">
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . absint($property->id))); ?>">
                                            <?php echo esc_html($property->title ?: $property->ref); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . absint($property->id))); ?>">
                                                <?php esc_html_e('Edit', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="view">
                                            <a href="<?php echo esc_url($property_manager->get_property_url($property)); ?>" target="_blank">
                                                <?php esc_html_e('View', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="delete">
                                            <a href="<?php echo esc_url(wp_nonce_url(
                                                admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . absint($property->id)), 
                                                'delete_property_' . absint($property->id)
                                            )); ?>" 
                                               class="delete-property"
                                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this property? This action cannot be undone.', 'property-manager-pro')); ?>')">
                                                <?php esc_html_e('Delete', 'property-manager-pro'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($property->type); ?></td>
                                <td><?php echo esc_html($property->town . ', ' . $property->province); ?></td>
                                <td><?php echo esc_html($property_manager->format_price($property->price)); ?></td>
                                <td><?php echo absint($property->beds) . '/' . absint($property->baths); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($property->status); ?>">
                                        <?php echo esc_html(ucfirst($property->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo esc_html(mysql2date('Y-m-d H:i', $property->updated_at)); ?>
                                    <br>
                                    <small><?php echo esc_html(human_time_diff(strtotime($property->updated_at), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'property-manager-pro'); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:40px;">
                                    <p><?php esc_html_e('No properties found.', 'property-manager-pro'); ?></p>
                                    <?php if (!empty($search) || !empty($status)): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-properties')); ?>" class="button">
                                            <?php esc_html_e('View All Properties', 'property-manager-pro'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php
                // Pagination
                if (isset($results['pages']) && $results['pages'] > 1) {
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo; Previous', 'property-manager-pro'),
                        'next_text' => __('Next &raquo;', 'property-manager-pro'),
                        'total' => $results['pages'],
                        'current' => $page,
                        'type' => 'plain'
                    );
                    
                    echo '<div class="tablenav bottom">';
                    echo '<div class="tablenav-pages">';
                    
                    if (isset($results['total'])) {
                        echo '<span class="displaying-num">' . 
                             sprintf(
                                 esc_html__('%s items', 'property-manager-pro'),
                                 number_format_i18n($results['total'])
                             ) . 
                             '</span>';
                    }
                    
                    echo paginate_links($pagination_args);
                    echo '</div>';
                    echo '</div>';
                }
                ?>
            </form>
            
            <style>
                .status-badge {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-active {
                    background: #d4edda;
                    color: #155724;
                }
                .status-sold {
                    background: #f8d7da;
                    color: #721c24;
                }
                .status-rented {
                    background: #d1ecf1;
                    color: #0c5460;
                }
                .status-draft {
                    background: #e2e3e5;
                    color: #383d41;
                }
            </style>
            
            <script>
            jQuery(document).ready(function($) {
                // Select all checkbox
                $('#cb-select-all-1').on('change', function() {
                    $('input[name="properties[]"]').prop('checked', this.checked);
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Property form page (add/edit)
     * COMPLETE IMPLEMENTATION
     */
    private function property_form_page($property_id = 0) {
        global $wpdb;
        
        $property = null;
        $features = array();
        $images = array();
        $is_edit = false;
        
        if ($property_id > 0) {
            $is_edit = true;
            $properties_table = PropertyManager_Database::get_table_name('properties');
            $features_table = PropertyManager_Database::get_table_name('property_features');
            $images_table = PropertyManager_Database::get_table_name('property_images');
            
            // Get property
            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $properties_table WHERE id = %d", 
                $property_id
            ));
            
            if (!$property) {
                wp_die(esc_html__('Property not found.', 'property-manager-pro'));
            }
            
            // Get features
            $feature_results = $wpdb->get_results($wpdb->prepare(
                "SELECT feature_name FROM $features_table WHERE property_id = %d", 
                $property_id
            ));
            $features = array_map(function($f) { return $f->feature_name; }, $feature_results);
            
            // Get images
            $images = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $images_table WHERE property_id = %d ORDER BY sort_order", 
                $property_id
            ));
        }
        
        // Get all available features for checkboxes
        $common_features = array(
            'Air Conditioning', 'Alarm System', 'Balcony', 'Barbecue', 
            'Built-in Wardrobes', 'Central Heating', 'Communal Pool', 
            'Double Glazing', 'Elevator', 'Fitted Kitchen', 'Furnished',
            'Garden', 'Garage', 'Home Appliances', 'Internet', 'Jacuzzi',
            'Parking', 'Private Pool', 'Sea View', 'Security Door', 
            'Solar Panels', 'Storage Room', 'Terrace', 'Video Intercom'
        );
        
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? esc_html__('Edit Property', 'property-manager-pro') : esc_html__('Add New Property', 'property-manager-pro'); ?></h1>
            
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('save_property_action', 'property_nonce'); ?>
                <input type="hidden" name="property_id" value="<?php echo absint($property_id); ?>">
                
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <!-- Basic Information -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Basic Information', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="ref"><?php esc_html_e('Reference *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="text" name="ref" id="ref" class="regular-text" 
                                                       value="<?php echo $property ? esc_attr($property->ref) : ''; ?>" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="type"><?php esc_html_e('Property Type *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <select name="type" id="type" required>
                                                    <option value=""><?php esc_html_e('Select Type', 'property-manager-pro'); ?></option>
                                                    <option value="Apartment" <?php echo $property && $property->type === 'Apartment' ? 'selected' : ''; ?>>Apartment</option>
                                                    <option value="Villa" <?php echo $property && $property->type === 'Villa' ? 'selected' : ''; ?>>Villa</option>
                                                    <option value="Bungalow" <?php echo $property && $property->type === 'Bungalow' ? 'selected' : ''; ?>>Bungalow</option>
                                                    <option value="Townhouse" <?php echo $property && $property->type === 'Townhouse' ? 'selected' : ''; ?>>Townhouse</option>
                                                    <option value="Land" <?php echo $property && $property->type === 'Land' ? 'selected' : ''; ?>>Land</option>
                                                    <option value="Commercial" <?php echo $property && $property->type === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="price"><?php esc_html_e('Price (EUR) *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="number" name="price" id="price" step="0.01" 
                                                       value="<?php echo $property ? esc_attr($property->price) : ''; ?>" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="town"><?php esc_html_e('Town *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="text" name="town" id="town" class="regular-text" 
                                                       value="<?php echo $property ? esc_attr($property->town) : ''; ?>" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="province"><?php esc_html_e('Province *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="text" name="province" id="province" class="regular-text" 
                                                       value="<?php echo $property ? esc_attr($property->province) : ''; ?>" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="beds"><?php esc_html_e('Bedrooms', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="number" name="beds" id="beds" min="0" 
                                                       value="<?php echo $property ? esc_attr($property->beds) : '0'; ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="baths"><?php esc_html_e('Bathrooms', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="number" name="baths" id="baths" min="0" 
                                                       value="<?php echo $property ? esc_attr($property->baths) : '0'; ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="built_area"><?php esc_html_e('Built Area (m²)', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="number" name="built_area" id="built_area" step="0.01"
                                                       value="<?php echo $property ? esc_attr($property->built_area) : ''; ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="pool"><?php esc_html_e('Pool', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="checkbox" name="pool" id="pool" value="1" 
                                                       <?php echo $property && $property->pool ? 'checked' : ''; ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="new_build"><?php esc_html_e('New Build', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="checkbox" name="new_build" id="new_build" value="1" 
                                                       <?php echo $property && $property->new_build ? 'checked' : ''; ?>>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Description -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Description', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="desc_en"><?php esc_html_e('English', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <textarea name="desc_en" id="desc_en" rows="5" class="large-text"><?php echo $property ? esc_textarea($property->desc_en) : ''; ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="desc_es"><?php esc_html_e('Spanish', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <textarea name="desc_es" id="desc_es" rows="5" class="large-text"><?php echo $property ? esc_textarea($property->desc_es) : ''; ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="desc_de"><?php esc_html_e('German', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <textarea name="desc_de" id="desc_de" rows="5" class="large-text"><?php echo $property ? esc_textarea($property->desc_de) : ''; ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="desc_fr"><?php esc_html_e('French', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <textarea name="desc_fr" id="desc_fr" rows="5" class="large-text"><?php echo $property ? esc_textarea($property->desc_fr) : ''; ?></textarea>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Features -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Features', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <div style="columns: 3; column-gap: 20px;">
                                        <?php foreach ($common_features as $feature): ?>
                                            <label style="display: block; margin-bottom: 8px;">
                                                <input type="checkbox" name="features[]" value="<?php echo esc_attr($feature); ?>" 
                                                       <?php echo in_array($feature, $features) ? 'checked' : ''; ?>>
                                                <?php echo esc_html($feature); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p>
                                        <label for="custom_features"><?php esc_html_e('Custom Features (one per line)', 'property-manager-pro'); ?></label><br>
                                        <textarea name="custom_features" id="custom_features" rows="3" class="large-text" 
                                                  placeholder="<?php esc_attr_e('Enter custom features...', 'property-manager-pro'); ?>"></textarea>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Location -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Location', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="latitude"><?php esc_html_e('Latitude', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="text" name="latitude" id="latitude" class="regular-text" 
                                                       value="<?php echo $property ? esc_attr($property->latitude) : ''; ?>"
                                                       placeholder="37.123456">
                                                <p class="description"><?php esc_html_e('Decimal format (e.g., 37.123456)', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="longitude"><?php esc_html_e('Longitude', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <input type="text" name="longitude" id="longitude" class="regular-text" 
                                                       value="<?php echo $property ? esc_attr($property->longitude) : ''; ?>"
                                                       placeholder="-0.123456">
                                                <p class="description"><?php esc_html_e('Decimal format (e.g., -0.123456)', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sidebar -->
                        <div id="postbox-container-1" class="postbox-container">
                            <!-- Publish Box -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Publish', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <div class="submitbox">
                                        <div id="minor-publishing">
                                            <div class="misc-pub-section">
                                                <label for="status"><strong><?php esc_html_e('Status:', 'property-manager-pro'); ?></strong></label>
                                                <select name="status" id="status">
                                                    <option value="active" <?php echo $property && $property->status === 'active' ? 'selected' : ''; ?>><?php esc_html_e('Active', 'property-manager-pro'); ?></option>
                                                    <option value="sold" <?php echo $property && $property->status === 'sold' ? 'selected' : ''; ?>><?php esc_html_e('Sold', 'property-manager-pro'); ?></option>
                                                    <option value="rented" <?php echo $property && $property->status === 'rented' ? 'selected' : ''; ?>><?php esc_html_e('Rented', 'property-manager-pro'); ?></option>
                                                    <option value="draft" <?php echo $property && $property->status === 'draft' ? 'selected' : ''; ?>><?php esc_html_e('Draft', 'property-manager-pro'); ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <div id="major-publishing-actions">
                                            <div id="delete-action">
                                                <?php if ($is_edit): ?>
                                                    <a href="<?php echo esc_url(wp_nonce_url(
                                                        admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . $property_id), 
                                                        'delete_property_' . $property_id
                                                    )); ?>" 
                                                       class="submitdelete deletion"
                                                       onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this property?', 'property-manager-pro')); ?>')">
                                                        <?php esc_html_e('Delete', 'property-manager-pro'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div id="publishing-action">
                                                <input type="submit" name="save_property" class="button button-primary button-large" 
                                                       value="<?php echo $is_edit ? esc_attr__('Update', 'property-manager-pro') : esc_attr__('Publish', 'property-manager-pro'); ?>">
                                            </div>
                                            <div class="clear"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Images Box -->
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e('Images', 'property-manager-pro'); ?></h2>
                                <div class="inside">
                                    <?php if ($is_edit && !empty($images)): ?>
                                        <div id="property-images-list" style="margin-bottom: 15px;">
                                            <?php foreach ($images as $image): ?>
                                                <div class="property-image-item" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 3px;">
                                                    <?php if ($image->attachment_id): ?>
                                                        <?php echo wp_get_attachment_image($image->attachment_id, 'thumbnail'); ?>
                                                    <?php else: ?>
                                                        <img src="<?php echo esc_url($image->image_url); ?>" style="max-width: 100px;">
                                                    <?php endif; ?>
                                                    <p style="margin: 5px 0;">
                                                        <small><?php esc_html_e('Order:', 'property-manager-pro'); ?> <?php echo absint($image->sort_order); ?></small>
                                                    </p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <p>
                                        <label for="property_images"><?php esc_html_e('Upload Images', 'property-manager-pro'); ?></label><br>
                                        <input type="file" name="property_images[]" id="property_images" multiple accept="image/*">
                                    </p>
                                    <p class="description">
                                        <?php esc_html_e('Select multiple images to upload. Max 10MB per image.', 'property-manager-pro'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Save property (add or update)
     * COMPLETE IMPLEMENTATION
     */
    private function save_property() {
        global $wpdb;
        
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        $is_update = $property_id > 0;
        
        // Validate required fields
        $required_fields = array('ref', 'type', 'price', 'town', 'province');
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_die(sprintf(
                    esc_html__('Missing required field: %s', 'property-manager-pro'),
                    $field
                ));
            }
        }
        
        // Prepare property data
        $property_data = array(
            'ref' => sanitize_text_field($_POST['ref']),
            'type' => sanitize_text_field($_POST['type']),
            'price' => floatval($_POST['price']),
            'town' => sanitize_text_field($_POST['town']),
            'province' => sanitize_text_field($_POST['province']),
            'beds' => isset($_POST['beds']) ? intval($_POST['beds']) : 0,
            'baths' => isset($_POST['baths']) ? intval($_POST['baths']) : 0,
            'built_area' => isset($_POST['built_area']) ? floatval($_POST['built_area']) : null,
            'pool' => isset($_POST['pool']) ? 1 : 0,
            'new_build' => isset($_POST['new_build']) ? 1 : 0,
            'desc_en' => isset($_POST['desc_en']) ? wp_kses_post($_POST['desc_en']) : '',
            'desc_es' => isset($_POST['desc_es']) ? wp_kses_post($_POST['desc_es']) : '',
            'desc_de' => isset($_POST['desc_de']) ? wp_kses_post($_POST['desc_de']) : '',
            'desc_fr' => isset($_POST['desc_fr']) ? wp_kses_post($_POST['desc_fr']) : '',
            'latitude' => isset($_POST['latitude']) ? sanitize_text_field($_POST['latitude']) : null,
            'longitude' => isset($_POST['longitude']) ? sanitize_text_field($_POST['longitude']) : null,
            'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active',
            'updated_at' => current_time('mysql')
        );
        
        // Generate title from type and location
        $property_data['title'] = $property_data['type'] . ' in ' . $property_data['town'];
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        if ($is_update) {
            // Update existing property
            $result = $wpdb->update(
                $properties_table,
                $property_data,
                array('id' => $property_id),
                array(
                    '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%f', 
                    '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', 
                    '%s', '%s', '%s'
                ),
                array('%d')
            );
        } else {
            // Insert new property
            $property_data['created_at'] = current_time('mysql');
            
            $result = $wpdb->insert(
                $properties_table,
                $property_data,
                array(
                    '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%f', 
                    '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', 
                    '%s', '%s', '%s', '%s'
                )
            );
            
            if ($result) {
                $property_id = $wpdb->insert_id;
            }
        }
        
        if ($result === false) {
            $redirect = add_query_arg(array(
                'page' => 'property-manager-properties',
                'action' => $is_update ? 'edit' : 'add',
                'property_id' => $property_id,
                'message' => 'save_failed'
            ), admin_url('admin.php'));
            
            wp_safe_redirect($redirect);
            exit;
        }
        
        // Save features
        $this->save_property_features($property_id);
        
        // Handle image uploads
        $this->handle_image_uploads($property_id);
        
        // Redirect with success message
        $redirect = add_query_arg(array(
            'page' => 'property-manager-properties',
            'action' => 'edit',
            'property_id' => $property_id,
            'message' => 'saved'
        ), admin_url('admin.php'));
        
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Save property features
     * 
     * @param int $property_id Property ID
     */
    private function save_property_features($property_id) {
        global $wpdb;
        
        $features_table = PropertyManager_Database::get_table_name('property_features');
        
        // Delete existing features
        $wpdb->delete($features_table, array('property_id' => $property_id), array('%d'));
        
        // Insert selected features
        if (isset($_POST['features']) && is_array($_POST['features'])) {
            foreach ($_POST['features'] as $feature) {
                $wpdb->insert(
                    $features_table,
                    array(
                        'property_id' => $property_id,
                        'feature_name' => sanitize_text_field($feature)
                    ),
                    array('%d', '%s')
                );
            }
        }
        
        // Add custom features
        if (!empty($_POST['custom_features'])) {
            $custom_features = explode("\n", $_POST['custom_features']);
            foreach ($custom_features as $feature) {
                $feature = trim($feature);
                if (!empty($feature)) {
                    $wpdb->insert(
                        $features_table,
                        array(
                            'property_id' => $property_id,
                            'feature_name' => sanitize_text_field($feature)
                        ),
                        array('%d', '%s')
                    );
                }
            }
        }
    }
    
    /**
     * Handle image uploads
     * 
     * @param int $property_id Property ID
     */
    private function handle_image_uploads($property_id) {
        if (empty($_FILES['property_images']['name'][0])) {
            return;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        global $wpdb;
        $images_table = PropertyManager_Database::get_table_name('property_images');
        
        // Get current max sort order
        $max_order = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM $images_table WHERE property_id = %d",
            $property_id
        ));
        $sort_order = $max_order ? $max_order + 1 : 1;
        
        $files = $_FILES['property_images'];
        
        foreach ($files['name'] as $key => $value) {
            if ($files['error'][$key] === 0) {
                $file = array(
                    'name' => $files['name'][$key],
                    'type' => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $files['error'][$key],
                    'size' => $files['size'][$key]
                );
                
                // Upload to WordPress media library
                $attachment_id = media_handle_sideload($file, 0);
                
                if (!is_wp_error($attachment_id)) {
                    // Save to property_images table
                    $wpdb->insert(
                        $images_table,
                        array(
                            'property_id' => $property_id,
                            'attachment_id' => $attachment_id,
                            'image_url' => wp_get_attachment_url($attachment_id),
                            'sort_order' => $sort_order,
                            'created_at' => current_time('mysql')
                        ),
                        array('%d', '%d', '%s', '%d', '%s')
                    );
                    
                    $sort_order++;
                }
            }
        }
    }
}