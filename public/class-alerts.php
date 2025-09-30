<?php
/**
 * Property Alerts management class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Alerts {
    
    private static $instance = null;
    
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
        
        // Check if alert already exists for this email and criteria
        $table = PropertyManager_Database::get_table_name('property_alerts');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE email = %s AND search_criteria = %s AND status != 'unsubscribed'",
            $email,
            json_encode($search_criteria)
        ));
        
        if ($existing) {
            return new WP_Error('alert_exists', __('An alert with these criteria already exists for this email address.', 'property-manager-pro'));
        }
        
        // Generate tokens
        $verification_token = wp_generate_password(32, false);
        $unsubscribe_token = wp_generate_password(32, false);
        
        // Get options to check if email verification is required
        $options = get_option('property_manager_options', array());
        $email_verification_required = isset($options['email_verification_required']) ? $options['email_verification_required'] : true;
        
        // Prepare data
        $data = array(
            'email' => $email,
            'user_id' => $user_id,
            'search_criteria' => json_encode($search_criteria),
            'frequency' => $frequency,
            'status' => 'active',
            'verification_token' => $verification_token,
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
     * Update an existing alert
     */
    public function update_alert($alert_id, $data) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Validate alert exists
        $alert = $this->get_alert($alert_id);
        if (!$alert) {
            return new WP_Error('alert_not_found', __('Alert not found.', 'property-manager-pro'));
        }
        
        // Prepare update data
        $update_data = array();
        
        if (isset($data['frequency']) && in_array($data['frequency'], array('daily', 'weekly', 'monthly'))) {
            $update_data['frequency'] = $data['frequency'];
        }
        
        if (isset($data['status']) && in_array($data['status'], array('active', 'paused'))) {
            $update_data['status'] = $data['status'];
        }
        
        if (isset($data['search_criteria'])) {
            $update_data['search_criteria'] = json_encode($data['search_criteria']);
        }
        
        if (!empty($update_data)) {
            $update_data['updated_at'] = current_time('mysql');
            
            $result = $wpdb->update(
                $table,
                $update_data,
                array('id' => $alert_id)
            );
            
            return $result !== false;
        }
        
        return true;
    }
    
    /**
     * Delete an alert
     */
    public function delete_alert($alert_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $result = $wpdb->delete($table, array('id' => $alert_id));
        
        return $result !== false;
    }
    
    /**
     * Get alert by ID
     */
    public function get_alert($alert_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $alert_id
        ));
    }
    
    /**
     * Get alerts by email
     */
    public function get_alerts_by_email($email) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE email = %s AND status != 'unsubscribed' ORDER BY created_at DESC",
            $email
        ));
    }
    
    /**
     * Get alerts by user ID
     */
    public function get_alerts_count($user_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND status != 'unsubscribed' ORDER BY created_at DESC",
            $user_id
        ));
    }
    
    /**
     * Get alerts by user ID
     */
    public function get_user_alerts($user_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND status != 'unsubscribed' ORDER BY created_at DESC",
            $user_id
        ));
    }
    
    /**
     * Verify email address
     */
    public function verify_email($token) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE verification_token = %s AND email_verified = 0",
            $token
        ));
        
        if (!$alert) {
            return false;
        }
        
        // Update alert as verified
        $result = $wpdb->update(
            $table,
            array(
                'email_verified' => 1,
                'verification_token' => null,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $alert->id)
        );
        
        return $result !== false;
    }
    
    /**
     * Unsubscribe from alerts
     */
    public function unsubscribe($token) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE unsubscribe_token = %s",
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
     * Send verification email
     */
    private function send_verification_email($alert_id, $email, $token) {
        $verification_url = add_query_arg(array(
            'action' => 'verify_alert',
            'token' => $token
        ), home_url());
        
        $subject = __('Verify Your Property Alert Subscription', 'property-manager-pro');
        
        $message = sprintf(
            __('Please click the following link to verify your property alert subscription: %s', 'property-manager-pro'),
            $verification_url
        );
        
        // Use the email class to send
        $email_manager = PropertyManager_Email::get_instance();
        return $email_manager->send_email($email, $subject, $message, 'alert_verification');
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
     */
    private function process_alerts($frequency) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Calculate the date threshold based on frequency
        $date_threshold = $this->get_date_threshold($frequency);
        
        // Get alerts to process
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE frequency = %s 
             AND status = 'active' 
             AND email_verified = 1 
             AND (last_sent IS NULL OR last_sent < %s)",
            $frequency,
            $date_threshold
        ));
        
        foreach ($alerts as $alert) {
            $this->send_alert_email($alert);
        }
    }
    
    /**
     * Get date threshold for alert frequency
     */
    private function get_date_threshold($frequency) {
        switch ($frequency) {
            case 'daily':
                return date('Y-m-d H:i:s', strtotime('-1 day'));
            case 'weekly':
                return date('Y-m-d H:i:s', strtotime('-1 week'));
            case 'monthly':
                return date('Y-m-d H:i:s', strtotime('-1 month'));
            default:
                return date('Y-m-d H:i:s', strtotime('-1 week'));
        }
    }
    
    /**
     * Send alert email
     */
    private function send_alert_email($alert) {
        global $wpdb;
        
        // Decode search criteria
        $search_criteria = json_decode($alert->search_criteria, true);
        if (!$search_criteria) {
            return false;
        }
        
        // Get matching properties using search class
        $search = PropertyManager_Search::get_instance();
        $properties = $search->search_properties($search_criteria, array(
            'limit' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ));
        
        if (empty($properties)) {
            // No new properties, still update last_sent to avoid repeated processing
            $this->update_alert_last_sent($alert->id);
            return false;
        }
        
        // Generate email content
        $subject = sprintf(
            __('Property Alert: %d new properties found', 'property-manager-pro'),
            count($properties)
        );
        
        $message = $this->generate_alert_email_content($alert, $properties);
        
        // Send email
        $email_manager = PropertyManager_Email::get_instance();
        $sent = $email_manager->send_email($alert->email, $subject, $message, 'property_alert');
        
        if ($sent) {
            $this->update_alert_last_sent($alert->id);
        }
        
        return $sent;
    }
    
    /**
     * Generate alert email content
     */
    private function generate_alert_email_content($alert, $properties) {
        $unsubscribe_url = add_query_arg(array(
            'action' => 'unsubscribe_alert',
            'token' => $alert->unsubscribe_token
        ), home_url());
        
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2><?php _e('New Properties Matching Your Alert', 'property-manager-pro'); ?></h2>
            
            <p><?php printf(__('We found %d new properties matching your search criteria:', 'property-manager-pro'), count($properties)); ?></p>
            
            <?php foreach ($properties as $property): ?>
                <div style="border: 1px solid #ddd; margin: 20px 0; padding: 15px; border-radius: 5px;">
                    <h3><a href="<?php echo esc_url($this->get_property_url($property->id)); ?>"><?php echo esc_html($property->title); ?></a></h3>
                    
                    <div style="display: flex; align-items: center; margin: 10px 0;">
                        <?php if ($property->images): ?>
                            <?php 
                            $images = json_decode($property->images, true);
                            if ($images && !empty($images[0])): 
                            ?>
                                <img src="<?php echo esc_url($images[0]['url']); ?>" style="width: 150px; height: 100px; object-fit: cover; margin-right: 15px;" alt="<?php echo esc_attr($property->title); ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div>
                            <p><strong><?php echo esc_html($property->price ? number_format($property->price) . ' ' . $property->currency : __('Price on request', 'property-manager-pro')); ?></strong></p>
                            <p><?php echo esc_html($property->town . ', ' . $property->province); ?></p>
                            <?php if ($property->beds || $property->baths): ?>
                                <p>
                                    <?php if ($property->beds): ?>
                                        <?php echo esc_html($property->beds); ?> <?php _e('beds', 'property-manager-pro'); ?>
                                    <?php endif; ?>
                                    <?php if ($property->beds && $property->baths): ?> | <?php endif; ?>
                                    <?php if ($property->baths): ?>
                                        <?php echo esc_html($property->baths); ?> <?php _e('baths', 'property-manager-pro'); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($property->description_en): ?>
                        <p><?php echo wp_trim_words(strip_tags($property->description_en), 20); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p><small>
                    <?php printf(__('You are receiving this email because you signed up for property alerts. You can <a href="%s">unsubscribe</a> at any time.', 'property-manager-pro'), $unsubscribe_url); ?>
                </small></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get property URL
     */
    private function get_property_url($property_id) {
        // This should return the URL to the property detail page
        // You'll need to implement this based on your URL structure
        return home_url("/property/$property_id/");
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
     * Handle verification requests
     */
    public function handle_verification() {
        if (isset($_GET['action']) && $_GET['action'] === 'verify_alert' && isset($_GET['token'])) {
            $token = sanitize_text_field($_GET['token']);
            
            if ($this->verify_email($token)) {
                wp_redirect(add_query_arg('verified', '1', home_url()));
            } else {
                wp_redirect(add_query_arg('verification_error', '1', home_url()));
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
                wp_redirect(add_query_arg('unsubscribed', '1', home_url()));
            } else {
                wp_redirect(add_query_arg('unsubscribe_error', '1', home_url()));
            }
            exit;
        }
    }
    
    /**
     * AJAX handler for creating alerts
     */
    public function ajax_create_alert() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'property_manager_nonce')) {
            wp_die(__('Security check failed', 'property-manager-pro'));
        }
        
        $email = sanitize_email($_POST['email']);
        $frequency = sanitize_text_field($_POST['frequency']);
        $search_criteria = $_POST['search_criteria']; // Will be sanitized in create_alert method
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        
        $result = $this->create_alert($email, $search_criteria, $frequency, $user_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Alert created successfully. Please check your email to verify your subscription.', 'property-manager-pro'),
                'alert_id' => $result
            ));
        }
    }
    
    /**
     * AJAX handler for managing alerts (pause/resume)
     */
    public function ajax_manage_alert() {
        // Verify nonce and user
        if (!wp_verify_nonce($_POST['nonce'], 'property_manager_nonce') || !is_user_logged_in()) {
            wp_die(__('Security check failed', 'property-manager-pro'));
        }
        
        $alert_id = intval($_POST['alert_id']);
        $action = sanitize_text_field($_POST['alert_action']);
        
        // Verify user owns this alert
        $alert = $this->get_alert($alert_id);
        if (!$alert || $alert->user_id != get_current_user_id()) {
            wp_send_json_error(array(
                'message' => __('Alert not found or access denied.', 'property-manager-pro')
            ));
        }
        
        $status = ($action === 'pause') ? 'paused' : 'active';
        
        $result = $this->update_alert($alert_id, array('status' => $status));
        
        if ($result) {
            wp_send_json_success(array(
                'message' => ($action === 'pause') ? 
                    __('Alert paused successfully.', 'property-manager-pro') : 
                    __('Alert resumed successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to update alert.', 'property-manager-pro')
            ));
        }
    }
    
    /**
     * AJAX handler for deleting alerts
     */
    public function ajax_delete_alert() {
        // Verify nonce and user
        if (!wp_verify_nonce($_POST['nonce'], 'property_manager_nonce') || !is_user_logged_in()) {
            wp_die(__('Security check failed', 'property-manager-pro'));
        }
        
        $alert_id = intval($_POST['alert_id']);
        
        // Verify user owns this alert
        $alert = $this->get_alert($alert_id);
        if (!$alert || $alert->user_id != get_current_user_id()) {
            wp_send_json_error(array(
                'message' => __('Alert not found or access denied.', 'property-manager-pro')
            ));
        }
        
        $result = $this->delete_alert($alert_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Alert deleted successfully.', 'property-manager-pro')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to delete alert.', 'property-manager-pro')
            ));
        }
    }
}