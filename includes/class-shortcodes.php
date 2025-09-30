<?php
/**
 * Shortcodes class for Property Manager Pro
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Shortcodes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init_shortcodes'));
    }
    
    /**
     * Initialize all shortcodes
     */
    public function init_shortcodes() {
        // Search shortcodes        
        add_shortcode('property_search_form', array($this, 'property_search_form'));
        add_shortcode('property_advanced_search_form', array($this, 'property_advanced_search_form'));
        add_shortcode('property_search_results', array($this, 'property_search_results'));
        
        // User management shortcodes
        add_shortcode('property_user_dashboard', array($this, 'property_user_dashboard'));
        //add_shortcode('property_user_favorites', array($this, 'property_user_favorites'));
        add_shortcode('property_saved_searches', array($this, 'property_saved_searches'));
        //add_shortcode('property_alerts_management', array($this, 'property_alerts_management'));
        //add_shortcode('property_last_viewed', array($this, 'property_last_viewed'));
        
        // Property display shortcodes
        add_shortcode('property_list', array($this, 'property_list'));
        add_shortcode('property_featured', array($this, 'property_featured'));
        add_shortcode('property_detail', array($this, 'property_detail'));
        add_shortcode('property_contact_form', array($this, 'property_contact_form'));
        
        // Alert subscription shortcode
        add_shortcode('property_alert_signup', array($this, 'property_alert_signup'));
    }
    
    /**
     * Basic property search form
     */
    public function property_search_form($atts) {
        $atts = shortcode_atts(array(
            'show_location' => true,
            'show_advanced_toggle' => false,
            'show_type' => true,
            'show_beds' => true,
            'show_price' => true,
            'ajax' => true
        ), $atts);
        
        $search_forms = PropertyManager_SearchForms::get_instance();
        return $search_forms->basic_search_form($atts);
    }
    
    /**
     * Advanced property search form
     */
    public function property_advanced_search_form($atts) {
        $atts = shortcode_atts(array(
            'ajax' => false,
            'show_map' => true
        ), $atts);
        
        $search_forms = PropertyManager_SearchForms::get_instance();
        return $search_forms->advanced_search_form($atts);
    }
    
    /**
     * Property search results
     */
    public function property_search_results($atts) {
        $atts = shortcode_atts(array(
            'per_page' => get_option('property_manager_options')['results_per_page'] ?? 20,
            'view' => get_option('property_manager_options')['default_view'] ?? 'grid',
            'show_map' => get_option('property_manager_options')['enable_map'] ?? true,
            'show_sorting' => true,
            'show_filters' => true,
            'ajax' => true
        ), $atts);
        
        ob_start();
        $this->render_search_results($atts);
        return ob_get_clean();
    }
    
    /**
     * User dashboard
     */
    public function property_user_dashboard($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        $atts = shortcode_atts(array(
            'show_stats' => true,
            'show_recent_activity' => true
        ), $atts);
        
        ob_start();
        $this->render_user_dashboard($atts);
        return ob_get_clean();
    }
    
    /**
     * User favorites
     */
    public function property_user_favorites($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        $atts = shortcode_atts(array(
            'per_page' => 20,
            'view' => 'grid'
        ), $atts);
        
        $favorites = PropertyManager_Favorites::get_instance();
        return $favorites->render_user_favorites($atts);
    }
    
    /**
     * Saved searches
     */
    public function property_saved_searches($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        $atts = shortcode_atts(array(
            'show_alerts' => true
        ), $atts);
        
        ob_start();
        $this->render_saved_searches($atts);
        return ob_get_clean();
    }
    
    /**
     * Property alerts management
     */
    public function property_alerts_management($atts) {
        $atts = shortcode_atts(array(
            'require_login' => false
        ), $atts);
        
        if ($atts['require_login'] && !is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        $alerts = PropertyManager_Alerts::get_instance();
        return $alerts->render_alerts_management($atts);
    }
    
    /**
     * Last viewed properties
     */
    public function property_last_viewed($atts) {
        $atts = shortcode_atts(array(
            'limit' => 10,
            'view' => 'list'
        ), $atts);
        
        ob_start();
        $this->render_last_viewed_properties($atts);
        return ob_get_clean();
    }
    
    /**
     * Property list with filters
     */
    public function property_list($atts) {
        $atts = shortcode_atts(array(
            'limit' => 20,
            'type' => '',
            'location' => '',
            'min_price' => '',
            'max_price' => '',
            'beds' => '',
            'featured' => false,
            'view' => 'grid',
            'show_pagination' => true,
            'orderby' => 'date',
            'order' => 'DESC'
        ), $atts);
        
        ob_start();
        $this->render_property_list($atts);
        return ob_get_clean();
    }
    
    /**
     * Featured properties
     */
    public function property_featured($atts) {
        $atts = shortcode_atts(array(
            'limit' => 6,
            'view' => 'grid',
            'show_all_link' => true
        ), $atts);
        
        $atts['featured'] = true;
        return $this->property_list($atts);
    }
    
    /**
     * Single property detail
     */
    public function property_detail($atts) {
        $atts = shortcode_atts(array(
            'id' => get_query_var('property_id', 0),
            'show_contact_form' => true,
            'show_map' => true,
            'show_similar' => true
        ), $atts);
        
        if (empty($atts['id'])) {
            return '<p>' . __('Property not found.', 'property-manager-pro') . '</p>';
        }
        
        ob_start();
        $this->render_property_detail($atts);
        return ob_get_clean();
    }
    
    /**
     * Property contact form
     */
    public function property_contact_form($atts) {
        $atts = shortcode_atts(array(
            'property_id' => get_query_var('property_id', 0),
            'show_property_info' => true
        ), $atts);
        
        ob_start();
        $this->render_property_contact_form($atts);
        return ob_get_clean();
    }
    
    /**
     * Property alert signup form
     */
    public function property_alert_signup($atts) {
        $atts = shortcode_atts(array(
            'title' => __('Get Property Alerts', 'property-manager-pro'),
            'description' => __('Sign up to receive email alerts for properties matching your criteria.', 'property-manager-pro')
        ), $atts);
        
        ob_start();
        $this->render_alert_signup_form($atts);
        return ob_get_clean();
    }
    
    /**
     * Render search results
     */
    private function render_search_results($atts) {
        $search = PropertyManager_Search::get_instance();
        $results = $search->search_properties();        
        ?>
        <div id="property-search-results" class="container-fluid">
            <!-- Search Controls -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="mb-0 text-primary">
                                <i class="fas fa-search me-2"></i>
                                <?php printf(__('Found %d properties', 'property-manager-pro'), $results['total']); ?>
                            </h5>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-end align-items-center gap-2">
                                <?php if ($atts['show_sorting']): ?>
                                    <select name="orderby" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                        <option value="date"><?php _e('Newest First', 'property-manager-pro'); ?></option>
                                        <option value="price-asc"><?php _e('Price: Low to High', 'property-manager-pro'); ?></option>
                                        <option value="price-desc"><?php _e('Price: High to Low', 'property-manager-pro'); ?></option>
                                        <option value="beds"><?php _e('Most Bedrooms', 'property-manager-pro'); ?></option>
                                    </select>
                                <?php endif; ?>
                                
                                <div class="btn-group btn-group-sm" role="group" aria-label="View Options">
                                    <input type="radio" class="btn-check" name="view-options" id="view-grid" autocomplete="off" checked>
                                    <label class="btn btn-outline-primary view-toggle" for="view-grid" data-view="grid">
                                        <i class="fas fa-th me-1"></i>
                                        <span class="d-none d-sm-inline"><?php _e('Grid', 'property-manager-pro'); ?></span>
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="view-options" id="view-list" autocomplete="off">
                                    <label class="btn btn-outline-primary view-toggle" for="view-list" data-view="list">
                                        <i class="fas fa-list me-1"></i>
                                        <span class="d-none d-sm-inline"><?php _e('List', 'property-manager-pro'); ?></span>
                                    </label>
                                    
                                    <?php if ($atts['show_map']): ?>
                                    <input type="radio" class="btn-check" name="view-options" id="view-map" autocomplete="off">
                                    <label class="btn btn-outline-primary view-toggle" for="view-map" data-view="map">
                                        <i class="fas fa-map me-1"></i>
                                        <span class="d-none d-sm-inline"><?php _e('Map', 'property-manager-pro'); ?></span>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Properties Container -->
            <div class="properties-container" data-view="<?php echo esc_attr($atts['view']); ?>">
                <?php if ($results['properties']): ?>
                    <div class="properties-grid row g-4">
                        <?php foreach ($results['properties'] as $property): ?>
                            <div class="property-item col-xxl-6 col-xl-4 col-lg-6 col-md-6">
                                <?php $this->render_property_card($property); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($results['total'] > $atts['per_page']): ?>
                        <nav aria-label="Properties pagination" class="mt-5">
                            <div class="d-flex justify-content-center">
                                <?php echo paginate_links(array(
                                    'total' => ceil($results['total'] / $atts['per_page']),
                                    'current' => max(1, get_query_var('paged')),
                                    'format' => '?paged=%#%',
                                    'prev_text' => '<i class="fas fa-chevron-left"></i> ' . __('Previous', 'property-manager-pro'),
                                    'next_text' => __('Next', 'property-manager-pro') . ' <i class="fas fa-chevron-right"></i>',
                                    'before_page_number' => '<span class="visually-hidden">' . __('Page', 'property-manager-pro') . ' </span>',
                                    'class' => 'pagination pagination-lg'
                                )); ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info text-center py-5" role="alert">
                        <i class="fas fa-search fa-3x mb-3 text-muted"></i>
                        <h4 class="alert-heading"><?php _e('No Properties Found', 'property-manager-pro'); ?></h4>
                        <p class="mb-0"><?php _e('Try adjusting your search criteria to find more properties.', 'property-manager-pro'); ?></p>
                        <hr>
                        <button class="btn btn-primary mt-2" onclick="history.back()">
                            <i class="fas fa-arrow-left me-1"></i>
                            <?php _e('Back to Search', 'property-manager-pro'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($atts['show_map']): ?>
                <div id="properties-map" class="card mt-4" style="display: none;">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-map-marked-alt me-2"></i>
                            <?php _e('Properties Map View', 'property-manager-pro'); ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div style="height: 500px;" id="map-container"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render user dashboard
     */
    private function render_user_dashboard($atts) {
        $user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        $favorites = PropertyManager_Favorites::get_instance();
        $alerts = PropertyManager_Alerts::get_instance();
        
        $stats = array(
            'favorites' => $favorites->get_favorites_count($user_id),
            'saved_searches' => $this->get_user_saved_searches_count($user_id),
            'active_alerts' => $alerts->get_alerts_count($user_id)
        );
        
        ?>
        <div class="container-fluid">
            <!-- Welcome Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm bg-primary text-white">
                        <div class="card-body py-4">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h2 class="mb-1">
                                        <i class="fas fa-tachometer-alt me-2"></i>
                                        <?php printf(__('Welcome, %s!', 'property-manager-pro'), $current_user->display_name); ?>
                                    </h2>
                                    <p class="mb-0 opacity-75"><?php _e('Manage your property searches, favorites, and alerts from your dashboard.', 'property-manager-pro'); ?></p>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <i class="fas fa-user-circle fa-4x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($atts['show_stats']): ?>
                <!-- Statistics Cards -->
                <div class="row g-4 mb-5">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                    <i class="fas fa-heart fa-lg"></i>
                                </div>
                                <h3 class="display-6 fw-bold text-danger mb-2"><?php echo $stats['favorites']; ?></h3>
                                <h6 class="text-muted mb-3"><?php _e('Favorite Properties', 'property-manager-pro'); ?></h6>
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['user_favorites']); ?>" 
                                   class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-eye me-1"></i>
                                    <?php _e('View All', 'property-manager-pro'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                    <i class="fas fa-search fa-lg"></i>
                                </div>
                                <h3 class="display-6 fw-bold text-success mb-2"><?php echo $stats['saved_searches']; ?></h3>
                                <h6 class="text-muted mb-3"><?php _e('Saved Searches', 'property-manager-pro'); ?></h6>
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['saved_searches']); ?>" 
                                   class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-cog me-1"></i>
                                    <?php _e('Manage', 'property-manager-pro'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon bg-warning bg-opacity-10 text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                    <i class="fas fa-bell fa-lg"></i>
                                </div>
                                <h3 class="display-6 fw-bold text-warning mb-2"><?php echo $stats['active_alerts']; ?></h3>
                                <h6 class="text-muted mb-3"><?php _e('Property Alerts', 'property-manager-pro'); ?></h6>
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['property_alerts']); ?>" 
                                   class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-cog me-1"></i>
                                    <?php _e('Manage', 'property-manager-pro'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Dashboard Menu -->
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                <?php _e('Quick Actions', 'property-manager-pro'); ?>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['user_favorites']); ?>" 
                                   class="list-group-item list-group-item-action d-flex align-items-center py-3">
                                    <div class="feature-icon bg-danger bg-opacity-10 text-danger rounded me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-heart"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php _e('My Favorite Properties', 'property-manager-pro'); ?></h6>
                                        <small class="text-muted"><?php _e('View and manage your saved properties', 'property-manager-pro'); ?></small>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['saved_searches']); ?>" 
                                   class="list-group-item list-group-item-action d-flex align-items-center py-3">
                                    <div class="feature-icon bg-success bg-opacity-10 text-success rounded me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-search"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php _e('Saved Searches', 'property-manager-pro'); ?></h6>
                                        <small class="text-muted"><?php _e('Access your saved search criteria', 'property-manager-pro'); ?></small>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['property_alerts']); ?>" 
                                   class="list-group-item list-group-item-action d-flex align-items-center py-3">
                                    <div class="feature-icon bg-warning bg-opacity-10 text-warning rounded me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php _e('Property Alerts', 'property-manager-pro'); ?></h6>
                                        <small class="text-muted"><?php _e('Manage your email alert subscriptions', 'property-manager-pro'); ?></small>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="<?php echo get_permalink(get_option('property_manager_pages')['last_viewed']); ?>" 
                                   class="list-group-item list-group-item-action d-flex align-items-center py-3">
                                    <div class="feature-icon bg-info bg-opacity-10 text-info rounded me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-eye"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php _e('Recently Viewed', 'property-manager-pro'); ?></h6>
                                        <small class="text-muted"><?php _e('Properties you have recently viewed', 'property-manager-pro'); ?></small>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <div class="list-group-item py-3 border-top">
                                    <div class="d-flex gap-2">
                                        <a href="<?php echo wp_logout_url(); ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-sign-out-alt me-1"></i>
                                            <?php _e('Logout', 'property-manager-pro'); ?>
                                        </a>
                                        <a href="<?php echo get_edit_user_link(); ?>" class="btn btn-outline-primary">
                                            <i class="fas fa-user-edit me-1"></i>
                                            <?php _e('Edit Profile', 'property-manager-pro'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render property card
     */
    private function render_property_card($property) {
        $options = get_option('property_manager_options');
        $currency = $options['currency_symbol'] ?? '€';        
        ?>
        <div class="property-card card h-100">
            <div class="property-image-container position-relative">
                <?php if (!empty($property->images)): 
                    $images = $property->images;
                    $first_image = !empty($images) ? (array)$images[0] : null;
                ?>
                    <img src="<?php echo esc_url($first_image['image_url']); ?>" 
                         alt="<?php echo esc_attr($property->title); ?>" 
                         class="card-img-top property-image">
                <?php else: ?>
                    <div class="no-image-placeholder card-img-top d-flex align-items-center justify-content-center bg-light">
                        <i class="fas fa-home fa-3x text-muted"></i>
                    </div>
                <?php endif; ?>
                
                <div class="property-badges position-absolute top-0 start-0 m-2">
                    <?php if ($property->featured): ?>
                        <span class="badge bg-warning"><?php _e('Featured', 'property-manager-pro'); ?></span>
                    <?php endif; ?>
                    <?php if ($property->new_build): ?>
                        <span class="badge bg-success"><?php _e('New Build', 'property-manager-pro'); ?></span>
                    <?php endif; ?>
                </div>
                
                <button class="btn btn-outline-light favorite-btn position-absolute top-0 end-0 m-2" 
                        data-property-id="<?php echo $property->id; ?>">
                    <i class="fas fa-heart"></i>
                </button>
            </div>
            
            <div class="card-body">
                <div class="property-price mb-2">
                    <h5 class="text-primary mb-0">
                        <?php echo $currency . number_format($property->price); ?>
                        <?php if ($property->price_freq == 'rent'): ?>
                            <small class="text-muted">/<?php _e('month', 'property-manager-pro'); ?></small>
                        <?php endif; ?>
                    </h5>
                </div>
                
                <h6 class="property-title">
                    <a href="<?php echo $this->get_property_url($property); ?>" class="text-decoration-none">
                        <?php echo esc_html($property->title); ?>
                    </a>
                </h6>
                
                <p class="property-location text-muted mb-2">
                    <i class="fas fa-map-marker-alt me-1"></i>
                    <?php echo esc_html($property->town . ', ' . $property->province); ?>
                </p>
                
                <div class="property-features mb-3">
                    <?php if ($property->beds): ?>
                        <span class="feature-item me-3">
                            <i class="fas fa-bed me-1"></i>
                            <?php echo $property->beds; ?> <?php _e('Beds', 'property-manager-pro'); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($property->baths): ?>
                        <span class="feature-item me-3">
                            <i class="fas fa-bath me-1"></i>
                            <?php echo $property->baths; ?> <?php _e('Baths', 'property-manager-pro'); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($property->surface_area_built): ?>
                        <span class="feature-item">
                            <i class="fas fa-expand-arrows-alt me-1"></i>
                            <?php echo $property->surface_area_built; ?>m²
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card-footer bg-transparent">
                <div class="d-grid">
                    <a href="<?php echo $this->get_property_url($property); ?>" 
                       class="btn btn-primary">
                        <?php _e('View Details', 'property-manager-pro'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render login required message
     */
    private function render_login_required_message() {
        ob_start();
        ?>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <div class="mb-4">
                                <i class="fas fa-lock fa-4x text-primary opacity-50"></i>
                            </div>
                            <h3 class="card-title text-primary mb-3">
                                <?php _e('Login Required', 'property-manager-pro'); ?>
                            </h3>
                            <p class="card-text text-muted mb-4">
                                <?php _e('Please log in to your account to access this feature. If you don\'t have an account, you can create one for free.', 'property-manager-pro'); ?>
                            </p>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <a href="<?php echo wp_login_url(get_permalink()); ?>" 
                                   class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>
                                    <?php _e('Login to Your Account', 'property-manager-pro'); ?>
                                </a>
                                <?php if (get_option('users_can_register')): ?>
                                    <a href="<?php echo wp_registration_url(); ?>" 
                                       class="btn btn-outline-secondary btn-lg">
                                        <i class="fas fa-user-plus me-2"></i>
                                        <?php _e('Create Account', 'property-manager-pro'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="row text-center">
                                <div class="col-md-4 mb-3">
                                    <i class="fas fa-heart text-danger mb-2 d-block"></i>
                                    <small class="text-muted"><?php _e('Save Favorites', 'property-manager-pro'); ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <i class="fas fa-search text-success mb-2 d-block"></i>
                                    <small class="text-muted"><?php _e('Save Searches', 'property-manager-pro'); ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <i class="fas fa-bell text-warning mb-2 d-block"></i>
                                    <small class="text-muted"><?php _e('Get Alerts', 'property-manager-pro'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get property URL
     */
    private function get_property_url($property) {
        // You can customize this based on your URL structure
        return add_query_arg('property_id', $property->id, get_permalink());
    }
    
    /**
     * Get user saved searches count
     */
    private function get_user_saved_searches_count($user_id) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    private function render_saved_searches($atts) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $saved_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));
        
        ?>
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h3 mb-0">
                    <i class="fas fa-search me-2 text-primary"></i>
                    <?php _e('My Saved Searches', 'property-manager-pro'); ?>
                </h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#saveSearchModal">
                    <i class="fas fa-plus me-1"></i>
                    <?php _e('Save Current Search', 'property-manager-pro'); ?>
                </button>
            </div>

            <?php if ($saved_searches): ?>
                <div class="row g-4">
                    <?php foreach ($saved_searches as $search): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold"><?php echo esc_html($search->search_name); ?></h6>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <button class="dropdown-item load-search-btn" 
                                                        data-search-id="<?php echo $search->id; ?>">
                                                    <i class="fas fa-search me-2"></i>
                                                    <?php _e('Run Search', 'property-manager-pro'); ?>
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item edit-search-btn" 
                                                        data-search-id="<?php echo $search->id; ?>">
                                                    <i class="fas fa-edit me-2"></i>
                                                    <?php _e('Edit', 'property-manager-pro'); ?>
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button class="dropdown-item text-danger delete-search-btn" 
                                                        data-search-id="<?php echo $search->id; ?>">
                                                    <i class="fas fa-trash me-2"></i>
                                                    <?php _e('Delete', 'property-manager-pro'); ?>
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <?php
                                    $criteria = json_decode($search->search_criteria, true);
                                    ?>
                                    <div class="search-criteria small text-muted mb-3">
                                        <?php if (!empty($criteria['location'])): ?>
                                            <div class="mb-1">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <strong><?php _e('Location:', 'property-manager-pro'); ?></strong> 
                                                <?php echo esc_html($criteria['location']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($criteria['type'])): ?>
                                            <div class="mb-1">
                                                <i class="fas fa-home me-1"></i>
                                                <strong><?php _e('Type:', 'property-manager-pro'); ?></strong> 
                                                <?php echo esc_html($criteria['type']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($criteria['min_price']) || !empty($criteria['max_price'])): ?>
                                            <div class="mb-1">
                                                <i class="fas fa-euro-sign me-1"></i>
                                                <strong><?php _e('Price:', 'property-manager-pro'); ?></strong>
                                                <?php 
                                                $price_range = array();
                                                if (!empty($criteria['min_price'])) {
                                                    $price_range[] = '€' . number_format($criteria['min_price']) . '+';
                                                }
                                                if (!empty($criteria['max_price'])) {
                                                    $price_range[] = '€' . number_format($criteria['max_price']) . ' max';
                                                }
                                                echo implode(' - ', $price_range);
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($criteria['beds'])): ?>
                                            <div class="mb-1">
                                                <i class="fas fa-bed me-1"></i>
                                                <strong><?php _e('Bedrooms:', 'property-manager-pro'); ?></strong> 
                                                <?php echo $criteria['beds']; ?>+
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($atts['show_alerts'] && $search->email_alerts): ?>
                                        <div class="alert alert-success alert-sm py-2 mb-2">
                                            <i class="fas fa-bell me-1"></i>
                                            <small>
                                                <?php printf(__('Email alerts: %s', 'property-manager-pro'), 
                                                      ucfirst($search->alert_frequency)); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-muted small">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?php printf(__('Created %s ago', 'property-manager-pro'), 
                                              human_time_diff(strtotime($search->created_at))); ?>
                                    </div>
                                </div>
                                
                                <div class="card-footer bg-transparent border-0">
                                    <div class="d-grid">
                                        <button class="btn btn-primary btn-sm load-search-btn" 
                                                data-search-id="<?php echo $search->id; ?>">
                                            <i class="fas fa-search me-1"></i>
                                            <?php _e('Run This Search', 'property-manager-pro'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-5">
                            <i class="fas fa-search fa-4x text-muted mb-3"></i>
                            <h4 class="text-muted"><?php _e('No Saved Searches', 'property-manager-pro'); ?></h4>
                            <p class="text-muted mb-4">
                                <?php _e('You haven\'t saved any searches yet. Search for properties and save your criteria for quick access later.', 'property-manager-pro'); ?>
                            </p>
                            <a href="<?php echo get_permalink(get_option('property_manager_pages')['property_search']); ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>
                                <?php _e('Search Properties', 'property-manager-pro'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}