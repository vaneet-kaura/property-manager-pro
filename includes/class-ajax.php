<?php
/**
 * General AJAX Handlers - Production Ready with All Security & Performance Enhancements
 * Handles favorites, alerts management, inquiries, and user actions
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
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
    private const RATE_LIMIT_STRICT = 10; // For sensitive operations
    
    // Cache constants
    private const CACHE_EXPIRATION = 3600;
    
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
        add_action('wp_ajax_get_user_favorites', array($this, 'ajax_get_favorites'));
        add_action('wp_ajax_clear_all_favorites', array($this, 'ajax_clear_favorites'));
        
        // Alerts Management
        add_action('wp_ajax_property_manage_alert', array($this, 'ajax_manage_alert'));
        add_action('wp_ajax_property_delete_alert', array($this, 'ajax_delete_alert'));
        add_action('wp_ajax_property_get_user_alerts', array($this, 'ajax_get_user_alerts'));
        
        // Property Inquiries
        add_action('wp_ajax_submit_property_inquiry', array($this, 'ajax_submit_inquiry'));
        add_action('wp_ajax_nopriv_submit_property_inquiry', array($this, 'ajax_submit_inquiry'));
        
        // Saved Searches
        add_action('wp_ajax_property_save_search', array($this, 'ajax_save_search'));
        add_action('wp_ajax_property_delete_search', array($this, 'ajax_delete_search'));
        add_action('wp_ajax_property_get_saved_searches', array($this, 'ajax_get_saved_searches'));
        
        // User Actions
        add_action('wp_ajax_track_property_view', array($this, 'ajax_track_view'));
        add_action('wp_ajax_nopriv_track_property_view', array($this, 'ajax_track_view'));
        add_action('wp_ajax_get_last_viewed', array($this, 'ajax_get_last_viewed'));
        
        // Admin Actions
        add_action('wp_ajax_update_inquiry_status', array($this, 'ajax_update_inquiry_status'));
        add_action('wp_ajax_bulk_delete_properties', array($this, 'ajax_bulk_delete_properties'));
        
        // Add security headers
        add_action('send_headers', array($this, 'add_security_headers'));
    }

    /**
     * Add security headers for AJAX requests
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
     * Verify nonce and capabilities
     */
    private function verify_nonce($nonce_action = 'property_manager_nonce') {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $nonce_action)) {
            $this->log_security_event('nonce_failed', array(
                'action' => $nonce_action,
                'ip' => $this->get_client_ip()
            ));
            
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'property-manager-pro')
            ), 403);
        }
    }

    /**
     * Check rate limiting
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
                'message' => __('Too many requests. Please try again in a minute.', 'property-manager-pro')
            ), 429);
        }
        
        set_transient($transient_key, ($request_count ? $request_count + 1 : 1), self::RATE_LIMIT_WINDOW);
    }

    /**
     * Get client IP safely
     */
    private function get_client_ip() {
        $ip = '0.0.0.0';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']), FILTER_VALIDATE_IP)) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }
        
        $headers = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
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

    // ==================== FAVORITES ====================

    /**
     * Toggle favorite - AJAX handler for logged-in users
     */
    public function ajax_toggle_favorite() {
        $this->verify_nonce();
        $this->check_rate_limit('toggle_favorite');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to save favorites.', 'property-manager-pro'),
                'login_required' => true
            ), 401);
        }
        
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        
        if (!$property_id || $property_id < 1) {
            wp_send_json_error(array(
                'message' => __('Invalid property ID.', 'property-manager-pro')
            ), 400);
        }
        
        // Verify property exists and is active
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property || $property->status !== 'active') {
            wp_send_json_error(array(
                'message' => __('Property not found or no longer available.', 'property-manager-pro')
            ), 404);
        }
        
        $user_id = get_current_user_id();
        
        // Check favorites limit
        $favorites_count = $this->get_favorites_count($user_id);
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        // Check if already favorited
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND property_id = %d",
            $user_id,
            $property_id
        ));
        
        if ($existing) {
            // Remove from favorites
            $result = $wpdb->delete(
                $table,
                array('user_id' => $user_id, 'property_id' => $property_id),
                array('%d', '%d')
            );
            
            if ($result !== false) {
                // Clear cache
                $this->clear_favorites_cache($user_id);
                
                $this->log_audit_event('favorite_removed', array(
                    'user_id' => $user_id,
                    'property_id' => $property_id
                ));
                
                wp_send_json_success(array(
                    'action' => 'removed',
                    'is_favorite' => false,
                    'message' => __('Property removed from favorites.', 'property-manager-pro')
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Failed to remove from favorites. Please try again.', 'property-manager-pro')
                ), 500);
            }
        } else {
            // Check limit before adding
            if ($favorites_count >= self::MAX_FAVORITES) {
                wp_send_json_error(array(
                    'message' => sprintf(
                        /* translators: %d: maximum number of favorites */
                        __('You have reached the maximum number of favorites (%d). Please remove some favorites before adding new ones.', 'property-manager-pro'),
                        self::MAX_FAVORITES
                    )
                ), 400);
            }
            
            // Add to favorites
            $result = $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'property_id' => $property_id,
                    'created_at' => current_time('mysql', true)
                ),
                array('%d', '%d', '%s')
            );
            
            if ($result !== false) {
                // Clear cache
                $this->clear_favorites_cache($user_id);
                
                $this->log_audit_event('favorite_added', array(
                    'user_id' => $user_id,
                    'property_id' => $property_id
                ));
                
                wp_send_json_success(array(
                    'action' => 'added',
                    'is_favorite' => true,
                    'message' => __('Property added to favorites.', 'property-manager-pro')
                ));
            } else {
                error_log('Property Manager: Failed to add favorite - ' . $wpdb->last_error);
                wp_send_json_error(array(
                    'message' => __('Failed to add to favorites. Please try again.', 'property-manager-pro')
                ), 500);
            }
        }
    }

    /**
     * Toggle favorite for guest users (redirect to login)
     */
    public function ajax_toggle_favorite_guest() {
        wp_send_json_error(array(
            'message' => __('Please log in to save favorites.', 'property-manager-pro'),
            'login_required' => true,
            'login_url' => wp_login_url(get_permalink())
        ), 401);
    }

    /**
     * Get user favorites count
     */
    private function get_favorites_count($user_id) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get user favorites - AJAX handler
     */
    public function ajax_get_favorites() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to view favorites.', 'property-manager-pro')
            ), 401);
        }
        
        $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, min(50, absint($_POST['per_page']))) : 12;
        $orderby = isset($_POST['orderby']) ? sanitize_text_field(wp_unslash($_POST['orderby'])) : 'created_at';
        $order = isset($_POST['order']) ? sanitize_text_field(wp_unslash($_POST['order'])) : 'DESC';
        
        // Validate orderby
        $allowed_orderby = array('created_at', 'price', 'title', 'beds', 'baths');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'created_at';
        }
        
        // Validate order
        $order = strtoupper($order);
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }
        
        $user_id = get_current_user_id();
        
        // Check cache
        $cache_key = 'pm_favorites_' . $user_id . '_' . $page . '_' . $per_page . '_' . $orderby . '_' . $order;
        $cached_result = wp_cache_get($cache_key, 'property_manager');
        
        if ($cached_result !== false) {
            wp_send_json_success($cached_result);
        }
        
        global $wpdb;
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$favorites_table} f 
             INNER JOIN {$properties_table} p ON f.property_id = p.id 
             WHERE f.user_id = %d AND p.status = 'active'",
            $user_id
        ));
        
        // Get properties
        $properties = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, f.created_at as favorited_at 
             FROM {$favorites_table} f 
             INNER JOIN {$properties_table} p ON f.property_id = p.id 
             WHERE f.user_id = %d AND p.status = 'active'
             ORDER BY p.{$orderby} {$order}
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));
        
        // Get images for each property
        $property_manager = PropertyManager_Property::get_instance();
        foreach ($properties as &$property) {
            $images = $property_manager->get_property_images($property->id);
            $property->featured_image = !empty($images) ? esc_url($images[0]->wp_image_url) : '';
        }
        
        $result = array(
            'properties' => $properties,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        );
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $result, 'property_manager', 300);
        
        wp_send_json_success($result);
    }

    /**
     * Clear all favorites - AJAX handler
     */
    public function ajax_clear_favorites() {
        $this->verify_nonce();
        $this->check_rate_limit('clear_favorites', true);
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to manage favorites.', 'property-manager-pro')
            ), 401);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        $result = $wpdb->delete($table, array('user_id' => $user_id), array('%d'));
        
        if ($result !== false) {
            $this->clear_favorites_cache($user_id);
            
            $this->log_audit_event('favorites_cleared', array(
                'user_id' => $user_id,
                'count' => $result
            ));
            
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %d: number of favorites removed */
                    __('%d favorites removed successfully.', 'property-manager-pro'),
                    $result
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to clear favorites. Please try again.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Clear favorites cache
     */
    private function clear_favorites_cache($user_id) {
        // Clear all favorites cache for this user
        wp_cache_delete('pm_favorites_' . $user_id . '*', 'property_manager');
    }

    // ==================== ALERTS MANAGEMENT ====================

    /**
     * Manage alert (pause/resume) - AJAX handler
     */
    public function ajax_manage_alert() {
        $this->verify_nonce();
        $this->check_rate_limit('manage_alert');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to manage alerts.', 'property-manager-pro')
            ), 401);
        }
        
        $alert_id = isset($_POST['alert_id']) ? absint($_POST['alert_id']) : 0;
        $action = isset($_POST['alert_action']) ? sanitize_text_field(wp_unslash($_POST['alert_action'])) : '';
        
        if (!$alert_id || !in_array($action, array('pause', 'resume'), true)) {
            wp_send_json_error(array(
                'message' => __('Invalid request parameters.', 'property-manager-pro')
            ), 400);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Verify user owns this alert
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, status, email_verified FROM {$table} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert || $alert->user_id != $user_id) {
            $this->log_security_event('alert_access_denied', array(
                'user_id' => $user_id,
                'alert_id' => $alert_id
            ));
            
            wp_send_json_error(array(
                'message' => __('Alert not found or access denied.', 'property-manager-pro')
            ), 403);
        }
        
        if ($alert->email_verified != 1) {
            wp_send_json_error(array(
                'message' => __('Please verify your email before managing this alert.', 'property-manager-pro')
            ), 400);
        }
        
        $new_status = ($action === 'pause') ? 'paused' : 'active';
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => $new_status,
                'updated_at' => current_time('mysql', true)
            ),
            array('id' => $alert_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->log_audit_event('alert_' . $action . 'd', array(
                'user_id' => $user_id,
                'alert_id' => $alert_id
            ));
            
            wp_send_json_success(array(
                'message' => ($action === 'pause') ? 
                    __('Alert paused successfully.', 'property-manager-pro') : 
                    __('Alert resumed successfully.', 'property-manager-pro'),
                'new_status' => $new_status
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to update alert. Please try again.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Delete alert - AJAX handler
     */
    public function ajax_delete_alert() {
        $this->verify_nonce();
        $this->check_rate_limit('delete_alert', true);
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to delete alerts.', 'property-manager-pro')
            ), 401);
        }
        
        $alert_id = isset($_POST['alert_id']) ? absint($_POST['alert_id']) : 0;
        
        if (!$alert_id) {
            wp_send_json_error(array(
                'message' => __('Invalid alert ID.', 'property-manager-pro')
            ), 400);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Verify user owns this alert
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM {$table} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert || $alert->user_id != $user_id) {
            $this->log_security_event('alert_delete_denied', array(
                'user_id' => $user_id,
                'alert_id' => $alert_id
            ));
            
            wp_send_json_error(array(
                'message' => __('Alert not found or access denied.', 'property-manager-pro')
            ), 403);
        }
        
        // Soft delete
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'deleted',
                'updated_at' => current_time('mysql', true)
            ),
            array('id' => $alert_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->log_audit_event('alert_deleted', array(
                'user_id' => $user_id,
                'alert_id' => $alert_id
            ));
            
            wp_send_json_success(array(
                'message' => __('Alert deleted successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to delete alert. Please try again.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Get user alerts - AJAX handler
     */
    public function ajax_get_user_alerts() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to view alerts.', 'property-manager-pro')
            ), 401);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, search_criteria, frequency, status, email_verified, last_sent, created_at 
             FROM {$table} 
             WHERE user_id = %d AND status != 'deleted'
             ORDER BY created_at DESC",
            $user_id
        ));
        
        // Parse search criteria for each alert
        foreach ($alerts as &$alert) {
            $alert->search_criteria = json_decode($alert->search_criteria, true);
        }
        
        wp_send_json_success(array(
            'alerts' => $alerts,
            'total' => count($alerts)
        ));
    }

    // ==================== PROPERTY INQUIRIES ====================

    /**
     * Submit property inquiry - AJAX handler
     */
    public function ajax_submit_inquiry() {
        $this->verify_nonce();
        $this->check_rate_limit('submit_inquiry', true);
        
        // Check honeypot
        if (!empty($_POST['website']) || !empty($_POST['url_field'])) {
            $this->log_security_event('inquiry_honeypot', array(
                'ip' => $this->get_client_ip()
            ));
            
            // Silent success for bots
            wp_send_json_success(array(
                'message' => __('Thank you for your inquiry. We will contact you soon.', 'property-manager-pro')
            ));
        }
        
        // Check daily limit
        if (!$this->check_inquiry_limit()) {
            wp_send_json_error(array(
                'message' => __('You have reached the maximum number of inquiries for today. Please try again tomorrow.', 'property-manager-pro')
            ), 429);
        }
        
        // Validate and sanitize inputs
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        
        // Validation
        $errors = array();
        
        if (!$property_id || $property_id < 1) {
            $errors[] = __('Invalid property ID.', 'property-manager-pro');
        }
        
        if (empty($name) || strlen($name) < 2 || strlen($name) > 100) {
            $errors[] = __('Please enter a valid name (2-100 characters).', 'property-manager-pro');
        }
        
        if (!preg_match('/^[\p{L}0-9\s\-\'\.]+$/u', $name)) {
            $errors[] = __('Name contains invalid characters.', 'property-manager-pro');
        }
        
        if (!is_email($email)) {
            $errors[] = __('Please enter a valid email address.', 'property-manager-pro');
        }
        
        if (!empty($phone) && !preg_match('/^[\d\s\-\+\(\)]+$/', $phone)) {
            $errors[] = __('Please enter a valid phone number.', 'property-manager-pro');
        }
        
        if (empty($message) || strlen($message) < 10 || strlen($message) > 2000) {
            $errors[] = __('Please enter a message (10-2000 characters).', 'property-manager-pro');
        }
        
        if (!empty($errors)) {
            wp_send_json_error(array(
                'message' => implode(' ', $errors)
            ), 400);
        }
        
        // Verify property exists
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property || $property->status !== 'active') {
            wp_send_json_error(array(
                'message' => __('Property not found or no longer available.', 'property-manager-pro')
            ), 404);
        }
        
        // Insert inquiry
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $result = $wpdb->insert(
            $table,
            array(
                'property_id' => $property_id,
                'user_id' => is_user_logged_in() ? get_current_user_id() : null,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'status' => 'new',
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $this->get_user_agent(),
                'created_at' => current_time('mysql', true)
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('Property Manager: Failed to save inquiry - ' . $wpdb->last_error);
            wp_send_json_error(array(
                'message' => __('Failed to submit inquiry. Please try again later.', 'property-manager-pro')
            ), 500);
        }
        
        $inquiry_id = $wpdb->insert_id;
        
        // Send notification email to admin
        $this->send_inquiry_notification($inquiry_id, $property, $name, $email, $phone, $message);
        
        // Update inquiry limit counter
        $this->increment_inquiry_counter();
        
        // Log inquiry
        $this->log_audit_event('inquiry_submitted', array(
            'inquiry_id' => $inquiry_id,
            'property_id' => $property_id,
            'email' => $email
        ));
        
        wp_send_json_success(array(
            'message' => __('Thank you for your inquiry. We will contact you soon.', 'property-manager-pro'),
            'inquiry_id' => $inquiry_id
        ));
    }

    /**
     * Check inquiry limit per IP/user
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
     * Increment inquiry counter
     */
    private function increment_inquiry_counter() {
        $ip = $this->get_client_ip();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $identifier = $user_id ? 'user_' . $user_id : 'ip_' . md5($ip);
        
        $transient_key = 'pm_inquiry_limit_' . $identifier;
        $count = get_transient($transient_key);
        
        set_transient($transient_key, ($count ? $count + 1 : 1), DAY_IN_SECONDS);
    }

    /**
     * Send inquiry notification email
     */
    private function send_inquiry_notification($inquiry_id, $property, $name, $email, $phone, $message) {
        $options = get_option('property_manager_options', array());
        $admin_email = !empty($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        $subject = sprintf(
            /* translators: %1$s: property reference, %2$s: site name */
            __('[%2$s] New Property Inquiry - %1$s', 'property-manager-pro'),
            esc_html($property->ref),
            get_bloginfo('name')
        );
        
        $property_url = home_url('/property/' . $property->id);
        
        $email_message = sprintf(
            /* translators: Property inquiry email template */
            __('A new inquiry has been received for property: %1$s

Property Details:
- Reference: %2$s
- Title: %3$s
- URL: %4$s

Inquiry Details:
- Name: %5$s
- Email: %6$s
- Phone: %7$s
- Message: %8$s

View inquiry in admin: %9$s', 'property-manager-pro'),
            esc_html($property->title),
            esc_html($property->ref),
            esc_html($property->title),
            esc_url($property_url),
            esc_html($name),
            esc_html($email),
            esc_html($phone),
            esc_html($message),
            esc_url(admin_url('admin.php?page=property-manager-inquiries&inquiry_id=' . $inquiry_id))
        );
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>'
        );
        
        wp_mail($admin_email, $subject, $email_message, $headers);
    }

    // ==================== SAVED SEARCHES ====================

    /**
     * Save search - AJAX handler
     */
    public function ajax_save_search() {
        $this->verify_nonce();
        $this->check_rate_limit('save_search');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to save searches.', 'property-manager-pro')
            ), 401);
        }
        
        $search_name = isset($_POST['search_name']) ? sanitize_text_field(wp_unslash($_POST['search_name'])) : '';
        $search_criteria = isset($_POST['search_criteria']) ? wp_unslash($_POST['search_criteria']) : '';
        $enable_alerts = isset($_POST['enable_alerts']) ? absint($_POST['enable_alerts']) : 0;
        $alert_frequency = isset($_POST['alert_frequency']) ? sanitize_text_field(wp_unslash($_POST['alert_frequency'])) : 'weekly';
        
        // Validation
        if (empty($search_name) || strlen($search_name) < 2 || strlen($search_name) > 100) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid search name (2-100 characters).', 'property-manager-pro')
            ), 400);
        }
        
        if (empty($search_criteria)) {
            wp_send_json_error(array(
                'message' => __('Search criteria is required.', 'property-manager-pro')
            ), 400);
        }
        
        // Parse and validate search criteria
        $criteria = json_decode($search_criteria, true);
        if (!is_array($criteria) || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => __('Invalid search criteria format.', 'property-manager-pro')
            ), 400);
        }
        
        // Sanitize criteria (reuse from search class)
        $criteria = $this->sanitize_search_criteria($criteria);
        
        $user_id = get_current_user_id();
        
        // Check if search with same name exists
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND search_name = %s",
            $user_id,
            $search_name
        ));
        
        if ($existing) {
            wp_send_json_error(array(
                'message' => __('A search with this name already exists. Please choose a different name.', 'property-manager-pro')
            ), 400);
        }
        
        // Check saved searches limit (max 20 per user)
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));
        
        if ($count >= 20) {
            wp_send_json_error(array(
                'message' => __('You have reached the maximum number of saved searches (20). Please delete some searches before saving new ones.', 'property-manager-pro')
            ), 400);
        }
        
        // Insert saved search
        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'search_name' => $search_name,
                'search_criteria' => wp_json_encode($criteria),
                'alert_enabled' => $enable_alerts,
                'alert_frequency' => $alert_frequency,
                'created_at' => current_time('mysql', true)
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('Property Manager: Failed to save search - ' . $wpdb->last_error);
            wp_send_json_error(array(
                'message' => __('Failed to save search. Please try again later.', 'property-manager-pro')
            ), 500);
        }
        
        $search_id = $wpdb->insert_id;
        
        // If alerts enabled, create alert
        if ($enable_alerts) {
            $user = wp_get_current_user();
            $alerts_manager = PropertyManager_Alerts::get_instance();
            $alerts_manager->create_alert($user->user_email, $criteria, $alert_frequency, $user_id);
        }
        
        $this->log_audit_event('search_saved', array(
            'user_id' => $user_id,
            'search_id' => $search_id,
            'search_name' => $search_name
        ));
        
        wp_send_json_success(array(
            'message' => __('Search saved successfully!', 'property-manager-pro'),
            'search_id' => $search_id
        ));
    }

    /**
     * Delete saved search - AJAX handler
     */
    public function ajax_delete_search() {
        $this->verify_nonce();
        $this->check_rate_limit('delete_search', true);
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to delete searches.', 'property-manager-pro')
            ), 401);
        }
        
        $search_id = isset($_POST['search_id']) ? absint($_POST['search_id']) : 0;
        
        if (!$search_id) {
            wp_send_json_error(array(
                'message' => __('Invalid search ID.', 'property-manager-pro')
            ), 400);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        // Verify user owns this search
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $search_id,
            $user_id
        ));
        
        if (!$search) {
            $this->log_security_event('search_delete_denied', array(
                'user_id' => $user_id,
                'search_id' => $search_id
            ));
            
            wp_send_json_error(array(
                'message' => __('Search not found or access denied.', 'property-manager-pro')
            ), 403);
        }
        
        $result = $wpdb->delete($table, array('id' => $search_id), array('%d'));
        
        if ($result !== false) {
            $this->log_audit_event('search_deleted', array(
                'user_id' => $user_id,
                'search_id' => $search_id
            ));
            
            wp_send_json_success(array(
                'message' => __('Search deleted successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to delete search. Please try again.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Get saved searches - AJAX handler
     */
    public function ajax_get_saved_searches() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please log in to view saved searches.', 'property-manager-pro')
            ), 401);
        }
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT id, search_name, search_criteria, alert_enabled, alert_frequency, created_at 
             FROM {$table} 
             WHERE user_id = %d 
             ORDER BY created_at DESC",
            $user_id
        ));
        
        // Parse search criteria
        foreach ($searches as &$search) {
            $search->search_criteria = json_decode($search->search_criteria, true);
        }
        
        wp_send_json_success(array(
            'searches' => $searches,
            'total' => count($searches)
        ));
    }

    /**
     * Sanitize search criteria (reused from search class)
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
            if (!in_array($key, $allowed_keys, true) || $value === '' || $value === null) {
                continue;
            }
            
            if (in_array($key, array('price_min', 'price_max', 'surface_min', 'surface_max'), true)) {
                $value = floatval($value);
                if ($value >= 0 && $value <= 100000000) {
                    $sanitized[$key] = $value;
                }
            } elseif (in_array($key, array('beds_min', 'beds_max', 'baths_min', 'baths_max'), true)) {
                $value = intval($value);
                if ($value >= 0 && $value <= 50) {
                    $sanitized[$key] = $value;
                }
            } elseif (in_array($key, array('pool', 'new_build', 'featured'), true)) {
                $sanitized[$key] = intval($value) === 1 ? 1 : 0;
            } else {
                $sanitized[$key] = substr(sanitize_text_field($value), 0, 200);
            }
        }
        
        return $sanitized;
    }

    // ==================== PROPERTY VIEW TRACKING ====================

    /**
     * Track property view - AJAX handler
     */
    public function ajax_track_view() {
        $this->verify_nonce();
        $this->check_rate_limit('track_view');
        
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        
        if (!$property_id || $property_id < 1) {
            wp_send_json_error(array(
                'message' => __('Invalid property ID.', 'property-manager-pro')
            ), 400);
        }
        
        // Verify property exists
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property || $property->status !== 'active') {
            wp_send_json_error(array(
                'message' => __('Property not found.', 'property-manager-pro')
            ), 404);
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_views');
        
        // Check if view already tracked in last hour
        $recent_view = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} 
             WHERE property_id = %d 
             AND ip_address = %s 
             AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $property_id,
            $this->get_client_ip()
        ));
        
        if ($recent_view) {
            // Don't track duplicate views within 1 hour
            wp_send_json_success(array(
                'message' => __('View already tracked.', 'property-manager-pro'),
                'tracked' => false
            ));
        }
        
        // Insert view record
        $result = $wpdb->insert(
            $table,
            array(
                'property_id' => $property_id,
                'user_id' => is_user_logged_in() ? get_current_user_id() : null,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $this->get_user_agent(),
                'viewed_at' => current_time('mysql', true)
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
        
        if ($result !== false) {
            // Update property view count
            $wpdb->query($wpdb->prepare(
                "UPDATE " . PropertyManager_Database::get_table_name('properties') . " 
                 SET view_count = view_count + 1 
                 WHERE id = %d",
                $property_id
            ));
            
            wp_send_json_success(array(
                'message' => __('View tracked successfully.', 'property-manager-pro'),
                'tracked' => true
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to track view.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Get last viewed properties - AJAX handler
     */
    public function ajax_get_last_viewed() {
        $this->verify_nonce();
        
        $limit = isset($_POST['limit']) ? max(1, min(20, absint($_POST['limit']))) : 10;
        
        global $wpdb;
        $views_table = PropertyManager_Database::get_table_name('property_views');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $properties = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT p.*, MAX(v.viewed_at) as last_viewed 
                 FROM {$views_table} v 
                 INNER JOIN {$properties_table} p ON v.property_id = p.id 
                 WHERE v.user_id = %d AND p.status = 'active'
                 GROUP BY p.id 
                 ORDER BY last_viewed DESC 
                 LIMIT %d",
                $user_id,
                $limit
            ));
        } else {
            $ip = $this->get_client_ip();
            $properties = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT p.*, MAX(v.viewed_at) as last_viewed 
                 FROM {$views_table} v 
                 INNER JOIN {$properties_table} p ON v.property_id = p.id 
                 WHERE v.ip_address = %s AND p.status = 'active'
                 GROUP BY p.id 
                 ORDER BY last_viewed DESC 
                 LIMIT %d",
                $ip,
                $limit
            ));
        }
        
        // Get images for each property
        $property_manager = PropertyManager_Property::get_instance();
        foreach ($properties as &$property) {
            $images = $property_manager->get_property_images($property->id);
            $property->featured_image = !empty($images) ? esc_url($images[0]->wp_image_url) : '';
        }
        
        wp_send_json_success(array(
            'properties' => $properties,
            'total' => count($properties)
        ));
    }

    // ==================== ADMIN ACTIONS ====================

    /**
     * Update inquiry status - AJAX handler (Admin only)
     */
    public function ajax_update_inquiry_status() {
        check_ajax_referer('property_manager_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions.', 'property-manager-pro')
            ), 403);
        }
        
        $inquiry_id = isset($_POST['inquiry_id']) ? absint($_POST['inquiry_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        
        if (!$inquiry_id || !in_array($status, array('new', 'read', 'replied', 'closed'), true)) {
            wp_send_json_error(array(
                'message' => __('Invalid parameters.', 'property-manager-pro')
            ), 400);
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql', true)
            ),
            array('id' => $inquiry_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->log_audit_event('inquiry_status_updated', array(
                'inquiry_id' => $inquiry_id,
                'new_status' => $status,
                'admin_id' => get_current_user_id()
            ));
            
            wp_send_json_success(array(
                'message' => __('Inquiry status updated successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to update inquiry status.', 'property-manager-pro')
            ), 500);
        }
    }

    /**
     * Bulk delete properties - AJAX handler (Admin only)
     */
    public function ajax_bulk_delete_properties() {
        check_ajax_referer('property_manager_bulk', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions.', 'property-manager-pro')
            ), 403);
        }
        
        $property_ids = isset($_POST['property_ids']) && is_array($_POST['property_ids']) ? 
            array_map('absint', wp_unslash($_POST['property_ids'])) : array();
        
        if (empty($property_ids)) {
            wp_send_json_error(array(
                'message' => __('No properties selected.', 'property-manager-pro')
            ), 400);
        }
        
        $deleted_count = 0;
        $failed_count = 0;
        
        foreach ($property_ids as $property_id) {
            $result = PropertyManager_Database::delete_property($property_id);
            if ($result) {
                $deleted_count++;
            } else {
                $failed_count++;
            }
        }
        
        $this->log_audit_event('properties_bulk_deleted', array(
            'deleted_count' => $deleted_count,
            'failed_count' => $failed_count,
            'admin_id' => get_current_user_id()
        ));
        
        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %1$d: deleted count, %2$d: failed count */
                __('%1$d properties deleted successfully. %2$d failed.', 'property-manager-pro'),
                $deleted_count,
                $failed_count
            ),
            'deleted' => $deleted_count,
            'failed' => $failed_count
        ));
    }

    // ==================== LOGGING ====================

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
        
        error_log('Property Manager Security: ' . wp_json_encode($log_entry));
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('security_logs');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->insert($table, $log_entry, array('%s', '%s', '%s', '%s', '%d', '%s'));
        }
    }

    /**
     * Log audit events
     */
    private function log_audit_event($event_type, $data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql', true),
            'event_type' => $event_type,
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'ip' => $this->get_client_ip(),
            'data' => wp_json_encode($data)
        );
        
        error_log('Property Manager Audit: ' . wp_json_encode($log_entry));
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('audit_logs');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->insert($table, $log_entry, array('%s', '%s', '%d', '%s', '%s'));
        }
    }
}