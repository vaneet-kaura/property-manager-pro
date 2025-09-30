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
        add_action('wp_ajax_property_manager_delete_property', array($this, 'ajax_delete_property'));
    }
    
    /**
     * Render Properties page
     */
    public function render() {
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        $property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
        
        // Display messages
        $this->display_admin_messages();
        
        // Route to appropriate view
        switch ($action) {
            case 'add':
                $this->property_form_page();
                break;
            case 'edit':
                $this->property_form_page($property_id);
                break;
            default:
                $this->properties_list_page();
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
        
        $search_args = array(
            'page' => $page,
            'per_page' => $per_page,
            'orderby' => 'updated_at',
            'order' => 'DESC'
        );
        
        if (!empty($search)) {
            $search_args['keyword'] = $search;
        }
        
        $results = $property_manager->search_properties($search_args);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Properties', 'property-manager-pro'); ?></h1>
            
            <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=add'); ?>" class="page-title-action">
                <?php _e('Add New', 'property-manager-pro'); ?>
            </a>
            
            <hr class="wp-header-end">
            
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="property-manager-properties">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search properties...', 'property-manager-pro'); ?>">
                    <input type="submit" class="button" value="<?php _e('Search', 'property-manager-pro'); ?>">
                </p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('property_manager_bulk_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value=""><?php _e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="bulk_delete"><?php _e('Delete', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php _e('Apply', 'property-manager-pro'); ?>">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <th><?php _e('Title', 'property-manager-pro'); ?></th>
                            <th><?php _e('Type', 'property-manager-pro'); ?></th>
                            <th><?php _e('Location', 'property-manager-pro'); ?></th>
                            <th><?php _e('Price', 'property-manager-pro'); ?></th>
                            <th><?php _e('Beds/Baths', 'property-manager-pro'); ?></th>
                            <th><?php _e('Status', 'property-manager-pro'); ?></th>
                            <th><?php _e('Updated', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results['properties'])): ?>
                            <?php foreach ($results['properties'] as $property): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="properties[]" value="<?php echo $property->id; ?>">
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . $property->id); ?>">
                                            <?php echo esc_html($property->title ?: $property->ref); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . $property->id); ?>">
                                                <?php _e('Edit', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="view">
                                            <a href="<?php echo $property_manager->get_property_url($property); ?>" target="_blank">
                                                <?php _e('View', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="delete">
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . $property->id), 'delete_property'); ?>" 
                                               onclick="return confirm('<?php _e('Are you sure you want to delete this property?', 'property-manager-pro'); ?>')">
                                                <?php _e('Delete', 'property-manager-pro'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($property->type); ?></td>
                                <td><?php echo esc_html($property->town . ', ' . $property->province); ?></td>
                                <td><?php echo $property_manager->format_price($property->price); ?></td>
                                <td><?php echo $property->beds . '/' . $property->baths; ?></td>
                                <td>
                                    <span class="status-<?php echo $property->status; ?>">
                                        <?php echo ucfirst($property->status); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($property->updated_at)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8"><?php _e('No properties found.', 'property-manager-pro'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php
                // Pagination
                if ($results['pages'] > 1) {
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $results['pages'],
                        'current' => $page
                    );
                    
                    echo '<div class="tablenav bottom">';
                    echo '<div class="tablenav-pages">';
                    echo paginate_links($pagination_args);
                    echo '</div>';
                    echo '</div>';
                }
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Property form page (add/edit)
     */
    private function property_form_page($property_id = 0) {
        $property = null;
        $features = array();
        $images = array();
        
        if ($property_id > 0) {
            global $wpdb;
            $properties_table = PropertyManager_Database::get_table_name('properties');
            $features_table = PropertyManager_Database::get_table_name('property_features');
            $images_table = PropertyManager_Database::get_table_name('property_images');
            
            // Get property
            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $properties_table WHERE id = %d", $property_id
            ));
            
            if (!$property) {
                wp_die(__('Property not found.', 'property-manager-pro'));
            }
            
            // Get features
            $feature_results = $wpdb->get_results($wpdb->prepare(
                "SELECT feature_name FROM $features_table WHERE property_id = %d", $property_id
            ));
            $features = array_map(function($f) { return $f->feature_name; }, $feature_results);
            
            // Get images
            $images = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $images_table WHERE property_id = %d ORDER BY sort_order", $property_id
            ));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $property_id > 0 ? __('Edit Property', 'property-manager-pro') : __('Add New Property', 'property-manager-pro'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('save_property', 'property_nonce'); ?>
                <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
                
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            
                            <!-- Basic Information -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Basic Information', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="property_unique_id"><?php _e('Property ID', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="property_unique_id" type="text" id="property_unique_id" 
                                                       value="<?php echo $property ? esc_attr($property->property_id) : ''; ?>" 
                                                       class="regular-text" required />
                                                <p class="description"><?php _e('Unique identifier for the property', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="ref"><?php _e('Reference', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="ref" type="text" id="ref" 
                                                       value="<?php echo $property ? esc_attr($property->ref) : ''; ?>" 
                                                       class="regular-text" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="title"><?php _e('Title', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="title" type="text" id="title" 
                                                       value="<?php echo $property ? esc_attr($property->title) : ''; ?>" 
                                                       class="large-text" required />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="type"><?php _e('Property Type', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="type" id="type" required>
                                                    <option value=""><?php _e('Select Type', 'property-manager-pro'); ?></option>
                                                    <?php 
                                                    $types = array('Apartment', 'Villa', 'House', 'Townhouse', 'Bungalow', 'Penthouse', 'Studio', 'Commercial');
                                                    foreach ($types as $type) {
                                                        $selected = ($property && $property->type === $type) ? 'selected' : '';
                                                        echo "<option value=\"$type\" $selected>$type</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="status"><?php _e('Status', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="status" id="status">
                                                    <option value="active" <?php echo ($property && $property->status === 'active') ? 'selected' : ''; ?>><?php _e('Active', 'property-manager-pro'); ?></option>
                                                    <option value="inactive" <?php echo ($property && $property->status === 'inactive') ? 'selected' : ''; ?>><?php _e('Inactive', 'property-manager-pro'); ?></option>
                                                    <option value="sold" <?php echo ($property && $property->status === 'sold') ? 'selected' : ''; ?>><?php _e('Sold', 'property-manager-pro'); ?></option>
                                                    <option value="rented" <?php echo ($property && $property->status === 'rented') ? 'selected' : ''; ?>><?php _e('Rented', 'property-manager-pro'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php _e('Featured', 'property-manager-pro'); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="featured" value="1" 
                                                           <?php echo ($property && $property->featured) ? 'checked' : ''; ?> />
                                                    <?php _e('Mark as featured property', 'property-manager-pro'); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php _e('New Build', 'property-manager-pro'); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="new_build" value="1" 
                                                           <?php echo ($property && $property->new_build) ? 'checked' : ''; ?> />
                                                    <?php _e('This is a new build property', 'property-manager-pro'); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php _e('Swimming Pool', 'property-manager-pro'); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="pool" value="1" 
                                                           <?php echo ($property && $property->pool) ? 'checked' : ''; ?> />
                                                    <?php _e('Has swimming pool', 'property-manager-pro'); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr style="display: none">
                                            <th scope="row">
                                                <label for="url_en"><?php _e('Property Feed URL', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="url_en" type="url" id="url_en" 
                                                       value="<?php echo $property ? esc_attr($property->url_en) : ''; ?>" 
                                                       class="large-text" />
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Price Information -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Price Information', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="price"><?php _e('Price', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="price" type="number" step="0.01" id="price" 
                                                       value="<?php echo $property ? esc_attr($property->price) : ''; ?>" 
                                                       class="regular-text" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="currency"><?php _e('Currency', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="currency" id="currency">
                                                    <option value="EUR" <?php echo ($property && $property->currency === 'EUR') ? 'selected' : ''; ?>>EUR</option>
                                                    <option value="USD" <?php echo ($property && $property->currency === 'USD') ? 'selected' : ''; ?>>USD</option>
                                                    <option value="GBP" <?php echo ($property && $property->currency === 'GBP') ? 'selected' : ''; ?>>GBP</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="price_freq"><?php _e('Price Type', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="price_freq" id="price_freq">
                                                    <option value="sale" <?php echo ($property && $property->price_freq === 'sale') ? 'selected' : ''; ?>><?php _e('For Sale', 'property-manager-pro'); ?></option>
                                                    <option value="rent" <?php echo ($property && $property->price_freq === 'rent') ? 'selected' : ''; ?>><?php _e('For Rent', 'property-manager-pro'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Location Information -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Location Information', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="address_search"><?php _e('Search Address', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <div style="display: flex;">
                                                    <input type="text" id="address_search" placeholder="<?php _e('Type address to search...', 'property-manager-pro'); ?>" class="large-text" />
                                                    <button type="button" id="search_address_btn" class="button"><?php _e('Search', 'property-manager-pro'); ?></button>
                                                </div>
                                                <p class="description"><?php _e('Search for an address or click on the map to set location', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php _e('Location Map', 'property-manager-pro'); ?></th>
                                            <td>
                                                <div id="property_location_map" style="height: 300px; width: 100%; border: 1px solid #ddd; border-radius: 4px;"></div>
                                                <p class="description"><?php _e('Click on the map to set the exact property location', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="town"><?php _e('Town', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="town" type="text" id="town" 
                                                       value="<?php echo $property ? esc_attr($property->town) : ''; ?>" 
                                                       class="regular-text" readonly />
                                                <p class="description"><?php _e('Automatically filled from map selection', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="province"><?php _e('Province', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="province" type="text" id="province" 
                                                       value="<?php echo $property ? esc_attr($property->province) : ''; ?>" 
                                                       class="regular-text" readonly />
                                                <p class="description"><?php _e('Automatically filled from map selection', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="location_detail"><?php _e('Full Address', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text" name="location_detail" id="location_detail" class="regular-text" value="<?php echo $property ? esc_attr($property->location_detail) : ''; ?>" readonly />
                                                <p class="description"><?php _e('Complete address automatically filled from map selection', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="latitude"><?php _e('Latitude', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="latitude" type="number" step="any" id="latitude" 
                                                       value="<?php echo $property ? esc_attr($property->latitude) : ''; ?>" 
                                                       class="regular-text" readonly />
                                                <button type="button" id="manual_coordinates" class="button"><?php _e('Manual Entry', 'property-manager-pro'); ?></button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="longitude"><?php _e('Longitude', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="longitude" type="number" step="any" id="longitude" 
                                                       value="<?php echo $property ? esc_attr($property->longitude) : ''; ?>" 
                                                       class="regular-text" readonly />
                                                <p class="description"><?php _e('Coordinates set from map selection', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Property Details -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Property Details', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="beds"><?php _e('Bedrooms', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="beds" type="number" id="beds" 
                                                       value="<?php echo $property ? esc_attr($property->beds) : ''; ?>" 
                                                       class="small-text" min="0" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="baths"><?php _e('Bathrooms', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="baths" type="number" id="baths" 
                                                       value="<?php echo $property ? esc_attr($property->baths) : ''; ?>" 
                                                       class="small-text" min="0" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="surface_area_built"><?php _e('Built Area (m2)', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="surface_area_built" type="number" id="surface_area_built" 
                                                       value="<?php echo $property ? esc_attr($property->surface_area_built) : ''; ?>" 
                                                       class="regular-text" min="0" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="surface_area_plot"><?php _e('Plot Area (m2)', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input name="surface_area_plot" type="number" id="surface_area_plot" 
                                                       value="<?php echo $property ? esc_attr($property->surface_area_plot) : ''; ?>" 
                                                       class="regular-text" min="0" />
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Energy Rating -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Energy Rating', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="energy_rating_consumption"><?php _e('Consumption Rating', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="energy_rating_consumption" id="energy_rating_consumption">
                                                    <option value=""><?php _e('Select Rating', 'property-manager-pro'); ?></option>
                                                    <?php 
                                                    $ratings = array('A', 'B', 'C', 'D', 'E', 'F', 'G');
                                                    foreach ($ratings as $rating) {
                                                        $selected = ($property && $property->energy_rating_consumption === $rating) ? 'selected' : '';
                                                        echo "<option value=\"$rating\" $selected>$rating</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="energy_rating_emissions"><?php _e('Emissions Rating', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select name="energy_rating_emissions" id="energy_rating_emissions">
                                                    <option value=""><?php _e('Select Rating', 'property-manager-pro'); ?></option>
                                                    <?php 
                                                    foreach ($ratings as $rating) {
                                                        $selected = ($property && $property->energy_rating_emissions === $rating) ? 'selected' : '';
                                                        echo "<option value=\"$rating\" $selected>$rating</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                                                        
                            <!-- Descriptions -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Property Descriptions', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <div class="property-descriptions">
                                        <div class="description-tab-nav">
                                            <button type="button" class="tab-button active" data-tab="en"><?php _e('English', 'property-manager-pro'); ?></button>
                                            <button type="button" class="tab-button" data-tab="es"><?php _e('Spanish', 'property-manager-pro'); ?></button>
                                            <button type="button" class="tab-button" data-tab="de"><?php _e('German', 'property-manager-pro'); ?></button>
                                            <button type="button" class="tab-button" data-tab="fr"><?php _e('French', 'property-manager-pro'); ?></button>
                                        </div>
                                        
                                        <div class="description-tab-content">
                                            <div id="desc-en" class="tab-pane active">
                                                <?php 
                                                wp_editor($property ? $property->description_en : '', 'description_en', array(
                                                    'textarea_rows' => 8,
                                                    'teeny' => true
                                                ));
                                                ?>
                                            </div>
                                            <div id="desc-es" class="tab-pane">
                                                <?php 
                                                wp_editor($property ? $property->description_es : '', 'description_es', array(
                                                    'textarea_rows' => 8,
                                                    'teeny' => true
                                                ));
                                                ?>
                                            </div>
                                            <div id="desc-de" class="tab-pane">
                                                <?php 
                                                wp_editor($property ? $property->description_de : '', 'description_de', array(
                                                    'textarea_rows' => 8,
                                                    'teeny' => true
                                                ));
                                                ?>
                                            </div>
                                            <div id="desc-fr" class="tab-pane">
                                                <?php 
                                                wp_editor($property ? $property->description_fr : '', 'description_fr', array(
                                                    'textarea_rows' => 8,
                                                    'teeny' => true
                                                ));
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                        
                        <div id="postbox-container-1" class="postbox-container">
                            
                            <!-- Features -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Property Features', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <div id="property-features">
                                        <?php foreach ($features as $index => $feature): ?>
                                        <div class="feature-item">
                                            <input type="text" name="features[<?php echo $index; ?>]" value="<?php echo esc_attr($feature); ?>" class="widefat" />
                                            <button type="button" class="button remove-feature"><?php _e('Remove', 'property-manager-pro'); ?></button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" id="add-feature" class="button"><?php _e('Add Feature', 'property-manager-pro'); ?></button>
                                </div>
                            </div>
                            
                            <!-- Images -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2 class="hndle"><?php _e('Property Images', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <div id="property-images">
                                        <?php foreach ($images as $index => $image): ?>
                                        <div class="image-item" data-index="<?php echo $index; ?>">
                                            <img src="<?php echo esc_url($image->image_url); ?>" alt="" style="max-width: 100px; height: auto;" />
                                            <input type="hidden" name="property_images[<?php echo $index; ?>][url]" value="<?php echo esc_attr($image->image_url); ?>" />
                                            <input type="text" name="property_images[<?php echo $index; ?>][title]" 
                                                   value="<?php echo esc_attr($image->image_title); ?>" 
                                                   placeholder="<?php _e('Image title', 'property-manager-pro'); ?>" class="widefat" />
                                            <input type="text" name="property_images[<?php echo $index; ?>][alt]" 
                                                   value="<?php echo esc_attr($image->image_alt); ?>" 
                                                   placeholder="<?php _e('Alt text', 'property-manager-pro'); ?>" class="widefat" />
                                            <button type="button" class="button remove-image"><?php _e('Remove', 'property-manager-pro'); ?></button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" id="add-image" class="button"><?php _e('Add Image', 'property-manager-pro'); ?></button>
                                </div>
                            </div>
                            
                            <!-- Save Button -->
                            <div class="postbox">
                                <div class="inside">
                                    <input type="submit" name="save_property" class="button-primary" value="<?php echo $property_id > 0 ? __('Update Property', 'property-manager-pro') : __('Create Property', 'property-manager-pro'); ?>" />
                                    <a href="<?php echo admin_url('admin.php?page=property-manager-properties'); ?>" class="button"><?php _e('Cancel', 'property-manager-pro'); ?></a>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Initialize map
                var initialLat = <?php echo $property && $property->latitude ? $property->latitude : '37.8836'; ?>; // Spain center
                var initialLng = <?php echo $property && $property->longitude ? $property->longitude : '-4.3242'; ?>; // Spain center            
                // Load Leaflet CSS and JS
                if (typeof L === 'undefined') {
                    // Load Leaflet CSS
                    $('<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />').appendTo('head');
                
                    // Load Leaflet JS
                    $.getScript('https://unpkg.com/leaflet@1.7.1/dist/leaflet.js', function() {
                        initMap(initialLat, initialLng);
                    });
                } else {
                    initMap(initialLat, initialLng);
                }
            });
        </script>
        <?php
    }
    
    


    /**
     * Display admin messages
     */
    private function display_admin_messages() {
        if (!isset($_GET['message'])) {
            return;
        }
        
        $message = $_GET['message'];
        $class = 'notice notice-success is-dismissible';
        $text = '';
        
        switch ($message) {
            case 'created':
                $text = __('Property created successfully.', 'property-manager-pro');
                break;
            case 'updated':
                $text = __('Property updated successfully.', 'property-manager-pro');
                break;
            case 'deleted':
                $text = __('Property deleted successfully.', 'property-manager-pro');
                break;
            case 'save_failed':
                $class = 'notice notice-error is-dismissible';
                $text = __('Failed to save property. Please try again.', 'property-manager-pro');
                break;
            case 'delete_failed':
                $class = 'notice notice-error is-dismissible';
                $text = __('Failed to delete property. Please try again.', 'property-manager-pro');
                break;
        }
        
        if ($text) {
            echo '<div class="' . $class . '"><p>' . $text . '</p></div>';
        }
    }

    /****************************************************************/

    /**
     * Handle property actions (add, edit, delete)
     */
    public function handle_property_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle single property deletion
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['property_id'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_property')) {
                wp_die(__('Security check failed.', 'property-manager-pro'));
            }
            
            $property_id = intval($_GET['property_id']);
            $result = PropertyManager_Database::delete_property($property_id);
            
            if ($result) {
                wp_redirect(add_query_arg(array(
                    'page' => 'property-manager-properties',
                    'message' => 'deleted'
                ), admin_url('admin.php')));
                exit;
            } else {
                wp_redirect(add_query_arg(array(
                    'page' => 'property-manager-properties',
                    'message' => 'delete_failed'
                ), admin_url('admin.php')));
                exit;
            }
        }
        
        // Handle property save (add/edit)
        if (isset($_POST['save_property']) && wp_verify_nonce($_POST['property_nonce'], 'save_property')) {
            $this->save_property();
        }
        
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['properties'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'property_manager_bulk_action')) {
                $this->handle_bulk_delete($_POST['properties']);
            }
        }
    }

    /**
     * Save property (create or update)
     */
    private function save_property() {
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        
        // Sanitize and prepare property data
        $property_data = array(
            'property_id' => sanitize_text_field($_POST['property_unique_id']),
            'ref' => sanitize_text_field($_POST['ref']),
            'title' => sanitize_text_field($_POST['title']),
            'price' => floatval($_POST['price']),
            'currency' => sanitize_text_field($_POST['currency']),
            'price_freq' => sanitize_text_field($_POST['price_freq']),
            'new_build' => isset($_POST['new_build']) ? 1 : 0,
            'type' => sanitize_text_field($_POST['type']),
            'town' => sanitize_text_field($_POST['town']),
            'province' => sanitize_text_field($_POST['province']),
            'location_detail' => sanitize_textarea_field($_POST['location_detail']),
            'beds' => intval($_POST['beds']),
            'baths' => intval($_POST['baths']),
            'pool' => isset($_POST['pool']) ? 1 : 0,
            'latitude' => floatval($_POST['latitude']),
            'longitude' => floatval($_POST['longitude']),
            'surface_area_built' => intval($_POST['surface_area_built']),
            'surface_area_plot' => intval($_POST['surface_area_plot']),
            'energy_rating_consumption' => sanitize_text_field($_POST['energy_rating_consumption']),
            'energy_rating_emissions' => sanitize_text_field($_POST['energy_rating_emissions']),
            'url_en' => esc_url_raw($_POST['url_en']),
            'description_en' => wp_kses_post($_POST['description_en']),
            'description_es' => wp_kses_post($_POST['description_es']),
            'description_de' => wp_kses_post($_POST['description_de']),
            'description_fr' => wp_kses_post($_POST['description_fr']),
            'status' => sanitize_text_field($_POST['status']),
            'featured' => isset($_POST['featured']) ? 1 : 0
        );
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        if ($property_id > 0) {
            // Update existing property
            $property_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update(
                $properties_table,
                $property_data,
                array('id' => $property_id)
            );
            $saved_property_id = $property_id;
            $message = 'updated';
        } else {
            // Create new property
            $property_data['created_at'] = current_time('mysql');
            $property_data['updated_at'] = current_time('mysql');
            $result = $wpdb->insert($properties_table, $property_data);
            $saved_property_id = $wpdb->insert_id;
            $message = 'created';
        }
        
        if ($result !== false) {
            // Handle features
            if (isset($_POST['features']) && is_array($_POST['features'])) {
                $this->save_property_features($saved_property_id, $_POST['features']);
            }
            
            // Handle images
            if (isset($_POST['property_images']) && is_array($_POST['property_images'])) {
                $this->save_property_images($saved_property_id, $_POST['property_images']);
            }
            
            wp_redirect(add_query_arg(array(
                'page' => 'property-manager-properties',
                //'action' => 'edit',
                //'property_id' => $saved_property_id,
                'message' => $message
            ), admin_url('admin.php')));
            exit;
        } else {
            wp_redirect(add_query_arg(array(
                'page' => 'property-manager-properties',
                'action' => $property_id > 0 ? 'edit' : 'add',
                'property_id' => $property_id > 0 ? $property_id : null,
                'message' => 'save_failed'
            ), admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * Save property features
     */
    private function save_property_features($property_id, $features) {
        PropertyManager_Database::insert_property_features($property_id, array_filter($features));
    }
    
    /**
     * Save property images
     */
    private function save_property_images($property_id, $image_data) {
        $images = array();
        
        foreach ($image_data as $index => $data) {
            if (!empty($data['url'])) {
                $images[] = array(
                    'id' => $index + 1,
                    'url' => $data['url'],
                    'title' => isset($data['title']) ? $data['title'] : '',
                    'alt' => isset($data['alt']) ? $data['alt'] : '',
                    'sort_order' => $index
                );
            }
        }
        
        if (!empty($images)) {
            PropertyManager_Database::insert_property_images($property_id, $images);
        }
    }

    /**
     * Handle bulk delete
     */
    private function handle_bulk_delete($property_ids) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'property_manager_bulk_action')) {
            wp_die(__('Security check failed.', 'property-manager-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $deleted = 0;
        foreach ($property_ids as $property_id) {
            $result = PropertyManager_Database::delete_property(intval($property_id));
            if ($result) {
                $deleted++;
            }
        }
        
        wp_redirect(add_query_arg(array(
            'page' => 'property-manager-properties',
            'message' => 'bulk_deleted',
            'deleted_count' => $deleted
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * AJAX handler for property deletion
     */
    public function ajax_delete_property() {
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $property_id = intval($_POST['property_id']);
        $result = PropertyManager_Database::delete_property($property_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Property deleted successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to delete property.', 'property-manager-pro')
            ));
        }
    }

}