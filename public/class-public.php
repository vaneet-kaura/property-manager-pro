<?php
/**
 * Public-facing functionality of the plugin
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Public {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Template hooks
        add_filter('template_include', array($this, 'property_template'));
        add_filter('the_content', array($this, 'property_single_content'));
        
        // Rewrite rules for property URLs
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_property_view'));
        
        // Login/Registration hooks
        add_action('wp_login', array($this, 'after_login'), 10, 2);
        add_action('user_register', array($this, 'after_registration'));
        
        // Property view tracking
        add_action('wp_footer', array($this, 'track_property_view'));
        
        // Handle form submissions
        add_action('wp', array($this, 'handle_form_submissions'));
        
        // Add body classes
        add_filter('body_class', array($this, 'add_body_classes'));
        
        // Handle notifications
        add_action('wp_footer', array($this, 'display_notifications'));
    }
    
    /**
     * Initialize public functionality
     */
    public function init() {
        // Load text domain if not already loaded
        if (!is_textdomain_loaded('property-manager-pro')) {
            load_plugin_textdomain('property-manager-pro', false, dirname(plugin_basename(PROPERTY_MANAGER_PLUGIN_PATH . 'property-manager-pro.php')) . '/languages');
        }
        
        // Start session if not started for guest tracking
        if (!session_id() && !is_admin()) {
            session_start();
        }
    }
    
    /**
     * Enqueue public styles
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'property-manager-public',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/property-manager.css',
            array('bootstrap'),
            PROPERTY_MANAGER_VERSION,
            'all'
        );
        
        // Enqueue Bootstrap 5 CSS
        wp_enqueue_style(
            'bootstrap',
            'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css',
            array(),
            '5.3.0'
        );
        
        // Enqueue Font Awesome for icons
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            array(),
            '6.4.0'
        );
        
        // Enqueue Leaflet for maps
        if ($this->is_property_page() || $this->is_search_page()) {
            wp_enqueue_style(
                'leaflet',
                'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
                array(),
                '1.9.4'
            );
        }
        
        // Add custom CSS variables
        $custom_css = $this->get_custom_css();
        wp_add_inline_style('property-manager-public', $custom_css);
    }
    
    /**
     * Enqueue public scripts
     */
    public function enqueue_scripts() {
        // Enqueue Bootstrap 5 JS
        wp_enqueue_script(
            'bootstrap',
            'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js',
            array('jquery'),
            '5.3.0',
            true
        );
        
        // Main public script
        wp_enqueue_script(
            'property-manager-public',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/property-manager.js',
            array('jquery', 'bootstrap'),
            PROPERTY_MANAGER_VERSION,
            true
        );
        
        // Enqueue Leaflet for maps
        if ($this->is_property_page() || $this->is_search_page()) {
            wp_enqueue_script(
                'leaflet',
                'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
                array('jquery'),
                '1.9.4',
                true
            );
        }
        
        // Localize script for AJAX
        wp_localize_script('property-manager-public', 'propertyManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('property_manager_nonce'),
            'isLoggedIn' => is_user_logged_in(),
            'currentUserId' => get_current_user_id(),
            'strings' => array(
                'loading' => __('Loading...', 'property-manager-pro'),
                'error' => __('An error occurred. Please try again.', 'property-manager-pro'),
                'success' => __('Success!', 'property-manager-pro'),
                'confirmDelete' => __('Are you sure you want to delete this item?', 'property-manager-pro'),
                'addedToFavorites' => __('Added to favorites!', 'property-manager-pro'),
                'removedFromFavorites' => __('Removed from favorites!', 'property-manager-pro'),
                'loginRequired' => __('Please login to use this feature.', 'property-manager-pro'),
                'emailRequired' => __('Please enter a valid email address.', 'property-manager-pro')
            )
        ));
        
        // Google Translate script
        $this->enqueue_google_translate();
    }
    
    /**
     * Add rewrite rules for property URLs
     */
    public function add_rewrite_rules() {
        // Property detail page: /property/123/property-title/
        add_rewrite_rule(
            '^property/([0-9]+)/([^/]+)/?$',
            'index.php?property_id=$matches[1]&property_slug=$matches[2]',
            'top'
        );
        
        // Property detail page simple: /property/123/
        add_rewrite_rule(
            '^property/([0-9]+)/?$',
            'index.php?property_id=$matches[1]',
            'top'
        );
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'property_id';
        $vars[] = 'property_slug';
        return $vars;
    }
    
    /**
     * Handle property single view template
     */
    public function property_template($template) {
        $property_id = get_query_var('property_id');
        
        if ($property_id) {
            $property_template = $this->locate_template('single-property.php');
            if ($property_template) {
                return $property_template;
            }
        }
        
        return $template;
    }
    
    /**
     * Handle property view tracking
     */
    public function handle_property_view() {
        $property_id = get_query_var('property_id');
        
        if ($property_id) {
            $this->track_property_view_action($property_id);
            
            // Increment view count
            $this->increment_property_views($property_id);
        }
    }
    
    /**
     * Track property view for user/session
     */
    private function track_property_view_action($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('last_viewed');
        $user_id = get_current_user_id();
        $session_id = session_id();
        
        // Check if already viewed recently (within last hour)
        $recent_view = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE property_id = %d 
             AND " . ($user_id ? "user_id = %d" : "session_id = %s") . " 
             AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $property_id,
            $user_id ?: $session_id
        ));
        
        if (!$recent_view) {
            // Insert new view record
            $wpdb->insert($table, array(
                'user_id' => $user_id ?: null,
                'session_id' => !$user_id ? $session_id : null,
                'property_id' => $property_id,
                'viewed_at' => current_time('mysql')
            ));
        }
    }
    
    /**
     * Increment property view count
     */
    private function increment_property_views($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET views = views + 1 WHERE id = %d",
            $property_id
        ));
    }
    
    /**
     * Add property content to single property pages
     */
    public function property_single_content($content) {
        if (get_query_var('property_id') && in_the_loop() && is_main_query()) {
            return $this->get_property_single_content();
        }
        
        return $content;
    }
    
    /**
     * Get property single content
     */
    private function get_property_single_content() {
        $property_id = get_query_var('property_id');
        $property = $this->get_property($property_id);
        
        if (!$property) {
            return '<div class="alert alert-danger">' . __('Property not found.', 'property-manager-pro') . '</div>';
        }
        
        ob_start();
        $this->load_template('single-property-content.php', array('property' => $property));
        return ob_get_clean();
    }
    
    /**
     * Get property data
     */
    private function get_property($property_id) {
        global $wpdb;
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $features_table = PropertyManager_Database::get_table_name('property_features');
        
        // Get property
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $properties_table WHERE id = %d AND status = 'active'",
            $property_id
        ));
        
        if (!$property) {
            return null;
        }
        
        // Get images
        $property->images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $images_table WHERE property_id = %d ORDER BY sort_order ASC",
            $property_id
        ));
        
        // Get features
        $property->features = $wpdb->get_results($wpdb->prepare(
            "SELECT feature_name, feature_value FROM $features_table WHERE property_id = %d",
            $property_id
        ));
        
        return $property;
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        // Handle property inquiry form
        if (isset($_POST['action']) && $_POST['action'] === 'property_inquiry' && wp_verify_nonce($_POST['nonce'], 'property_inquiry_nonce')) {
            $this->handle_property_inquiry();
        }
        
        // Handle contact form
        if (isset($_POST['action']) && $_POST['action'] === 'contact_form' && wp_verify_nonce($_POST['nonce'], 'contact_form_nonce')) {
            $this->handle_contact_form();
        }
        
        // Handle alert subscription
        if (isset($_POST['action']) && $_POST['action'] === 'subscribe_alert' && wp_verify_nonce($_POST['nonce'], 'alert_subscription_nonce')) {
            $this->handle_alert_subscription();
        }
    }
    
    /**
     * Handle property inquiry form submission
     */
    private function handle_property_inquiry() {
        $property_id = intval($_POST['property_id']);
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $message = sanitize_textarea_field($_POST['message']);
        
        // Validate required fields
        if (empty($name) || empty($email) || empty($message)) {
            $this->add_notice('error', __('Please fill in all required fields.', 'property-manager-pro'));
            return;
        }
        
        if (!is_email($email)) {
            $this->add_notice('error', __('Please enter a valid email address.', 'property-manager-pro'));
            return;
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
            'user_id' => get_current_user_id() ?: null,
            'status' => 'new',
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            // Send email to admin
            $email_manager = PropertyManager_Email::get_instance();
            $email_manager->send_property_inquiry($property_id, array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message
            ));
            
            $this->add_notice('success', __('Your inquiry has been sent successfully. We will contact you soon.', 'property-manager-pro'));
        } else {
            $this->add_notice('error', __('Failed to send inquiry. Please try again.', 'property-manager-pro'));
        }
    }
    
    /**
     * Handle alert subscription
     */
    private function handle_alert_subscription() {
        $email = sanitize_email($_POST['email']);
        $frequency = sanitize_text_field($_POST['frequency']);
        $search_criteria = $_POST['search_criteria'];
        
        if (!is_email($email)) {
            $this->add_notice('error', __('Please enter a valid email address.', 'property-manager-pro'));
            return;
        }
        
        $alerts_manager = PropertyManager_Alerts::get_instance();
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        
        $result = $alerts_manager->create_alert($email, $search_criteria, $frequency, $user_id);
        
        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('success', __('Property alert created successfully. Please check your email to verify your subscription.', 'property-manager-pro'));
        }
    }
    
    /**
     * After user login actions
     */
    public function after_login($user_login, $user) {
        // Redirect to dashboard if no redirect URL is set
        if (!isset($_REQUEST['redirect_to']) || empty($_REQUEST['redirect_to'])) {
            $dashboard_page = $this->get_page_by_key('user_dashboard');
            if ($dashboard_page) {
                wp_redirect(get_permalink($dashboard_page));
                exit;
            }
        }
    }
    
    /**
     * After user registration actions
     */
    public function after_registration($user_id) {
        $user = get_userdata($user_id);
        
        if ($user) {
            // Send welcome email
            $email_manager = PropertyManager_Email::get_instance();
            $email_manager->send_welcome_email($user->user_email, $user->display_name);
        }
    }
    
    /**
     * Add body classes for property pages
     */
    public function add_body_classes($classes) {
        if ($this->is_property_page()) {
            $classes[] = 'property-single';
            $classes[] = 'property-manager-page';
        }
        
        if ($this->is_search_page()) {
            $classes[] = 'property-search';
            $classes[] = 'property-manager-page';
        }
        
        return $classes;
    }
    
    /**
     * Check if current page is a property page
     */
    private function is_property_page() {
        return get_query_var('property_id') !== '';
    }
    
    /**
     * Check if current page is a search page
     */
    private function is_search_page() {
        $search_pages = array('property_search', 'property_advanced_search');
        
        foreach ($search_pages as $page_key) {
            $page_id = $this->get_page_by_key($page_key);
            if ($page_id && is_page($page_id)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get page ID by key
     */
    private function get_page_by_key($key) {
        $pages = get_option('property_manager_pages', array());
        return isset($pages[$key]) ? $pages[$key] : null;
    }
    
    /**
     * Locate template file
     */
    private function locate_template($template_name) {
        // Check theme directory first
        $theme_template = locate_template(array(
            'property-manager/' . $template_name,
            $template_name
        ));
        
        if ($theme_template) {
            return $theme_template;
        }
        
        // Fall back to plugin template
        $plugin_template = PROPERTY_MANAGER_PLUGIN_PATH . 'public/templates/' . $template_name;
        
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
        
        return false;
    }
    
    /**
     * Load template with variables
     */
    public function load_template($template_name, $vars = array()) {
        $template_path = $this->locate_template($template_name);
        
        if ($template_path) {
            extract($vars);
            include $template_path;
            return true;
        }
        
        return false;
    }
    
    /**
     * Add notification
     */
    public function add_notice($type, $message) {
        if (!isset($_SESSION['property_manager_notices'])) {
            $_SESSION['property_manager_notices'] = array();
        }
        
        $_SESSION['property_manager_notices'][] = array(
            'type' => $type,
            'message' => $message
        );
    }
    
    /**
     * Display notifications
     */
    public function display_notifications() {
        if (isset($_SESSION['property_manager_notices']) && !empty($_SESSION['property_manager_notices'])) {
            echo '<div id="property-manager-notifications" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">';
            
            foreach ($_SESSION['property_manager_notices'] as $notice) {
                $alert_class = $notice['type'] === 'error' ? 'alert-danger' : 'alert-success';
                echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
                echo esc_html($notice['message']);
                echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                echo '</div>';
            }
            
            echo '</div>';
            
            // Clear notices after displaying
            unset($_SESSION['property_manager_notices']);
        }
    }
    
    /**
     * Get custom CSS
     */
    private function get_custom_css() {
        $options = get_option('property_manager_options', array());
        
        $css = '
        :root {
            --pm-primary-color: #2c3e50;
            --pm-secondary-color: #3498db;
            --pm-success-color: #27ae60;
            --pm-danger-color: #e74c3c;
            --pm-warning-color: #f39c12;
            --pm-info-color: #17a2b8;
            --pm-light-color: #f8f9fa;
            --pm-dark-color: #343a40;
        }
        
        .property-manager-page {
            --bs-primary: var(--pm-primary-color);
            --bs-secondary: var(--pm-secondary-color);
        }
        
        .property-card {
            transition: transform 0.2s ease-in-out;
        }
        
        .property-card:hover {
            transform: translateY(-5px);
        }
        
        .property-price {
            color: var(--pm-primary-color);
            font-weight: bold;
            font-size: 1.25rem;
        }
        
        .property-features {
            color: var(--pm-secondary-color);
        }
        
        .favorite-btn {
            transition: color 0.2s ease;
        }
        
        .favorite-btn.favorited {
            color: var(--pm-danger-color);
        }
        
        .map-container {
            height: 400px;
            border-radius: 0.375rem;
            overflow: hidden;
        }
        
        .property-gallery {
            border-radius: 0.375rem;
            overflow: hidden;
        }
        
        .search-filters {
            background: var(--pm-light-color);
            border-radius: 0.375rem;
            padding: 1.5rem;
        }
        
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        #google_translate_element {
            margin: 0;
        }
        
        .goog-te-gadget {
            font-size: 0;
        }
        
        .goog-te-gadget-simple {
            background: none !important;
            border: 1px solid #ccc !important;
            padding: 8px !important;
            border-radius: 4px !important;
        }
        ';
        
        return $css;
    }
    
    /**
     * Enqueue Google Translate
     */
    private function enqueue_google_translate() {
        // Add Google Translate script
        wp_add_inline_script('property-manager-public', '
            function googleTranslateElementInit() {
                new google.translate.TranslateElement({
                    pageLanguage: "en",
                    includedLanguages: "en,es,de,fr",
                    layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
                    autoDisplay: false
                }, "google_translate_element");
            }
        ');
        
        wp_enqueue_script(
            'google-translate',
            'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit',
            array(),
            null,
            true
        );
    }
    
    /**
     * Track property view in footer
     */
    public function track_property_view() {
        $property_id = get_query_var('property_id');
        
        if ($property_id && !wp_doing_ajax()) {
            echo '<script>
                jQuery(document).ready(function($) {
                    // Track property view via AJAX for analytics
                    $.post(propertyManager.ajaxUrl, {
                        action: "track_property_view",
                        property_id: ' . intval($property_id) . ',
                        nonce: propertyManager.nonce
                    });
                });
            </script>';
        }
    }
}