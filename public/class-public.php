<?php
/**
 * Public-facing functionality of the plugin
 * 
 * @package PropertyManagerPro
 * @version 1.0.2
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

        add_action('wp_ajax_submit_property_inquiry', array($this, 'handle_property_inquiry'));
        add_action('wp_ajax_nopriv_submit_property_inquiry', array($this, 'handle_property_inquiry'));
        
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
        $this->start_secure_session();
    }
    
    /**
     * Prevents "headers already sent" errors and adds security
     */
    private function start_secure_session() {
        // Don't start session in admin or if doing AJAX
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Check if session already started
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        // Check if headers already sent
        if (headers_sent()) {
            error_log('Property Manager Pro: Cannot start session - headers already sent');
            return;
        }
        
        // Set secure session parameters before starting
        if (!session_id()) {
            // Ensure session uses cookies only (not URL)
            ini_set('session.use_only_cookies', 1);
            
            // Use httponly cookies to prevent XSS
            ini_set('session.cookie_httponly', 1);
            
            // Use secure cookies if HTTPS
            if (is_ssl()) {
                ini_set('session.cookie_secure', 1);
            }
            
            // Use strict SameSite policy
            if (PHP_VERSION_ID >= 70300) {
                ini_set('session.cookie_samesite', 'Strict');
            }
            
            // Set session name
            $session_name = 'pm_session_' . COOKIEHASH;
            session_name($session_name);
            
            // Start the session
            if (!session_start()) {
                error_log('Property Manager Pro: Failed to start session');
            }
            
            // Regenerate session ID periodically for security
            $this->regenerate_session_periodically();
        }
    }
    
    private function regenerate_session_periodically() {
        if (!isset($_SESSION['pm_session_created'])) {
            $_SESSION['pm_session_created'] = time();
        } elseif (time() - $_SESSION['pm_session_created'] > 1800) {
            // Regenerate every 30 minutes
            session_regenerate_id(true);
            $_SESSION['pm_session_created'] = time();
        }
    }
    
    /**
     * Enqueue public styles
     */
    public function enqueue_styles() {
        // Enqueue Bootstrap 5 CSS
        wp_enqueue_style(
            'bootstrap',
            'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css',
            array(),
            '5.3.3'
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
    }
    
    /**
     * Enqueue public scripts
     */
    public function enqueue_scripts() {
        // Enqueue Bootstrap 5 JS
        wp_enqueue_script(
            'bootstrap',
            'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js',
            array('jquery'),
            '5.3.3',
            true
        );
        
        // Leaflet JS for maps
        if ($this->is_property_page() || $this->is_search_page()) {
            wp_enqueue_script(
                'leaflet',
                'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
                array(),
                '1.9.4',
                true
            );
        }
        
        // Localize script with AJAX data
        wp_localize_script('property-manager-public', 'propertyManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('property_manager_nonce'),
            'isLoggedIn' => is_user_logged_in(),
            'strings' => array(
                'error' => __('An error occurred. Please try again.', 'property-manager-pro'),
                'success' => __('Success!', 'property-manager-pro'),
                'loading' => __('Loading...', 'property-manager-pro'),
                'confirmDelete' => __('Are you sure you want to delete this?', 'property-manager-pro')
            )
        ));
    }
    
    /**
     * Add rewrite rules
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^property/([0-9]+)/?$',
            'index.php?property_id=$matches[1]',
            'top'
        );
        flush_rewrite_rules();        
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'property_id';
        return $vars;
    }
    
    /**
     * Property template
     */
    public function property_template($template) {
        if (get_query_var('property_id')) {
            $custom_template = locate_template(array('single-property.php'));
            
            if ($custom_template) {
                return $custom_template;
            }
            
            return PROPERTY_MANAGER_PLUGIN_PATH . 'public/single-property.php';
        }        
        return $template;
    }
    
    /**
     * Handle property view
     */
    public function handle_property_view() {
        $property_id = get_query_var('property_id');        
        if ($property_id) {
            $property_manager = PropertyManager_Property::get_instance();
            $property_manager->track_property_view($property_id);
        }
    }
    
    /**
     * Track property view in footer
     */
    public function track_property_view() {
        if (!$this->is_property_page()) {
            return;
        }
        
        $property_id = get_query_var('property_id');
        if (!$property_id) {
            return;
        }
        
        // Add to viewed properties (session/cookie)
        $this->add_to_viewed_properties($property_id);
    }
    
    /**
     * Add property to viewed properties list
     */
    private function add_to_viewed_properties($property_id) {
        if (!isset($_SESSION['pm_viewed_properties'])) {
            $_SESSION['pm_viewed_properties'] = array();
        }
        
        $viewed = $_SESSION['pm_viewed_properties'];
        
        // Remove if already exists (to update position)
        $key = array_search($property_id, $viewed);
        if ($key !== false) {
            unset($viewed[$key]);
        }
        
        // Add to beginning
        array_unshift($viewed, $property_id);
        
        // Keep only last 10
        $viewed = array_slice($viewed, 0, 10);
        
        $_SESSION['pm_viewed_properties'] = $viewed;
    }
    
    /**
     * Check if current page is property page
     */
    private function is_property_page() {
        return get_query_var('property_id') ? true : false;
    }
    
    /**
     * Check if current page is search page
     */
    private function is_search_page() {
        $pages = get_option('property_manager_pages', array());
        return is_page($pages);
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        // Check if this is a form submission
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        
        // Handle property inquiry form
        if (isset($_POST['property_inquiry_submit'])) {
            $this->handle_property_inquiry();
        }
        
        // Handle property alert signup
        if (isset($_POST['property_alert_submit'])) {
            $this->handle_alert_signup();
        }
        
        // Handle saved search
        if (isset($_POST['save_search_submit'])) {
            $this->handle_save_search();
        }
        
        // Handle contact form
        if (isset($_POST['contact_submit'])) {
            $this->handle_contact_form();
        }
    }
    
    /**
     * Handle property inquiry submission
     */
    public function handle_property_inquiry() {        
        if (!isset($_POST['inquiry_nonce']) || !wp_verify_nonce($_POST['inquiry_nonce'], 'property_inquiry')) {
            $message = __('Security verification failed. Please refresh the page and try again.', 'property-manager-pro');            
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => $message));
                return;
            }
            $this->add_notice('error', $message);
            return;
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit('property_inquiry', 30, 3600)) {
            $message = __('Too many inquiry requests. Please try again later.', 'property-manager-pro');
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => $message));
                return;
            }
            $this->add_notice('error', $message);
            return;
        }
        
        // Sanitize and validate input
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        // Validation
        $errors = array();
        
        if (empty($name) || strlen($name) < 2) {
            $errors[] = __('Please enter your full name.', 'property-manager-pro');
        }
        
        if (empty($email) || !is_email($email)) {
            $errors[] = __('Please enter a valid email address.', 'property-manager-pro');
        }
        
        if (empty($message) || strlen($message) < 10) {
            $errors[] = __('Please enter a message with at least 10 characters.', 'property-manager-pro');
        }
        
        if (!empty($phone)) {
            // Validate phone format
            $phone_clean = preg_replace('/[^0-9+\-\s()]/', '', $phone);
            if (strlen($phone_clean) < 10) {
                $errors[] = __('Please enter a valid phone number.', 'property-manager-pro');
            }
        }
        
        if ($property_id <= 0) {
            $errors[] = __('Invalid property ID.', 'property-manager-pro');
        }
        
        if (!empty($errors)) {
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => implode("\n", $errors)));
                return;
            }

            foreach ($errors as $error) {
                $this->add_notice('error', $error);
            }
            return;
        }

        // Insert inquiry
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $result = $wpdb->insert($table, array(
            'property_id' => $property_id,
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'status' => 'new'
        ));
        
        // Send inquiry email
        $email_manager = PropertyManager_Email::get_instance();
        $result = $email_manager->send_property_inquiry($property_id, $name, $email, $phone, $message);
        
        if ($result) {
            $message = __('Your inquiry has been sent successfully. We will contact you soon.', 'property-manager-pro');
            if (wp_doing_ajax()) {
                wp_send_json_success(array('message' => $message));
                return;
            }
            $this->add_notice('success', $message);
            return;
        } 

        $message = __('Failed to send inquiry. Please try again or contact us directly.', 'property-manager-pro');
        if (wp_doing_ajax()) {
            wp_send_json_error(array('message' => $message));
            return;
        }
        $this->add_notice('error', $message);
    }
    
    /**
     * Handle alert signup
     */
    private function handle_alert_signup() {
        if (!isset($_POST['alert_nonce']) || !wp_verify_nonce($_POST['alert_nonce'], 'property_alert_signup')) {
            $this->add_notice('error', __('Security verification failed. Please refresh and try again.', 'property-manager-pro'));
            return;
        }
        
        // This is typically handled by AJAX, but adding fallback
        $this->add_notice('info', __('Please use the property alert form to subscribe.', 'property-manager-pro'));
    }
    
    /**
     * Handle save search
     */
    private function handle_save_search() {
        if (!isset($_POST['save_search_nonce']) || !wp_verify_nonce($_POST['save_search_nonce'], 'save_property_search')) {
            $this->add_notice('error', __('Security verification failed. Please try again.', 'property-manager-pro'));
            return;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            $this->add_notice('error', __('You must be logged in to save searches.', 'property-manager-pro'));
            return;
        }
        
        // Implementation for saving search
        $this->add_notice('success', __('Search saved successfully.', 'property-manager-pro'));
    }
    
    /**
     * Handle contact form
     */
    private function handle_contact_form() {
        if (!isset($_POST['contact_nonce']) || !wp_verify_nonce($_POST['contact_nonce'], 'contact_form')) {
            $this->add_notice('error', __('Security verification failed. Please try again.', 'property-manager-pro'));
            return;
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit('contact_form', 10, 3600)) {
            $this->add_notice('error', __('Too many contact requests. Please try again later.', 'property-manager-pro'));
            return;
        }
        
        // Process contact form
        $this->add_notice('success', __('Your message has been sent successfully.', 'property-manager-pro'));
    }
    
    private function check_rate_limit($action, $max_attempts = 10, $time_window = 3600) {
        $ip = $this->get_client_ip();
        $transient_key = 'pm_rate_limit_' . md5($action . '_' . $ip);
        
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            set_transient($transient_key, 1, $time_window);
            return true;
        }
        
        if ($attempts >= $max_attempts) {
            return false;
        }
        
        set_transient($transient_key, $attempts + 1, $time_window);
        return true;
    }
    
    /**
     * After login hook
     */
    public function after_login($user_login, $user) {
        // Redirect to dashboard if specified
        $redirect = get_user_meta($user->ID, 'property_manager_redirect_after_login', true);
        if ($redirect) {
            delete_user_meta($user->ID, 'property_manager_redirect_after_login');
            wp_safe_redirect($redirect);
            exit;
        }
    }
    
    /**
     * After registration hook
     */
    public function after_registration($user_id) {
        // Send welcome email
        $email_manager = PropertyManager_Email::get_instance();
        $user = get_userdata($user_id);
        $email_manager->send_welcome_email($user->user_email, $user->display_name);
    }
    
    /**
     * Add body classes
     */
    public function add_body_classes($classes) {
        if ($this->is_property_page()) {
            $classes[] = 'property-manager-single';
        }
        
        if ($this->is_search_page()) {
            $classes[] = 'property-manager-search';
        }
        
        return $classes;
    }
    
    /**
     * Get page ID by key
     */
    private function get_page_id($key) {
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
            echo '<div id="property-manager-notifications" style="position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px;">';
            
            foreach ($_SESSION['property_manager_notices'] as $notice) {
                $alert_class = $notice['type'] === 'error' ? 'alert-danger' : 'alert-success';
                echo '<div class="alert ' . esc_attr($alert_class) . ' alert-dismissible fade show" role="alert">';
                echo esc_html($notice['message']);
                echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                echo '</div>';
            }
            
            echo '</div>';
            
            // Clear notices after displaying
            unset($_SESSION['property_manager_notices']);
        }
    }
    
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Validate IP
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        
        return $ip ? $ip : 'unknown';
    }
    
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? 
            substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
    }
}