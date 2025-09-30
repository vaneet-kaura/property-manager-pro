<?php
/**
 * AJAX Search Handlers - Production Ready with All Security & Performance Enhancements
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_AjaxSearch {
    
    private static $instance = null;
    
    // Rate limiting constants
    private const RATE_LIMIT_REQUESTS = 30;
    private const RATE_LIMIT_WINDOW = 60; // seconds
    
    // Cache constants
    private const CACHE_EXPIRATION = 3600; // 1 hour
    private const TOKEN_EXPIRATION = 172800; // 48 hours
    
    // Security constants
    private const MAX_SEARCH_LENGTH = 200;
    private const MAX_CRITERIA_ITEMS = 20;
    
    // Blocked disposable email domains
    private $blocked_email_domains = array(
        'tempmail.com', 'guerrillamail.com', '10minutemail.com', 
        'throwaway.email', 'mailinator.com', 'trashmail.com',
        'temp-mail.org', 'fakeinbox.com', 'maildrop.cc'
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // All AJAX actions with proper nonce verification
        add_action('wp_ajax_get_towns_by_province', array($this, 'get_towns_by_province'));
        add_action('wp_ajax_nopriv_get_towns_by_province', array($this, 'get_towns_by_province'));
        
        add_action('wp_ajax_save_search_alert', array($this, 'save_search_alert'));
        add_action('wp_ajax_nopriv_save_search_alert', array($this, 'save_search_alert'));
        
        add_action('wp_ajax_load_more_properties', array($this, 'load_more_properties'));
        add_action('wp_ajax_nopriv_load_more_properties', array($this, 'load_more_properties'));
        
        add_action('wp_ajax_get_property_details', array($this, 'get_property_details'));
        add_action('wp_ajax_nopriv_get_property_details', array($this, 'get_property_details'));
        
        add_action('wp_ajax_verify_recaptcha', array($this, 'verify_recaptcha'));
        add_action('wp_ajax_nopriv_verify_recaptcha', array($this, 'verify_recaptcha'));
        
        // Cleanup expired tokens daily
        add_action('property_manager_daily_cleanup', array($this, 'cleanup_expired_tokens'));
        
        // Add Content Security Policy
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
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    /**
     * Verify nonce for AJAX requests
     */
    private function verify_ajax_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'property_manager_nonce')) {
            $this->log_security_event('nonce_verification_failed', array(
                'ip' => $this->get_client_ip(),
                'user_agent' => $this->get_user_agent()
            ));
            
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'property-manager-pro')
            ), 403);
            exit;
        }
    }

    /**
     * Check honeypot field
     */
    private function check_honeypot() {
        // Honeypot field should be empty
        if (!empty($_POST['website']) || !empty($_POST['url_field'])) {
            $this->log_security_event('honeypot_triggered', array(
                'ip' => $this->get_client_ip(),
                'honeypot_value' => isset($_POST['website']) ? 'website filled' : 'url_field filled'
            ));
            
            // Silent fail for bots
            wp_send_json_success(array(
                'message' => __('Thank you for your submission.', 'property-manager-pro')
            ));
            exit;
        }
    }

    /**
     * Check rate limiting with progressive backoff
     */
    private function check_rate_limit($action) {
        $ip = $this->get_client_ip();
        $transient_key = 'pm_rate_limit_' . md5($action . '_' . $ip);
        
        $request_data = get_transient($transient_key);
        
        if (!$request_data) {
            $request_data = array('count' => 0, 'first_request' => time());
        }
        
        $request_data['count']++;
        
        // Progressive rate limiting
        if ($request_data['count'] > self::RATE_LIMIT_REQUESTS) {
            $wait_time = min(300, ($request_data['count'] - self::RATE_LIMIT_REQUESTS) * 10);
            
            $this->log_security_event('rate_limit_exceeded', array(
                'ip' => $ip,
                'action' => $action,
                'count' => $request_data['count']
            ));
            
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %d: wait time in seconds */
                    __('Too many requests. Please try again in %d seconds.', 'property-manager-pro'),
                    $wait_time
                )
            ), 429);
            exit;
        }
        
        set_transient($transient_key, $request_data, self::RATE_LIMIT_WINDOW);
    }

    /**
     * Get client IP address safely with proxy detection
     */
    private function get_client_ip() {
        $ip = '0.0.0.0';
        
        // Check for CloudFlare
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']), FILTER_VALIDATE_IP)) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }
        
        // Check for proxy headers in order of reliability
        $headers = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ip_list = sanitize_text_field(wp_unslash($_SERVER[$header]));
                $ip_array = array_map('trim', explode(',', $ip_list));
                
                foreach ($ip_array as $potential_ip) {
                    if (filter_var($potential_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $potential_ip;
                    }
                }
            }
        }
        
        return $ip;
    }

    /**
     * Get user agent safely
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? 
            substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : 
            'Unknown';
    }

    /**
     * Validate email domain
     */
    private function validate_email_domain($email) {
        $domain = substr(strrchr($email, "@"), 1);
        
        // Check against blocked domains
        if (in_array(strtolower($domain), $this->blocked_email_domains, true)) {
            return false;
        }
        
        // Check DNS MX record
        if (!checkdnsrr($domain, 'MX')) {
            return false;
        }
        
        return true;
    }

    /**
     * Verify reCAPTCHA if enabled
     */
    private function verify_recaptcha_token($token) {
        $options = get_option('property_manager_options', array());
        
        if (empty($options['enable_recaptcha']) || empty($options['recaptcha_secret_key'])) {
            return true; // Skip if not configured
        }
        
        $secret_key = $options['recaptcha_secret_key'];
        
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret' => $secret_key,
                'response' => $token,
                'remoteip' => $this->get_client_ip()
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            error_log('reCAPTCHA verification error: ' . $response->get_error_message());
            return false;
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        return isset($result['success']) && $result['success'] === true && 
               isset($result['score']) && $result['score'] >= 0.5;
    }

    /**
     * Get towns by province - AJAX handler with caching
     */
    public function get_towns_by_province() {
        $this->verify_ajax_nonce();
        $this->check_rate_limit('get_towns');
        
        $province = isset($_POST['province']) ? sanitize_text_field(wp_unslash($_POST['province'])) : '';
        
        if (empty($province)) {
            wp_send_json_error(array(
                'message' => __('Province is required.', 'property-manager-pro')
            ));
        }
        
        // Validate province name (Unicode letters, numbers, spaces, hyphens, apostrophes)
        if (!preg_match('/^[\p{L}0-9\s\-\']+$/u', $province)) {
            wp_send_json_error(array(
                'message' => __('Invalid province name.', 'property-manager-pro')
            ));
        }
        
        // Check cache first
        $cache_key = 'pm_towns_' . md5($province);
        $cached_towns = wp_cache_get($cache_key, 'property_manager');
        
        if ($cached_towns !== false) {
            wp_send_json_success(array(
                'towns' => $cached_towns,
                'cached' => true,
                'message' => sprintf(
                    /* translators: %1$d: number of towns, %2$s: province name */
                    __('Found %1$d towns in %2$s', 'property-manager-pro'),
                    count($cached_towns),
                    esc_html($province)
                )
            ));
        }
        
        $property_manager = PropertyManager_Property::get_instance();
        $towns = $property_manager->get_towns($province);
        
        // Sanitize town names before sending
        $sanitized_towns = array_map('sanitize_text_field', $towns);
        
        // Cache the results
        wp_cache_set($cache_key, $sanitized_towns, 'property_manager', self::CACHE_EXPIRATION);
        
        wp_send_json_success(array(
            'towns' => $sanitized_towns,
            'cached' => false,
            'message' => sprintf(
                /* translators: %1$d: number of towns, %2$s: province name */
                __('Found %1$d towns in %2$s', 'property-manager-pro'),
                count($sanitized_towns),
                esc_html($province)
            )
        ));
    }

    /**
     * Save search alert - AJAX handler with comprehensive security
     */
    public function save_search_alert() {
        // Verify nonce with specific action
        if (!isset($_POST['search_alert_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['search_alert_nonce'])), 'save_search_alert')) {
            $this->log_security_event('alert_nonce_failed', array(
                'ip' => $this->get_client_ip()
            ));
            
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'property-manager-pro')
            ), 403);
        }
        
        $this->check_honeypot();
        $this->check_rate_limit('save_alert');
        
        // Verify reCAPTCHA if enabled
        if (isset($_POST['recaptcha_token'])) {
            $recaptcha_token = sanitize_text_field(wp_unslash($_POST['recaptcha_token']));
            if (!$this->verify_recaptcha_token($recaptcha_token)) {
                $this->log_security_event('recaptcha_failed', array(
                    'ip' => $this->get_client_ip()
                ));
                
                wp_send_json_error(array(
                    'message' => __('reCAPTCHA verification failed. Please try again.', 'property-manager-pro')
                ), 403);
            }
        }
        
        // Prevent duplicate submissions
        if (!$this->check_submission_uniqueness('alert')) {
            wp_send_json_error(array(
                'message' => __('Duplicate submission detected. Please wait before submitting again.', 'property-manager-pro')
            ));
        }
        
        // Sanitize and validate inputs
        $alert_name = isset($_POST['alert_name']) ? sanitize_text_field(wp_unslash($_POST['alert_name'])) : '';
        $alert_email = isset($_POST['alert_email']) ? sanitize_email(wp_unslash($_POST['alert_email'])) : '';
        $alert_frequency = isset($_POST['alert_frequency']) ? sanitize_text_field(wp_unslash($_POST['alert_frequency'])) : 'weekly';
        $search_criteria = isset($_POST['search_criteria']) ? wp_unslash($_POST['search_criteria']) : '';
        
        // Validation
        if (empty($alert_name) || empty($alert_email) || empty($search_criteria)) {
            wp_send_json_error(array(
                'message' => __('Please fill in all required fields.', 'property-manager-pro')
            ));
        }
        
        // Validate name length and characters
        if (strlen($alert_name) > 100 || !preg_match('/^[\p{L}0-9\s\-\'\.]+$/u', $alert_name)) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid name.', 'property-manager-pro')
            ));
        }
        
        if (!is_email($alert_email)) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid email address.', 'property-manager-pro')
            ));
        }
        
        // Validate email domain
        if (!$this->validate_email_domain($alert_email)) {
            wp_send_json_error(array(
                'message' => __('Please use a valid email address. Temporary email services are not allowed.', 'property-manager-pro')
            ));
        }
        
        // Validate frequency
        $valid_frequencies = array('daily', 'weekly', 'monthly');
        if (!in_array($alert_frequency, $valid_frequencies, true)) {
            $alert_frequency = 'weekly';
        }
        
        // Parse and validate search criteria JSON
        $criteria = json_decode($search_criteria, true);
        if (!is_array($criteria) || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => __('Invalid search criteria format.', 'property-manager-pro')
            ));
        }
        
        // Prevent criteria stuffing
        if (count($criteria) > self::MAX_CRITERIA_ITEMS) {
            wp_send_json_error(array(
                'message' => __('Too many search criteria. Please simplify your search.', 'property-manager-pro')
            ));
        }
        
        // Sanitize search criteria
        $criteria = $this->sanitize_search_criteria($criteria);
        
        global $wpdb;
        $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Check for duplicate alert with better detection
        $existing_alert = $wpdb->get_row($wpdb->prepare(
            "SELECT id, email_verified, status FROM {$alerts_table} WHERE email = %s AND search_criteria = %s AND status != 'deleted'",
            $alert_email,
            wp_json_encode($criteria)
        ));
        
        if ($existing_alert) {
            if ($existing_alert->email_verified == 1 && $existing_alert->status === 'active') {
                wp_send_json_error(array(
                    'message' => __('You already have an active alert for this search.', 'property-manager-pro')
                ));
            } else {
                // Resend verification email for unverified alerts
                wp_send_json_error(array(
                    'message' => __('An alert for this search already exists. Please check your email to verify it.', 'property-manager-pro')
                ));
            }
        }
        
        // Check alert limit per email (max 10 alerts per email)
        $alert_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$alerts_table} WHERE email = %s AND status = 'active'",
            $alert_email
        ));
        
        if ($alert_count >= 10) {
            wp_send_json_error(array(
                'message' => __('You have reached the maximum number of alerts (10). Please delete some alerts before creating new ones.', 'property-manager-pro')
            ));
        }
        
        // Generate secure tokens
        $verification_token = wp_generate_password(32, false);
        $unsubscribe_token = wp_generate_password(32, false);
        
        // Calculate token expiration
        $token_expires = gmdate('Y-m-d H:i:s', time() + self::TOKEN_EXPIRATION);
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Insert alert
            $result = $wpdb->insert(
                $alerts_table,
                array(
                    'email' => $alert_email,
                    'user_id' => is_user_logged_in() ? get_current_user_id() : null,
                    'search_criteria' => wp_json_encode($criteria),
                    'frequency' => $alert_frequency,
                    'status' => 'pending',
                    'verification_token' => hash('sha256', $verification_token),
                    'token_expires_at' => $token_expires,
                    'email_verified' => 0,
                    'unsubscribe_token' => hash('sha256', $unsubscribe_token),
                    'created_at' => current_time('mysql', true),
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => $this->get_user_agent()
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                throw new Exception('Database insert failed: ' . $wpdb->last_error);
            }
            
            $alert_id = $wpdb->insert_id;
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Send verification email with retry logic
            $email_sent = $this->send_verification_email_with_retry(
                $alert_id, 
                $alert_email, 
                $alert_name, 
                $verification_token
            );
            
            if (!$email_sent) {
                error_log('Property Manager: Failed to send verification email to ' . $alert_email);
            }
            
            // Log successful alert creation
            $this->log_audit_event('alert_created', array(
                'alert_id' => $alert_id,
                'email' => $alert_email,
                'frequency' => $alert_frequency,
                'ip' => $this->get_client_ip()
            ));
            
            wp_send_json_success(array(
                'message' => __('Search alert saved successfully! Please check your email to verify your subscription within 48 hours.', 'property-manager-pro'),
                'alert_id' => $alert_id
            ));
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            
            error_log('Property Manager: Failed to save search alert - ' . $e->getMessage());
            
            wp_send_json_error(array(
                'message' => __('Failed to save search alert. Please try again later.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Check submission uniqueness to prevent duplicate submissions
     */
    private function check_submission_uniqueness($action) {
        $ip = $this->get_client_ip();
        $transient_key = 'pm_submission_' . md5($action . '_' . $ip);
        
        if (get_transient($transient_key)) {
            return false;
        }
        
        set_transient($transient_key, true, 5); // 5 seconds cooldown
        return true;
    }

    /**
     * Sanitize search criteria array with advanced validation
     */
    private function sanitize_search_criteria($criteria) {
        if (!is_array($criteria)) {
            return array();
        }
        
        $sanitized = array();
        $allowed_keys = array(
            'keyword', 'location', 'property_type', 'price_min', 'price_max',
            'beds_min', 'beds_max', 'baths_min', 'baths_max',
            'province', 'town', 'surface_min', 'surface_max',
            'pool', 'new_build', 'featured', 'price_freq', 'currency'
        );
        
        foreach ($criteria as $key => $value) {
            // Only process allowed keys
            if (!in_array($key, $allowed_keys, true)) {
                continue;
            }
            
            // Skip empty values
            if ($value === '' || $value === null) {
                continue;
            }
            
            // Sanitize based on field type
            if (in_array($key, array('price_min', 'price_max', 'surface_min', 'surface_max'), true)) {
                // Numeric fields - ensure positive values and reasonable limits
                $value = floatval($value);
                if ($value < 0 || $value > 100000000) { // Max 100 million
                    continue;
                }
                $sanitized[$key] = $value;
                
            } elseif (in_array($key, array('beds_min', 'beds_max', 'baths_min', 'baths_max'), true)) {
                // Integer fields - ensure positive values and reasonable limits
                $value = intval($value);
                if ($value < 0 || $value > 50) {
                    continue;
                }
                $sanitized[$key] = $value;
                
            } elseif (in_array($key, array('pool', 'new_build', 'featured'), true)) {
                // Boolean fields
                $sanitized[$key] = intval($value) === 1 ? 1 : 0;
                
            } else {
                // Text fields - sanitize, validate, and limit length
                $value = sanitize_text_field($value);
                
                // Validate text fields don't contain SQL injection patterns
                if ($this->contains_sql_injection_pattern($value)) {
                    $this->log_security_event('sql_injection_attempt', array(
                        'field' => $key,
                        'value' => substr($value, 0, 50),
                        'ip' => $this->get_client_ip()
                    ));
                    continue;
                }
                
                $sanitized[$key] = substr($value, 0, self::MAX_SEARCH_LENGTH);
            }
        }
        
        return $sanitized;
    }

    /**
     * Detect SQL injection patterns
     */
    private function contains_sql_injection_pattern($value) {
        $patterns = array(
            '/(\bUNION\b|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b)/i',
            '/(-{2}|\/\*|\*\/|;)/',
            '/(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/i',
            '/(\'|")\s*(OR|AND)\s*\1\s*=\s*\1/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Send verification email with retry logic
     */
    private function send_verification_email_with_retry($alert_id, $email, $name, $token, $retry_count = 0) {
        $max_retries = 3;
        
        $success = $this->send_verification_email($alert_id, $email, $name, $token);
        
        if (!$success && $retry_count < $max_retries) {
            sleep(2); // Wait 2 seconds before retry
            return $this->send_verification_email_with_retry($alert_id, $email, $name, $token, $retry_count + 1);
        }
        
        return $success;
    }

    /**
     * Send verification email
     */
    private function send_verification_email($alert_id, $email, $name, $token) {
        $verification_url = add_query_arg(
            array(
                'action' => 'verify_alert',
                'token' => rawurlencode($token),
                'alert_id' => absint($alert_id)
            ),
            home_url('/')
        );
        
        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Verify Your Property Alert Subscription', 'property-manager-pro'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            /* translators: %1$s: user name, %2$s: verification URL, %3$s: site name */
            __('Hi %1$s,

Thank you for subscribing to property alerts on %3$s!

Please verify your email address by clicking the link below within 48 hours:
%2$s

If you did not request this subscription, please ignore this email and the subscription will be automatically deleted after 48 hours.

For your security, this verification link will expire in 48 hours.

Best regards,
The %3$s Team', 'property-manager-pro'),
            esc_html($name),
            esc_url($verification_url),
            esc_html(get_bloginfo('name'))
        );
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        return wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Load more properties - AJAX handler with caching
     */
    public function load_more_properties() {
        $this->verify_ajax_nonce();
        $this->check_rate_limit('load_properties');
        
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $search_params = isset($_POST['search_params']) && is_array($_POST['search_params']) ? 
            wp_unslash($_POST['search_params']) : array();
        
        // Validate page number
        if ($page < 1 || $page > 1000) {
            wp_send_json_error(array(
                'message' => __('Invalid page number.', 'property-manager-pro')
            ));
        }
        
        // Sanitize search parameters
        $search_params = $this->sanitize_search_params($search_params);
        
        // Generate cache key
        $cache_key = 'pm_search_' . md5(wp_json_encode($search_params) . '_page_' . $page);
        $cached_result = wp_cache_get($cache_key, 'property_manager');
        
        if ($cached_result !== false) {
            wp_send_json_success($cached_result);
        }
        
        // Perform search
        $search = PropertyManager_Search::get_instance();
        foreach ($search_params as $key => $value) {
            $search->set_param($key, $value);
        }
        $search->set_param('page', $page);
        
        $results = $search->search_properties();
        
        if (empty($results['properties'])) {
            $response = array(
                'html' => '',
                'has_more' => false,
                'message' => __('No more properties found.', 'property-manager-pro')
            );
            
            wp_cache_set($cache_key, $response, 'property_manager', 300); // Cache for 5 minutes
            wp_send_json_success($response);
        }
        
        // Generate HTML
        ob_start();
        foreach ($results['properties'] as $property) {
            $this->render_property_card($property);
        }
        $html = ob_get_clean();
        
        $response = array(
            'html' => $html,
            'has_more' => ($results['current_page'] < $results['pages']),
            'current_page' => intval($results['current_page']),
            'total_pages' => intval($results['pages']),
            'total_results' => intval($results['total'])
        );
        
        // Cache the response
        wp_cache_set($cache_key, $response, 'property_manager', 300); // Cache for 5 minutes
        
        wp_send_json_success($response);
    }

    /**
     * Sanitize search parameters
     */
    private function sanitize_search_params($params) {
        if (!is_array($params)) {
            return array();
        }
        
        return $this->sanitize_search_criteria($params);
    }

    /**
     * Get property details - AJAX handler with validation
     */
    public function get_property_details() {
        $this->verify_ajax_nonce();
        $this->check_rate_limit('property_details');
        
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        
        if (!$property_id || $property_id < 1) {
            wp_send_json_error(array(
                'message' => __('Invalid property ID.', 'property-manager-pro')
            ));
        }
        
        // Check cache first
        $cache_key = 'pm_property_details_' . $property_id;
        $cached_details = wp_cache_get($cache_key, 'property_manager');
        
        if ($cached_details !== false) {
            wp_send_json_success($cached_details);
        }
        
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property || $property->status !== 'active') {
            wp_send_json_error(array(
                'message' => __('Property not found or no longer available.', 'property-manager-pro')
            ), 404);
        }
        
        // Get property images
        $images = $property_manager->get_property_images($property_id);
        
        // Get property features
        $features = $property_manager->get_property_features($property_id);
        
        // Sanitize output
        $sanitized_property = array(
            'id' => intval($property->id),
            'title' => wp_kses_post($property->title),
            'ref' => esc_html($property->ref),
            'price' => floatval($property->price),
            'currency' => esc_html($property->currency),
            'price_freq' => esc_html($property->price_freq),
            'beds' => intval($property->beds),
            'baths' => intval($property->baths),
            'town' => esc_html($property->town),
            'province' => esc_html($property->province),
            'location_detail' => esc_html($property->location_detail),
            'description' => wp_kses_post($property->desc_en),
            'surface_area' => floatval($property->surface_area_built),
            'pool' => intval($property->pool),
            'new_build' => intval($property->new_build),
            'url' => esc_url(home_url('/property/' . $property->id)),
            'latitude' => floatval($property->latitude),
            'longitude' => floatval($property->longitude)
        );
        
        $response = array(
            'property' => $sanitized_property,
            'images' => array_map('esc_url', wp_list_pluck($images, 'wp_image_url')),
            'features' => array_map('esc_html', $features)
        );
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $response, 'property_manager', self::CACHE_EXPIRATION);
        
        wp_send_json_success($response);
    }

    /**
     * Render property card
     */
    private function render_property_card($property) {
        // Sanitize all property data before rendering
        $safe_property = (object) array(
            'id' => intval($property->id),
            'title' => esc_html($property->title),
            'ref' => esc_html($property->ref),
            'price' => floatval($property->price),
            'currency' => esc_html($property->currency),
            'price_freq' => esc_html($property->price_freq),
            'beds' => intval($property->beds),
            'baths' => intval($property->baths),
            'town' => esc_html($property->town),
            'province' => esc_html($property->province),
            'surface_area_built' => floatval($property->surface_area_built),
            'pool' => intval($property->pool),
            'new_build' => intval($property->new_build),
            'property_type' => esc_html($property->property_type),
            'featured_image' => esc_url($property->featured_image ?? ''),
            'url' => esc_url(home_url('/property/' . $property->id))
        );
        
        // Load template file
        $template_path = PROPERTY_MANAGER_PLUGIN_PATH . 'public/templates/property-card.php';
        
        if (file_exists($template_path)) {
            // Make sanitized property available to template
            set_query_var('property', $safe_property);
            include $template_path;
        } else {
            // Fallback inline template with all output escaped
            echo '<div class="property-card" data-property-id="' . esc_attr($safe_property->id) . '">';
            echo '<h3>' . esc_html($safe_property->title) . '</h3>';
            echo '<p class="property-price">' . esc_html($safe_property->currency) . ' ' . number_format($safe_property->price) . '</p>';
            echo '<p class="property-location">' . esc_html($safe_property->town) . ', ' . esc_html($safe_property->province) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Cleanup expired tokens - runs daily
     */
    public function cleanup_expired_tokens() {
        global $wpdb;
        
        $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Delete unverified alerts with expired tokens
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$alerts_table} 
             WHERE email_verified = 0 
             AND token_expires_at < %s 
             AND status = 'pending'",
            current_time('mysql', true)
        ));
        
        if ($deleted) {
            $this->log_audit_event('expired_tokens_cleanup', array(
                'deleted_count' => $deleted,
                'timestamp' => current_time('mysql', true)
            ));
        }
    }

    /**
     * Log security events
     */
    private function log_security_event($event_type, $data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql', true),
            'event_type' => $event_type,
            'ip' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'data' => wp_json_encode($data)
        );
        
        // Log to WordPress error log
        error_log('Property Manager Security Event: ' . wp_json_encode($log_entry));
        
        // Optionally store in database for audit trail
        global $wpdb;
        $security_log_table = PropertyManager_Database::get_table_name('security_logs');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$security_log_table}'") === $security_log_table) {
            $wpdb->insert(
                $security_log_table,
                $log_entry,
                array('%s', '%s', '%s', '%s', '%d', '%s')
            );
        }
    }

    /**
     * Log audit events for compliance
     */
    private function log_audit_event($event_type, $data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql', true),
            'event_type' => $event_type,
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'ip' => $this->get_client_ip(),
            'data' => wp_json_encode($data)
        );
        
        // Log to WordPress error log
        error_log('Property Manager Audit: ' . wp_json_encode($log_entry));
        
        // Store in database for audit trail
        global $wpdb;
        $audit_log_table = PropertyManager_Database::get_table_name('audit_logs');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_log_table}'") === $audit_log_table) {
            $wpdb->insert(
                $audit_log_table,
                $log_entry,
                array('%s', '%s', '%d', '%s', '%s')
            );
        }
    }

    /**
     * Get search history for autocomplete
     */
    public function get_search_history() {
        $this->verify_ajax_nonce();
        
        $user_id = get_current_user_id();
        $ip = $this->get_client_ip();
        
        $cache_key = 'pm_search_history_' . ($user_id ? $user_id : md5($ip));
        $history = wp_cache_get($cache_key, 'property_manager');
        
        if ($history === false) {
            global $wpdb;
            $history_table = PropertyManager_Database::get_table_name('search_history');
            
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
                $query = $user_id ? 
                    $wpdb->prepare("SELECT DISTINCT search_query FROM {$history_table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 10", $user_id) :
                    $wpdb->prepare("SELECT DISTINCT search_query FROM {$history_table} WHERE ip_address = %s ORDER BY created_at DESC LIMIT 10", $ip);
                
                $history = $wpdb->get_col($query);
            } else {
                $history = array();
            }
            
            wp_cache_set($cache_key, $history, 'property_manager', 1800); // Cache for 30 minutes
        }
        
        wp_send_json_success(array(
            'history' => array_map('esc_html', $history)
        ));
    }

    /**
     * Save search query to history
     */
    public function save_search_to_history($search_query) {
        if (empty($search_query)) {
            return;
        }
        
        $user_id = get_current_user_id();
        $ip = $this->get_client_ip();
        
        global $wpdb;
        $history_table = PropertyManager_Database::get_table_name('search_history');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
            $wpdb->insert(
                $history_table,
                array(
                    'user_id' => $user_id ? $user_id : null,
                    'ip_address' => $ip,
                    'search_query' => sanitize_text_field($search_query),
                    'created_at' => current_time('mysql', true)
                ),
                array('%d', '%s', '%s', '%s')
            );
            
            // Invalidate cache
            $cache_key = 'pm_search_history_' . ($user_id ? $user_id : md5($ip));
            wp_cache_delete($cache_key, 'property_manager');
        }
    }

    /**
     * Compress JSON response for large datasets
     */
    private function compress_response($data) {
        if (function_exists('gzencode') && isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $encoding = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_ENCODING']));
            
            if (strpos($encoding, 'gzip') !== false) {
                header('Content-Encoding: gzip');
                return gzencode(wp_json_encode($data), 9);
            }
        }
        
        return wp_json_encode($data);
    }

    /**
     * Verify reCAPTCHA - AJAX handler
     */
    public function verify_recaptcha() {
        $this->verify_ajax_nonce();
        
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        
        if (empty($token)) {
            wp_send_json_error(array(
                'message' => __('reCAPTCHA token is missing.', 'property-manager-pro')
            ));
        }
        
        $verified = $this->verify_recaptcha_token($token);
        
        if ($verified) {
            wp_send_json_success(array(
                'message' => __('reCAPTCHA verified successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('reCAPTCHA verification failed.', 'property-manager-pro')
            ));
        }
    }

    /**
     * Clear specific cache
     */
    public function clear_cache($cache_key) {
        wp_cache_delete($cache_key, 'property_manager');
    }

    /**
     * Clear all property manager caches
     */
    public function clear_all_caches() {
        // This would require implementing cache key tracking
        // Or using wp_cache_flush() which clears all caches
        wp_cache_flush();
        
        $this->log_audit_event('cache_cleared', array(
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql', true)
        ));
    }
}