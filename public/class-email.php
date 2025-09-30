<?php
/**
 * Email management class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Email {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into WordPress email filters
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
        add_action('wp_mail_failed', array($this, 'log_mail_failure'));
        
        // Hook cleanup to cron
        add_action('property_manager_cleanup', array($this, 'cleanup_old_logs'));
    }
    
    /**
     * Set email content type to HTML
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send email with logging
     */
    public function send_email($to, $subject, $message, $email_type = 'general', $headers = array(), $attachments = array()) {
        // Validate email
        if (!is_email($to)) {
            $this->log_email($email_type, $to, $subject, $message, 'failed', 'Invalid email address');
            return false;
        }
        
        // Get plugin options
        $options = get_option('property_manager_options', array());
        $admin_email = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        // Set default headers
        if (empty($headers)) {
            $headers = array(
                'From: ' . get_bloginfo('name') . ' <' . $admin_email . '>',
                'Reply-To: ' . $admin_email,
                'X-Mailer: Property Manager Pro'
            );
        }
        
        // Wrap message in template
        $template_message = $this->wrap_in_template($message, $subject, $email_type);
        
        // Log email attempt
        $log_id = $this->log_email($email_type, $to, $subject, $template_message, 'pending');
        
        // Send email
        $sent = wp_mail($to, $subject, $template_message, $headers, $attachments);
        
        // Update log
        if ($sent) {
            $this->update_email_log($log_id, 'sent', null, current_time('mysql'));
        } else {
            $this->update_email_log($log_id, 'failed', 'wp_mail returned false');
        }
        
        return $sent;
    }
    
    /**
     * Send property inquiry email
     */
    public function send_property_inquiry($property_id, $inquiry_data) {
        $property = $this->get_property_data($property_id);
        if (!$property) {
            return false;
        }
        
        $options = get_option('property_manager_options', array());
        $admin_email = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        $subject = sprintf(
            __('Property Inquiry: %s (Ref: %s)', 'property-manager-pro'),
            $property->title,
            $property->ref
        );
        
        $message = $this->generate_inquiry_email_content($property, $inquiry_data);
        
        return $this->send_email($admin_email, $subject, $message, 'property_inquiry');
    }
    
    /**
     * Send welcome email to new users
     */
    public function send_welcome_email($user_email, $user_name) {
        $subject = sprintf(__('Welcome to %s', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->generate_welcome_email_content($user_name);
        
        return $this->send_email($user_email, $subject, $message, 'welcome');
    }
    
    /**
     * Send password reset instructions
     */
    public function send_password_reset_email($user_email, $reset_link) {
        $subject = sprintf(__('Password Reset - %s', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->generate_password_reset_email_content($reset_link);
        
        return $this->send_email($user_email, $subject, $message, 'password_reset');
    }
    
    /**
     * Send email verification
     */
    public function send_email_verification($email, $verification_link) {
        $subject = sprintf(__('Verify Your Email - %s', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->generate_email_verification_content($verification_link);
        
        return $this->send_email($email, $subject, $message, 'email_verification');
    }
    
    /**
     * Send saved search notification
     */
    public function send_saved_search_notification($user_email, $search_name, $properties) {
        $subject = sprintf(
            __('New Properties for "%s"', 'property-manager-pro'),
            $search_name
        );
        
        $message = $this->generate_saved_search_email_content($search_name, $properties);
        
        return $this->send_email($user_email, $subject, $message, 'saved_search');
    }
    
    /**
     * Wrap message in email template
     */
    private function wrap_in_template($content, $subject, $email_type = 'general') {
        $template = $this->get_email_template($email_type);
        
        // Replace placeholders
        $template = str_replace('[SITE_NAME]', get_bloginfo('name'), $template);
        $template = str_replace('[SITE_URL]', home_url(), $template);
        $template = str_replace('[SUBJECT]', $subject, $template);
        $template = str_replace('[CONTENT]', $content, $template);
        $template = str_replace('[YEAR]', date('Y'), $template);
        
        return $template;
    }
    
    /**
     * Get email template
     */
    private function get_email_template($email_type = 'general') {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>[SUBJECT]</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0; 
                    padding: 0; 
                    background-color: #f4f4f4; 
                }
                .email-container { 
                    max-width: 600px; 
                    margin: 20px auto; 
                    background: #ffffff; 
                    border-radius: 8px; 
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                    overflow: hidden;
                }
                .email-header { 
                    background: #2c3e50; 
                    color: white; 
                    padding: 20px; 
                    text-align: center; 
                }
                .email-header h1 { 
                    margin: 0; 
                    font-size: 24px; 
                }
                .email-body { 
                    padding: 30px; 
                }
                .email-footer { 
                    background: #34495e; 
                    color: white; 
                    padding: 20px; 
                    text-align: center; 
                    font-size: 12px; 
                }
                .email-footer a { 
                    color: #3498db; 
                    text-decoration: none; 
                }
                .button { 
                    display: inline-block; 
                    padding: 12px 25px; 
                    background: #3498db; 
                    color: white; 
                    text-decoration: none; 
                    border-radius: 5px; 
                    margin: 10px 0; 
                }
                .property-item { 
                    border: 1px solid #ddd; 
                    margin: 15px 0; 
                    padding: 15px; 
                    border-radius: 5px; 
                    background: #f9f9f9; 
                }
                .property-image { 
                    max-width: 150px; 
                    height: 100px; 
                    object-fit: cover; 
                    float: left; 
                    margin-right: 15px; 
                    border-radius: 3px; 
                }
                .property-details { 
                    overflow: hidden; 
                }
                .property-price { 
                    font-size: 18px; 
                    font-weight: bold; 
                    color: #2c3e50; 
                }
                .property-location { 
                    color: #7f8c8d; 
                    margin: 5px 0; 
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1>' . $site_name . '</h1>
                </div>
                <div class="email-body">
                    [CONTENT]
                </div>
                <div class="email-footer">
                    <p>© [YEAR] ' . $site_name . '. All rights reserved.</p>
                    <p><a href="' . $site_url . '">Visit our website</a></p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Generate property inquiry email content
     */
    private function generate_inquiry_email_content($property, $inquiry_data) {
        ob_start();
        ?>
        <h2><?php _e('New Property Inquiry', 'property-manager-pro'); ?></h2>
        
        <div class="property-item">
            <h3><?php echo esc_html($property->title); ?></h3>
            <p><strong><?php _e('Reference:', 'property-manager-pro'); ?></strong> <?php echo esc_html($property->ref); ?></p>
            <p><strong><?php _e('Price:', 'property-manager-pro'); ?></strong> <?php echo esc_html($property->price ? number_format($property->price) . ' ' . $property->currency : __('Price on request', 'property-manager-pro')); ?></p>
            <p><strong><?php _e('Location:', 'property-manager-pro'); ?></strong> <?php echo esc_html($property->town . ', ' . $property->province); ?></p>
            <p><a href="<?php echo esc_url($this->get_property_url($property->id)); ?>" class="button"><?php _e('View Property', 'property-manager-pro'); ?></a></p>
        </div>
        
        <h3><?php _e('Inquiry Details', 'property-manager-pro'); ?></h3>
        <p><strong><?php _e('Name:', 'property-manager-pro'); ?></strong> <?php echo esc_html($inquiry_data['name']); ?></p>
        <p><strong><?php _e('Email:', 'property-manager-pro'); ?></strong> <a href="mailto:<?php echo esc_attr($inquiry_data['email']); ?>"><?php echo esc_html($inquiry_data['email']); ?></a></p>
        <?php if (!empty($inquiry_data['phone'])): ?>
        <p><strong><?php _e('Phone:', 'property-manager-pro'); ?></strong> <?php echo esc_html($inquiry_data['phone']); ?></p>
        <?php endif; ?>
        
        <h4><?php _e('Message:', 'property-manager-pro'); ?></h4>
        <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 10px 0;">
            <?php echo nl2br(esc_html($inquiry_data['message'])); ?>
        </div>
        
        <p><strong><?php _e('Inquiry Date:', 'property-manager-pro'); ?></strong> <?php echo current_time('F j, Y g:i A'); ?></p>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate welcome email content
     */
    private function generate_welcome_email_content($user_name) {
        ob_start();
        ?>
        <h2><?php printf(__('Welcome %s!', 'property-manager-pro'), esc_html($user_name)); ?></h2>
        
        <p><?php printf(__('Thank you for creating an account at %s. We\'re excited to help you find your perfect property!', 'property-manager-pro'), get_bloginfo('name')); ?></p>
        
        <h3><?php _e('What you can do now:', 'property-manager-pro'); ?></h3>
        <ul>
            <li><?php _e('Browse our extensive property listings', 'property-manager-pro'); ?></li>
            <li><?php _e('Save properties to your favorites', 'property-manager-pro'); ?></li>
            <li><?php _e('Create saved searches for quick access', 'property-manager-pro'); ?></li>
            <li><?php _e('Set up property alerts to get notified of new listings', 'property-manager-pro'); ?></li>
            <li><?php _e('Contact agents directly about properties', 'property-manager-pro'); ?></li>
        </ul>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url(home_url('/property-search/')); ?>" class="button"><?php _e('Start Searching Properties', 'property-manager-pro'); ?></a>
        </p>
        
        <p><?php _e('If you have any questions, please don\'t hesitate to contact us.', 'property-manager-pro'); ?></p>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate password reset email content
     */
    private function generate_password_reset_email_content($reset_link) {
        ob_start();
        ?>
        <h2><?php _e('Password Reset Request', 'property-manager-pro'); ?></h2>
        
        <p><?php _e('You have requested to reset your password. Click the button below to create a new password:', 'property-manager-pro'); ?></p>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url($reset_link); ?>" class="button"><?php _e('Reset Password', 'property-manager-pro'); ?></a>
        </p>
        
        <p><?php _e('If you did not request this password reset, please ignore this email. The link will expire in 24 hours for security reasons.', 'property-manager-pro'); ?></p>
        
        <p><?php _e('For security purposes, you can also copy and paste the following link into your browser:', 'property-manager-pro'); ?></p>
        <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 3px;"><?php echo esc_html($reset_link); ?></p>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate email verification content
     */
    private function generate_email_verification_content($verification_link) {
        ob_start();
        ?>
        <h2><?php _e('Verify Your Email Address', 'property-manager-pro'); ?></h2>
        
        <p><?php _e('Thank you for subscribing to property alerts. To complete your subscription, please verify your email address by clicking the button below:', 'property-manager-pro'); ?></p>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url($verification_link); ?>" class="button"><?php _e('Verify Email Address', 'property-manager-pro'); ?></a>
        </p>
        
        <p><?php _e('Once verified, you will start receiving property alerts based on your search criteria.', 'property-manager-pro'); ?></p>
        
        <p><?php _e('If you did not sign up for property alerts, please ignore this email.', 'property-manager-pro'); ?></p>
        
        <p><?php _e('For security purposes, you can also copy and paste the following link into your browser:', 'property-manager-pro'); ?></p>
        <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 3px;"><?php echo esc_html($verification_link); ?></p>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate saved search email content
     */
    private function generate_saved_search_email_content($search_name, $properties) {
        ob_start();
        ?>
        <h2><?php printf(__('New Properties for "%s"', 'property-manager-pro'), esc_html($search_name)); ?></h2>
        
        <p><?php printf(__('We found %d new properties matching your saved search:', 'property-manager-pro'), count($properties)); ?></p>
        
        <?php foreach ($properties as $property): ?>
            <div class="property-item">
                <?php if ($property->images): ?>
                    <?php 
                    $images = json_decode($property->images, true);
                    if ($images && !empty($images[0])): 
                    ?>
                        <img src="<?php echo esc_url($images[0]['url']); ?>" class="property-image" alt="<?php echo esc_attr($property->title); ?>">
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="property-details">
                    <h3><a href="<?php echo esc_url($this->get_property_url($property->id)); ?>"><?php echo esc_html($property->title); ?></a></h3>
                    
                    <p class="property-price"><?php echo esc_html($property->price ? number_format($property->price) . ' ' . $property->currency : __('Price on request', 'property-manager-pro')); ?></p>
                    <p class="property-location"><?php echo esc_html($property->town . ', ' . $property->province); ?></p>
                    
                    <?php if ($property->beds || $property->baths): ?>
                        <p>
                            <?php if ($property->beds): ?>
                                <strong><?php echo esc_html($property->beds); ?></strong> <?php _e('beds', 'property-manager-pro'); ?>
                            <?php endif; ?>
                            <?php if ($property->beds && $property->baths): ?> | <?php endif; ?>
                            <?php if ($property->baths): ?>
                                <strong><?php echo esc_html($property->baths); ?></strong> <?php _e('baths', 'property-manager-pro'); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($property->description_en): ?>
                        <p><?php echo wp_trim_words(strip_tags($property->description_en), 25); ?></p>
                    <?php endif; ?>
                </div>
                <div style="clear: both;"></div>
            </div>
        <?php endforeach; ?>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="<?php echo esc_url(home_url('/property-search/')); ?>" class="button"><?php _e('View All Properties', 'property-manager-pro'); ?></a>
        </p>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get property data for emails
     */
    private function get_property_data($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $property_id
        ));
    }
    
    /**
     * Get property URL
     */
    private function get_property_url($property_id) {
        // This should return the URL to the property detail page
        // Adjust based on your URL structure
        return home_url("/property/$property_id/");
    }
    
    /**
     * Log email
     */
    public function log_email($email_type, $recipient, $subject, $message, $status = 'pending', $error_message = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        $data = array(
            'email_type' => $email_type,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'message' => $message,
            'status' => $status,
            'error_message' => $error_message,
            'created_at' => current_time('mysql')
        );
        
        if ($status === 'sent') {
            $data['sent_at'] = current_time('mysql');
        }
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Update email log
     */
    public function update_email_log($log_id, $status, $error_message = null, $sent_at = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        $data = array(
            'status' => $status
        );
        
        if ($error_message) {
            $data['error_message'] = $error_message;
        }
        
        if ($sent_at) {
            $data['sent_at'] = $sent_at;
        }
        
        return $wpdb->update($table, $data, array('id' => $log_id));
    }
    
    /**
     * Log mail failure
     */
    public function log_mail_failure($wp_error) {
        error_log('Property Manager Email Failed: ' . $wp_error->get_error_message());
    }
    
    /**
     * Clean up old email logs (older than 90 days)
     */
    public function cleanup_old_logs() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        $wpdb->query("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
    
    /**
     * Get email logs
     */
    public function get_email_logs($limit = 50, $offset = 0, $email_type = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        $where = '';
        $params = array();
        
        if ($email_type) {
            $where = 'WHERE email_type = %s';
            $params[] = $email_type;
        }
        
        $params[] = $limit;
        $params[] = $offset;
        
        $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get email statistics
     */
    public function get_email_stats($days = 30) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                email_type,
                status,
                COUNT(*) as count
            FROM $table 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY email_type, status
            ORDER BY email_type, status
        ", $days));
    }
    
    /**
     * Test email configuration
     */
    public function test_email_configuration($test_email = null) {
        $test_email = $test_email ?: get_option('admin_email');
        
        $subject = __('Email Test - Property Manager Pro', 'property-manager-pro');
        $message = '<h2>' . __('Email Configuration Test', 'property-manager-pro') . '</h2>';
        $message .= '<p>' . __('This is a test email to verify that your email configuration is working correctly.', 'property-manager-pro') . '</p>';
        $message .= '<p><strong>' . __('Sent at:', 'property-manager-pro') . '</strong> ' . current_time('F j, Y g:i A') . '</p>';
        
        return $this->send_email($test_email, $subject, $message, 'test');
    }
}