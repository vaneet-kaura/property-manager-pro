<?php
/**
 * Property Search Forms class
 * Handles basic and advanced search form generation with Bootstrap 5
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_SearchForms {
    
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
     * Generate basic search form
     */
    public function basic_search_form($args = array()) {
        $defaults = array(
            'show_title' => true,
            'title' => __('Find Your Perfect Property', 'property-manager-pro'),
            'action_url' => get_permalink(get_option('property_manager_pages')['property_search'] ?? ''),
            'method' => 'GET',
            'button_text' => __('Search Properties', 'property-manager-pro'),
            'show_advanced_button' => true,
            'form_id' => 'property-basic-search'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Get current search values
        $current_values = $this->get_current_search_values();
        
        // Get form options
        $options = $this->get_form_options();
        
        ob_start();
        ?>
        <div class="property-search-form <?php echo esc_attr($args['show_title'] ? "bg-light p-4 border" : "")?>">
            <?php if ($args['show_title']): ?>
                <h3 class="mb-4"><?php echo esc_html($args['title']); ?></h3>
            <?php endif; ?>
            
            <form method="<?php echo esc_attr($args['method']); ?>" 
                  action="<?php echo esc_url($args['action_url']); ?>" 
                  id="<?php echo esc_attr($args['form_id']); ?>" 
                  class="property-search-form-inner">
                
                <input type="hidden" name="featured" value="<?php echo esc_attr($current_values['featured']); ?>" />
				
                <!-- Keyword Search -->
				<div class="form-group mb-3">
					<label for="keyword" class="form-label sr-only">
						<?php _e('Search Keyword', 'property-manager-pro'); ?>
					</label>
					<input type="search" class="form-control" name="keyword" value="<?php echo esc_attr($current_values['keyword']); ?>" placeholder="Property reference or keyword" />
				</div>

                <!-- Location Search -->
				<div class="form-group mb-3">
					<label for="location" class="form-label sr-only">
						<?php _e('Location', 'property-manager-pro'); ?>
					</label>
					<select class="form-select" id="town" name="town">
                        <option value=""><?php _e('Any Town', 'property-manager-pro'); ?></option>
                        <?php foreach ($options['towns'] as $province => $towns): foreach ($towns as $town): ?>
                            <option value="<?php echo esc_attr($town); ?>" <?php selected($current_values['town'], $town); ?>>
                                <?php echo esc_html($town); ?>
                            </option>
                        <?php endforeach; endforeach; ?>
                    </select>
				</div>
				
				<!-- Property Type -->
				<div class="form-group mb-3">
					<label for="property_type" class="form-label sr-only">
						<?php _e('Property Type', 'property-manager-pro'); ?>
					</label>
					<select class="form-select" id="property_type" name="property_type">
						<option value=""><?php _e('Property Type', 'property-manager-pro'); ?></option>
						<?php foreach ($options['property_types'] as $type): ?>
							<option value="<?php echo esc_attr($type); ?>" <?php selected($current_values['property_type'], $type); ?>>
								<?php echo esc_html($type); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				
				<!-- Price Range -->
				<div class="form-group mb-3">
					<label class="form-label sr-only"><?php _e('Price Range', 'property-manager-pro'); ?></label>
					<div class="d-flex align-items-center">
						<input type="number" 
							   class="form-control" 
							   name="price_min" 
							   value="<?php echo esc_attr($current_values['price_min']); ?>"
							   placeholder="<?php _e('Min Price', 'property-manager-pro'); ?>"
							   min="0">
						<span class="range-separator d-block px-2">-</span>
						<input type="number" 
							   class="form-control" 
							   name="price_max" 
							   value="<?php echo esc_attr($current_values['price_max']); ?>"
							   placeholder="<?php _e('Max Price', 'property-manager-pro'); ?>"
							   min="0">
					</div>
				</div>          
                
                <!-- Search Buttons -->
                <div class="row align-items-center">
                    <div class="col">
                        <?php if ($args['show_advanced_button']): ?>
							<a href="<?php echo esc_url(get_permalink(get_option('property_manager_pages')['property_advanced_search'] ?? ''))?>">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
								  <path d="M5.6 8.8H7.2V10.4C7.2 10.6122 7.28429 10.8157 7.43431 10.9657C7.58434 11.1157 7.78783 11.2 8 11.2C8.21217 11.2 8.41566 11.1157 8.56569 10.9657C8.71571 10.8157 8.8 10.6122 8.8 10.4V8.8H10.4C10.6122 8.8 10.8157 8.71571 10.9657 8.56569C11.1157 8.41566 11.2 8.21217 11.2 8C11.2 7.78783 11.1157 7.58434 10.9657 7.43431C10.8157 7.28429 10.6122 7.2 10.4 7.2H8.8V5.6C8.8 5.38783 8.71571 5.18434 8.56569 5.03431C8.41566 4.88429 8.21217 4.8 8 4.8C7.78783 4.8 7.58434 4.88429 7.43431 5.03431C7.28429 5.18434 7.2 5.38783 7.2 5.6V7.2H5.6C5.38783 7.2 5.18434 7.28429 5.03431 7.43431C4.88429 7.58434 4.8 7.78783 4.8 8C4.8 8.21217 4.88429 8.41566 5.03431 8.56569C5.18434 8.71571 5.38783 8.8 5.6 8.8ZM15.2 0H0.8C0.587827 0 0.384344 0.0842854 0.234315 0.234315C0.0842854 0.384344 0 0.587827 0 0.8V15.2C0 15.4122 0.0842854 15.6157 0.234315 15.7657C0.384344 15.9157 0.587827 16 0.8 16H15.2C15.4122 16 15.6157 15.9157 15.7657 15.7657C15.9157 15.6157 16 15.4122 16 15.2V0.8C16 0.587827 15.9157 0.384344 15.7657 0.234315C15.6157 0.0842854 15.4122 0 15.2 0ZM14.4 14.4H1.6V1.6H14.4V14.4Z" fill="#FA940C"/>
								</svg>
								Advanced search
							</a>
						<?php endif; ?>
                    </div>
					<div class="col-auto">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-search me-2"></i><?php echo esc_html($args['button_text']); ?>
                        </button>
                    </div>
                </div>
                <?php wp_nonce_field('property_search', 'search_nonce'); ?>
            </form>
        </div>
        <?php        
        return ob_get_clean();
    }
    
    /**
     * Generate advanced search form
     */
    public function advanced_search_form($args = array()) {
        $defaults = array(
            'show_title' => false,
            'title' => __('Advanced Property Search', 'property-manager-pro'),
            'action_url' => '',
            'method' => 'GET',
            'button_text' => __('Search Properties', 'property-manager-pro'),
            'form_id' => 'property-advanced-search'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Get current search values
        $current_values = $this->get_current_search_values();
        
        // Get form options
        $options = $this->get_form_options();
        
        ob_start();
        ?>
        <div class="property-search-form p-4 bg-light mb-4 border">
            <?php if ($args['show_title']): ?>
                <h3 class="text-center mb-4"><?php echo esc_html($args['title']); ?></h3>
            <?php endif; ?>
            
            <form method="<?php echo esc_attr($args['method']); ?>" 
                  action="<?php echo esc_url($args['action_url']); ?>" 
                  id="<?php echo esc_attr($args['form_id']); ?>" 
                  class="property-search-form-inner">
                
                <!-- Basic Fields Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label for="keyword" class="form-label">
						    <?php _e('Search Keyword', 'property-manager-pro'); ?>
					    </label>
					    <input type="search" class="form-control" name="keyword" value="<?php echo esc_attr($current_values['keyword']); ?>" placeholder="Property reference or keyword" />
                    </div>
                    <div class="col-md-4">
                        <label for="town" class="form-label">
                            <?php _e('Location', 'property-manager-pro'); ?>
                        </label>
                        <select class="form-select" id="town" name="town">
                            <option value=""><?php _e('Any Town', 'property-manager-pro'); ?></option>
                            <?php foreach ($options['towns'] as $province => $towns): foreach ($towns as $town): ?>
                                <option value="<?php echo esc_attr($town); ?>" <?php selected($current_values['town'], $town); ?>>
                                    <?php echo esc_html($town); ?>
                                </option>
                            <?php endforeach; endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label for="property_type" class="form-label">
                            <?php _e('Property Type', 'property-manager-pro'); ?>
                        </label>
                        <select class="form-select" id="property_type" name="property_type">
                            <option value=""><?php _e('All Types', 'property-manager-pro'); ?></option>
                            <?php foreach ($options['property_types'] as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($current_values['property_type'], $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <?php echo $this->get_advanced_search_fields($current_values, $options); ?>
                
                <!-- Search Buttons -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-2"></i><?php echo esc_html($args['button_text']); ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="this.form.reset();">
                            <i class="fas fa-undo me-2"></i><?php _e('Clear', 'property-manager-pro'); ?>
                        </button>
                        <?php if (is_user_logged_in()): ?>
                            <button type="button" class="btn btn-outline-primary" id="save-search-btn">
                                <i class="fas fa-heart me-2"></i><?php _e('Save Search', 'property-manager-pro'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php wp_nonce_field('property_search', 'search_nonce'); ?>
            </form>
        </div>
        
        <?php if (is_user_logged_in()): ?>
        <!-- Save Search Modal -->
        <div class="modal fade" id="saveSearchModal" tabindex="-1" aria-labelledby="saveSearchModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="saveSearchModalLabel">
                            <?php _e('Save Search', 'property-manager-pro'); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="save-search-form">
                            <div class="mb-3">
                                <label for="search_name" class="form-label">
                                    <?php _e('Search Name', 'property-manager-pro'); ?>
                                </label>
                                <input type="text" class="form-control" id="search_name" name="search_name" required>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="enable_alerts" name="enable_alerts" value="1">
                                    <label class="form-check-label" for="enable_alerts">
                                        <?php _e('Send me email alerts for new matching properties', 'property-manager-pro'); ?>
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3" id="alert-frequency" style="display: none;">
                                <label for="alert_freq" class="form-label">
                                    <?php _e('Alert Frequency', 'property-manager-pro'); ?>
                                </label>
                                <select class="form-select" id="alert_freq" name="alert_frequency">
                                    <option value="daily"><?php _e('Daily', 'property-manager-pro'); ?></option>
                                    <option value="weekly" selected><?php _e('Weekly', 'property-manager-pro'); ?></option>
                                    <option value="monthly"><?php _e('Monthly', 'property-manager-pro'); ?></option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <?php _e('Cancel', 'property-manager-pro'); ?>
                        </button>
                        <button type="button" class="btn btn-primary" id="confirm-save-search">
                            <?php _e('Save Search', 'property-manager-pro'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif;         
        return ob_get_clean();
    }
    
    /**
     * Get advanced search fields
     */
    private function get_advanced_search_fields($current_values, $options) {
        ob_start();
        ?>
        <div class="advanced-search-content">
            <!-- Price and Size Row -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label"><?php _e('Price Range', 'property-manager-pro'); ?></label>
                    <div class="range-inputs">
                        <input type="number" 
                               class="form-control" 
                               name="price_min" 
                               value="<?php echo esc_attr($current_values['price_min']); ?>"
                               placeholder="<?php _e('Min Price', 'property-manager-pro'); ?>"
                               min="0">
                        <span class="range-separator">-</span>
                        <input type="number" 
                               class="form-control" 
                               name="price_max" 
                               value="<?php echo esc_attr($current_values['price_max']); ?>"
                               placeholder="<?php _e('Max Price', 'property-manager-pro'); ?>"
                               min="0">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label"><?php _e('Built Area (m²)', 'property-manager-pro'); ?></label>
                    <div class="range-inputs">
                        <input type="number" 
                               class="form-control" 
                               name="area_min" 
                               value="<?php echo esc_attr($current_values['area_min']); ?>"
                               placeholder="<?php _e('Min Area', 'property-manager-pro'); ?>"
                               min="0">
                        <span class="range-separator">-</span>
                        <input type="number" 
                               class="form-control" 
                               name="area_max" 
                               value="<?php echo esc_attr($current_values['area_max']); ?>"
                               placeholder="<?php _e('Max Area', 'property-manager-pro'); ?>"
                               min="0">
                    </div>
                </div>
            </div>
            
            <!-- Rooms Row -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label for="bedrooms" class="form-label">
                        <?php _e('Bedrooms', 'property-manager-pro'); ?>
                    </label>
                    <select class="form-select" id="bedrooms" name="bedrooms">
                        <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected($current_values['bedrooms'], $i); ?>>
                                <?php echo $i; ?>+ <?php echo $i == 1 ? __('Bedroom', 'property-manager-pro') : __('Bedrooms', 'property-manager-pro'); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="bathrooms" class="form-label">
                        <?php _e('Bathrooms', 'property-manager-pro'); ?>
                    </label>
                    <select class="form-select" id="bathrooms" name="bathrooms">
                        <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected($current_values['bathrooms'], $i); ?>>
                                <?php echo $i; ?>+ <?php echo $i == 1 ? __('Bathroom', 'property-manager-pro') : __('Bathrooms', 'property-manager-pro'); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="price_freq" class="form-label">
                        <?php _e('For', 'property-manager-pro'); ?>
                    </label>
                    <select class="form-select" id="price_freq" name="price_freq">
                        <option value=""><?php _e('Sale & Rent', 'property-manager-pro'); ?></option>
                        <option value="sale" <?php selected($current_values['price_freq'], 'sale'); ?>>
                            <?php _e('Sale', 'property-manager-pro'); ?>
                        </option>
                        <option value="rent" <?php selected($current_values['price_freq'], 'rent'); ?>>
                            <?php _e('Rent', 'property-manager-pro'); ?>
                        </option>
                    </select>
                </div>
            </div>
            
            <!-- Features Row -->
            <div class="row g-3 mb-3">
                <div class="col-md-12">
                    <label class="form-label"><?php _e('Features', 'property-manager-pro'); ?></label>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="pool" id="feature_pool" 
                                       <?php echo in_array('pool', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_pool">
                                    <?php _e('Swimming Pool', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="new_build" id="feature_new_build" 
                                       <?php echo in_array('new_build', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_new_build">
                                    <?php _e('New Build', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="furnished" id="feature_furnished" 
                                       <?php echo in_array('furnished', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_furnished">
                                    <?php _e('Furnished', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="terrace" id="feature_terrace" 
                                       <?php echo in_array('terrace', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_terrace">
                                    <?php _e('Terrace', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="garage" id="feature_garage" 
                                       <?php echo in_array('garage', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_garage">
                                    <?php _e('Garage', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="garden" id="feature_garden" 
                                       <?php echo in_array('garden', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_garden">
                                    <?php _e('Garden', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="air_conditioning" id="feature_air_conditioning" 
                                       <?php echo in_array('air_conditioning', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_air_conditioning">
                                    <?php _e('Air Conditioning', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="features[]" value="sea_view" id="feature_sea_view" 
                                       <?php echo in_array('sea_view', $current_values['features']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="feature_sea_view">
                                    <?php _e('Sea View', 'property-manager-pro'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Plot Area Row -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label"><?php _e('Plot Area (m²)', 'property-manager-pro'); ?></label>
                    <div class="range-inputs">
                        <input type="number" 
                               class="form-control" 
                               name="plot_min" 
                               value="<?php echo esc_attr($current_values['plot_min']); ?>"
                               placeholder="<?php _e('Min Plot Size', 'property-manager-pro'); ?>"
                               min="0">
                        <span class="range-separator">-</span>
                        <input type="number" 
                               class="form-control" 
                               name="plot_max" 
                               value="<?php echo esc_attr($current_values['plot_max']); ?>"
                               placeholder="<?php _e('Max Plot Size', 'property-manager-pro'); ?>"
                               min="0">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <label for="sort_by" class="form-label">
                        <?php _e('Sort By', 'property-manager-pro'); ?>
                    </label>
                    <select class="form-select" id="sort_by" name="sort_by">
                        <option value="updated_at" <?php selected($current_values['sort_by'], 'updated_at'); ?>>
                            <?php _e('Recently Updated', 'property-manager-pro'); ?>
                        </option>
                        <option value="price_asc" <?php selected($current_values['sort_by'], 'price_asc'); ?>>
                            <?php _e('Price: Low to High', 'property-manager-pro'); ?>
                        </option>
                        <option value="price_desc" <?php selected($current_values['sort_by'], 'price_desc'); ?>>
                            <?php _e('Price: High to Low', 'property-manager-pro'); ?>
                        </option>
                        <option value="beds_desc" <?php selected($current_values['sort_by'], 'beds_desc'); ?>>
                            <?php _e('Most Bedrooms', 'property-manager-pro'); ?>
                        </option>
                        <option value="area_desc" <?php selected($current_values['sort_by'], 'area_desc'); ?>>
                            <?php _e('Largest Area', 'property-manager-pro'); ?>
                        </option>
                    </select>
                </div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Get current search values from request
     */
    private function get_current_search_values() {
        return array(
            'keyword' => sanitize_text_field($_GET['keyword'] ?? ''),
            'location' => sanitize_text_field($_GET['location'] ?? ''),
            'town' => sanitize_text_field($_GET['town'] ?? ''),
            'property_type' => sanitize_text_field($_GET['property_type'] ?? ''),
            'price_min' => intval($_GET['price_min'] ?? 0) ?: '',
            'price_max' => intval($_GET['price_max'] ?? 0) ?: '',
            'area_min' => intval($_GET['area_min'] ?? 0) ?: '',
            'area_max' => intval($_GET['area_max'] ?? 0) ?: '',
            'plot_min' => intval($_GET['plot_min'] ?? 0) ?: '',
            'plot_max' => intval($_GET['plot_max'] ?? 0) ?: '',
            'featured' => boolval($_GET['featured'] ?? 0) ?: '',
            'bedrooms' => sanitize_text_field($_GET['bedrooms'] ?? ''),
            'bathrooms' => sanitize_text_field($_GET['bathrooms'] ?? ''),
            'price_freq' => sanitize_text_field($_GET['price_freq'] ?? ''),
            'province' => sanitize_text_field($_GET['province'] ?? ''),
            'town' => sanitize_text_field($_GET['town'] ?? ''),
            'features' => array_map('sanitize_text_field', $_GET['features'] ?? array()),
            'sort_by' => sanitize_text_field($_GET['sort_by'] ?? 'updated_at')
        );
    }
    
    /**
     * Get form options (property types, provinces, etc.)
     */
    private function get_form_options() {
        global $wpdb;
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Get unique property types
        $property_types = $wpdb->get_col("
            SELECT DISTINCT property_type 
            FROM $properties_table 
            WHERE property_type IS NOT NULL AND property_type != '' 
            ORDER BY property_type ASC
        ");
        
        // Get unique provinces
        $provinces = $wpdb->get_col("
            SELECT DISTINCT province 
            FROM $properties_table 
            WHERE province IS NOT NULL AND province != '' 
            ORDER BY province ASC
        ");
        
        // Get towns grouped by province
        $towns_data = $wpdb->get_results("
            SELECT DISTINCT province, town 
            FROM $properties_table 
            WHERE province IS NOT NULL AND province != '' 
            AND town IS NOT NULL AND town != '' 
            ORDER BY province ASC, town ASC
        ");
        
        $towns = array();
        foreach ($towns_data as $row) {
            $towns[$row->province][] = $row->town;
        }
        
        return array(
            'property_types' => $property_types,
            'provinces' => $provinces,
            'towns' => $towns
        );
    }
    
    /**
     * Generate property alert signup form
     */
    public function get_alert_signup_form($args = array()) {
        $defaults = array(
            'show_title' => true,
            'title' => __('Get Property Alerts', 'property-manager-pro'),
            'description' => __('Subscribe to receive email alerts when new properties matching your criteria become available.', 'property-manager-pro'),
            'form_id' => 'property-alert-signup'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        ob_start();
        ?>
        <div class="property-search-form">
            <?php if ($args['show_title']): ?>
                <h4 class="mb-3"><?php echo esc_html($args['title']); ?></h4>
                <?php if ($args['description']): ?>
                    <p class="text-muted mb-4"><?php echo esc_html($args['description']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <form id="<?php echo esc_attr($args['form_id']); ?>" class="property-alert-form">
                <div class="row g-3">
                    <?php if (!is_user_logged_in()): ?>
                        <div class="col-md-12">
                            <label for="alert_email" class="form-label">
                                <?php _e('Email Address', 'property-manager-pro'); ?> <span class="text-danger">*</span>
                            </label>
                            <input type="email" class="form-control" id="alert_email" name="email" required>
                        </div>
                    <?php endif; ?>
                    
                    <div class="col-md-12">
                        <label for="alert_frequency" class="form-label">
                            <?php _e('Alert Frequency', 'property-manager-pro'); ?>
                        </label>
                        <div class="btn-group w-100" role="group" aria-label="Alert frequency">
                            <input type="radio" class="btn-check" name="frequency" id="freq_daily" value="daily">
                            <label class="btn btn-outline-primary" for="freq_daily">
                                <?php _e('Daily', 'property-manager-pro'); ?>
                            </label>
                            
                            <input type="radio" class="btn-check" name="frequency" id="freq_weekly" value="weekly" checked>
                            <label class="btn btn-outline-primary" for="freq_weekly">
                                <?php _e('Weekly', 'property-manager-pro'); ?>
                            </label>
                            
                            <input type="radio" class="btn-check" name="frequency" id="freq_monthly" value="monthly">
                            <label class="btn btn-outline-primary" for="freq_monthly">
                                <?php _e('Monthly', 'property-manager-pro'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-md-12 text-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-bell me-2"></i>
                            <?php _e('Subscribe to Alerts', 'property-manager-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <?php _e('You can unsubscribe at any time using the link provided in our emails.', 'property-manager-pro'); ?>
                    </small>
                </div>
                
                <?php wp_nonce_field('property_alert_signup', 'alert_nonce'); ?>
            </form>
            
            <div id="alert-signup-result" style="display: none;"></div>
        </div>
        <?php        
        return ob_get_clean();
    }
    
    /**
     * Generate search results filter bar
     */
    public function get_search_filters_bar($current_search = array()) {
        if (empty($current_search) || empty(array_filter($current_search))) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="search-filters-bar bg-light p-3 rounded mb-4">
            <div class="d-flex flex-wrap align-items-center">
                <span class="me-3 fw-bold"><?php _e('Active Filters:', 'property-manager-pro'); ?></span>
                
                <?php foreach ($current_search as $key => $value): ?>
                    <?php if (empty($value) || $key === 'search_nonce' || $key === '_wp_http_referer') continue; ?>
                    
                    <?php if (is_array($value)): ?>
                        <?php foreach ($value as $v): ?>
                            <span class="badge bg-primary me-2 mb-2">
                                <?php echo esc_html($this->get_filter_label($key, $v)); ?>
                                <button type="button" class="btn-close btn-close-white ms-1" 
                                        onclick="removeFilter('<?php echo esc_js($key); ?>', '<?php echo esc_js($v); ?>')"></button>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="badge bg-primary me-2 mb-2">
                            <?php echo esc_html($this->get_filter_label($key, $value)); ?>
                            <button type="button" class="btn-close btn-close-white ms-1" 
                                    onclick="removeFilter('<?php echo esc_js($key); ?>')"></button>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearAllFilters()">
                    <?php _e('Clear All', 'property-manager-pro'); ?>
                </button>
            </div>
        </div>
        <?php        
        return ob_get_clean();
    }
    
    /**
     * Get human-readable filter label
     */
    private function get_filter_label($key, $value) {
        $labels = array(
            'location' => __('Location: %s', 'property-manager-pro'),
            'property_type' => __('Type: %s', 'property-manager-pro'),
            'price_min' => __('Min Price: €%s', 'property-manager-pro'),
            'price_max' => __('Max Price: €%s', 'property-manager-pro'),
            'bedrooms' => __('Bedrooms: %s+', 'property-manager-pro'),
            'bathrooms' => __('Bathrooms: %s+', 'property-manager-pro'),
            'price_freq' => __('For: %s', 'property-manager-pro'),
            'province' => __('Province: %s', 'property-manager-pro'),
            'town' => __('Town: %s', 'property-manager-pro'),
            'area_min' => __('Min Area: %s m²', 'property-manager-pro'),
            'area_max' => __('Max Area: %s m²', 'property-manager-pro'),
            'plot_min' => __('Min Plot: %s m²', 'property-manager-pro'),
            'plot_max' => __('Max Plot: %s m²', 'property-manager-pro')
        );
        
        // Handle feature labels
        if ($key === 'features') {
            $feature_labels = array(
                'pool' => __('Swimming Pool', 'property-manager-pro'),
                'new_build' => __('New Build', 'property-manager-pro'),
                'furnished' => __('Furnished', 'property-manager-pro'),
                'terrace' => __('Terrace', 'property-manager-pro'),
                'garage' => __('Garage', 'property-manager-pro'),
                'garden' => __('Garden', 'property-manager-pro'),
                'air_conditioning' => __('Air Conditioning', 'property-manager-pro'),
                'sea_view' => __('Sea View', 'property-manager-pro')
            );
            return $feature_labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
        }
        
        if (isset($labels[$key])) {
            return sprintf($labels[$key], $value);
        }
        
        return ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
    }
}