<?php
/**
 * Shortcodes Handler Class - Property Manager Pro
 * 
 * Handles all shortcodes for the plugin with WordPress standards and security measures
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Shortcodes {
    
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
     * Constructor - Register all shortcodes
     */
    private function __construct() {
        // Basic shortcodes
        add_shortcode('property_search_form', array($this, 'render_basic_search_form'));
        add_shortcode('property_advanced_search_form', array($this, 'render_advanced_search_form'));
        add_shortcode('property_search_results', array($this, 'render_search_results'));
        
        // Property display shortcodes
        add_shortcode('property_grid', array($this, 'render_property_grid'));
        add_shortcode('property_list', array($this, 'render_property_list'));
        add_shortcode('property_featured', array($this, 'render_featured_properties'));
        add_shortcode('property_single', array($this, 'render_single_property'));
        
        // User-related shortcodes
        add_shortcode('property_user_dashboard', array($this, 'render_user_dashboard'));
        add_shortcode('property_user_favorites', array($this, 'render_user_favorites'));
        add_shortcode('property_saved_searches', array($this, 'render_saved_searches'));
        add_shortcode('property_alerts_management', array($this, 'render_alerts_management'));
        add_shortcode('property_last_viewed', array($this, 'render_last_viewed'));
        
        // Authentication shortcodes
        add_shortcode('property_login_form', array($this, 'render_login_form'));
        add_shortcode('property_register_form', array($this, 'render_register_form'));
        add_shortcode('property_reset_password', array($this, 'render_reset_password_form'));
        
        // Misc shortcodes
        add_shortcode('property_contact_form', array($this, 'render_contact_form'));
        add_shortcode('property_alert_signup', array($this, 'render_alert_signup_form'));
    }
    
    /**
     * Render Basic Search Form
     * Usage: [property_search_form]
     */
    public function render_basic_search_form($atts) {
        $atts = shortcode_atts(array(
            'show_title' => true,
            'show_advanced_button' => true,
            'title' => __('Search Properties', 'property-manager-pro'),
            'button_text' => __('Search', 'property-manager-pro'),
            'placeholder' => __('Location, Property Type...', 'property-manager-pro'),
            'action' => get_permalink(get_option('property_manager_pages')['property_search'] ?? ''),
            'class' => ''
        ), $atts, 'property_search_form');
        
        ob_start();
        
        $search_forms = PropertyManager_SearchForms::get_instance();
        echo $search_forms->basic_search_form($atts);
        return ob_get_clean();
    }
    
    /**
     * Render Advanced Search Form
     * Usage: [property_advanced_search_form]
     */
    public function render_advanced_search_form($atts) {
        $atts = shortcode_atts(array(
            'show_title' => false,
            'title' => __('Advanced Property Search', 'property-manager-pro'),
            'button_text' => __('Search', 'property-manager-pro'),
            'action' => '',
            'class' => ''
        ), $atts, 'property_advanced_search_form');
        
        ob_start();
        
        $search_forms = PropertyManager_SearchForms::get_instance();
        echo $search_forms->advanced_search_form($atts);
        
        return ob_get_clean();
    }
    
    /**
     * Render Search Results
     * Usage: [property_search_results]
     */
    public function render_search_results($atts) {
        $atts = shortcode_atts(array(
            'view' => 'grid',
            'per_page' => 12,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'show_filters' => 'yes',
            'show_pagination' => 'yes',
            'show_view_switcher' => 'yes',
            'class' => ''
        ), $atts, 'property_search_results');
        
        $options = get_option('property_manager_options', array());
        $default_view = isset($options['default_view']) ? $options['default_view'] : "grid";
        $enable_map = isset($options['enable_map']) ? $options['enable_map'] : true;

        // Sanitize attributes
        $view = sanitize_text_field($atts['view']);
        $per_page = absint($atts['per_page']);
        $orderby = sanitize_text_field($atts['orderby']);
        $order = strtoupper(sanitize_text_field($atts['order']));
        
        // Validate values
        if (!in_array($view, array('grid', 'list', 'map'))) {
            $view = 'grid';
        }
        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'DESC';
        }
        if ($per_page < 1 || $per_page > 100) {
            $per_page = 12;
        }
        
        // Override view if in URL
        if (isset($_GET['view']) && in_array($_GET['view'], array('grid', 'list', 'map'))) {
            $view = sanitize_text_field($_GET['view']);
        }

        if(!$enable_map)
            $view = $default_view;

        if ($view === 'map')
            $per_page = 100;
            
        // Get search parameters
        $search_params = $this->get_sanitized_search_params();
        $search_params['per_page'] = $per_page;
        $search_params['orderby'] = $orderby;
        $search_params['order'] = $order;
        $search_params['page'] = intval(get_query_var('paged')) > 0 ? get_query_var('paged') : (isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1);
        
        ob_start();
        
        $property_search = PropertyManager_Search::get_instance();
        $results = $property_search->search_properties($search_params);
        $map_properties = [];

        if ($view === 'map') {
            $map_properties = $this->prepare_map_data($results['properties']);
            $results['total'] = count($map_properties);
        }
        ?>
        <div class="property-search-results-wrapper mt-4 <?php echo esc_attr($atts['class']); ?>">
            
            <?php if ($atts['show_view_switcher'] === 'yes' || $atts['show_filters'] === 'yes'): ?>
                <div class="search-results-header mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="results-count">
                            <h5 class="mb-0">
                                <?php 
                                printf(
                                    esc_html(_n('%s Property Found', '%s Properties Found', $results['total'], 'property-manager-pro')),
                                    '<strong>' . number_format_i18n($results['total']) . '</strong>'
                                );
                                ?>
                            </h5>
                        </div>
                        
                        <?php if ($atts['show_view_switcher'] === 'yes'): ?>
                            <div class="view-switcher btn-group" role="group">
                                <a href="<?php echo esc_url(add_query_arg('view', 'grid')); ?>" 
                                   class="btn btn-outline-secondary <?php echo $view === 'grid' ? 'active' : ''; ?>">
                                    <i class="fas fa-th"></i>
                                </a>
                                <a href="<?php echo esc_url(add_query_arg('view', 'list')); ?>" 
                                   class="btn btn-outline-secondary <?php echo $view === 'list' ? 'active' : ''; ?>">
                                    <i class="fas fa-list"></i>
                                </a>
                                <?php if($enable_map):?>
                                    <a href="<?php echo esc_url(add_query_arg('view', 'map')); ?>" 
                                       class="btn btn-outline-secondary <?php echo $view === 'map' ? 'active' : ''; ?>">
                                        <i class="fas fa-map"></i>
                                    </a>
                                <?php endif;?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="sort-options">
                            <form method="get" class="d-inline">
                                <?php foreach ($_GET as $key => $value): ?>
                                    <?php if ($key !== 'orderby' && $key !== 'order'): ?>
                                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <select name="sort" class="form-select" onchange="this.form.submit()">
                                    <option value="created_at-DESC" <?php selected($orderby . '-' . $order, 'created_at-DESC'); ?>>
                                        <?php esc_html_e('Newest First', 'property-manager-pro'); ?>
                                    </option>
                                    <option value="price-ASC" <?php selected($orderby . '-' . $order, 'price-ASC'); ?>>
                                        <?php esc_html_e('Price: Low to High', 'property-manager-pro'); ?>
                                    </option>
                                    <option value="price-DESC" <?php selected($orderby . '-' . $order, 'price-DESC'); ?>>
                                        <?php esc_html_e('Price: High to Low', 'property-manager-pro'); ?>
                                    </option>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (empty($results['properties'])): ?>
                <div class="alert alert-info">
                    <h4><?php esc_html_e('No Properties Found', 'property-manager-pro'); ?></h4>
                    <p><?php esc_html_e('Try adjusting your search criteria.', 'property-manager-pro'); ?></p>
                </div>
            <?php else: ?>
                
                <?php if ($view === 'grid'): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xxl-4 g-4 property-grid">
                        <?php foreach ($results['properties'] as $property): ?>
                            <div class="col">
                                <?php $this->render_property_card($property); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php elseif ($view === 'list'): ?>
                    <div class="row row-cols-1 property-list">
                        <?php foreach ($results['properties'] as $property): ?>
                            <div class="col"><?php $this->render_property_list_item($property); ?></div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php elseif ($view === 'map'): ?>
                    <div class="property-map-view">
                        <div id="properties-map" style="height: 600px;" data-properties='<?php echo esc_attr(wp_json_encode($map_properties)); ?>'></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($atts['show_pagination'] === 'yes' && $results['total'] > $per_page && $view !== 'map'): ?>
                    <div class="search-pagination mt-4">
                        <?php
                        $pages = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $search_params['page'],
                            'total' => ceil($results['total'] / $per_page),
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'type' => 'array'
                        ));
                        if ( is_array( $pages ) ) : ?>
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center">
                                    <?php foreach ( $pages as $page ) : 
                                        if ( strpos( $page, 'current' ) !== false ) {
                                            echo '<li class="page-item active"><span class="page-link">' . strip_tags( $page ) . '</span></li>';
                                        } else {
                                            echo '<li class="page-item">' . str_replace( 'page-numbers', 'page-link', $page ) . '</li>';
                                        }
                                    endforeach; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render Property Grid
     */
    public function render_property_grid($atts) {
        $atts = shortcode_atts(array(
            'posts_per_page' => 12,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'property_type' => '',
            'town' => '',
            'featured' => 'no',
            'class' => ''
        ), $atts, 'property_grid');
        
        $args = array(
            'per_page' => absint($atts['posts_per_page']),
            'orderby' => sanitize_text_field($atts['orderby']),
            'order' => strtoupper(sanitize_text_field($atts['order'])),
            'page' => 1
        );
        
        if (!empty($atts['property_type'])) {
            $args['property_type'] = sanitize_text_field($atts['property_type']);
        }
        
        if (!empty($atts['town'])) {
            $args['town'] = sanitize_text_field($atts['town']);
        }
        
        if ($atts['featured'] === 'yes') {
            $args['featured'] = 1;
        }
        
        ob_start();
        
        $property_search = PropertyManager_Search::get_instance();
        $results = $property_search->search_properties($args);
        
        if (!empty($results['properties'])):
        ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xxl-4 g-4 property-grid <?php echo esc_attr($atts['class']); ?>">
                <?php foreach ($results['properties'] as $property):?>
                    <div class="col">
                        <?php $this->render_property_card($property); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php
        else:
            echo '<p class="alert alert-info">' . esc_html__('No properties found.', 'property-manager-pro') . '</p>';
        endif;
        
        return ob_get_clean();
    }
    
    /**
     * Render Featured Properties
     */
    public function render_featured_properties($atts) {
        $atts = shortcode_atts(array(
            'limit' => 4,
            'class' => ''
        ), $atts, 'property_featured');
        
        return $this->render_property_grid(array(
            'posts_per_page' => absint($atts['limit']),
            'featured' => 'yes',
            'class' => $atts['class']
        ));
    }
    
    /**
     * Render Single Property
     */
    public function render_single_property($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'class' => ''
        ), $atts, 'property_single');
        
        $property_id = absint($atts['id']);
        
        if (!$property_id) {
            return '<p class="alert alert-warning">' . esc_html__('Property ID required.', 'property-manager-pro') . '</p>';
        }
        
        ob_start();
        
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property) {
            return '<p class="alert alert-danger">' . esc_html__('Property not found.', 'property-manager-pro') . '</p>';
        }
        
        $template_path = PROPERTY_MANAGER_PLUGIN_PATH . 'public/content-single-property.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="alert alert-warning">' . esc_html__('Template not found.', 'property-manager-pro') . '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Render User Dashboard
     */
    public function render_user_dashboard($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        $atts = shortcode_atts(array(
            'class' => ''
        ), $atts, 'property_user_dashboard');
        
        ob_start();
        
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        
        $favorites_manager = PropertyManager_Favorites::get_instance();
        $alerts_manager = PropertyManager_Alerts::get_instance();
        $property_manager = PropertyManager_Property::get_instance();
        
        $favorites_count = $favorites_manager->get_user_favorites_count($user_id);
        $alerts = $alerts_manager->get_user_alerts($user_id);
        $last_viewed = $property_manager->get_last_viewed_properties(5);
        
        ?>
        <div class="property-user-dashboard <?php echo esc_attr($atts['class']); ?>">
            <div class="dashboard-header mb-4">
                <h2><?php printf(esc_html__('Welcome back, %s', 'property-manager-pro'), esc_html($user->display_name)); ?></h2>
            </div>
            
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-heart fa-3x text-danger mb-3"></i>
                            <h3><?php echo number_format_i18n($favorites_count); ?></h3>
                            <p class="text-muted"><?php esc_html_e('Favorites', 'property-manager-pro'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-bell fa-3x text-warning mb-3"></i>
                            <h3><?php echo number_format_i18n(count($alerts)); ?></h3>
                            <p class="text-muted"><?php esc_html_e('Active Alerts', 'property-manager-pro'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-eye fa-3x text-info mb-3"></i>
                            <h3><?php echo number_format_i18n($last_viewed['total']); ?></h3>
                            <p class="text-muted"><?php esc_html_e('Viewed', 'property-manager-pro'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render User Favorites
     */
    public function render_user_favorites($atts) {
        $options = get_option('property_manager_options', array());
        $enable_user_registration = isset($options['enable_user_registration']) ? boolval($options['enable_user_registration']) : false;
        
        if (!is_user_logged_in() && $enable_user_registration) {
            return $this->render_login_required_message();
        }
        
        $atts = shortcode_atts(array(
            'per_page' => 12,
            'class' => ''
        ), $atts, 'property_user_favorites');
        
        ob_start();
        
        $favorites_manager = PropertyManager_Favorites::get_instance();
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        
        $favorites = $favorites_manager->get_user_favorites(null, array(
            'page' => $page,
            'per_page' => absint($atts['per_page'])
        ));        
        ?>
        <div class="property-user-favorites <?php echo esc_attr($atts['class']); ?>">
            <?php if (empty($favorites['properties'])): ?>
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-heart fa-4x text-muted mb-3"></i>
                    <h4><?php esc_html_e('No Favorites Yet', 'property-manager-pro'); ?></h4>
                    <p><?php esc_html_e('Start adding properties to see them here.', 'property-manager-pro'); ?></p>
                </div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xxl-4 g-4 property-grid">
                    <?php foreach ($favorites['properties'] as $property): ?>
                        <div class="col">
                            <?php $this->render_property_card($property, 'default', true); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($favorites['total'] > $atts['per_page']): ?>
                    <div class="mt-4">
                        <?php
                        $pages = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'current' => $page,
                            'type'  => 'array',
                            'total' => ceil($favorites['total'] / $atts['per_page'])
                        ));
                        if ( is_array( $pages ) ) : ?>
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center">
                                    <?php foreach ( $pages as $page ) : 
                                        if ( strpos( $page, 'current' ) !== false ) {
                                            echo '<li class="page-item active"><span class="page-link">' . strip_tags( $page ) . '</span></li>';
                                        } else {
                                            echo '<li class="page-item">' . str_replace( 'page-numbers', 'page-link', $page ) . '</li>';
                                        }
                                    endforeach; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render Saved Searches
     */
    public function render_saved_searches($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        ob_start();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        $user_id = get_current_user_id();
        
        $saved_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));
        
        ?>
        <div class="property-saved-searches">
            <h2 class="mb-4"><?php esc_html_e('Saved Searches', 'property-manager-pro'); ?></h2>
            
            <?php if (empty($saved_searches)): ?>
                <div class="alert alert-info">
                    <p><?php esc_html_e('No saved searches yet.', 'property-manager-pro'); ?></p>
                </div>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($saved_searches as $search): ?>
                        <?php $criteria = maybe_unserialize($search->search_criteria); ?>
                        <div class="list-group-item">
                            <h5><?php echo esc_html($search->search_name); ?></h5>
                            <small class="text-muted">
                                <?php echo esc_html(human_time_diff(strtotime($search->created_at))) . ' ' . esc_html__('ago', 'property-manager-pro'); ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render Alerts Management
     */
    public function render_alerts_management($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_required_message();
        }
        
        ob_start();
        
        $alerts_manager = PropertyManager_Alerts::get_instance();
        $alerts = $alerts_manager->get_user_alerts(get_current_user_id());
        
        ?>
        <div class="property-alerts-management">
            <h2 class="mb-4"><?php esc_html_e('Property Alerts', 'property-manager-pro'); ?></h2>
            
            <?php if (empty($alerts)): ?>
                <div class="alert alert-info">
                    <p><?php esc_html_e('No alerts created yet.', 'property-manager-pro'); ?></p>
                </div>
            <?php else: ?>
                <div class="alerts-list">
                    <?php foreach ($alerts as $alert): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5><?php echo esc_html($alert->alert_name); ?></h5>
                                <p class="mb-0">
                                    <span class="badge <?php echo $alert->status === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo esc_html(ucfirst($alert->status)); ?>
                                    </span>
                                    <small class="text-muted ms-2">
                                        <?php printf(esc_html__('Frequency: %s', 'property-manager-pro'), esc_html(ucfirst($alert->frequency))); ?>
                                    </small>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render Last Viewed
     */
    public function render_last_viewed($atts) {
        $atts = shortcode_atts(array(
            'limit' => 10,
            'class' => ''
        ), $atts, 'property_last_viewed');
        
        ob_start();
        
        $property_manager = PropertyManager_Property::get_instance();
        $last_viewed = $property_manager->get_last_viewed_properties(absint($atts['limit']));
        
        ?>
        <div class="property-last-viewed <?php echo esc_attr($atts['class']); ?>">
            <h2 class="mb-4"><?php esc_html_e('Recently Viewed', 'property-manager-pro'); ?></h2>
            
            <?php if (empty($last_viewed['properties'])): ?>
                <div class="alert alert-info">
                    <p><?php esc_html_e('No recently viewed properties.', 'property-manager-pro'); ?></p>
                </div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xxl-4 g-4 property-grid">
                    <?php foreach ($last_viewed['properties'] as $property): ?>
                        <div class="col">
                            <?php $this->render_property_card($property); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render Login Form
     */
    public function render_login_form($atts) {
        if (is_user_logged_in()) {
            return '<p class="alert alert-info">' . esc_html__('You are already logged in.', 'property-manager-pro') . '</p>';
        }
        
        $atts = shortcode_atts(array(
            'redirect' => '',
            'class' => ''
        ), $atts, 'property_login_form');
        
        $redirect = !empty($atts['redirect']) ? esc_url($atts['redirect']) : home_url();
        
        ob_start();
        
        wp_login_form(array(
            'redirect' => $redirect,
            'label_username' => __('Username', 'property-manager-pro'),
            'label_password' => __('Password', 'property-manager-pro'),
            'label_log_in' => __('Login', 'property-manager-pro')
        ));
        
        return ob_get_clean();
    }
    
    /**
     * Render Register Form
     */
    public function render_register_form($atts) {
        if (is_user_logged_in()) {
            return '<p class="alert alert-info">' . esc_html__('You are already registered.', 'property-manager-pro') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="property-register-form">
            <h3><?php esc_html_e('Create Account', 'property-manager-pro'); ?></h3>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label"><?php esc_html_e('Username', 'property-manager-pro'); ?></label>
                    <input type="text" name="user_login" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php esc_html_e('Email', 'property-manager-pro'); ?></label>
                    <input type="email" name="user_email" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-warning"><?php esc_html_e('Register', 'property-manager-pro'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Reset Password Form
     */
    public function render_reset_password_form($atts) {
        ob_start();
        ?>
        <div class="property-reset-password">
            <h3><?php esc_html_e('Reset Password', 'property-manager-pro'); ?></h3>
            <form method="post" action="<?php echo esc_url(wp_lostpassword_url()); ?>">
                <div class="mb-3">
                    <label class="form-label"><?php esc_html_e('Email Address', 'property-manager-pro'); ?></label>
                    <input type="email" name="user_login" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-warning"><?php esc_html_e('Reset Password', 'property-manager-pro'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Contact Form
     */
    public function render_contact_form($atts) {
        $atts = shortcode_atts(array(
            'property_id' => 0,
            'class' => ''
        ), $atts, 'property_contact_form');
        
        $property_id = absint($atts['property_id']);
        
        ob_start();
        ?>
        <div id="property-contact-form" class="<?php echo esc_attr($atts['class']); ?>">
            <h3 class="h4 mb-3"><?php esc_html_e('Contact About This Property', 'property-manager-pro'); ?></h3>
            
            <form id="property-inquiry-form" method="post">
                <div class="mb-3">
                    <label for="inquiry_name" class="form-label"><?php esc_html_e('Name', 'property-manager-pro'); ?></label>
                    <input type="text" name="name" id="inquiry_name" class="form-control" required 
                           value="<?php echo is_user_logged_in() ? esc_attr(wp_get_current_user()->display_name) : (isset($_POST['name']) ? $_POST['name'] : ''); ?>">
                </div>
                
                <div class="mb-3">
                    <label for="inquiry_email" class="form-label"><?php esc_html_e('Email', 'property-manager-pro'); ?></label>
                    <input type="email" name="email" id="inquiry_email" class="form-control" required
                           value="<?php echo is_user_logged_in() ? esc_attr(wp_get_current_user()->user_email) : (isset($_POST['email']) ? $_POST['email'] : ''); ?>">
                </div>
                
                <div class="mb-3">
                    <label for="inquiry_phone" class="form-label"><?php esc_html_e('Phone', 'property-manager-pro'); ?></label>
                    <input type="tel" name="phone" id="inquiry_phone" class="form-control" value="<?php echo isset($_POST['phone']) ? $_POST['phone'] : ''?>">
                </div>
                
                <div class="mb-3">
                    <label for="inquiry_message" class="form-label"><?php esc_html_e('Message', 'property-manager-pro'); ?></label>
                    <textarea name="message" id="inquiry_message" class="form-control" rows="5" required><?php echo isset($_POST['message']) ? $_POST['message'] : ''?></textarea>
                </div>
                
                <?php wp_nonce_field('property_inquiry', 'inquiry_nonce'); ?>
                <input type="hidden" name="property_id" id="property_id" value="<?php echo esc_attr($property_id); ?>">
                <input type="hidden" name="property_inquiry_submit" value="1">
                
                <button type="submit" class="btn btn-warning">
                    <?php esc_html_e('Send Inquiry', 'property-manager-pro'); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Alert Signup Form
     */
    public function render_alert_signup_form($atts) {
        $atts = shortcode_atts(array(
            'class' => '',
            'show_search_form' => 'yes'
        ), $atts, 'property_alert_signup');
        
        ob_start();
        
        $current_search = $this->get_sanitized_search_params();
        
        ?>
        <div class="property-alert-signup-form <?php echo esc_attr($atts['class']); ?>">
            <form id="property-alert-form" method="post">
                <div class="mb-3">
                    <label for="alert_name" class="form-label"><?php esc_html_e('Alert Name', 'property-manager-pro'); ?></label>
                    <input type="text" name="alert_name" id="alert_name" class="form-control" required>
                </div>
                
                <?php if (!is_user_logged_in()): ?>
                    <div class="mb-3">
                        <label for="alert_email" class="form-label"><?php esc_html_e('Email', 'property-manager-pro'); ?></label>
                        <input type="email" name="alert_email" id="alert_email" class="form-control" required>
                    </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="alert_frequency" class="form-label"><?php esc_html_e('Frequency', 'property-manager-pro'); ?></label>
                    <select name="frequency" id="alert_frequency" class="form-select" required>
                        <option value="daily"><?php esc_html_e('Daily', 'property-manager-pro'); ?></option>
                        <option value="weekly" selected><?php esc_html_e('Weekly', 'property-manager-pro'); ?></option>
                        <option value="monthly"><?php esc_html_e('Monthly', 'property-manager-pro'); ?></option>
                    </select>
                </div>
                
                <?php if ($atts['show_search_form'] === 'yes'): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="alert_location" class="form-label"><?php esc_html_e('Location', 'property-manager-pro'); ?></label>
                            <input type="text" name="location" id="alert_location" class="form-control" 
                                   value="<?php echo esc_attr($current_search['location'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="alert_property_type" class="form-label"><?php esc_html_e('Type', 'property-manager-pro'); ?></label>
                            <select name="property_type" id="alert_property_type" class="form-select">
                                <option value=""><?php esc_html_e('Any', 'property-manager-pro'); ?></option>
                                <option value="Apartment"><?php esc_html_e('Apartment', 'property-manager-pro'); ?></option>
                                <option value="Villa"><?php esc_html_e('Villa', 'property-manager-pro'); ?></option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="alert_price_min" class="form-label"><?php esc_html_e('Min Price', 'property-manager-pro'); ?></label>
                            <input type="number" name="price_min" id="alert_price_min" class="form-control">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="alert_price_max" class="form-label"><?php esc_html_e('Max Price', 'property-manager-pro'); ?></label>
                            <input type="number" name="price_max" id="alert_price_max" class="form-control">
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php wp_nonce_field('property_alert_signup', 'alert_nonce'); ?>
                <input type="hidden" name="property_alert_submit" value="1">
                
                <button type="submit" class="btn btn-warning mt-3">
                    <?php esc_html_e('Create Alert', 'property-manager-pro'); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Helper: Render property card
     */
    private function render_property_card($property, $style = 'default', $show_remove = false) {		
        $safe_property = $this->get_safe_property($property);        
        ?>
        <div class="card property-card h-100 rounded-3 overflow-hidden">
            <?php if ($safe_property->featured_image): ?>
                <div class="position-relative bg-black">
                    <a href="<?php echo $safe_property->url; ?>">
                        <img src="<?php echo $safe_property->featured_image; ?>" 
                             class="card-img-top rounded-0" 
                             alt="<?php echo $safe_property->title; ?>">
                    </a>
                    
                    <?php if ($safe_property->new_build || $safe_property->pool): ?>
                        <div class="position-absolute top-0 start-0 p-2 d-none">
                            <?php if ($safe_property->new_build): ?>
                                <span class="badge bg-success"><?php esc_html_e('New', 'property-manager-pro'); ?></span>
                            <?php endif; ?>
                            <?php if ($safe_property->pool): ?>
                                <span class="badge bg-info"><?php esc_html_e('Pool', 'property-manager-pro'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="position-absolute top-0 end-0 p-2">
                        <button type="button" 
                                class="btn btn-sm btn-light favorite-btn <?php echo $safe_property->is_favorite ? 'favorited' : ''; ?>" 
                                data-property-id="<?php echo esc_attr($safe_property->id); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_nonce')); ?>">
                            <i class="fas fa-heart"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="card-body position-relative">
                <h5><?php echo $safe_property->title; ?></h5>
                <div class="property-features">
                    <?php if ($safe_property->beds): ?>
                        <div><i class="fas fa-fw fa-bed"></i> <?php echo $safe_property->beds; ?> Bedrooms</div>
                    <?php endif; ?>
                    <?php if ($safe_property->baths): ?>
                        <div><i class="fas fa-fw fa-bath"></i> <?php echo $safe_property->baths; ?> Bathrooms</div>
                    <?php endif; ?>
                    <div><i class="fas fa-fw fa-map-marker-alt"></i><?php echo $safe_property->town . ', ' . $safe_property->province; ?></div>
                </div>
                <h4><?php echo $safe_property->currency . ' ' . number_format($safe_property->price); ?></h4>
                <a href="<?php echo $safe_property->url; ?>" class="stretched-link"></a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Helper: Render property list item
     */
    private function render_property_list_item($property) {
        $safe_property = $this->get_safe_property($property);        
        ?>
        <div class="card rounded-3 property-card overflow-hidden mb-3">
            <div class="row g-0">
                <?php if ($safe_property->featured_image): ?>
                    <div class="col-md-4 position-relative">
                        <a href="<?php echo $safe_property->url; ?>">
                            <img src="<?php echo $safe_property->featured_image; ?>" class="img-fluid" alt="<?php echo $safe_property->title; ?>">
                        </a>
                        <?php if ($safe_property->new_build || $safe_property->pool): ?>
                            <div class="position-absolute top-0 start-0 p-2 d-none">
                                <?php if ($safe_property->new_build): ?>
                                    <span class="badge bg-success"><?php esc_html_e('New', 'property-manager-pro'); ?></span>
                                <?php endif; ?>
                                <?php if ($safe_property->pool): ?>
                                    <span class="badge bg-info"><?php esc_html_e('Pool', 'property-manager-pro'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    
                        <div class="position-absolute top-0 end-0 p-2">
                            <button type="button" 
                                    class="btn btn-sm btn-light favorite-btn <?php echo $safe_property->is_favorite ? 'favorited' : ''; ?>" 
                                    data-property-id="<?php echo esc_attr($safe_property->id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_nonce')); ?>">
                                <i class="fas fa-heart"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="col-md-8">
                    <div class="card-body position-relative">
                        <h5><?php echo $safe_property->title; ?></h5>
                        <div class="property-features">
                            <?php if ($safe_property->beds): ?>
                                <div><i class="fas fa-fw fa-bed"></i> <?php echo $safe_property->beds; ?> Bedrooms</div>
                            <?php endif; ?>
                            <?php if ($safe_property->baths): ?>
                                <div><i class="fas fa-fw fa-bath"></i> <?php echo $safe_property->baths; ?> Bathrooms</div>
                            <?php endif; ?>
                            <div><i class="fas fa-fw fa-map-marker-alt"></i><?php echo $safe_property->town . ', ' . $safe_property->province; ?></div>
                        </div>
                        <h4><?php echo $safe_property->currency . ' ' . number_format($safe_property->price); ?></h4>
                        <a href="<?php echo $safe_property->url; ?>" class="stretched-link"></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Helper: Prepare map data
     */
    private function prepare_map_data($properties) {
        $map_data = array();
    
        foreach ($properties as $property) {
            // Only include properties with valid coordinates
            if (empty($property->latitude) || empty($property->longitude)) {
                continue;
            }
        
            // Convert latitude/longitude from DMS format if needed
            $latitude = floatval($property->latitude);
            $longitude = floatval($property->longitude);
        
            // Skip if coordinates are invalid
            if ($latitude === false || $longitude === false) {
                continue;
            }
        
            // Get the featured image
            $featured_image = '';
            if (is_array($property->images) && count($property->images) > 0) {
                if ($property->images[0]->attachment_id != null) {
                    $featured_image = wp_get_attachment_image_url($property->images[0]->attachment_id, 'medium');
                } else if (!empty($property->images[0]->image_url)) {
                    $featured_image = $property->images[0]->image_url;
                }
            }

            $options = get_option('property_manager_options', array());
            $currency_symbol = isset($options['currency_symbol']) ? $options['currency_symbol'] : "";
        
            $map_data[] = array(
                'id' => absint($property->id),
                'title' => esc_html($property->title),
                'price' => esc_html(($property->currency == "EUR" ? "&euro;" : $currency_symbol) . number_format($property->price)),
                'beds' => absint($property->beds ?? 0),
                'baths' => absint($property->baths ?? 0),
                'town' => esc_html($property->town ?? ''),
                'province' => esc_html($property->province ?? ''),
                'latitude' => floatval($latitude),
                'longitude' => floatval($longitude),
                'image' => esc_url($featured_image),
                'url' => esc_url(home_url('/property/' . absint($property->id)))
            );
        }    
        return $map_data;
    }

    /**
     * Helper: Get sanitized search parameters
     */
    private function get_sanitized_search_params() {
        $params = array();
        
        $text_fields = array('location', 'town', 'province', 'keyword', 'property_type');
        foreach ($text_fields as $field) {
            if (isset($_GET[$field]) && !empty($_GET[$field])) {
                $params[$field] = sanitize_text_field($_GET[$field]);
            }
        }
        
        $numeric_fields = array('price_min', 'price_max', 'beds_min', 'beds_max', 'baths_min', 'baths_max');
        foreach ($numeric_fields as $field) {
            if (isset($_GET[$field]) && $_GET[$field] !== '') {
                $params[$field] = absint($_GET[$field]);
            }
        }
        
        $boolean_fields = array('pool', 'new_build', 'featured');
        foreach ($boolean_fields as $field) {
            if (isset($_GET[$field]) && $_GET[$field] == '1') {
                $params[$field] = 1;
            }
        }
        
        return $params;
    }
    
    /**
     * Helper: Render login required message
     */
    private function render_login_required_message() {
        ob_start();
        ?>
        <div class="alert alert-warning text-center">
            <h4><?php esc_html_e('Login Required', 'property-manager-pro'); ?></h4>
            <p><?php esc_html_e('Please login to access this feature.', 'property-manager-pro'); ?></p>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="btn btn-warning">
                <?php esc_html_e('Login', 'property-manager-pro'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_safe_property($property) {
        $options = get_option('property_manager_options', array());
        $currency_symbol = isset($options['currency_symbol']) ? $options['currency_symbol'] : "";

        return (object) array(
            'id' => absint($property->id ?? 0),
            'title' => esc_html($property->title ?? ''),
            'ref' => esc_html($property->ref ?? ''),
            'price' => floatval($property->price ?? 0),
            'currency' => esc_html($property->currency == "EUR" ? "&euro;" : $currency_symbol),
            'beds' => absint($property->beds ?? 0),
            'baths' => absint($property->baths ?? 0),
            'town' => esc_html($property->town ?? ''),
            'province' => esc_html($property->province ?? ''),
            'surface_area_built' => floatval($property->surface_area_built ?? 0),
            'pool' => absint($property->pool ?? 0),
            'new_build' => absint($property->new_build ?? 0),
            'property_type' => esc_html($property->property_type ?? ''),
            'featured_image' => is_array($property->images) && count($property->images) > 0 ? ($property->images[0]->attachment_id != null ? wp_get_attachment_image_url($property->images[0]->attachment_id, 'medium') : esc_url($property->images[0]->image_url ?? '')) : "",
            'url' => esc_url(home_url('/property/' . absint($property->id ?? 0))),
            'is_favorite' => PropertyManager_Favorites::get_instance()->is_favorite(absint($property->id ?? 0))
        );
    }
}