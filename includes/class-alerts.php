<?php
/**
 * Property Alerts Management Class - FIXED VERSION
 * 
 * @package PropertyManagerPro
 * @version 1.0.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Alerts {
    
    private static $instance = null;
    
    // Token expiration (48 hours)
    private const TOKEN_EXPIRATION = 172800; // 48 hours in seconds
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook alert processing to cron jobs
        add_action('property_manager_daily_alerts', array($this, 'process_daily_alerts'));
        add_action('property_manager_weekly_alerts', array($this, 'process_weekly_alerts'));
        add_action('property_manager_monthly_alerts', array($this, 'process_monthly_alerts'));
        
        // Hook verification and unsubscribe handlers
        add_action('init', array($this, 'handle_verification'));
        add_action('init', array($this, 'handle_unsubscribe'));
        
        // Cleanup expired tokens daily
        add_action('property_manager_daily_cleanup', array($this, 'cleanup_expired_tokens'));
        
        // AJAX handlers
        add_action('wp_ajax_property_create_alert', array($this, 'ajax_create_alert'));
        add_action('wp_ajax_nopriv_property_create_alert', array($this, 'ajax_create_alert'));
        add_action('wp_ajax_property_manage_alert', array($this, 'ajax_manage_alert'));
        add_action('wp_ajax_property_delete_alert', array($this, 'ajax_delete_alert'));
    }
    
    /**
     * Create a new property alert
     */
    public function create_alert($email, $search_criteria, $frequency = 'weekly', $user_id = null) {
        global $wpdb;
        
        // Validate email
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Invalid email address.', 'property-manager-pro'));
        }
        
        // Validate frequency
        $valid_frequencies = array('daily', 'weekly', 'monthly');
        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'weekly';
        }
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Check if alert already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE email = %s AND search_criteria = %s AND status != 'unsubscribed'",
            $email,
            wp_json_encode($search_criteria)
        ));
        
        if ($existing) {
            return new WP_Error('alert_exists', __('An alert with these criteria already exists for this email address.', 'property-manager-pro'));
        }
        
        // Generate tokens
        $verification_token = wp_generate_password(32, false);
        $unsubscribe_token = wp_generate_password(32, false);
        
        // Calculate token expiration
        $token_expires = gmdate('Y-m-d H:i:s', time() + self::TOKEN_EXPIRATION);
        
        // Get options
        $options = get_option('property_manager_options', array());
        $email_verification_required = isset($options['email_verification_required']) ? $options['email_verification_required'] : true;
        
        // Prepare data
        $data = array(
            'email' => $email,
            'user_id' => $user_id,
            'search_criteria' => wp_json_encode($search_criteria),
            'frequency' => $frequency,
            'status' => $email_verification_required ? 'pending' : 'active',
            'verification_token' => $verification_token,
            'token_expires_at' => $token_expires,
            'email_verified' => $email_verification_required ? 0 : 1,
            'unsubscribe_token' => $unsubscribe_token,
            'created_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return new WP_Error('database_error', __('Failed to create alert.', 'property-manager-pro'));
        }
        
        $alert_id = $wpdb->insert_id;
        
        // Send verification email if required
        if ($email_verification_required) {
            $this->send_verification_email($alert_id, $email, $verification_token);
        }
        
        return $alert_id;
    }
    
    /**
     * Verify email address
     * FIXED: Added token expiration check
     */
    public function verify_email($token) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE verification_token = %s AND email_verified = 0",
            $token
        ));
        
        if (!$alert) {
            return false;
        }
        
        // FIXED: Check token expiration
        if ($alert->token_expires_at && strtotime($alert->token_expires_at) < time()) {
            // Token expired - delete the alert
            $wpdb->delete($table, array('id' => $alert->id));
            return false;
        }
        
        // Update alert as verified and activate
        $result = $wpdb->update(
            $table,
            array(
                'email_verified' => 1,
                'status' => 'active',
                'verification_token' => null,
                'token_expires_at' => null,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $alert->id)
        );
        
        return $result !== false;
    }
    
    /**
     * Unsubscribe from alerts
     * FIXED: Better validation
     */
    public function unsubscribe($token) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE unsubscribe_token = %s AND status != 'unsubscribed'",
            $token
        ));
        
        if (!$alert) {
            return false;
        }
        
        // Update alert as unsubscribed
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'unsubscribed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $alert->id)
        );
        
        return $result !== false;
    }
    
    /**
     * Process daily alerts
     */
    public function process_daily_alerts() {
        $this->process_alerts('daily');
    }
    
    /**
     * Process weekly alerts
     */
    public function process_weekly_alerts() {
        $this->process_alerts('weekly');
    }
    
    /**
     * Process monthly alerts
     */
    public function process_monthly_alerts() {
        $this->process_alerts('monthly');
    }
    
    /**
     * Process alerts for a specific frequency
     * FIXED: COMPLETE IMPLEMENTATION with property matching!
     */
    private function process_alerts($frequency) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Calculate the date threshold
        $date_threshold = $this->get_date_threshold($frequency);
        
        // Get active, verified alerts that haven't been sent recently
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE frequency = %s 
             AND status = 'active' 
             AND email_verified = 1
             AND (last_sent IS NULL OR last_sent < %s)",
            $frequency,
            $date_threshold
        ));
        
        if (empty($alerts)) {
            return;
        }
        
        $property_search = PropertyManager_Search::get_instance();
        $email_manager = PropertyManager_Email::get_instance();
        
        foreach ($alerts as $alert) {
            try {
                // Parse search criteria
                $criteria = json_decode($alert->search_criteria, true);
                
                if (!is_array($criteria)) {
                    error_log('Property Manager Pro: Invalid search criteria for alert ID ' . $alert->id);
                    continue;
                }
                
                // FIXED: Search for properties matching the criteria
                $search_args = $this->build_search_args($criteria, $date_threshold);
                $search_results = $property_search->search($search_args);
                
                // Check if there are new properties
                if (empty($search_results['properties'])) {
                    // Update last_sent even if no properties found
                    $this->update_alert_last_sent($alert->id);
                    continue;
                }
                
                // Send email with properties
                $email_sent = $email_manager->send_property_alert(
                    $alert->email,
                    $search_results['properties'],
                    $frequency,
                    $alert->unsubscribe_token
                );
                
                if ($email_sent) {
                    // Update last_sent timestamp
                    $this->update_alert_last_sent($alert->id);
                } else {
                    error_log('Property Manager Pro: Failed to send alert email to ' . $alert->email);
                }
                
            } catch (Exception $e) {
                error_log('Property Manager Pro: Error processing alert ID ' . $alert->id . ' - ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Build search arguments from criteria
     * NEW METHOD: Converts alert criteria to search parameters
     */
    private function build_search_args($criteria, $date_threshold) {
        $search_args = array(
            'per_page' => 20, // Limit to 20 properties per alert
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        // Only show properties created since last check
        $search_args['created_after'] = $date_threshold;
        
        // Map criteria to search args
        $mapping = array(
            'property_type' => 'property_type',
            'town' => 'town',
            'province' => 'province',
            'price_min' => 'price_min',
            'price_max' => 'price_max',
            'beds_min' => 'beds_min',
            'beds_max' => 'beds_max',
            'baths_min' => 'baths_min',
            'baths_max' => 'baths_max',
            'pool' => 'pool',
            'new_build' => 'new_build',
            'price_freq' => 'price_freq'
        );
        
        foreach ($mapping as $criteria_key => $search_key) {
            if (isset($criteria[$criteria_key]) && $criteria[$criteria_key] !== '') {
                $search_args[$search_key] = $criteria[$criteria_key];
            }
        }
        
        return $search_args;
    }
    
    /**
     * Get date threshold based on frequency
     */
    private function get_date_threshold($frequency) {
        switch ($frequency) {
            case 'daily':
                return gmdate('Y-m-d H:i:s', strtotime('-1 day'));
            case 'weekly':
                return gmdate('Y-m-d H:i:s', strtotime('-7 days'));
            case 'monthly':
                return gmdate('Y-m-d H:i:s', strtotime('-30 days'));
            default:
                return gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        }
    }
    
    /**
     * Update alert last sent timestamp
     */
    private function update_alert_last_sent($alert_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->update(
            $table,
            array('last_sent' => current_time('mysql')),
            array('id' => $alert_id)
        );
    }
    
    /**
     * Cleanup expired verification tokens
     * NEW METHOD: Removes unverified alerts with expired tokens
     */
    public function cleanup_expired_tokens() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Delete unverified alerts with expired tokens
        $deleted = $wpdb->query("
            DELETE FROM {$table}
            WHERE email_verified = 0
            AND token_expires_at IS NOT NULL
            AND token_expires_at < NOW()
        ");
    }
    
    /**
     * Send verification email
     */
    private function send_verification_email($alert_id, $email, $token) {
        $verification_url = add_query_arg(array(
            'action' => 'verify_alert',
            'token' => $token
        ), home_url());
        
        $subject = sprintf(
            __('[%s] Verify Your Property Alert', 'property-manager-pro'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            __('Please verify your property alert subscription by clicking this link: %s

This link will expire in 48 hours.', 'property-manager-pro'),
            $verification_url
        );
        
        $email_manager = PropertyManager_Email::get_instance();
        return $email_manager->send_email($email, $subject, $message);
    }
    
    /**
     * Get alert by ID
     */
    public function get_alert($alert_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $alert_id
        ));
    }
    
    /**
     * Update alert
     */
    public function update_alert($alert_id, $data) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $data['updated_at'] = current_time('mysql');
        
        return $wpdb->update($table, $data, array('id' => $alert_id));
    }
    
    /**
     * Delete alert
     */
    public function delete_alert($alert_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->delete($table, array('id' => $alert_id));
    }
    
    /**
     * Get user alerts
     */
    public function get_user_alerts($user_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status != 'unsubscribed' ORDER BY created_at DESC",
            $user_id
        ));
    }
    
    /**
     * Handle verification requests
     */
    public function handle_verification() {
        if (isset($_GET['action']) && $_GET['action'] === 'verify_alert' && isset($_GET['token'])) {
            $token = sanitize_text_field($_GET['token']);
            
            if ($this->verify_email($token)) {
                wp_redirect(add_query_arg('alert_verified', '1', home_url()));
            } else {
                wp_redirect(add_query_arg('alert_verification_failed', '1', home_url()));
            }
            exit;
        }
    }
    
    /**
     * Handle unsubscribe requests
     */
    public function handle_unsubscribe() {
        if (isset($_GET['action']) && $_GET['action'] === 'unsubscribe_alert' && isset($_GET['token'])) {
            $token = sanitize_text_field($_GET['token']);
            
            if ($this->unsubscribe($token)) {
                wp_redirect(add_query_arg('alert_unsubscribed', '1', home_url()));
            } else {
                wp_redirect(add_query_arg('alert_unsubscribe_failed', '1', home_url()));
            }
            exit;
        }
    }
}