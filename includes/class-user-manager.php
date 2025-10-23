<?php
/**
 * User Manager Class - Property Manager Pro
 * 
 * Handles user registration, authentication, profile management, and GDPR compliance
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_UserManager {
    
    private static $instance = null;
    
    private $enable_user_registration = false;
    
    // Rate limiting settings
    private $rate_limit_attempts = 5;
    private $rate_limit_window = 3600; // 1 hour
    
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
     * Constructor
     */
    private function __construct() {
        // User registration and profile hooks
        add_action('init', array($this, 'init_user_management'));
        add_action('user_register', array($this, 'on_user_register'));
        add_action('wp_login', array($this, 'on_user_login'), 10, 2);
        add_action('wp_logout', array($this, 'on_user_logout'));
        
        // Profile fields
        add_action('show_user_profile', array($this, 'add_profile_fields'));
        add_action('edit_user_profile', array($this, 'add_profile_fields'));
        add_action('personal_options_update', array($this, 'save_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_profile_fields'));
        
        // User capabilities
        add_action('init', array($this, 'add_user_capabilities'));
        
        // Dashboard redirect
        add_filter('login_redirect', array($this, 'login_redirect'), 10, 3);
        
        // AJAX handlers with security
        add_action('wp_ajax_update_user_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_change_user_password', array($this, 'ajax_change_password'));
        add_action('wp_ajax_delete_user_account', array($this, 'ajax_delete_account'));
        add_action('wp_ajax_resend_verification_email', array($this, 'ajax_resend_verification'));
        add_action('wp_ajax_nopriv_verify_email', array($this, 'handle_email_verification'));
        add_action('wp_ajax_verify_email', array($this, 'handle_email_verification'));
        
        // Email verification
        add_action('init', array($this, 'check_email_verification'));
        
        // GDPR compliance
        add_action('wp_ajax_export_user_data', array($this, 'ajax_export_user_data'));
        add_action('wp_ajax_request_data_deletion', array($this, 'ajax_request_data_deletion'));
        
        // Password strength enforcement
        add_action('user_profile_update_errors', array($this, 'validate_password_strength'), 10, 3);
        add_action('validate_password_reset', array($this, 'validate_password_strength_reset'), 10, 2);
        
        // Session security
        add_action('wp_login', array($this, 'regenerate_session_on_login'), 5, 2);
    }
    
    /**
     * Initialize user management
     */
    public function init_user_management() {
        $this->add_user_rewrite_rules();
        $this->cleanup_expired_tokens();
    }
    
    /**
     * Add custom rewrite rules for user pages
     */
    private function add_user_rewrite_rules() {
        add_rewrite_rule(
            'user-dashboard/?$',
            'index.php?property_user_page=dashboard',
            'top'
        );
        
        add_rewrite_rule(
            'user-profile/?$',
            'index.php?property_user_page=profile',
            'top'
        );
        
        add_rewrite_rule(
            'user-favorites/?$',
            'index.php?property_user_page=favorites',
            'top'
        );
        
        add_rewrite_rule(
            'verify-email/([^/]+)/?$',
            'index.php?property_verify_email=$matches[1]',
            'top'
        );
        
        // Add query vars
        add_filter('query_vars', function($vars) {
            $vars[] = 'property_user_page';
            $vars[] = 'property_verify_email';
            return $vars;
        });
        
        // Handle requests
        add_action('template_redirect', array($this, 'handle_user_page_request'));
    }
    
    /**
     * Handle user page requests
     */
    public function handle_user_page_request() {
        $user_page = get_query_var('property_user_page');
        
        if (!$user_page) {
            return;
        }
        
        // Require user to be logged in
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(home_url('/user-dashboard/'));
            wp_safe_redirect($login_url);
            exit;
        }
        
        // Handle different user pages
        switch ($user_page) {
            case 'dashboard':
                $this->display_user_dashboard();
                break;
            case 'profile':
                $this->display_user_profile();
                break;
            case 'favorites':
                $this->display_user_favorites();
                break;
            default:
                wp_safe_redirect(home_url('/user-dashboard/'));
                exit;
        }
    }
    
    /**
     * Handle user registration
     */
    public function on_user_register($user_id) {
        // Validate user ID
        if (!$user_id || $user_id < 1) {
            error_log('Property Manager Pro: Invalid user ID on registration');
            return;
        }
        
        // Set default user meta
        $this->set_default_user_meta($user_id);
        
        // Generate and send email verification
        $this->send_email_verification($user_id);
        
        // Send welcome email
        $this->send_welcome_email($user_id);
    }
    
    /**
     * Handle user login
     */
    public function on_user_login($user_login, $user) {
        // Validate user object
        if (!is_a($user, 'WP_User')) {
            return;
        }
        
        // Update last login time
        update_user_meta($user->ID, 'property_manager_last_login', current_time('mysql'));
        
        // Clean up old session data
        $this->cleanup_user_session_data($user->ID);
        
        // Log successful login
        $this->log_user_activity($user->ID, 'login', array(
            'ip' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent()
        ));
    }
    
    /**
     * Handle user logout
     */
    public function on_user_logout() {
        $user_id = get_current_user_id();
        
        if ($user_id) {
            // Log logout activity
            $this->log_user_activity($user_id, 'logout', array(
                'ip' => $this->get_client_ip()
            ));
            
            // Clean up user transients
            delete_transient('property_manager_user_cache_' . $user_id);
            
            // Clear any rate limiting flags
            delete_transient('property_manager_rate_limit_' . $user_id);
        }
    }
    
    /**
     * Regenerate session on login for security
     */
    public function regenerate_session_on_login($user_login, $user) {
		if (function_exists('session_regenerate_id') && function_exists('session_status')) {
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}
            session_regenerate_id(true);
        }
    }
    
    /**
     * Set default user meta values
     */
    private function set_default_user_meta($user_id) {
        $defaults = array(
            'property_manager_preferences' => array(
                'email_notifications' => true,
                'search_alerts' => true,
                'newsletter' => false,
                'preferred_language' => 'en',
                'results_per_page' => 20,
                'default_view' => 'grid'
            ),
            'property_manager_profile_completion' => 20,
            'property_manager_registration_date' => current_time('mysql'),
            'property_manager_account_status' => 'active',
            'property_manager_email_verified' => false,
            'property_manager_gdpr_consent' => current_time('mysql'),
            'property_manager_privacy_version' => '1.0'
        );
        
        foreach ($defaults as $key => $value) {
            add_user_meta($user_id, $key, $value, true);
        }
    }
    
    /**
     * Send email verification
     */
    private function send_email_verification($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        // Generate verification token
        $token = wp_generate_password(32, false);
        $token_hash = wp_hash($token);
        
        // Store token with expiration (24 hours)
        set_transient(
            'property_manager_email_verify_' . $user_id,
            $token_hash,
            DAY_IN_SECONDS
        );
        
        // Create verification URL
        $verify_url = add_query_arg(array(
            'action' => 'verify_email',
            'user_id' => $user_id,
            'token' => $token
        ), admin_url('admin-ajax.php'));
        
        // Send email
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Verify Your Email Address', 'property-manager-pro'), $site_name);
        
        $message = sprintf(
            __("Hello %s,\n\nThank you for registering at %s.\n\nPlease verify your email address by clicking the link below:\n\n%s\n\nThis link will expire in 24 hours.\n\nIf you did not create this account, please ignore this email.\n\nBest regards,\n%s Team", 'property-manager-pro'),
            $user->display_name,
            $site_name,
            $verify_url,
            $site_name
        );
        
        $result = wp_mail($user->user_email, $subject, $message);
        
        if (!$result) {
            error_log('Property Manager Pro: Failed to send verification email to user ' . $user_id);
        }
        
        return $result;
    }
    
    /**
     * Handle email verification
     */
    public function handle_email_verification() {
        // Check if verification parameters exist
        if (!isset($_GET['user_id']) || !isset($_GET['token'])) {
            wp_die(__('Invalid verification link.', 'property-manager-pro'));
        }
        
        $user_id = absint($_GET['user_id']);
        $token = sanitize_text_field($_GET['token']);
        
        // Get stored token
        $stored_token = get_transient('property_manager_email_verify_' . $user_id);
        
        if (!$stored_token) {
            wp_die(__('Verification link has expired. Please request a new one.', 'property-manager-pro'));
        }
        
        // Verify token
        if (!wp_check_password($token, $stored_token)) {
            wp_die(__('Invalid verification token.', 'property-manager-pro'));
        }
        
        // Mark email as verified
        update_user_meta($user_id, 'property_manager_email_verified', true);
        delete_transient('property_manager_email_verify_' . $user_id);
        
        // Redirect with success message
        $redirect_url = add_query_arg('email_verified', '1', home_url('/user-dashboard/'));
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Check email verification status
     */
    public function check_email_verification() {
        if (isset($_GET['property_verify_email'])) {
            $this->handle_email_verification();
        }
    }
    
    /**
     * Send welcome email
     */
    private function send_welcome_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Welcome to %s', 'property-manager-pro'), $site_name, $site_name);
        
        $dashboard_url = home_url('/user-dashboard/');
        
        $message = sprintf(
            __("Welcome %s!\n\nThank you for registering at %s.\n\nYour account has been created successfully.\n\nYou can now:\n• Save your favorite properties\n• Create property search alerts\n• Save your searches for future use\n• Track your recently viewed properties\n\nGet started by visiting your dashboard: %s\n\nIf you have any questions, please don't hesitate to contact us.\n\nBest regards,\n%s Team", 'property-manager-pro'),
            $user->display_name,
            $site_name,
            $dashboard_url,
            $site_name
        );
        
        $result = wp_mail($user->user_email, $subject, $message);
        
        if (!$result) {
            error_log('Property Manager Pro: Failed to send welcome email to user ' . $user_id);
        }
        
        return $result;
    }
    
    /**
     * Add custom profile fields
     */
    public function add_profile_fields($user) {
        // Security check
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        
        $preferences = get_user_meta($user->ID, 'property_manager_preferences', true);
        if (!is_array($preferences)) {
            $preferences = array();
        }
        
        $phone = get_user_meta($user->ID, 'property_manager_phone', true);
        $location = get_user_meta($user->ID, 'property_manager_location', true);
        $bio = get_user_meta($user->ID, 'property_manager_bio', true);
        $email_verified = get_user_meta($user->ID, 'property_manager_email_verified', true);
        ?>
        
        <h3><?php esc_html_e('Property Manager Settings', 'property-manager-pro'); ?></h3>
        
        <table class="form-table" role="presentation">
            <?php if (!$email_verified): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Email Verification', 'property-manager-pro'); ?></th>
                    <td>
                        <p class="description" style="color: #d63638;">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e('Your email address is not verified. Please check your inbox for the verification link.', 'property-manager-pro'); ?>
                        </p>
                    </td>
                </tr>
            <?php endif; ?>
            
            <tr>
                <th scope="row">
                    <label for="property_manager_phone"><?php esc_html_e('Phone Number', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <input type="tel" 
                           name="property_manager_phone" 
                           id="property_manager_phone" 
                           value="<?php echo esc_attr($phone); ?>" 
                           class="regular-text" 
                           pattern="[0-9+\-\s()]+"
                           maxlength="20" />
                    <p class="description"><?php esc_html_e('Your phone number for property inquiries.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="property_manager_location"><?php esc_html_e('Location', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="property_manager_location" 
                           id="property_manager_location" 
                           value="<?php echo esc_attr($location); ?>" 
                           class="regular-text" 
                           maxlength="100" />
                    <p class="description"><?php esc_html_e('Your preferred location or area of interest.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="property_manager_bio"><?php esc_html_e('Bio', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <textarea name="property_manager_bio" 
                              id="property_manager_bio" 
                              rows="5" 
                              cols="30" 
                              class="large-text"
                              maxlength="500"><?php echo esc_textarea($bio); ?></textarea>
                    <p class="description"><?php esc_html_e('Brief description about yourself or your property requirements (max 500 characters).', 'property-manager-pro'); ?></p>
                </td>
            </tr>
        </table>
        
        <h3><?php esc_html_e('Notification Preferences', 'property-manager-pro'); ?></h3>
        
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Email Notifications', 'property-manager-pro'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('Email Notification Preferences', 'property-manager-pro'); ?></legend>
                        
                        <label for="email_notifications">
                            <input name="property_manager_preferences[email_notifications]" 
                                   type="checkbox" 
                                   id="email_notifications" 
                                   value="1" 
                                   <?php checked(isset($preferences['email_notifications']) ? $preferences['email_notifications'] : false, true); ?> />
                            <?php esc_html_e('Receive email notifications for property updates', 'property-manager-pro'); ?>
                        </label><br>
                        
                        <label for="search_alerts">
                            <input name="property_manager_preferences[search_alerts]" 
                                   type="checkbox" 
                                   id="search_alerts" 
                                   value="1" 
                                   <?php checked(isset($preferences['search_alerts']) ? $preferences['search_alerts'] : false, true); ?> />
                            <?php esc_html_e('Enable search alerts for saved searches', 'property-manager-pro'); ?>
                        </label><br>
                        
                        <label for="newsletter">
                            <input name="property_manager_preferences[newsletter]" 
                                   type="checkbox" 
                                   id="newsletter" 
                                   value="1" 
                                   <?php checked(isset($preferences['newsletter']) ? $preferences['newsletter'] : false, true); ?> />
                            <?php esc_html_e('Subscribe to newsletter', 'property-manager-pro'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="preferred_language"><?php esc_html_e('Preferred Language', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[preferred_language]" id="preferred_language">
                        <option value="en" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'en'); ?>>English</option>
                        <option value="es" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'es'); ?>>Español</option>
                        <option value="de" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'de'); ?>>Deutsch</option>
                        <option value="fr" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'fr'); ?>>Français</option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="results_per_page"><?php esc_html_e('Results Per Page', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[results_per_page]" id="results_per_page">
                        <option value="12" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 12); ?>>12</option>
                        <option value="20" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 20); ?>>20</option>
                        <option value="50" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 50); ?>>50</option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="default_view"><?php esc_html_e('Default View', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[default_view]" id="default_view">
                        <option value="grid" <?php selected(isset($preferences['default_view']) ? $preferences['default_view'] : 'grid', 'grid'); ?>>
                            <?php esc_html_e('Grid View', 'property-manager-pro'); ?>
                        </option>
                        <option value="list" <?php selected(isset($preferences['default_view']) ? $preferences['default_view'] : 'grid', 'list'); ?>>
                            <?php esc_html_e('List View', 'property-manager-pro'); ?>
                        </option>
                    </select>
                </td>
            </tr>
        </table>
        
        <?php wp_nonce_field('update_property_manager_profile_' . $user->ID, 'property_manager_profile_nonce'); ?>
        <?php
    }
    
    /**
     * Save custom profile fields with validation
     */
    public function save_profile_fields($user_id) {
        // Security checks
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        // Verify nonce
        if (!isset($_POST['property_manager_profile_nonce']) || 
            !wp_verify_nonce($_POST['property_manager_profile_nonce'], 'update_property_manager_profile_' . $user_id)) {
            return false;
        }
        
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        
        // Save phone number with validation
        if (isset($_POST['property_manager_phone'])) {
            $phone = sanitize_text_field($_POST['property_manager_phone']);
            
            // Validate phone format (basic validation)
            if (!empty($phone) && !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
                add_action('user_profile_update_errors', function($errors) {
                    $errors->add('invalid_phone', __('Please enter a valid phone number.', 'property-manager-pro'));
                });
                return false;
            }
            
            update_user_meta($user_id, 'property_manager_phone', substr($phone, 0, 20));
        }
        
        // Save location
        if (isset($_POST['property_manager_location'])) {
            $location = sanitize_text_field($_POST['property_manager_location']);
            update_user_meta($user_id, 'property_manager_location', substr($location, 0, 100));
        }
        
        // Save bio
        if (isset($_POST['property_manager_bio'])) {
            $bio = sanitize_textarea_field($_POST['property_manager_bio']);
            update_user_meta($user_id, 'property_manager_bio', substr($bio, 0, 500));
        }
        
        // Save preferences with validation
        if (isset($_POST['property_manager_preferences']) && is_array($_POST['property_manager_preferences'])) {
            $preferences = array();
            $allowed_preferences = array(
                'email_notifications', 'search_alerts', 'newsletter', 
                'preferred_language', 'results_per_page', 'default_view'
            );
            
            foreach ($allowed_preferences as $pref) {
                if (isset($_POST['property_manager_preferences'][$pref])) {
                    $value = $_POST['property_manager_preferences'][$pref];
                    
                    // Handle checkboxes
                    if (in_array($pref, array('email_notifications', 'search_alerts', 'newsletter'))) {
                        $preferences[$pref] = (bool) $value;
                    } 
                    // Validate language
                    elseif ($pref === 'preferred_language') {
                        $allowed_languages = array('en', 'es', 'de', 'fr');
                        $preferences[$pref] = in_array($value, $allowed_languages) ? $value : 'en';
                    }
                    // Validate results per page
                    elseif ($pref === 'results_per_page') {
                        $allowed_values = array(12, 20, 50);
                        $preferences[$pref] = in_array((int)$value, $allowed_values) ? (int)$value : 20;
                    }
                    // Validate default view
                    elseif ($pref === 'default_view') {
                        $allowed_views = array('grid', 'list');
                        $preferences[$pref] = in_array($value, $allowed_views) ? $value : 'grid';
                    }
                    else {
                        $preferences[$pref] = sanitize_text_field($value);
                    }
                } else {
                    // Unchecked checkboxes
                    if (in_array($pref, array('email_notifications', 'search_alerts', 'newsletter'))) {
                        $preferences[$pref] = false;
                    }
                }
            }
            
            update_user_meta($user_id, 'property_manager_preferences', $preferences);
        }
        
        // Update profile completion percentage
        $this->update_profile_completion($user_id);
        
        // Log activity
        $this->log_user_activity($user_id, 'profile_update');
    }
    
    /**
     * Update profile completion percentage
     */
    private function update_profile_completion($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        
        $completion = 0;
        $total_points = 100;
        
        // Basic WordPress fields (40 points)
        if (!empty($user->first_name)) $completion += 10;
        if (!empty($user->last_name)) $completion += 10;
        if (!empty($user->user_email)) $completion += 10;
        if (!empty($user->description)) $completion += 10;
        
        // Custom fields (60 points)
        $phone = get_user_meta($user_id, 'property_manager_phone', true);
        $location = get_user_meta($user_id, 'property_manager_location', true);
        $bio = get_user_meta($user_id, 'property_manager_bio', true);
        $preferences = get_user_meta($user_id, 'property_manager_preferences', true);
        $email_verified = get_user_meta($user_id, 'property_manager_email_verified', true);
        
        if (!empty($phone)) $completion += 10;
        if (!empty($location)) $completion += 10;
        if (!empty($bio)) $completion += 10;
        if (is_array($preferences) && count($preferences) >= 3) $completion += 10;
        if ($email_verified) $completion += 20;
        
        update_user_meta($user_id, 'property_manager_profile_completion', min(100, $completion));
    }
    
    /**
     * Add user capabilities
     */
    public function add_user_capabilities() {
        $role = get_role('subscriber');
        if ($role) {
            $role->add_cap('manage_property_favorites');
            $role->add_cap('manage_property_searches');
            $role->add_cap('manage_property_alerts');
        }
    }
    
    /**
     * Redirect users after login
     */
    public function login_redirect($redirect_to, $request, $user) {
        if (is_a($user, 'WP_User') && $user->has_cap('manage_property_favorites')) {
            if ($redirect_to == admin_url()) {
                $pages = get_option('property_manager_pages', array());
                if (isset($pages['user_dashboard'])) {
                    return get_permalink($pages['user_dashboard']);
                }
                return home_url('/user-dashboard/');
            }
        }
        
        return $redirect_to;
    }
    
    	/**
     * AJAX: Update user profile
     */
    public function ajax_update_profile() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('profile_update', $user_id)) {
            wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
        }
        
        // Get and validate data
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
        $bio = isset($_POST['bio']) ? sanitize_textarea_field($_POST['bio']) : '';
        
        // Validate phone
        if (!empty($phone) && !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
            wp_send_json_error(array('message' => __('Invalid phone number format.', 'property-manager-pro')));
        }
        
        // Update user data
        $result = wp_update_user(array(
            'ID' => $user_id,
            'first_name' => substr($first_name, 0, 50),
            'last_name' => substr($last_name, 0, 50)
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update custom fields
        update_user_meta($user_id, 'property_manager_phone', substr($phone, 0, 20));
        update_user_meta($user_id, 'property_manager_location', substr($location, 0, 100));
        update_user_meta($user_id, 'property_manager_bio', substr($bio, 0, 500));
        
        // Update profile completion
        $this->update_profile_completion($user_id);
        
        // Log activity
        $this->log_user_activity($user_id, 'profile_update_ajax');
        
        wp_send_json_success(array(
            'message' => __('Profile updated successfully.', 'property-manager-pro'),
            'completion' => get_user_meta($user_id, 'property_manager_profile_completion', true)
        ));
    }
    
    /**
     * AJAX: Change user password
     */
    public function ajax_change_password() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('password_change', $user_id)) {
            wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
        }
        
        // Get and validate data
        $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
        $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            wp_send_json_error(array('message' => __('All fields are required.', 'property-manager-pro')));
        }
        
        // Verify current password
        $user = get_userdata($user_id);
        if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
            wp_send_json_error(array('message' => __('Current password is incorrect.', 'property-manager-pro')));
        }
        
        // Check if passwords match
        if ($new_password !== $confirm_password) {
            wp_send_json_error(array('message' => __('New passwords do not match.', 'property-manager-pro')));
        }
        
        // Validate password strength
        $strength_check = $this->validate_password_strength_check($new_password);
        if (is_wp_error($strength_check)) {
            wp_send_json_error(array('message' => $strength_check->get_error_message()));
        }
        
        // Update password
        wp_set_password($new_password, $user_id);
        
        // Log activity
        $this->log_user_activity($user_id, 'password_changed');
        
        // Send notification email
        $this->send_password_changed_notification($user_id);
        
        wp_send_json_success(array('message' => __('Password changed successfully.', 'property-manager-pro')));
    }
    
    /**
     * AJAX: Delete user account
     */
    public function ajax_delete_account() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('account_deletion', $user_id)) {
            wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
        }
        
        // Verify password
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $user = get_userdata($user_id);
        
        if (!wp_check_password($password, $user->user_pass, $user_id)) {
            wp_send_json_error(array('message' => __('Incorrect password.', 'property-manager-pro')));
        }
        
        // Prevent admin deletion
        if (user_can($user_id, 'manage_options')) {
            wp_send_json_error(array('message' => __('Administrator accounts cannot be deleted.', 'property-manager-pro')));
        }
        
        // Delete user data
        $this->delete_user_data($user_id);
        
        // Delete user account
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $result = wp_delete_user($user_id);
        
        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to delete account.', 'property-manager-pro')));
        }
        
        // Log out user
        wp_logout();
        
        wp_send_json_success(array(
            'message' => __('Account deleted successfully.', 'property-manager-pro'),
            'redirect' => home_url()
        ));
    }
    
    /**
     * AJAX: Resend verification email
     */
    public function ajax_resend_verification() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('verification_email', $user_id)) {
            wp_send_json_error(array('message' => __('Please wait before requesting another verification email.', 'property-manager-pro')));
        }
        
        // Check if already verified
        if (get_user_meta($user_id, 'property_manager_email_verified', true)) {
            wp_send_json_error(array('message' => __('Your email is already verified.', 'property-manager-pro')));
        }
        
        // Send verification email
        $result = $this->send_email_verification($user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Verification email sent. Please check your inbox.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send verification email.', 'property-manager-pro')));
        }
    }
    
    /**
     * AJAX: Export user data (GDPR)
     */
    public function ajax_export_user_data() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('data_export', $user_id)) {
            wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
        }
        
        // Collect user data
        $user = get_userdata($user_id);
        $data = array(
            'personal_information' => array(
                'username' => $user->user_login,
                'email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'registration_date' => $user->user_registered,
                'phone' => get_user_meta($user_id, 'property_manager_phone', true),
                'location' => get_user_meta($user_id, 'property_manager_location', true),
                'bio' => get_user_meta($user_id, 'property_manager_bio', true)
            ),
            'preferences' => get_user_meta($user_id, 'property_manager_preferences', true),
            'favorites' => $this->get_user_favorites_export($user_id),
            'alerts' => $this->get_user_alerts_export($user_id),
            'saved_searches' => $this->get_user_searches_export($user_id),
            'activity_log' => $this->get_user_activity_export($user_id)
        );
        
        wp_send_json_success(array(
            'message' => __('Data export ready.', 'property-manager-pro'),
            'data' => $data,
            'filename' => 'user-data-' . $user_id . '-' . date('Y-m-d') . '.json'
        ));
    }
    
    /**
     * AJAX: Request data deletion (GDPR)
     */
    public function ajax_request_data_deletion() {
        // Security checks
        check_ajax_referer('property_manager_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'property-manager-pro')));
        }
        
        $user_id = get_current_user_id();
        
        // Rate limiting
        if (!$this->check_rate_limit('data_deletion', $user_id)) {
            wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
        }
        
        // Mark account for deletion
        update_user_meta($user_id, 'property_manager_deletion_requested', current_time('mysql'));
        
        // Send confirmation email
        $this->send_deletion_request_email($user_id);
        
        wp_send_json_success(array('message' => __('Data deletion request received. Your account will be deleted within 30 days.', 'property-manager-pro')));
    }
    
    /**
     * Validate password strength
     */
    public function validate_password_strength($errors, $update, $user) {
        if (!empty($_POST['pass1'])) {
            $password = $_POST['pass1'];
            $check = $this->validate_password_strength_check($password);
            
            if (is_wp_error($check)) {
                $errors->add('weak_password', $check->get_error_message());
            }
        }
    }
    
    /**
     * Validate password strength for reset
     */
    public function validate_password_strength_reset($errors, $user) {
        if (!empty($_POST['pass1'])) {
            $password = $_POST['pass1'];
            $check = $this->validate_password_strength_check($password);
            
            if (is_wp_error($check)) {
                $errors->add('weak_password', $check->get_error_message());
            }
        }
    }
    
    /**
     * Check password strength
     */
    private function validate_password_strength_check($password) {
        // Minimum length
        if (strlen($password) < 8) {
            return new WP_Error('weak_password', __('Password must be at least 8 characters long.', 'property-manager-pro'));
        }
        
        // Must contain at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return new WP_Error('weak_password', __('Password must contain at least one number.', 'property-manager-pro'));
        }
        
        // Must contain at least one uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            return new WP_Error('weak_password', __('Password must contain at least one uppercase letter.', 'property-manager-pro'));
        }
        
        // Must contain at least one lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            return new WP_Error('weak_password', __('Password must contain at least one lowercase letter.', 'property-manager-pro'));
        }
        
        return true;
    }
    
    /**
     * Check rate limit
     */
    private function check_rate_limit($action, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $key = 'property_manager_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            set_transient($key, 1, $this->rate_limit_window);
            return true;
        }
        
        if ($attempts >= $this->rate_limit_attempts) {
            return false;
        }
        
        set_transient($key, $attempts + 1, $this->rate_limit_window);
        return true;
    }
    
    /**
     * Log user activity
     */
    private function log_user_activity($user_id, $action, $metadata = array()) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('user_activity');
        
        $wpdb->insert($table, array(
            'user_id' => $user_id,
            'action' => sanitize_text_field($action),
            'metadata' => maybe_serialize($metadata),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'created_at' => current_time('mysql')
        ), array('%d', '%s', '%s', '%s', '%s', '%s'));
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
    
    /**
     * Get user agent
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
    }
    
    /**
     * Cleanup user session data
     */
    private function cleanup_user_session_data($user_id) {
        // Delete old transients
        delete_transient('property_manager_user_cache_' . $user_id);
        delete_transient('property_manager_search_cache_' . $user_id);
        
        // Clean up old activity logs (keep last 100 entries)
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_activity');
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table 
             WHERE user_id = %d 
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM $table 
                     WHERE user_id = %d 
                     ORDER BY created_at DESC 
                     LIMIT 100
                 ) AS keep_records
             )",
            $user_id,
            $user_id
        ));
    }
    
    /**
     * Cleanup expired tokens
     */
    private function cleanup_expired_tokens() {
        // This runs on init, only clean up once per day
        $last_cleanup = get_option('property_manager_token_cleanup_last');
        
        if ($last_cleanup && (time() - $last_cleanup) < DAY_IN_SECONDS) {
            return;
        }
        
        // Clean up expired verification tokens
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE '_transient_property_manager_email_verify_%'"
        );
        
        foreach ($results as $result) {
            $transient_key = str_replace('_transient_', '', $result->option_name);
            if (get_transient($transient_key) === false) {
                delete_option($result->option_name);
                delete_option('_transient_timeout_' . $transient_key);
            }
        }
        
        update_option('property_manager_token_cleanup_last', time());
    }
    
    /**
     * Delete user data (GDPR)
     */
    private function delete_user_data($user_id) {
        global $wpdb;
        
        // Delete favorites
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $wpdb->delete($favorites_table, array('user_id' => $user_id), array('%d'));
        
        // Delete alerts
        $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
        $wpdb->delete($alerts_table, array('user_id' => $user_id), array('%d'));
        
        // Delete saved searches
        $searches_table = PropertyManager_Database::get_table_name('saved_searches');
        $wpdb->delete($searches_table, array('user_id' => $user_id), array('%d'));
        
        // Delete activity logs
        $activity_table = PropertyManager_Database::get_table_name('user_activity');
        $wpdb->delete($activity_table, array('user_id' => $user_id), array('%d'));
        
        // Delete property views
        $views_table = PropertyManager_Database::get_table_name('property_views');
        $wpdb->delete($views_table, array('user_id' => $user_id), array('%d'));
        
        // Delete all user meta
        delete_user_meta($user_id, 'property_manager_preferences');
        delete_user_meta($user_id, 'property_manager_phone');
        delete_user_meta($user_id, 'property_manager_location');
        delete_user_meta($user_id, 'property_manager_bio');
        delete_user_meta($user_id, 'property_manager_profile_completion');
        delete_user_meta($user_id, 'property_manager_email_verified');
        delete_user_meta($user_id, 'property_manager_last_login');
        
        // Delete transients
        delete_transient('property_manager_email_verify_' . $user_id);
        delete_transient('property_manager_user_cache_' . $user_id);
    }
    
    /**
     * Get user favorites for export
     */
    private function get_user_favorites_export($user_id) {
        $favorites_manager = PropertyManager_Favorites::get_instance();
        $favorites = $favorites_manager->get_user_favorites($user_id);
        
        $export = array();
        if (!empty($favorites['properties'])) {
            foreach ($favorites['properties'] as $property) {
                $export[] = array(
                    'property_id' => $property->id,
                    'title' => $property->title,
                    'added_date' => $property->favorited_at
                );
            }
        }
        
        return $export;
    }
    
    /**
     * Get user alerts for export
     */
    private function get_user_alerts_export($user_id) {
        $alerts_manager = PropertyManager_Alerts::get_instance();
        $alerts = $alerts_manager->get_user_alerts($user_id);
        
        $export = array();
        foreach ($alerts as $alert) {
            $export[] = array(
                'name' => $alert->alert_name,
                'frequency' => $alert->frequency,
                'status' => $alert->status,
                'created_date' => $alert->created_at
            );
        }
        
        return $export;
    }
    
    /**
     * Get user saved searches for export
     */
    private function get_user_searches_export($user_id) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('saved_searches');
        
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        $export = array();
        foreach ($searches as $search) {
            $export[] = array(
                'name' => $search->search_name,
                'criteria' => maybe_unserialize($search->search_criteria),
                'created_date' => $search->created_at
            );
        }
        
        return $export;
    }
    
    /**
     * Get user activity for export
     */
    private function get_user_activity_export($user_id) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_activity');
        
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT 100",
            $user_id
        ));
        
        $export = array();
        foreach ($activities as $activity) {
            $export[] = array(
                'action' => $activity->action,
                'date' => $activity->created_at,
                'ip_address' => $activity->ip_address
            );
        }
        
        return $export;
    }
    
    /**
     * Send password changed notification
     */
    private function send_password_changed_notification($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Password Changed', 'property-manager-pro'), $site_name);
        
        $message = sprintf(
            __("Hello %s,\n\nYour password was recently changed.\n\nIf you did not make this change, please contact us immediately.\n\nBest regards,\n%s Team", 'property-manager-pro'),
            $user->display_name,
            $site_name
        );
        
        return wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Send deletion request email
     */
    private function send_deletion_request_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Data Deletion Request Received', 'property-manager-pro'), $site_name);
        
        $message = sprintf(
            __("Hello %s,\n\nWe have received your request to delete your account and data.\n\nYour account will be deleted within 30 days. If you change your mind, please contact us before then.\n\nBest regards,\n%s Team", 'property-manager-pro'),
            $user->display_name,
            $site_name
        );
        
        return wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Display user dashboard
     */
    private function display_user_dashboard() {
        get_header();
        
        echo '<div class="container my-5">';
        echo '<div class="row">';
        echo '<div class="col-12">';
        echo do_shortcode('[property_user_dashboard]');
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        get_footer();
        exit;
    }
    
    /**
     * Display user profile
     */
    private function display_user_profile() {
        get_header();
        
        echo '<div class="container my-5">';
        echo '<div class="row">';
        echo '<div class="col-12">';
        echo wp_kses_post($this->get_user_profile_form());
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        get_footer();
        exit;
    }
    
    /**
     * Display user favorites
     */
    private function display_user_favorites() {
        get_header();
        
        echo '<div class="container my-5">';
        echo '<div class="row">';
        echo '<div class="col-12">';
        echo do_shortcode('[property_user_favorites]');
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        get_footer();
        exit;
    }
    
    /**
     * Get user profile form HTML
     */
    private function get_user_profile_form() {
        if (!is_user_logged_in()) {
            return '<div class="alert alert-warning">' . esc_html__('Please login to access your profile.', 'property-manager-pro') . '</div>';
        }
        
        $current_user = wp_get_current_user();
        $preferences = get_user_meta($current_user->ID, 'property_manager_preferences', true);
        if (!is_array($preferences)) {
            $preferences = array();
        }
        
        $phone = get_user_meta($current_user->ID, 'property_manager_phone', true);
        $location = get_user_meta($current_user->ID, 'property_manager_location', true);
        $bio = get_user_meta($current_user->ID, 'property_manager_bio', true);
        $completion = get_user_meta($current_user->ID, 'property_manager_profile_completion', true);
        $email_verified = get_user_meta($current_user->ID, 'property_manager_email_verified', true);
        
        ob_start();
        ?>
        <div class="user-profile-container">
            <h2><?php esc_html_e('My Profile', 'property-manager-pro'); ?></h2>
            
            <?php if (!$email_verified): ?>
                <div class="alert alert-warning">
                    <strong><?php esc_html_e('Email Not Verified', 'property-manager-pro'); ?></strong>
                    <p><?php esc_html_e('Please verify your email address to receive property alerts.', 'property-manager-pro'); ?></p>
                    <button type="button" class="btn btn-sm btn-warning" id="resend-verification" data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_nonce')); ?>">
                        <?php esc_html_e('Resend Verification Email', 'property-manager-pro'); ?>
                    </button>
                </div>
            <?php endif; ?>
            
            <div class="profile-completion mb-4">
                <label><?php esc_html_e('Profile Completion', 'property-manager-pro'); ?>: <?php echo absint($completion); ?>%</label>
                <div class="progress">
                    <div class="progress-bar" role="progressbar" style="width: <?php echo absint($completion); ?>%" aria-valuenow="<?php echo absint($completion); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
            
            <form id="user-profile-form" method="post">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="first_name" class="form-label"><?php esc_html_e('First Name', 'property-manager-pro'); ?></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label for="last_name" class="form-label"><?php esc_html_e('Last Name', 'property-manager-pro'); ?></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label for="user_email" class="form-label"><?php esc_html_e('Email', 'property-manager-pro'); ?></label>
                        <input type="email" class="form-control" id="user_email" value="<?php echo esc_attr($current_user->user_email); ?>" disabled>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="phone" class="form-label"><?php esc_html_e('Phone', 'property-manager-pro'); ?></label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo esc_attr($phone); ?>">
                    </div>
                    
                    <div class="col-12">
                        <label for="location" class="form-label"><?php esc_html_e('Location', 'property-manager-pro'); ?></label>
                        <input type="text" class="form-control" id="location" name="location" value="<?php echo esc_attr($location); ?>">
                    </div>
                    
                    <div class="col-12">
                        <label for="bio" class="form-label"><?php esc_html_e('Bio', 'property-manager-pro'); ?></label>
                        <textarea class="form-control" id="bio" name="bio" rows="4" maxlength="500"><?php echo esc_textarea($bio); ?></textarea>
                        <small class="text-muted"><?php esc_html_e('Maximum 500 characters', 'property-manager-pro'); ?></small>
                    </div>
                    
                    <div class="col-12">
                        <h4><?php esc_html_e('Preferences', 'property-manager-pro'); ?></h4>
                    </div>
                    
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications" value="1" <?php checked(isset($preferences['email_notifications']) ? $preferences['email_notifications'] : false, true); ?>>
                            <label class="form-check-label" for="email_notifications">
                                <?php esc_html_e('Receive email notifications', 'property-manager-pro'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="search_alerts" name="search_alerts" value="1" <?php checked(isset($preferences['search_alerts']) ? $preferences['search_alerts'] : false, true); ?>>
                            <label class="form-check-label" for="search_alerts">
                                <?php esc_html_e('Enable search alerts', 'property-manager-pro'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <?php wp_nonce_field('property_manager_profile_update', 'profile_nonce'); ?>
                        <button type="submit" class="btn btn-primary"><?php esc_html_e('Update Profile', 'property-manager-pro'); ?></button>
                    </div>
                </div>
            </form>
            
            <hr class="my-5">
            
            <h3><?php esc_html_e('Change Password', 'property-manager-pro'); ?></h3>
            <form id="change-password-form">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="current_password" class="form-label"><?php esc_html_e('Current Password', 'property-manager-pro'); ?></label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="new_password" class="form-label"><?php esc_html_e('New Password', 'property-manager-pro'); ?></label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                        <small class="text-muted"><?php esc_html_e('Minimum 8 characters with uppercase, lowercase, and number', 'property-manager-pro'); ?></small>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label"><?php esc_html_e('Confirm Password', 'property-manager-pro'); ?></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" class="btn btn-warning"><?php esc_html_e('Change Password', 'property-manager-pro'); ?></button>
                    </div>
                </div>
            </form>
            
            <hr class="my-5">
            
            <h3><?php esc_html_e('GDPR & Data Privacy', 'property-manager-pro'); ?></h3>
            <div class="gdpr-section">
                <p><?php esc_html_e('You have the right to access, export, and delete your personal data.', 'property-manager-pro'); ?></p>
                
                <button type="button" class="btn btn-info me-2" id="export-data-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('property_manager_nonce')); ?>">
                    <i class="fas fa-download"></i> <?php esc_html_e('Export My Data', 'property-manager-pro'); ?>
                </button>
                
                <button type="button" class="btn btn-danger" id="delete-account-btn" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                    <i class="fas fa-trash"></i> <?php esc_html_e('Delete Account', 'property-manager-pro'); ?>
                </button>
            </div>
        </div>
        
        <!-- Delete Account Modal -->
        <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteAccountModalLabel"><?php esc_html_e('Delete Account', 'property-manager-pro'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <strong><?php esc_html_e('Warning:', 'property-manager-pro'); ?></strong>
                            <?php esc_html_e('This action cannot be undone. All your data including favorites, alerts, and saved searches will be permanently deleted.', 'property-manager-pro'); ?>
                        </div>
                        
                        <form id="delete-account-form">
                            <div class="mb-3">
                                <label for="delete_password" class="form-label"><?php esc_html_e('Enter your password to confirm', 'property-manager-pro'); ?></label>
                                <input type="password" class="form-control" id="delete_password" name="password" required>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="confirm_delete" required>
                                <label class="form-check-label" for="confirm_delete">
                                    <?php esc_html_e('I understand that this action is permanent and irreversible', 'property-manager-pro'); ?>
                                </label>
                            </div>
                            
                            <?php wp_nonce_field('property_manager_nonce', 'delete_nonce'); ?>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e('Cancel', 'property-manager-pro'); ?></button>
                        <button type="button" class="btn btn-danger" id="confirm-delete-account"><?php esc_html_e('Delete My Account', 'property-manager-pro'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php        
        return ob_get_clean();
    }
}