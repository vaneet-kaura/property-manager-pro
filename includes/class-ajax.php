<?php
/**
 * AJAX Handlers - FIXED VERSION
 * 
 * @package PropertyManagerPro
 * @version 1.0.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Ajax {
    
    private static $instance = null;
    
    // Rate limiting constants
    private const RATE_LIMIT_REQUESTS = 30;
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_STRICT = 10;
    
    // Security constants
    private const MAX_FAVORITES = 100;
    private const MAX_INQUIRIES_PER_DAY = 5;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Favorites
        add_action('wp_ajax_toggle_property_favorite', array($this, 'ajax_toggle_favorite'));
        add_action('wp_ajax_nopriv_toggle_property_favorite', array($this, 'ajax_toggle_favorite_guest'));
        
        // Property Inquiries
        add_action('wp_ajax_submit_property_inquiry', array($this, 'ajax_submit_inquiry'));
        add_action('wp_ajax_nopriv_submit_property_inquiry', array($this, 'ajax_submit_inquiry'));
        
        // User Actions
        add_action('wp_ajax_track_property_view', array($this, 'ajax_track_view'));
        add_action('wp_ajax_nopriv_track_property_view', array($this, 'ajax_track_view'));
        
        // Add security headers
        add_action('send_headers', array($this, 'add_security_headers'));
    }

    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (wp_doing_ajax()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');
        }
    }

    /**
     * Verify nonce - FIXED: Better error handling
     */
    private function verify_nonce($nonce_action = 'property_manager_nonce') {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_action)) {
            $this->log_security_event('nonce_failed', array(
                'action' => $nonce_action,
                'ip' => $this->get_client_ip()
            ));
            
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh and try again.', 'property-manager-pro')
            ), 403);
        }
    }

    /**
     * Check rate limiting - FIXED: Now actually enforces limits!
     */
    private function check_rate_limit($action, $strict = false) {
        $ip = $this->get_client_ip();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $identifier = $user_id ? 'user_' . $user_id : 'ip_' . md5($ip);
        
        $transient_key = 'pm_rate_' . md5($action . '_' . $identifier);
        $request_count = get_transient($transient_key);
        
        $limit = $strict ? self::RATE_LIMIT_STRICT : self::RATE_LIMIT_REQUESTS;
        
        if ($request_count && $request_count >= $limit) {
            $this->log_security_event('rate_limit_exceeded', array(
                'action' => $action,
                'identifier' => $identifier,
                'count' => $request_count
            ));
            
            wp_send_json_error(array(
                'message' => __('Too many requests. Please wait a minute.', 'property-manager-pro')
            ), 429);
        }
        
        set_transient($transient_key, ($request_count ? $request_count + 1 : 1), self::RATE_LIMIT_WINDOW);
    }

    /**
     * Check inquiry daily limit - FIXED: Now actually enforced!
     */
    private function check_inquiry_limit() {
        $ip = $this->get_client_ip();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $identifier = $user_id ? 'user_' . $user_id : 'ip_' . md5($ip);
        
        $transient_key = 'pm_inquiry_limit_' . $identifier;
        $count = get_transient($transient_key);
        
        if ($count && $count >= self::MAX_INQUIRIES_PER_DAY) {
            return false;
        }
        
        return true;
    }

    /**
     * Increment inquiry count
     */
    private function increment_inquiry_count() {
        $ip = $this->get_client_ip();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $identifier = $user_id ? 'user_' . $user_id : 'ip_' . md5($ip);
        
        $transient_key = 'pm_inquiry_limit_' . $identifier;
        $count = get_transient($transient_key);
        
        set_transient($transient_key, ($count ? $count + 1 : 1), DAY_IN_SECONDS);
    }

    /**
     * Submit inquiry - FIXED: Enforces rate limits!
     */
    public function ajax_submit_inquiry() {
        $this->verify_nonce();
        $this->check_rate_limit('submit_inquiry', true);
        
        // FIXED: Actually check inquiry limit!
        if (!$this->check_inquiry_limit()) {
            wp_send_json_error(array(
                'message' => __('You have reached the maximum number of inquiries for today (5). Please try again tomorrow.', 'property-manager-pro')
            ), 429);
        }
        
        // Validate inputs
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        
        // Validation
        if (!$property_id || empty($name) || !is_email($email) || empty($message)) {
            wp_send_json_error(array(
                'message' => __('Please fill in all required fields.', 'property-manager-pro')
            ), 400);
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
            'status' => 'new'
        ));
        
        if ($result) {
            // Increment inquiry count
            $this->increment_inquiry_count();
            
            // Send notification email
            $property = PropertyManager_Property::get_instance()->get_property($property_id);
            if ($property) {
                $this->send_inquiry_notification($wpdb->insert_id, $property, $name, $email, $phone, $message);
            }
            
            wp_send_json_success(array(
                'message' => __('Thank you! Your inquiry has been sent successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to send inquiry. Please try again.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Send inquiry notification
     */
    private function send_inquiry_notification($inquiry_id, $property, $name, $email, $phone, $message) {
        $email_manager = PropertyManager_Email::get_instance();
        $email_manager->send_inquiry_notification(array(
            'inquiry_id' => $inquiry_id,
            'property' => $property,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message
        ));
    }

    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER)) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
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
            substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
    }

    /**
     * Log security event
     */
    private function log_security_event($event_type, $data) {
        error_log('Property Manager Security: ' . $event_type . ' - ' . wp_json_encode($data));
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('security_logs');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->insert($table, array(
                'event_type' => $event_type,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $this->get_user_agent(),
                'user_id' => is_user_logged_in() ? get_current_user_id() : null,
                'data' => wp_json_encode($data)
            ));
        }
    }
    
    // Implement other AJAX methods here (ajax_toggle_favorite, ajax_track_view, etc.)
    // Following the same security patterns shown above
}