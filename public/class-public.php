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
     * Track property view for user/IP
     * FIXED: Now uses pm_property_views table instead of pm_last_viewed
     */
    private function track_property_view_action($property_id) {
        global $wpdb;
        
        // Use correct table name
        $table = PropertyManager_Database::get_table_name('property_views');
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        
        // Build WHERE clause
        if ($user_id) {
            $where_clause = $wpdb->prepare("user_id = %d", $user_id);
        } else {
            $where_clause = $wpdb->prepare("ip_address = %s AND user_id IS NULL", $ip_address);
        }
        
        // Check if already viewed recently (within last hour)
        $recent_view = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE property_id = %d 
             AND " . $where_clause . "
             AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $property_id
        ));
        
        if (!$recent_view) {
            // Insert new view record
            $wpdb->insert($table, array(
                'property_id' => $property_id,
                'user_id' => $user_id ?: null,
                'ip_address' => $ip_address,
                'user_agent' => $this->get_user_agent(),
                'viewed_at' => current_time('mysql')
            ), array('%d', '%d', '%s', '%s', '%s'));
        }
    }
    
    /**
     * Increment property view count
     * FIXED: Changed column name from 'views' to 'view_count'
     */
    private function increment_property_views($property_id) {
        global $wpdb;
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE $properties_table 
             SET view_count = view_count + 1 
             WHERE id = %d",
            $property_id
        ));
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Get user agent
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? 
            substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : 
            '';
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
     * Get custom CSS
     */
    private function get_custom_css() {
        $options = get_option('property_manager_options', array());
        
        $primary_color = isset($options['primary_color']) ? $options['primary_color'] : '#007bff';
        $secondary_color = isset($options['secondary_color']) ? $options['secondary_color'] : '#6c757d';
        
        $css = "
            :root {
                --pm-primary-color: {$primary_color};
                --pm-secondary-color: {$secondary_color};
            }
            .property-card:hover {
                transform: translateY(-5px);
                transition: transform 0.3s ease;
            }
            .favorite-btn.active {
                color: #dc3545;
            }
        ";
        
        return $css;
    }
    
    /**
     * Property single content filter
     */
    public function property_single_content($content) {
        if (get_query_var('property_id')) {
            $property_id = get_query_var('property_id');
            $property_manager = PropertyManager_Property::get_instance();
            $property = $property_manager->get_property($property_id);
            
            if ($property) {
                ob_start();
                $this->load_template('content-single-property.php', array('property' => $property));
                return ob_get_clean();
            }
        }
        
        return $content;
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        // Handle property inquiry form
        if (isset($_POST['property_inquiry_submit']) && wp_verify_nonce($_POST['inquiry_nonce'], 'property_inquiry')) {
            $this->handle_property_inquiry();
        }
        
        // Handle property alert signup
        if (isset($_POST['property_alert_submit']) && wp_verify_nonce($_POST['alert_nonce'], 'property_alert_signup')) {
            $this->handle_alert_signup();
        }
    }
    
    /**
     * Handle property inquiry submission
     */
    private function handle_property_inquiry() {
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        // Validation
        if (empty($name) || empty($email) || empty($message) || !is_email($email)) {
            $this->add_notice('error', __('Please fill in all required fields with valid information.', 'property-manager-pro'));
            return;
        }
        
        // Insert inquiry
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $result = $wpdb->insert($table, array(
            'property_id' => $property_id,
            'user_id' => get_current_user_id() ?: null,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'status' => 'new'
        ));
        
        if ($result) {
            // Send notification email
            $email_manager = PropertyManager_Email::get_instance();
            $email_manager->send_inquiry_notification(array(
                'property_id' => $property_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message
            ));
            
            $this->add_notice('success', __('Thank you! Your inquiry has been sent successfully.', 'property-manager-pro'));
        } else {
            $this->add_notice('error', __('Failed to send inquiry. Please try again.', 'property-manager-pro'));
        }
    }
    
    /**
     * Handle alert signup
     */
    private function handle_alert_signup() {
        // Implementation handled by AJAX in class-ajax-search.php
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
}