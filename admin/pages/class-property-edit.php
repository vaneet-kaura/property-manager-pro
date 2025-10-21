<?php
/**
 * Admin Property Edit/Add Page - Property Manager Pro
 * 
 * Add or edit properties with WP Media Gallery integration and interactive map
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Property_Edit {
    
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
        add_action('admin_init', array($this, 'handle_property_save'));
    }
    
    /**
     * Handle property save/update
     */
    public function handle_property_save() {
        // Check if this is a save request
        if (!isset($_POST['property_manager_save_property'])) {
            return;
        }
        
        // SECURITY: Verify nonce
        if (!isset($_POST['property_edit_nonce']) || 
            !wp_verify_nonce($_POST['property_edit_nonce'], 'property_manager_edit_property')) {
            wp_die(esc_html__('Security check failed.', 'property-manager-pro'));
        }
        
        // SECURITY: Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'property-manager-pro'));
        }
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $features_table = PropertyManager_Database::get_table_name('property_features');
        
        // Get property ID (0 for new property)
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        
        // SECURITY: Sanitize all inputs
        $data = array(
            'ref' => sanitize_text_field($_POST['ref']),
            'title' => sanitize_text_field($_POST['title']),
            'desc_en' => wp_kses_post($_POST['desc_en']),
            'desc_es' => wp_kses_post($_POST['desc_es']),
            'desc_de' => wp_kses_post($_POST['desc_de']),
            'desc_fr' => wp_kses_post($_POST['desc_fr']),
            'property_type' => sanitize_text_field($_POST['property_type']),
            'town' => sanitize_text_field($_POST['town']),
            'province' => sanitize_text_field($_POST['province']),
            'location_detail' => sanitize_text_field($_POST['location_detail']),
            'price' => floatval($_POST['price']),
            'currency' => sanitize_text_field($_POST['currency']),
            'price_freq' => sanitize_text_field($_POST['price_freq']),
            'beds' => absint($_POST['beds']),
            'baths' => absint($_POST['baths']),
            'surface_area_built' => absint($_POST['surface_area_built']),
            'surface_area_plot' => absint($_POST['surface_area_plot']),
            'energy_rating_consumption' => sanitize_text_field($_POST['energy_rating_consumption']),
            'energy_rating_emissions' => sanitize_text_field($_POST['energy_rating_emissions']),
            'latitude' => floatval($_POST['latitude']),
            'longitude' => floatval($_POST['longitude']),
            'new_build' => isset($_POST['new_build']) ? 1 : 0,
            'pool' => isset($_POST['pool']) ? 1 : 0,
            'featured' => isset($_POST['featured']) ? 1 : 0,
            'status' => sanitize_text_field($_POST['status'])
        );

        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            if ($property_id > 0) {
                // UPDATE existing property
                $data['updated_at'] = current_time('mysql');
                
                $result = $wpdb->update(
                    $properties_table,
                    $data,
                    array('id' => $property_id),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%s', '%s'),
                    array('%d')
                );
                
                if ($result === false) {
                    throw new Exception('Failed to update property');
                }
                
                $message = __('Property updated successfully.', 'property-manager-pro');
                
            } else {
                // INSERT new property
                $data['created_at'] = current_time('mysql');
                $data['updated_at'] = current_time('mysql');
                $data['property_id'] = uniqid("website_");
                
                $result = $wpdb->insert(
                    $properties_table,
                    $data,
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
                );
                
                if ($result === false) {
                    throw new Exception('Failed to create property');
                }
                
                $property_id = $wpdb->insert_id;
                $message = __('Property created successfully.', 'property-manager-pro');
            }
            
            // Handle WP Media Gallery Images
            if (isset($_POST['property_images']) && !empty($_POST['property_images'])) {
                
                $sort_order = 1;
                $images = [];
                foreach (json_decode($_POST['property_images']) as $attachment_id) {
                    $attachment_id = absint($attachment_id);
                    
                    // Verify attachment exists and is an image
                    if (!wp_attachment_is_image($attachment_id)) {
                        continue;
                    }
                    
                    // Get attachment URL
                    $image_url = wp_get_attachment_url($attachment_id);
                    
                    if (!$image_url) {
                        continue;
                    }
                    
                    array_push($images, array(
                        'id' => $sort_order,
                        'attachment_id' => $attachment_id,
                        'url' => $image_url,
                        'sort_order' => $sort_order,
                        'download_status' => 'downloaded',
                        'created_at' => current_time('mysql')
                    ));                    
                    $sort_order++;
                }
                $result = PropertyManager_Database::insert_update_property_images($property_id, $images, false);
            }
            
            // Handle Features
            if (isset($_POST['property_features']) && !empty($_POST['property_features'])) {
                // Delete existing features
                $wpdb->delete($features_table, array('property_id' => $property_id), array('%d'));
                
                // Parse features (comma or newline separated)
                $features_text = sanitize_textarea_field($_POST['property_features']);
                $features = preg_split('/[\r\n,]+/', $features_text);
                
                foreach ($features as $feature) {
                    $feature = trim($feature);
                    if (!empty($feature)) {
                        $wpdb->insert(
                            $features_table,
                            array(
                                'property_id' => $property_id,
                                'feature_name' => $feature,
                                'created_at' => current_time('mysql')
                            ),
                            array('%d', '%s', '%s')
                        );
                    }
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Redirect with success message
            wp_redirect(add_query_arg(
                array(
                    'page' => 'property-manager-edit',
                    'property_id' => $property_id,
                    'message' => 'saved'
                ),
                admin_url('admin.php')
            ));
            exit;
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            
            wp_redirect(add_query_arg(
                array(
                    'page' => 'property-manager-edit',
                    'property_id' => $property_id,
                    'message' => 'error'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Render property edit/add page
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
        
        // Get property ID from URL
        $property_id = isset($_GET['property_id']) ? absint($_GET['property_id']) : 0;
        
        // Get property data if editing
        $property = null;
        $property_images = array();
        $property_features = '';
        
        if ($property_id > 0) {
            $properties_table = PropertyManager_Database::get_table_name('properties');
            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$properties_table} WHERE id = %d",
                $property_id
            ));
            
            if (!$property) {
                wp_die(esc_html__('Property not found.', 'property-manager-pro'));
            }
            
            // Get images
            $images_table = PropertyManager_Database::get_table_name('property_images');
            $property_images = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$images_table} WHERE property_id = %d ORDER BY sort_order ASC",
                $property_id
            ));
            
            // Get features
            $features_table = PropertyManager_Database::get_table_name('property_features');
            $features_result = $wpdb->get_col($wpdb->prepare(
                "SELECT feature_name FROM {$features_table} WHERE property_id = %d ORDER BY id ASC",
                $property_id
            ));
            $property_features = !empty($features_result) ? implode("\n", $features_result) : '';
        }
        
        // Default values for new property
        $defaults = array(
            'ref' => '',
            'title' => '',
            'desc_en' => '',
            'desc_es' => '',
            'desc_de' => '',
            'desc_fr' => '',
            'property_type' => 'Apartment',
            'town' => '',
            'province' => '',
            'location_detail' => '',
            'price' => 0,
            'currency' => 'EUR',
            'price_freq' => 'sale',
            'beds' => 0,
            'baths' => 0,
            'surface_area_built' => 0,
            'surface_area_plot' => 0,
            'energy_rating_consumption' => '',
            'energy_rating_emissions' => '',
            'latitude' => 37.8724,
            'longitude' => -0.7959,
            'new_build' => 0,
            'pool' => 0,
            'featured' => 0,
            'status' => 'active',
            'url_en' => ''
        );
        
        // Merge with existing property data
        if ($property) {
            foreach ($defaults as $key => $value) {
                $defaults[$key] = isset($property->$key) ? $property->$key : $value;
            }
        }
        
        // Enqueue WordPress media uploader
        wp_enqueue_media();
        
        // Enqueue Leaflet for map
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
        
        ?>
        <div class="wrap">
            <h1><?php echo $property_id > 0 ? esc_html__('Edit Property', 'property-manager-pro') : esc_html__('Add New Property', 'property-manager-pro'); ?></h1>
            
            <?php
            // Display messages
            if (isset($_GET['message'])) {
                if ($_GET['message'] === 'saved') {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         esc_html__('Property saved successfully.', 'property-manager-pro') . 
                         '</p></div>';
                } elseif ($_GET['message'] === 'error') {
                    echo '<div class="notice notice-error is-dismissible"><p>' . 
                         esc_html__('Error saving property. Please try again.', 'property-manager-pro') . 
                         '</p></div>';
                }
            }
            ?>
            
            <form method="post" action="" id="property-edit-form">
                <?php wp_nonce_field('property_manager_edit_property', 'property_edit_nonce'); ?>
                <input type="hidden" name="property_manager_save_property" value="1">
                <input type="hidden" name="property_id" value="<?php echo esc_attr($property_id); ?>">
                <input type="hidden" name="property_images" id="property_images_field" value="">
                
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        
                        <!-- Main Content -->
                        <div id="post-body-content">
                            
                            <!-- Basic Information -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Basic Information', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><label for="title"><?php esc_html_e('Title *', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="title" name="title" value="<?php echo esc_attr($defaults['title']); ?>" class="large-text" required></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="ref"><?php esc_html_e('Reference *', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text" 
                                                       id="ref" 
                                                       name="ref" 
                                                       value="<?php echo esc_attr($defaults['ref']); ?>" 
                                                       class="regular-text" 
                                                       required>
                                                <p class="description"><?php esc_html_e('Unique property reference code', 'property-manager-pro'); ?></p>
                                            </td>
                                        </tr>                                        
                                        <tr>
                                            <th scope="row">
                                                <label for="property_type"><?php esc_html_e('Property Type *', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select id="property_type" name="property_type" class="regular-text" required>
                                                    <?php
                                                    $property_types = array(
                                                        'Apartment', 'Villa', 'Townhouse', 'Bungalow', 
                                                        'Penthouse', 'Duplex', 'Studio', 'Commercial', 'Land', 'Other'
                                                    );
                                                    foreach ($property_types as $property_type) {
                                                        echo '<option value="' . esc_attr($property_type) . '" ' . 
                                                             selected($defaults['property_type'], $property_type, false) . '>' . 
                                                             esc_html($property_type) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        
                                        <tr>
                                            <th scope="row">
                                                <label for="status"><?php esc_html_e('Status *', 'property-manager-pro'); ?></label>
                                            </th>
                                            <td>
                                                <select id="status" name="status" class="regular-text" required>
                                                    <option value="active" <?php selected($defaults['status'], 'active'); ?>><?php esc_html_e('Active', 'property-manager-pro'); ?></option>
                                                    <option value="inactive" <?php selected($defaults['status'], 'inactive'); ?>><?php esc_html_e('Inactive', 'property-manager-pro'); ?></option>
                                                    <option value="sold" <?php selected($defaults['status'], 'sold'); ?>><?php esc_html_e('Sold', 'property-manager-pro'); ?></option>
                                                    <option value="rented" <?php selected($defaults['status'], 'rented'); ?>><?php esc_html_e('Rented', 'property-manager-pro'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            
                            
                            <!-- Multilingual Descriptions -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Property Descriptions', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <div style="margin: 20px 0px;">
                                        <label for="desc_en" style="margin-bottom: -20px;display: block;"><strong><?php esc_html_e('Description (English) *', 'property-manager-pro'); ?></strong></label>
                                        <?php
                                        wp_editor($defaults['desc_en'], 'desc_en', array(
                                            'textarea_name' => 'desc_en',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true
                                        ));
                                        ?>
                                    </div>
                                    
                                    <div style="margin: 20px 0px;">
                                        <label for="desc_es" style="margin-bottom: -20px;display: block;"><strong><?php esc_html_e('Description (Spanish)', 'property-manager-pro'); ?></strong></label>
                                        <?php
                                        wp_editor($defaults['desc_es'], 'desc_es', array(
                                            'textarea_name' => 'desc_es',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true
                                        ));
                                        ?>
                                    </div>
                                    
                                    <div style="margin: 20px 0px;">
                                        <label for="desc_de" style="margin-bottom: -20px;display: block;"><strong><?php esc_html_e('Description (German)', 'property-manager-pro'); ?></strong></label>
                                        <?php
                                        wp_editor($defaults['desc_de'], 'desc_de', array(
                                            'textarea_name' => 'desc_de',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true
                                        ));
                                        ?>
                                    </div>
                                    
                                    <div style="margin: 20px 0px;">
                                        <label for="desc_fr" style="margin-bottom: -20px;display: block;"><strong><?php esc_html_e('Description (French)', 'property-manager-pro'); ?></strong></label>
                                        <?php
                                        wp_editor($defaults['desc_fr'], 'desc_fr', array(
                                            'textarea_name' => 'desc_fr',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true
                                        ));
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Location & Map -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Location & Map', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><label for="town"><?php esc_html_e('Town *', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="town" name="town" value="<?php echo esc_attr($defaults['town']); ?>" class="regular-text" required></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="province"><?php esc_html_e('Province *', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="province" name="province" value="<?php echo esc_attr($defaults['province']); ?>" class="regular-text" required></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="location_detail"><?php esc_html_e('Location Detail', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="location_detail" name="location_detail" value="<?php echo esc_attr($defaults['location_detail']); ?>" class="large-text"></td>
                                        </tr>
                                    </table>
                                    
                                    <div style="margin-top: 20px;">
                                        <h3><?php esc_html_e('Map Coordinates', 'property-manager-pro'); ?></h3>
                                        <p class="description"><?php esc_html_e('Click on the map to set property location or enter coordinates manually.', 'property-manager-pro'); ?></p>
                                        
                                        <div style="display: flex; gap: 20px; margin-bottom: 10px;">
                                            <div>
                                                <label for="latitude"><?php esc_html_e('Latitude:', 'property-manager-pro'); ?></label>
                                                <input type="number" 
                                                       id="latitude" 
                                                       name="latitude" 
                                                       value="<?php echo esc_attr($defaults['latitude']); ?>" 
                                                       step="0.000001" 
                                                       min="-90" 
                                                       max="90" 
                                                       class="regular-text"
                                                       required>
                                            </div>
                                            <div>
                                                <label for="longitude"><?php esc_html_e('Longitude:', 'property-manager-pro'); ?></label>
                                                <input type="number" 
                                                       id="longitude" 
                                                       name="longitude" 
                                                       value="<?php echo esc_attr($defaults['longitude']); ?>" 
                                                       step="0.000001" 
                                                       min="-180" 
                                                       max="180" 
                                                       class="regular-text"
                                                       required>
                                            </div>
                                            <div style="align-self: flex-end;">
                                                <button type="button" id="update-map-btn" class="button">
                                                    <?php esc_html_e('Update Map', 'property-manager-pro'); ?>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div id="property-map" style="width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 4px;"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Property Details -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Property Details', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><label for="price"><?php esc_html_e('Price *', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <select name="currency" style="vertical-align: top">
                                                    <option value="EUR" <?php selected($defaults['currency'], 'EUR'); ?>>EUR (&euro;)</option>
                                                </select>
                                                <input type="number" id="price" name="price" value="<?php echo esc_attr($defaults['price']); ?>" step="0.01" min="0" class="regular-text" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="price_freq"><?php esc_html_e('Price Type', 'property-manager-pro'); ?></label></th>
                                            <td>
                                                <select name="price_freq" id="price_freq">
                                                    <option value="sale" <?php selected($defaults['price_freq'], 'sale'); ?>><?php esc_html_e('For Sale', 'property-manager-pro'); ?></option>
                                                    <option value="rent" <?php selected($defaults['price_freq'], 'rent'); ?>><?php esc_html_e('For Rent', 'property-manager-pro'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="beds"><?php esc_html_e('Bedrooms', 'property-manager-pro'); ?></label></th>
                                            <td><input type="number" id="beds" name="beds" value="<?php echo esc_attr($defaults['beds']); ?>" min="0" class="small-text"></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="baths"><?php esc_html_e('Bathrooms', 'property-manager-pro'); ?></label></th>
                                            <td><input type="number" id="baths" name="baths" value="<?php echo esc_attr($defaults['baths']); ?>" min="0" class="small-text"></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="surface_area_built"><?php esc_html_e('Built Area', 'property-manager-pro'); ?> (m<sup>2</sup>)</label></th>
                                            <td><input type="number" id="surface_area_built" name="surface_area_built" value="<?php echo esc_attr($defaults['surface_area_built']); ?>" min="0" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="surface_area_plot"><?php esc_html_e('Plot Area', 'property-manager-pro'); ?> (m<sup>2</sup>)</label></th>
                                            <td><input type="number" id="surface_area_plot" name="surface_area_plot" value="<?php echo esc_attr($defaults['surface_area_plot']); ?>" min="0" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="energy_rating_consumption"><?php esc_html_e('Energy Rating (consumption)', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="energy_rating_consumption" name="energy_rating_consumption" value="<?php echo esc_attr($defaults['energy_rating_consumption']); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="energy_rating_emissions"><?php esc_html_e('Energy Rating (emissions)', 'property-manager-pro'); ?></label></th>
                                            <td><input type="text" id="energy_rating_emissions" name="energy_rating_emissions" value="<?php echo esc_attr($defaults['energy_rating_emissions']); ?>" class="regular-text"></td>
                                        </tr>
                                    </table>
                                    
                                    <h3><?php esc_html_e('Additional Features', 'property-manager-pro'); ?></h3>
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><?php esc_html_e('Property Attributes', 'property-manager-pro'); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="new_build" value="1" <?php checked($defaults['new_build'], 1); ?>>
                                                    <?php esc_html_e('New Build', 'property-manager-pro'); ?>
                                                </label><br>
                                                <label>
                                                    <input type="checkbox" name="pool" value="1" <?php checked($defaults['pool'], 1); ?>>
                                                    <?php esc_html_e('Swimming Pool', 'property-manager-pro'); ?>
                                                </label><br>
                                                <label>
                                                    <input type="checkbox" name="featured" value="1" <?php checked($defaults['featured'], 1); ?>>
                                                    <?php esc_html_e('Featured Property', 'property-manager-pro'); ?>
                                                </label>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Property Features -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Property Features', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <p class="description"><?php esc_html_e('Enter one feature per line (e.g., Air Conditioning, Garage, Garden, etc.)', 'property-manager-pro'); ?></p>
                                    <textarea name="property_features" 
                                              id="property_features" 
                                              rows="10" 
                                              class="large-text code"><?php echo esc_textarea($property_features); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <!-- End Main Content -->
                        
                        <!-- Sidebar -->
                        <div id="postbox-container-1" class="postbox-container">
                            
                            <!-- Save/Publish -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Publish', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <div class="submitbox">
                                        <div id="major-publishing-actions">
                                            <div id="delete-action">
                                                <?php if ($property_id > 0): ?>
                                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . $property_id), 'property_action_delete')); ?>" 
                                                       class="submitdelete deletion"
                                                       onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this property?', 'property-manager-pro')); ?>');">
                                                        <?php esc_html_e('Delete Property', 'property-manager-pro'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div id="publishing-action">
                                                <span class="spinner"></span>
                                                <input type="submit" 
                                                       name="save" 
                                                       id="publish" 
                                                       class="button button-primary button-large" 
                                                       value="<?php echo $property_id > 0 ? esc_attr__('Update Property', 'property-manager-pro') : esc_attr__('Add Property', 'property-manager-pro'); ?>">
                                            </div>
                                            <div class="clear"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Property Images - WP Media Gallery -->
                            <div class="postbox">
                                <div class="postbox-header">
                                    <h2><?php esc_html_e('Property Images', 'property-manager-pro'); ?></h2>
                                </div>
                                <div class="inside">
                                    <p class="description"><?php esc_html_e('Add images from WordPress Media Library. Drag to reorder.', 'property-manager-pro'); ?></p>
                                    
                                    <div id="property-images-container" class="property-images-gallery">
                                        <?php if (!empty($property_images)): ?>
                                            <?php foreach ($property_images as $image): ?>
												<?php if($image->attachment_id != null):?>
													<div class="property-image-item" data-attachment-id="<?php echo esc_attr($image->attachment_id); ?>">
														<img src="<?php echo esc_url(wp_get_attachment_image_url($image->attachment_id, 'medium')); ?>" alt="">
														<button type="button" class="remove-image" title="<?php esc_attr_e('Remove', 'property-manager-pro'); ?>">x</button>
													</div>
												<?php else:?>
													<div class="property-image-item" data-attachment-id="<?php echo esc_attr($image->attachment_id); ?>">
														<img src="<?php echo esc_url($image->image_url); ?>" alt="">
														<button type="button" class="remove-image" title="<?php esc_attr_e('Remove', 'property-manager-pro'); ?>">x</button>
													</div>
												<?php endif;?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p style="margin-top: 15px;">
                                        <button type="button" id="add-images-btn" class="button button-secondary">
                                            <?php esc_html_e('Add Images', 'property-manager-pro'); ?>
                                        </button>
                                    </p>
                                </div>
                            </div>
                            
                        </div>
                        <!-- End Sidebar -->
                        
                    </div>
                </div>
            </form>
        </div>
        
        <style>
            .property-images-gallery {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 10px;
                margin-top: 10px;
            }
            
            .property-image-item {
                position: relative;
                aspect-ratio: 1;
                border: 2px solid #ddd;
                border-radius: 4px;
                overflow: hidden;
                cursor: move;
            }
            
            .property-image-item img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .property-image-item .remove-image {
                position: absolute;
                top: 5px;
                right: 5px;
                background: rgba(255, 0, 0, 0.8);
                color: white;
                border: none;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                font-size: 16px;
                line-height: 1;
                cursor: pointer;
                display: none;
            }
            
            .property-image-item:hover .remove-image {
                display: block;
            }
            
            .property-image-item.ui-sortable-helper {
                opacity: 0.6;
            }
            
            #property-map {
                cursor: crosshair;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // ====================
            // WP MEDIA GALLERY
            // ====================
            
            var propertyImages = [];
            
            // Initialize images from existing data
            <?php if (!empty($property_images)): ?>
                propertyImages = [<?php echo implode(',', array_map(function($img) { return $img->attachment_id; }, $property_images)); ?>];
            <?php endif; ?>
            
            // Update hidden field
            function updateImagesField() {
                $('#property_images_field').val(JSON.stringify(propertyImages));
            }
            
            // WordPress Media Library
            var mediaFrame;
            
            $('#add-images-btn').on('click', function(e) {
                e.preventDefault();
                
                if (mediaFrame) {
                    mediaFrame.open();
                    return;
                }
                
                mediaFrame = wp.media({
                    title: '<?php esc_html_e('Select Property Images', 'property-manager-pro'); ?>',
                    button: {
                        text: '<?php esc_html_e('Add to Property', 'property-manager-pro'); ?>'
                    },
                    multiple: true,
                    library: {
                        type: 'image'
                    }
                });
                
                mediaFrame.on('select', function() {
                    var selection = mediaFrame.state().get('selection');
                    
                    selection.map(function(attachment) {
                        attachment = attachment.toJSON();
                        
                        // Check if already added
                        if (propertyImages.indexOf(attachment.id) !== -1) {
                            return;
                        }
                        
                        propertyImages.push(attachment.id);
                        
                        // Add to gallery
                        var imageHtml = '<div class="property-image-item" data-attachment-id="' + attachment.id + '">' +
                                        '<img src="' + attachment.url + '" alt="">' +
                                        '<button type="button" class="remove-image" title="<?php esc_attr_e('Remove', 'property-manager-pro'); ?>">×</button>' +
                                        '</div>';
                        
                        $('#property-images-container').append(imageHtml);
                    });
                    
                    updateImagesField();
                });
                
                mediaFrame.open();
            });
            
            // Remove image
            $(document).on('click', '.remove-image', function() {
                var item = $(this).closest('.property-image-item');
                var attachmentId = parseInt(item.data('attachment-id'));
                
                var index = propertyImages.indexOf(attachmentId);
                if (index > -1) {
                    propertyImages.splice(index, 1);
                }
                
                item.remove();
                updateImagesField();
            });
            
            // Make images sortable
            if ($.fn.sortable) {
                $('#property-images-container').sortable({
                    items: '.property-image-item',
                    cursor: 'move',
                    opacity: 0.6,
                    update: function() {
                        // Update array order
                        propertyImages = [];
                        $('#property-images-container .property-image-item').each(function() {
                            propertyImages.push(parseInt($(this).data('attachment-id')));
                        });
                        updateImagesField();
                    }
                });
            }
            
            // ====================
            // LEAFLET MAP
            // ====================
            
            var lat = parseFloat($('#latitude').val()) || 37.8724;
            var lng = parseFloat($('#longitude').val()) || -0.7959;
            
            // Initialize map
            var map = L.map('property-map').setView([lat, lng], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Add marker
            var marker = L.marker([lat, lng], {
                draggable: true
            }).addTo(map);
            
            // Update coordinates when marker is dragged
            marker.on('dragend', function(e) {
                var position = marker.getLatLng();
                $('#latitude').val(position.lat.toFixed(6));
                $('#longitude').val(position.lng.toFixed(6));
            });
            
            // Update marker when clicking on map
            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                $('#latitude').val(e.latlng.lat.toFixed(6));
                $('#longitude').val(e.latlng.lng.toFixed(6));
            });
            
            // Update map when coordinates are entered manually
            $('#update-map-btn').on('click', function() {
                var newLat = parseFloat($('#latitude').val());
                var newLng = parseFloat($('#longitude').val());
                
                if (!isNaN(newLat) && !isNaN(newLng)) {
                    marker.setLatLng([newLat, newLng]);
                    map.setView([newLat, newLng], 13);
                } else {
                    alert('<?php esc_html_e('Please enter valid coordinates.', 'property-manager-pro'); ?>');
                }
            });
            
            // Also update map when latitude/longitude inputs change
            $('#latitude, #longitude').on('change', function() {
                var newLat = parseFloat($('#latitude').val());
                var newLng = parseFloat($('#longitude').val());
                
                if (!isNaN(newLat) && !isNaN(newLng)) {
                    marker.setLatLng([newLat, newLng]);
                    map.setView([newLat, newLng]);
                }
            });
            
            // Initialize images field on load
            updateImagesField();
        });
        </script>
        <?php
    }
}