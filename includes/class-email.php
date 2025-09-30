<?php
/**
 * Email Manager Class - Production Ready
 * Handles all email functionality with logging and templates
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Email {
    
    private static $instance = null;
    private $from_email;
    private $from_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $options = get_option('property_manager_options', array());
        $this->from_email = !empty($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        $this->from_name = get_bloginfo('name');
        
        // Set custom from email
        add_filter('wp_mail_from', array($this, 'set_from_email'));
        add_filter('wp_mail_from_name', array($this, 'set_from_name'));
    }
    
    /**
     * Set from email
     */
    public function set_from_email($email) {
        return $this->from_email;
    }
    
    /**
     * Set from name
     */
    public function set_from_name($name) {
        return $this->from_name;
    }
    
    /**
     * Send email with logging
     */
    private function send_email($to, $subject, $message, $headers = array(), $attachments = array()) {
        // Validate email
        if (!is_email($to)) {
            $this->log_email('invalid_email', $to, $subject, $message, 'failed', 'Invalid email address');
            return false;
        }
        
        // Default headers
        if (empty($headers)) {
            $headers = array('Content-Type: text/html; charset=UTF-8');
        }
        
        // Send email
        $result = wp_mail($to, $subject, $message, $headers, $attachments);
        
        // Log email
        $status = $result ? 'sent' : 'failed';
        $error = $result ? null : 'wp_mail returned false';
        $this->log_email('general', $to, $subject, $message, $status, $error);
        
        return $result;
    }
    
    /**
     * Log email to database
     */
    private function log_email($type, $to, $subject, $message, $status, $error = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        $wpdb->insert($table, array(
            'email_type' => sanitize_text_field($type),
            'recipient_email' => sanitize_email($to),
            'subject' => sanitize_text_field($subject),
            'message' => wp_kses_post($message),
            'status' => $status,
            'error_message' => $error ? sanitize_text_field($error) : null,
            'sent_at' => $status === 'sent' ? current_time('mysql', true) : null,
            'created_at' => current_time('mysql', true)
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
    }
    
    /**
     * Send verification email
     */
    public function send_verification_email($email, $name, $token, $alert_id) {
        $verification_url = add_query_arg(array(
            'action' => 'verify_alert',
            'token' => rawurlencode($token),
            'alert_id' => absint($alert_id)
        ), home_url('/'));
        
        $subject = sprintf(__('[%s] Verify Your Property Alert', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->get_email_template('verification', array(
            'name' => esc_html($name),
            'verification_url' => esc_url($verification_url),
            'site_name' => get_bloginfo('name')
        ));
        
        return $this->send_email($email, $subject, $message);
    }
    
    /**
     * Send welcome email
     */
    public function send_welcome_email($email, $name) {
        $subject = sprintf(__('Welcome to %s', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->get_email_template('welcome', array(
            'name' => esc_html($name),
            'site_name' => get_bloginfo('name'),
            'dashboard_url' => esc_url(home_url('/user-dashboard'))
        ));
        
        return $this->send_email($email, $subject, $message);
    }
    
    /**
     * Send property alert email
     */
    public function send_property_alert($email, $properties, $alert_frequency, $unsubscribe_token) {
        if (empty($properties)) {
            return false;
        }
        
        $unsubscribe_url = add_query_arg(array(
            'action' => 'unsubscribe_alert',
            'token' => rawurlencode($unsubscribe_token)
        ), home_url('/'));
        
        $subject = sprintf(__('[%s] New Properties Matching Your Criteria', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->get_email_template('property_alert', array(
            'properties' => $properties,
            'frequency' => $alert_frequency,
            'unsubscribe_url' => esc_url($unsubscribe_url),
            'site_name' => get_bloginfo('name')
        ));
        
        return $this->send_email($email, $subject, $message);
    }
    
    /**
     * Send inquiry notification to admin
     */
    public function send_inquiry_notification($inquiry_data) {
        $admin_email = $this->from_email;
        
        $subject = sprintf(__('[%s] New Property Inquiry', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->get_email_template('inquiry_notification', array(
            'inquiry' => $inquiry_data,
            'admin_url' => admin_url('admin.php?page=property-manager-inquiries')
        ));
        
        return $this->send_email($admin_email, $subject, $message);
    }
    
    /**
     * Get email template
     */
    private function get_email_template($template_name, $data = array()) {
        ob_start();
        
        // Header
        echo $this->get_email_header();
        
        // Template content
        switch ($template_name) {
            case 'verification':
                include $this->locate_template('email-verification.php', $data);
                break;
            case 'welcome':
                include $this->locate_template('email-welcome.php', $data);
                break;
            case 'property_alert':
                include $this->locate_template('email-property-alert.php', $data);
                break;
            case 'inquiry_notification':
                include $this->locate_template('email-inquiry-notification.php', $data);
                break;
            default:
                echo wp_kses_post($data['message'] ?? '');
        }
        
        // Footer
        echo $this->get_email_footer();
        
        return ob_get_clean();
    }
    
    /**
     * Locate email template
     */
    private function locate_template($template_name, $data = array()) {
        // Check theme
        $theme_template = locate_template(array(
            'property-manager/emails/' . $template_name,
            'emails/' . $template_name
        ));
        
        if ($theme_template) {
            extract($data);
            return $theme_template;
        }
        
        // Check plugin
        $plugin_template = PROPERTY_MANAGER_PLUGIN_PATH . 'public/templates/emails/' . $template_name;
        
        if (file_exists($plugin_template)) {
            extract($data);
            return $plugin_template;
        }
        
        // Fallback to inline
        return $this->get_inline_template($template_name, $data);
    }
    
    /**
     * Get inline template fallback
     */
    private function get_inline_template($template_name, $data) {
        switch ($template_name) {
            case 'email-verification.php':
                ?>
                <p><?php printf(__('Hi %s,', 'property-manager-pro'), $data['name']); ?></p>
                <p><?php _e('Thank you for subscribing to property alerts!', 'property-manager-pro'); ?></p>
                <p><a href="<?php echo $data['verification_url']; ?>" style="background:#0073aa;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">
                    <?php _e('Verify Email Address', 'property-manager-pro'); ?>
                </a></p>
                <p><?php _e('If you did not request this, please ignore this email.', 'property-manager-pro'); ?></p>
                <?php
                break;
                
            case 'email-welcome.php':
                ?>
                <p><?php printf(__('Welcome to %s, %s!', 'property-manager-pro'), $data['site_name'], $data['name']); ?></p>
                <p><?php _e('Your account has been created successfully.', 'property-manager-pro'); ?></p>
                <p><a href="<?php echo $data['dashboard_url']; ?>"><?php _e('Go to Dashboard', 'property-manager-pro'); ?></a></p>
                <?php
                break;
                
            case 'email-property-alert.php':
                ?>
                <h2><?php _e('New Properties Matching Your Criteria', 'property-manager-pro'); ?></h2>
                <?php foreach ($data['properties'] as $property): ?>
                    <div style="border:1px solid #ddd;padding:15px;margin:10px 0;">
                        <h3><?php echo esc_html($property->title); ?></h3>
                        <p><strong><?php echo esc_html($property->currency); ?> <?php echo number_format($property->price); ?></strong></p>
                        <p><?php echo esc_html($property->town); ?>, <?php echo esc_html($property->province); ?></p>
                        <p><a href="<?php echo esc_url(home_url('/property/' . $property->id)); ?>"><?php _e('View Property', 'property-manager-pro'); ?></a></p>
                    </div>
                <?php endforeach; ?>
                <p><small><a href="<?php echo $data['unsubscribe_url']; ?>"><?php _e('Unsubscribe', 'property-manager-pro'); ?></a></small></p>
                <?php
                break;
        }
    }
    
    /**
     * Get email header
     */
    private function get_email_header() {
        $header = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . esc_html(get_bloginfo('name')) . '</title>
        </head>
        <body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f5f5f5;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd;border-radius:8px;">
                            <tr>
                                <td style="padding:20px;text-align:center;background:#0073aa;color:#fff;border-radius:8px 8px 0 0;">
                                    <h1 style="margin:0;font-size:24px;">' . esc_html(get_bloginfo('name')) . '</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:30px;color:#333;line-height:1.6;">';
        
        return $header;
    }
    
    /**
     * Get email footer
     */
    private function get_email_footer() {
        $footer = '              </td>
                            </tr>
                            <tr>
                                <td style="padding:20px;text-align:center;background:#f9f9f9;border-top:1px solid #ddd;color:#666;font-size:12px;">
                                    <p style="margin:0;">&copy; ' . date('Y') . ' ' . esc_html(get_bloginfo('name')) . '</p>
                                    <p style="margin:5px 0 0 0;"><a href="' . esc_url(home_url()) . '" style="color:#0073aa;">' . esc_url(home_url()) . '</a></p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
        
        return $footer;
    }
    
    /**
     * Test email functionality
     */
    public function send_test_email($to_email) {
        $subject = __('Test Email from Property Manager Pro', 'property-manager-pro');
        $message = '<p>' . __('This is a test email to verify email functionality is working correctly.', 'property-manager-pro') . '</p>';
        $message = $this->get_email_header() . $message . $this->get_email_footer();
        
        return $this->send_email($to_email, $subject, $message);
    }
}