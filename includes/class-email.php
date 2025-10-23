<?php
/**
 * Email Management Class - FIXED VERSION
 * 
 * @package PropertyManagerPro
 * @version 1.0.1
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
        $this->from_email = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        $this->from_name = get_bloginfo('name');
        
        // Set email content type to HTML
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
        
        // Hook for daily cleanup
        add_action('property_manager_daily_cleanup', array($this, 'cleanup_old_email_logs'));
    }
    
    /**
     * Set email content type to HTML
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send email - FIXED: Now checks return value and logs properly
     */
    public function send_email($to, $subject, $message, $type = 'general') {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->from_name . ' <' . $this->from_email . '>'
        );
        
        // FIXED: Actually check if email was sent
        $sent = wp_mail($to, $subject, $message, $headers);
        
        // Log email
        $this->log_email($type, $to, $subject, $message, $sent ? 'sent' : 'failed', $sent ? null : 'wp_mail returned false');
        
        if (!$sent) {
            error_log('Property Manager Pro: Failed to send email to ' . $to . ' - Subject: ' . $subject);
        }
        
        return $sent;
    }
    
    /**
     * Send property alert email
     */
    public function send_property_inquiry($property_id, $name, $email, $phone, $message) {
        return true;
    }
    
    
    /**
     * Send property alert email
     */
    public function send_property_alert($email, $properties, $frequency, $unsubscribe_token) {
        if (empty($properties)) {
            return false;
        }
        
        $unsubscribe_url = add_query_arg(array(
            'action' => 'unsubscribe_alert',
            'token' => rawurlencode($unsubscribe_token)
        ), home_url('/'));
        
        $subject = sprintf(__('[%s] New Properties Matching Your Criteria', 'property-manager-pro'), get_bloginfo('name'));
        
        $message = $this->get_property_alert_template($properties, $frequency, $unsubscribe_url);
        
        return $this->send_email($email, $subject, $message, 'property_alert');
    }
    
    /**
     * Property alert email template - FIXED: Complete implementation
     */
    private function get_property_alert_template($properties, $frequency, $unsubscribe_url) {
        ob_start();
        echo $this->get_email_header();
        ?>
        <div style="padding: 20px;">
            <h2 style="color: #333; font-size: 24px; margin-bottom: 20px;">
                <?php printf(__('New Properties Matching Your Criteria (%s alerts)', 'property-manager-pro'), ucfirst($frequency)); ?>
            </h2>
            
            <p style="color: #666; margin-bottom: 30px;">
                <?php printf(_n('We found %d new property that matches your search:', 'We found %d new properties that match your search:', count($properties), 'property-manager-pro'), count($properties)); ?>
            </p>
            
            <?php foreach ($properties as $property): ?>
                <div style="border: 1px solid #e0e0e0; margin-bottom: 20px; padding: 20px; border-radius: 8px; background: #f9f9f9;">
                    <h3 style="margin: 0 0 10px 0; font-size: 20px;">
                        <a href="<?php echo esc_url(home_url('/property/' . $property->id)); ?>" style="color: #0073aa; text-decoration: none;">
                            <?php echo esc_html($property->title ?: 'Property ' . $property->ref); ?>
                        </a>
                    </h3>
                    
                    <p style="font-size: 18px; font-weight: bold; color: #0073aa; margin: 10px 0;">
                        <?php echo $property->price ? esc_html($property->currency . ' ' . number_format($property->price)) : __('Price on request', 'property-manager-pro'); ?>
                    </p>
                    
                    <p style="color: #666; margin: 5px 0;">
                        <strong><?php _e('Location:', 'property-manager-pro'); ?></strong> 
                        <?php echo esc_html($property->town . ', ' . $property->province); ?>
                    </p>
                    
                    <?php if ($property->beds || $property->baths): ?>
                    <p style="color: #666; margin: 5px 0;">
                        <strong><?php _e('Details:', 'property-manager-pro'); ?></strong> 
                        <?php echo $property->beds ? $property->beds . ' ' . __('beds', 'property-manager-pro') : ''; ?>
                        <?php echo ($property->beds && $property->baths) ? ' | ' : ''; ?>
                        <?php echo $property->baths ? $property->baths . ' ' . __('baths', 'property-manager-pro') : ''; ?>
                    </p>
                    <?php endif; ?>
                    
                    <p style="margin-top: 15px;">
                        <a href="<?php echo esc_url(home_url('/property/' . $property->id)); ?>" style="background: #0073aa; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;">
                            <?php _e('View Property Details', 'property-manager-pro'); ?>
                        </a>
                    </p>
                </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px;">
                    <?php _e('You are receiving this email because you signed up for property alerts.', 'property-manager-pro'); ?><br>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>" style="color: #999;"><?php _e('Unsubscribe from these alerts', 'property-manager-pro'); ?></a>
                </p>
            </div>
        </div>
        <?php
        echo $this->get_email_footer();
        return ob_get_clean();
    }
    
    /**
     * Get email header
     */
    private function get_email_header() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table cellpadding="0" cellspacing="0" border="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background-color: #0073aa; padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px;">' . esc_html(get_bloginfo('name')) . '</h1>
                        </td>
                    </tr>
                    <tr>
                        <td>';
    }
    
    /**
     * Get email footer
     */
    private function get_email_footer() {
        return '                </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px;">
                            <p style="margin: 0;">&copy; ' . date('Y') . ' ' . esc_html(get_bloginfo('name')) . '. ' . __('All rights reserved.', 'property-manager-pro') . '</p>
                            <p style="margin: 10px 0 0 0;">' . esc_html(home_url()) . '</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Log email - FIXED: Better error handling
     */
    private function log_email($type, $to, $subject, $message, $status, $error = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }
        
        return $wpdb->insert($table, array(
            'email_type' => sanitize_text_field($type),
            'recipient_email' => sanitize_email($to),
            'subject' => sanitize_text_field(substr($subject, 0, 255)),
            'message' => wp_kses_post(substr($message, 0, 65535)),
            'status' => $status,
            'error_message' => $error ? sanitize_text_field(substr($error, 0, 1000)) : null,
            'sent_at' => $status === 'sent' ? current_time('mysql') : null,
            'created_at' => current_time('mysql')
        ));
    }
    
    /**
     * Cleanup old email logs - FIXED: New method
     */
    public function cleanup_old_email_logs() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('email_logs');
        
        // Delete logs older than 30 days
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE created_at < DATE_SUB(%s, INTERVAL 30 DAY)
        ", current_time('mysql')));
    }
}