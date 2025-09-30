<?php
/**
 * AJAX handler class for Property Manager Pro
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Ajax {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_ajax_hooks();
    }
    
    /**
     * Initialize AJAX hooks
     */
    private function init_ajax_hooks() {
        // Property search
        add_action('wp_ajax_property_search', array($this, 'ajax_property_search'));
        add_action('wp_ajax_nopriv_property_search', array($this, 'ajax_property_search'));
        
        // Property filters
        add_action('wp_ajax_get_property_locations', array($this, 'ajax_get_property_locations'));
        add_action('wp_ajax_nopriv_get_property_locations', array($this, 'ajax_get_property_locations'));
        
        // Favorites management
        add_action('wp_ajax_toggle_favorite', array($this, 'ajax_toggle_favorite'));
        add_action('wp_ajax_remove_favorite', array($this, 'ajax_remove_favorite'));
        add_action('wp_ajax_get_user_favorites', array($this, 'ajax_get_user_favorites'));
        
        // Saved searches
        add_action('wp_ajax_save_search', array($this, 'ajax_save_search'));
        add_action('wp_ajax_delete_saved_search', array($this, 'ajax_delete_saved_search'));
        add_action('wp_ajax_load_saved_search', array($this, 'ajax_load_saved_search'));
        
        // Property alerts
        add_action('wp_ajax_subscribe_alert', array($this, 'ajax_subscribe_alert'));
        add_action('wp_ajax_nopriv_subscribe_alert', array($this, 'ajax_subscribe_alert'));
        add_action('wp_ajax_toggle_alert_status', array($this, 'ajax_toggle_alert_status'));
        add_action('wp_ajax_delete_alert', array($this, 'ajax_delete_alert'));
        add_action('wp_ajax_verify_alert_email', array($this, 'ajax_verify_alert_email'));
        add_action('wp_ajax_nopriv_verify_alert_email', array($this, 'ajax_verify_alert_email'));
        
        // Property contact form
        add_action('wp_ajax_property_inquiry', array($this, 'ajax_property_inquiry'));
        add_action('wp_ajax_nopriv_property_inquiry', array($this, 'ajax_property_inquiry'));
        
        // Last viewed properties
        add_action('wp_ajax_track_property_view', array($this, 'ajax_track_property_view'));
        add_action('wp_ajax_nopriv_track_property_view', array($this, 'ajax_track_property_view'));
        
        // Map functionality
        add_action('wp_ajax_get_properties_for_map', array($this, 'ajax_get_properties_for_map'));
        add_action('wp_ajax_nopriv_get_properties_for_map', array($this, 'ajax_get_properties_for_map'));
        
        // Load more properties
        add_action('wp_ajax_load_more_properties', array($this, 'ajax_load_more_properties'));
        add_action('wp_ajax_nopriv_load_more_properties', array($this, 'ajax_load_more_properties'));
    }
    
    /**
     * AJAX property search
     */
    public function ajax_property_search() {
        $this->verify_nonce();
        
        $search_params = $this->sanitize_search_params($_POST);
        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        $view = sanitize_text_field($_POST['view'] ?? 'grid');
        
        $search = PropertyManager_Search::get_instance();
        $results = $search->search($search_params, $page, $per_page);
        
        ob_start();
        if (!empty($results['properties'])) {
            foreach ($results['properties'] as $property) {
                echo '<div class="property-item col-lg-4 col-md-6 mb-4">';
                $this->render_property_card($property, $view);
                echo '</div>';
            }
        } else {
            echo '<div class="col-12"><div class="no-results text-center py-5">';
            echo '<h4>' . __('No Properties Found', 'property-manager-pro') . '</h4>';
            echo '<p>' . __('Try adjusting your search criteria.', 'property-manager-pro') . '</p>';
            echo '</div></div>';
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'total' => $results['total'],
            'found' => count($results['properties']),
            'page' => $page,
            'max_pages' => ceil($results['total'] / $per_page)
        ));
    }
    
    /**
     * Get property locations for dropdown
     */
    public function ajax_get_property_locations() {
        $this->verify_nonce();
        
        $type = sanitize_text_field($_POST['type'] ?? 'town');
        $parent = sanitize_text_field($_POST['parent'] ?? '');
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $locations = array();
        
        if ($type === 'town') {
            $query = "SELECT DISTINCT town FROM $properties_table WHERE town IS NOT NULL AND town != '' ORDER BY town ASC";
            if ($parent) {
                $query = $wpdb->prepare(
                    "SELECT DISTINCT town FROM $properties_table WHERE province = %s AND town IS NOT NULL AND town != '' ORDER BY town ASC",
                    $parent
                );
            }
            
            $results = $wpdb->get_results($query);
            foreach ($results as $result) {
                $locations[] = array(
                    'value' => $result->town,
                    'label' => $result->town
                );
            }
        } elseif ($type === 'province') {
            $results = $wpdb->get_results("SELECT DISTINCT province FROM $properties_table WHERE province IS NOT NULL AND province != '' ORDER BY province ASC");
            foreach ($results as $result) {
                $locations[] = array(
                    'value' => $result->province,
                    'label' => $result->province
                );
            }
        }
        
        wp_send_json_success($locations);
    }
    
    /**
     * Toggle property favorite status
     */
    public function ajax_toggle_favorite() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please log in to add favorites.', 'property-manager-pro')));
        }
        
        $property_id = intval($_POST['property_id']);
        $user_id = get_current_user_id();
        
        if (!$property_id) {
            wp_send_json_error(array('message' => __('Invalid property ID.', 'property-manager-pro')));
        }
        
        $favorites = PropertyManager_Favorites::get_instance();
        
        if ($favorites->is_favorite($user_id, $property_id)) {
            $result = $favorites->remove_favorite($user_id, $property_id);
            $action = 'removed';
            $message = __('Property removed from favorites.', 'property-manager-pro');
        } else {
            $result = $favorites->add_favorite($user_id, $property_id);
            $action = 'added';
            $message = __('Property added to favorites.', 'property-manager-pro');
        }
        
        if ($result) {
            wp_send_json_success(array(
                'action' => $action,
                'message' => $message,
                'is_favorite' => ($action === 'added')
            ));
        } else {
            wp_send_json_error(array('message' => __('Unable to update favorites.', 'property-manager-pro')));
        }
    }
    
    /**
     * Remove property from favorites
     */
    public function ajax_remove_favorite() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Access denied.', 'property-manager-pro')));
        }
        
        $property_id = intval($_POST['property_id']);
        $user_id = get_current_user_id();
        
        $favorites = PropertyManager_Favorites::get_instance();
        $result = $favorites->remove_favorite($user_id, $property_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Property removed from favorites.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to remove favorite.', 'property-manager-pro')));
        }
    }
    
    /**
     * Get user favorites
     */
    public function ajax_get_user_favorites() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Access denied.', 'property-manager-pro')));
        }
        
        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        $view = sanitize_text_field($_POST['view'] ?? 'grid');
        
        $favorites = PropertyManager_Favorites::get_instance();
        $results = $favorites->get_user_favorites(get_current_user_id(), $page, $per_page);
        
        ob_start();
        if (!empty($results['properties'])) {
            foreach ($results['properties'] as $property) {
                echo '<div class="property-item col-lg-4 col-md-6 mb-4">';
                $this->render_property_card($property, $view, true);
                echo '</div>';
            }
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'total' => $results['total']
        ));
    }
    
    /**
     * Save search criteria
     */
    public function ajax_save_search() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please log in to save searches.', 'property-manager-pro')));
        }
        
        $search_name = sanitize_text_field($_POST['search_name']);
        $search_criteria = $this->sanitize_search_params($_POST['search_criteria']);
        $email_alerts = !empty($_POST['email_alerts']);
        $alert_frequency = sanitize_text_field($_POST['alert_frequency'] ?? 'weekly');
        
        if (empty($search_name)) {
            wp_send_json_error(array('message' => __('Please provide a search name.', 'property-manager-pro')));
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $result = $wpdb->insert($table, array(
            'user_id' => get_current_user_id(),
            'search_name' => $search_name,
            'search_criteria' => json_encode($search_criteria),
            'email_alerts' => $email_alerts ? 1 : 0,
            'alert_frequency' => $alert_frequency,
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => __('Search saved successfully.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to save search.', 'property-manager-pro')));
        }
    }
    
    /**
     * Delete saved search
     */
    public function ajax_delete_saved_search() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Access denied.', 'property-manager-pro')));
        }
        
        $search_id = intval($_POST['search_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $result = $wpdb->delete($table, array(
            'id' => $search_id,
            'user_id' => $user_id
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => __('Search deleted successfully.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to delete search.', 'property-manager-pro')));
        }
    }
    
    /**
     * Load saved search criteria
     */
    public function ajax_load_saved_search() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Access denied.', 'property-manager-pro')));
        }
        
        $search_id = intval($_POST['search_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $search_id,
            $user_id
        ));
        
        if ($search) {
            wp_send_json_success(array(
                'criteria' => json_decode($search->search_criteria, true),
                'name' => $search->search_name
            ));
        } else {
            wp_send_json_error(array('message' => __('Search not found.', 'property-manager-pro')));
        }
    }
    
    /**
     * Subscribe to property alerts
     */
    public function ajax_subscribe_alert() {
        $this->verify_nonce();
        
        $email = sanitize_email($_POST['email']);
        $search_criteria = $this->sanitize_search_params($_POST['search_criteria']);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        
        if (!is_email($email)) {
            wp_send_json_error(array('message' => __('Please provide a valid email address.', 'property-manager-pro')));
        }
        
        $alerts = PropertyManager_Alerts::get_instance();
        $result = $alerts->subscribe_alert($email, $search_criteria, $frequency);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Alert subscription created. Please check your email to confirm.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to create alert subscription.', 'property-manager-pro')));
        }
    }
    
    /**
     * Toggle alert status (pause/resume)
     */
    public function ajax_toggle_alert_status() {
        $this->verify_nonce();
        
        $alert_id = intval($_POST['alert_id']);
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        $alerts = PropertyManager_Alerts::get_instance();
        
        // Verify ownership
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $valid = $alerts->verify_user_alert($alert_id, $user_id);
        } elseif ($token) {
            $valid = $alerts->verify_alert_token($alert_id, $token);
        } else {
            $valid = false;
        }
        
        if (!$valid) {
            wp_send_json_error(array('message' => __('Invalid alert or access denied.', 'property-manager-pro')));
        }
        
        $result = $alerts->toggle_alert_status($alert_id);
        
        if ($result) {
            $status = $alerts->get_alert_status($alert_id);
            $message = ($status === 'active') ? 
                __('Alert activated.', 'property-manager-pro') : 
                __('Alert paused.', 'property-manager-pro');
            
            wp_send_json_success(array(
                'message' => $message,
                'status' => $status
            ));
        } else {
            wp_send_json_error(array('message' => __('Unable to update alert status.', 'property-manager-pro')));
        }
    }
    
    /**
     * Delete property alert
     */
    public function ajax_delete_alert() {
        $this->verify_nonce();
        
        $alert_id = intval($_POST['alert_id']);
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        $alerts = PropertyManager_Alerts::get_instance();
        
        // Verify ownership
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $valid = $alerts->verify_user_alert($alert_id, $user_id);
        } elseif ($token) {
            $valid = $alerts->verify_alert_token($alert_id, $token);
        } else {
            $valid = false;
        }
        
        if (!$valid) {
            wp_send_json_error(array('message' => __('Invalid alert or access denied.', 'property-manager-pro')));
        }
        
        $result = $alerts->delete_alert($alert_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Alert deleted successfully.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to delete alert.', 'property-manager-pro')));
        }
    }
    
    /**
     * Verify alert email
     */
    public function ajax_verify_alert_email() {
        $token = sanitize_text_field($_GET['token'] ?? '');
        
        if (empty($token)) {
            wp_send_json_error(array('message' => __('Invalid verification token.', 'property-manager-pro')));
        }
        
        $alerts = PropertyManager_Alerts::get_instance();
        $result = $alerts->verify_email($token);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Email verified successfully. You will now receive property alerts.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Invalid or expired verification token.', 'property-manager-pro')));
        }
    }
    
    /**
     * Handle property inquiry form
     */
    public function ajax_property_inquiry() {
        $this->verify_nonce();
        
        $property_id = intval($_POST['property_id']);
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $message = sanitize_textarea_field($_POST['message']);
        
        // Validation
        if (!$property_id || !$name || !is_email($email) || !$message) {
            wp_send_json_error(array('message' => __('Please fill in all required fields.', 'property-manager-pro')));
        }
        
        // Save inquiry to database
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $result = $wpdb->insert($table, array(
            'property_id' => $property_id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            // Send email notification to admin
            $email_manager = PropertyManager_Email::get_instance();
            $email_manager->send_inquiry_notification($property_id, compact('name', 'email', 'phone', 'message'));
            
            wp_send_json_success(array('message' => __('Your inquiry has been sent successfully.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Unable to send inquiry. Please try again.', 'property-manager-pro')));
        }
    }
    
    /**
     * Track property view
     */
    public function ajax_track_property_view() {
        $property_id = intval($_POST['property_id']);
        
        if (!$property_id) {
            wp_send_json_error(array('message' => __('Invalid property ID.', 'property-manager-pro')));
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('last_viewed');
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $session_id = session_id() ?: wp_generate_password(32, false);
        
        // Remove existing record for this user/session and property
        if ($user_id) {
            $wpdb->delete($table, array(
                'user_id' => $user_id,
                'property_id' => $property_id
            ));
        } else {
            $wpdb->delete($table, array(
                'session_id' => $session_id,
                'property_id' => $property_id
            ));
        }
        
        // Insert new record
        $result = $wpdb->insert($table, array(
            'user_id' => $user_id,
            'session_id' => $session_id,
            'property_id' => $property_id,
            'viewed_at' => current_time('mysql')
        ));
        
        // Update property views counter
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $wpdb->query($wpdb->prepare(
            "UPDATE $properties_table SET views = views + 1 WHERE id = %d",
            $property_id
        ));
        
        wp_send_json_success();
    }
    
    /**
     * Get properties for map display
     */
    public function ajax_get_properties_for_map() {
        $this->verify_nonce();
        
        $search_params = $this->sanitize_search_params($_POST);
        $bounds = isset($_POST['bounds']) ? $_POST['bounds'] : array();
        
        // Add bounds to search if provided
        if (!empty($bounds)) {
            $search_params['lat_min'] = floatval($bounds['south']);
            $search_params['lat_max'] = floatval($bounds['north']);
            $search_params['lng_min'] = floatval($bounds['west']);
            $search_params['lng_max'] = floatval($bounds['east']);
        }
        
        $search = PropertyManager_Search::get_instance();
        $results = $search->search_for_map($search_params);
        
        $map_properties = array();
        foreach ($results['properties'] as $property) {
            if ($property->latitude && $property->longitude) {
                $options = get_option('property_manager_options');
                $currency = $options['currency_symbol'] ?? '€';
                
                $map_properties[] = array(
                    'id' => $property->id,
                    'lat' => floatval($property->latitude),
                    'lng' => floatval($property->longitude),
                    'title' => $property->title,
                    'price' => $currency . number_format($property->price),
                    'location' => $property->town . ', ' . $property->province,
                    'url' => $this->get_property_url($property),
                    'image' => $this->get_property_thumbnail($property),
                    'beds' => $property->beds,
                    'baths' => $property->baths
                );
            }
        }
        
        wp_send_json_success(array(
            'properties' => $map_properties,
            'total' => count($map_properties)
        ));
    }
    
    /**
     * Load more properties (infinite scroll)
     */
    public function ajax_load_more_properties() {
        $this->verify_nonce();
        
        $search_params = $this->sanitize_search_params($_POST);
        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        $view = sanitize_text_field($_POST['view'] ?? 'grid');
        
        $search = PropertyManager_Search::get_instance();
        $results = $search->search($search_params, $page, $per_page);
        
        ob_start();
        if (!empty($results['properties'])) {
            foreach ($results['properties'] as $property) {
                echo '<div class="property-item col-lg-4 col-md-6 mb-4">';
                $this->render_property_card($property, $view);
                echo '</div>';
            }
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'has_more' => ($page * $per_page) < $results['total'],
            'next_page' => $page + 1
        ));
    }
    
    /**
     * Verify AJAX nonce
     */
    private function verify_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'property_manager_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'property-manager-pro')));
        }
    }
    
    /**
     * Sanitize search parameters
     */
    private function sanitize_search_params($params) {
        $sanitized = array();
        
        if (isset($params['location'])) {
            $sanitized['location'] = sanitize_text_field($params['location']);
        }
        
        if (isset($params['type'])) {
            $sanitized['type'] = sanitize_text_field($params['type']);
        }
        
        if (isset($params['min_price'])) {
            $sanitized['min_price'] = floatval($params['min_price']);
        }
        
        if (isset($params['max_price'])) {
            $sanitized['max_price'] = floatval($params['max_price']);
        }
        
        if (isset($params['beds'])) {
            $sanitized['beds'] = intval($params['beds']);
        }
        
        if (isset($params['baths'])) {
            $sanitized['baths'] = intval($params['baths']);
        }
        
        if (isset($params['pool'])) {
            $sanitized['pool'] = !empty($params['pool']);
        }
        
        if (isset($params['new_build'])) {
            $sanitized['new_build'] = !empty($params['new_build']);
        }
        
        if (isset($params['featured'])) {
            $sanitized['featured'] = !empty($params['featured']);
        }
        
        if (isset($params['price_freq'])) {
            $sanitized['price_freq'] = sanitize_text_field($params['price_freq']);
        }
        
        return $sanitized;
    }
    
    /**
     * Render property card for AJAX responses
     */
    private function render_property_card($property, $view = 'grid', $show_remove = false) {
        $shortcodes = PropertyManager_Shortcodes::get_instance();
        
        // Use reflection to access the private method
        $reflection = new ReflectionClass($shortcodes);
        $method = $reflection->getMethod('render_property_card');
        $method->setAccessible(true);
        $method->invoke($shortcodes, $property);
        
        if ($show_remove) {
            echo '<div class="remove-favorite-overlay">';
            echo '<button class="btn btn-sm btn-danger remove-favorite-btn" data-property-id="' . $property->id . '">';
            echo '<i class="fas fa-times"></i> ' . __('Remove', 'property-manager-pro');
            echo '</button>';
            echo '</div>';
        }
    }
    
    /**
     * Get property URL
     */
    private function get_property_url($property) {
        return add_query_arg('property_id', $property->id, get_permalink());
    }
    
    /**
     * Get property thumbnail
     */
    private function get_property_thumbnail($property) {
        if (!empty($property->images)) {
            $images = json_decode($property->images, true);
            if (!empty($images)) {
                return $images[0]['url'];
            }
        }
        return REPM_PLUGIN_URL . 'assets/images/no-image.jpg';
    }
}