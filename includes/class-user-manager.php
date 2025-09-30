<?php
/**
 * User Manager Class - Property Manager Pro
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_UserManager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
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
        
        // AJAX handlers
        add_action('wp_ajax_update_user_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_change_user_password', array($this, 'ajax_change_password'));
        add_action('wp_ajax_delete_user_account', array($this, 'ajax_delete_account'));
    }
    
    /**
     * Initialize user management
     */
    public function init_user_management() {
        // Enable user registration if not already enabled
        $this->maybe_enable_registration();
        
        // Add custom rewrite rules for user pages
        $this->add_user_rewrite_rules();
    }
    
    /**
     * Maybe enable user registration
     */
    private function maybe_enable_registration() {
        $options = get_option('property_manager_options', array());
        $enable_registration = isset($options['enable_user_registration']) ? $options['enable_registration'] : true;
        
        if ($enable_registration && !get_option('users_can_register')) {
            // Don't force enable registration, just make it available through plugin
        }
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
        
        // Add query var
        add_filter('query_vars', function($vars) {
            $vars[] = 'property_user_page';
            return $vars;
        });
        
        // Handle the request
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
            wp_redirect(wp_login_url(home_url('/user-dashboard/')));
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
                wp_redirect(home_url('/user-dashboard/'));
                exit;
        }
    }
    
    /**
     * Handle user registration
     */
    public function on_user_register($user_id) {
        // Set default user meta
        $this->set_default_user_meta($user_id);
        
        // Send welcome email
        $this->send_welcome_email($user_id);
    }
    
    /**
     * Handle user login
     */
    public function on_user_login($user_login, $user) {
        // Update last login time
        update_user_meta($user->ID, 'property_manager_last_login', current_time('mysql'));
        
        // Clean up old session data
        $this->cleanup_user_session_data($user->ID);
    }
    
    /**
     * Handle user logout
     */
    public function on_user_logout() {
        // Any cleanup needed on logout
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
            'property_manager_profile_completion' => 20, // Basic info only
            'property_manager_registration_date' => current_time('mysql'),
            'property_manager_account_status' => 'active'
        );
        
        foreach ($defaults as $key => $value) {
            update_user_meta($user_id, $key, $value);
        }
    }
    
    /**
     * Send welcome email to new users
     */
    private function send_welcome_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $options = get_option('property_manager_options', array());
        $site_name = get_bloginfo('name');
        
        $subject = sprintf(__('[%s] Welcome to %s', 'property-manager-pro'), $site_name, $site_name);
        
        $message = sprintf(
            __("Welcome %s!\n\nThank you for registering at %s. Your account has been created successfully.\n\nYou can now:\n• Save your favorite properties\n• Create property search alerts\n• Save your searches for future use\n• Track your recently viewed properties\n\nGet started by visiting your dashboard: %s\n\nIf you have any questions, please don't hesitate to contact us.\n\nBest regards,\n%s Team", 'property-manager-pro'),
            $user->display_name,
            $site_name,
            home_url('/user-dashboard/'),
            $site_name
        );
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Add custom profile fields
     */
    public function add_profile_fields($user) {
        $preferences = get_user_meta($user->ID, 'property_manager_preferences', true);
        if (!is_array($preferences)) {
            $preferences = array();
        }
        
        $phone = get_user_meta($user->ID, 'property_manager_phone', true);
        $location = get_user_meta($user->ID, 'property_manager_location', true);
        $bio = get_user_meta($user->ID, 'property_manager_bio', true);
        ?>
        
        <h3><?php _e('Property Manager Settings', 'property-manager-pro'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th>
                    <label for="property_manager_phone"><?php _e('Phone Number', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <input type="tel" 
                           name="property_manager_phone" 
                           id="property_manager_phone" 
                           value="<?php echo esc_attr($phone); ?>" 
                           class="regular-text" />
                    <p class="description"><?php _e('Your phone number for property inquiries.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="property_manager_location"><?php _e('Location', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="property_manager_location" 
                           id="property_manager_location" 
                           value="<?php echo esc_attr($location); ?>" 
                           class="regular-text" />
                    <p class="description"><?php _e('Your preferred location or area of interest.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="property_manager_bio"><?php _e('Bio', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <textarea name="property_manager_bio" 
                              id="property_manager_bio" 
                              rows="3" 
                              cols="30"><?php echo esc_textarea($bio); ?></textarea>
                    <p class="description"><?php _e('Brief description about yourself or your property requirements.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('Notification Preferences', 'property-manager-pro'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th><?php _e('Email Notifications', 'property-manager-pro'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php _e('Email Notification Preferences', 'property-manager-pro'); ?></legend>
                        
                        <label for="email_notifications">
                            <input name="property_manager_preferences[email_notifications]" 
                                   type="checkbox" 
                                   id="email_notifications" 
                                   value="1" 
                                   <?php checked(isset($preferences['email_notifications']) ? $preferences['email_notifications'] : false, true); ?> />
                            <?php _e('Receive email notifications for property updates', 'property-manager-pro'); ?>
                        </label><br>
                        
                        <label for="search_alerts">
                            <input name="property_manager_preferences[search_alerts]" 
                                   type="checkbox" 
                                   id="search_alerts" 
                                   value="1" 
                                   <?php checked(isset($preferences['search_alerts']) ? $preferences['search_alerts'] : false, true); ?> />
                            <?php _e('Enable search alerts for saved searches', 'property-manager-pro'); ?>
                        </label><br>
                        
                        <label for="newsletter">
                            <input name="property_manager_preferences[newsletter]" 
                                   type="checkbox" 
                                   id="newsletter" 
                                   value="1" 
                                   <?php checked(isset($preferences['newsletter']) ? $preferences['newsletter'] : false, true); ?> />
                            <?php _e('Subscribe to property newsletter', 'property-manager-pro'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="preferred_language"><?php _e('Preferred Language', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[preferred_language]" id="preferred_language">
                        <option value="en" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'en'); ?>>
                            <?php _e('English', 'property-manager-pro'); ?>
                        </option>
                        <option value="es" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'es'); ?>>
                            <?php _e('Spanish', 'property-manager-pro'); ?>
                        </option>
                        <option value="de" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'de'); ?>>
                            <?php _e('German', 'property-manager-pro'); ?>
                        </option>
                        <option value="fr" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'fr'); ?>>
                            <?php _e('French', 'property-manager-pro'); ?>
                        </option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="results_per_page"><?php _e('Results Per Page', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[results_per_page]" id="results_per_page">
                        <option value="12" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 12); ?>>12</option>
                        <option value="20" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 20); ?>>20</option>
                        <option value="36" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 36); ?>>36</option>
                        <option value="60" <?php selected(isset($preferences['results_per_page']) ? $preferences['results_per_page'] : 20, 60); ?>>60</option>
                    </select>
                    <p class="description"><?php _e('Number of properties to show per page in search results.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="default_view"><?php _e('Default View', 'property-manager-pro'); ?></label>
                </th>
                <td>
                    <select name="property_manager_preferences[default_view]" id="default_view">
                        <option value="grid" <?php selected(isset($preferences['default_view']) ? $preferences['default_view'] : 'grid', 'grid'); ?>>
                            <?php _e('Grid View', 'property-manager-pro'); ?>
                        </option>
                        <option value="list" <?php selected(isset($preferences['default_view']) ? $preferences['default_view'] : 'grid', 'list'); ?>>
                            <?php _e('List View', 'property-manager-pro'); ?>
                        </option>
                    </select>
                    <p class="description"><?php _e('Your preferred view for property search results.', 'property-manager-pro'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php
    }
    
    /**
     * Save custom profile fields
     */
    public function save_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        // Save basic fields
        $fields = array('property_manager_phone', 'property_manager_location', 'property_manager_bio');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        // Save preferences
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
                    } else {
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
    }
    
    /**
     * Update profile completion percentage
     */
    private function update_profile_completion($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $completion = 0;
        $total_fields = 10;
        
        // Basic WordPress fields (4 fields = 40%)
        if (!empty($user->first_name)) $completion += 10;
        if (!empty($user->last_name)) $completion += 10;
        if (!empty($user->user_email)) $completion += 10;
        if (!empty($user->description)) $completion += 10;
        
        // Custom fields (6 fields = 60%)
        $phone = get_user_meta($user_id, 'property_manager_phone', true);
        $location = get_user_meta($user_id, 'property_manager_location', true);
        $bio = get_user_meta($user_id, 'property_manager_bio', true);
        $preferences = get_user_meta($user_id, 'property_manager_preferences', true);
        
        if (!empty($phone)) $completion += 10;
        if (!empty($location)) $completion += 10;
        if (!empty($bio)) $completion += 10;
        if (is_array($preferences) && !empty($preferences)) $completion += 30;
        
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
        // Check if user has property manager capabilities
        if (is_a($user, 'WP_User') && $user->has_cap('manage_property_favorites')) {
            // If no specific redirect was requested, go to user dashboard
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
        echo $this->get_user_profile_form();
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
            return '<p>' . __('Please login to access your profile.', 'property-manager-pro') . '</p>';
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
        
        ob_start();
        ?>
        <div class="user-profile-container">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h3><?php _e('My Profile', 'property-manager-pro'); ?></h3>
                        </div>
                        <div class="card-body">
                            
                            <!-- Profile Completion Progress -->
                            <div class="profile-completion mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span><?php _e('Profile Completion', 'property-manager-pro'); ?></span>
                                    <span><?php echo intval($completion); ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" 
                                         role="progressbar" 
                                         style="width: <?php echo intval($completion); ?>%"
                                         aria-valuenow="<?php echo intval($completion); ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            </div>
                            
                            <form id="user-profile-form" method="post">
                                <?php wp_nonce_field('update_user_profile', 'profile_nonce'); ?>
                                
                                <!-- Basic Information -->
                                <div class="form-section mb-4">
                                    <h4><?php _e('Basic Information', 'property-manager-pro'); ?></h4>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="first_name" class="form-label"><?php _e('First Name', 'property-manager-pro'); ?></label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="first_name" 
                                                   name="first_name" 
                                                   value="<?php echo esc_attr($current_user->first_name); ?>">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="last_name" class="form-label"><?php _e('Last Name', 'property-manager-pro'); ?></label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="last_name" 
                                                   name="last_name" 
                                                   value="<?php echo esc_attr($current_user->last_name); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="user_email" class="form-label"><?php _e('Email Address', 'property-manager-pro'); ?></label>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="user_email" 
                                                   name="user_email" 
                                                   value="<?php echo esc_attr($current_user->user_email); ?>">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="property_manager_phone" class="form-label"><?php _e('Phone Number', 'property-manager-pro'); ?></label>
                                            <input type="tel" 
                                                   class="form-control" 
                                                   id="property_manager_phone" 
                                                   name="property_manager_phone" 
                                                   value="<?php echo esc_attr($phone); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="property_manager_location" class="form-label"><?php _e('Location', 'property-manager-pro'); ?></label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="property_manager_location" 
                                               name="property_manager_location" 
                                               value="<?php echo esc_attr($location); ?>"
                                               placeholder="<?php _e('Your city or preferred area', 'property-manager-pro'); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="property_manager_bio" class="form-label"><?php _e('Bio', 'property-manager-pro'); ?></label>
                                        <textarea class="form-control" 
                                                  id="property_manager_bio" 
                                                  name="property_manager_bio" 
                                                  rows="3"
                                                  placeholder="<?php _e('Tell us about yourself or your property requirements...', 'property-manager-pro'); ?>"><?php echo esc_textarea($bio); ?></textarea>
                                    </div>
                                </div>
                                
                                <!-- Notification Preferences -->
                                <div class="form-section mb-4">
                                    <h4><?php _e('Notification Preferences', 'property-manager-pro'); ?></h4>
                                    
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="email_notifications" 
                                               name="property_manager_preferences[email_notifications]" 
                                               value="1"
                                               <?php checked(isset($preferences['email_notifications']) ? $preferences['email_notifications'] : false, true); ?>>
                                        <label class="form-check-label" for="email_notifications">
                                            <?php _e('Receive email notifications for property updates', 'property-manager-pro'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="search_alerts" 
                                               name="property_manager_preferences[search_alerts]" 
                                               value="1"
                                               <?php checked(isset($preferences['search_alerts']) ? $preferences['search_alerts'] : false, true); ?>>
                                        <label class="form-check-label" for="search_alerts">
                                            <?php _e('Enable search alerts for saved searches', 'property-manager-pro'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="newsletter" 
                                               name="property_manager_preferences[newsletter]" 
                                               value="1"
                                               <?php checked(isset($preferences['newsletter']) ? $preferences['newsletter'] : false, true); ?>>
                                        <label class="form-check-label" for="newsletter">
                                            <?php _e('Subscribe to property newsletter', 'property-manager-pro'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Display Preferences -->
                                <div class="form-section mb-4">
                                    <h4><?php _e('Display Preferences', 'property-manager-pro'); ?></h4>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="preferred_language" class="form-label"><?php _e('Preferred Language', 'property-manager-pro'); ?></label>
                                            <select class="form-select" id="preferred_language" name="property_manager_preferences[preferred_language]">
                                                <option value="en" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'en'); ?>>
                                                    <?php _e('English', 'property-manager-pro'); ?>
                                                </option>
                                                <option value="es" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'es'); ?>>
                                                    <?php _e('Spanish', 'property-manager-pro'); ?>
                                                </option>
                                                <option value="de" <?php selected(isset($preferences['preferred_language']) ? $preferences['preferred_language'] : 'en', 'de'); ?>>
                                                    <?php _e('German', 'property-manager-pro'); ?>
                                                </option>
											
											
											
											
											</select>
										</div>
									</div>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>
	<?php 
	}
}