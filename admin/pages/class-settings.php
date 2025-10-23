<?php
/**
 * Admin Settings Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Settings initialization is handled in class-admin.php
        // This class only handles rendering
    }
    
    /**
     * Render Settings page
     * SECURITY FIX: Added capability check
     */
    public function render() {
        // Capability check - SECURITY FIX
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'property-manager-pro'),
                esc_html__('Permission Denied', 'property-manager-pro'),
                array('response' => 403)
            );
        }
        
        // Get current options
        $options = get_option('property_manager_options', array());
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Property Manager Settings', 'property-manager-pro'); ?></h1>
            
            <?php settings_errors('property_manager_options'); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('property_manager_settings');
                do_settings_sections('property-manager-settings');
                submit_button();
                ?>
            </form>
            
            <!-- Quick Actions -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Quick Actions', 'property-manager-pro'); ?></h2>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-import')); ?>" class="button button-secondary">
                        <?php esc_html_e('Import Feed Now', 'property-manager-pro'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=property-manager-images')); ?>" class="button button-secondary">
                        <?php esc_html_e('Process Images', 'property-manager-pro'); ?>
                    </a>
                    <button type="button" class="button button-secondary" id="test-email-settings">
                        <?php esc_html_e('Send Test Email', 'property-manager-pro'); ?>
                    </button>
                </p>
            </div>
            
            <!-- System Information -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('System Information', 'property-manager-pro'); ?></h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Plugin Version:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(PROPERTY_MANAGER_VERSION); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('WordPress Version:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('PHP Version:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(phpversion()); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Max Upload Size:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(size_format(wp_max_upload_size())); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Memory Limit:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(ini_get('memory_limit')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Max Execution Time:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo esc_html(ini_get('max_execution_time')); ?> <?php esc_html_e('seconds', 'property-manager-pro'); ?></td>
                        </tr>
                        <?php
                        global $wpdb;
                        $properties_count = $wpdb->get_var("SELECT COUNT(*) FROM " . PropertyManager_Database::get_table_name('properties'));
                        $images_count = $wpdb->get_var("SELECT COUNT(*) FROM " . PropertyManager_Database::get_table_name('property_images'));
                        ?>
                        <tr>
                            <td><strong><?php esc_html_e('Total Properties:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo number_format_i18n($properties_count); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Total Images:', 'property-manager-pro'); ?></strong></td>
                            <td><?php echo number_format_i18n($images_count); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Debug Information -->
            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Debug Information', 'property-manager-pro'); ?></h2>
                <p class="description"><?php esc_html_e('This section is only visible when WP_DEBUG is enabled.', 'property-manager-pro'); ?></p>
                <textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;"><?php
                    echo "Plugin Version: " . PROPERTY_MANAGER_VERSION . "\n";
                    echo "WordPress Version: " . get_bloginfo('version') . "\n";
                    echo "PHP Version: " . phpversion() . "\n";
                    echo "Site URL: " . get_site_url() . "\n";
                    echo "Upload Directory: " . wp_upload_dir()['basedir'] . "\n";
                    echo "\nActive Plugins:\n";
                    $active_plugins = get_option('active_plugins');
                    foreach ($active_plugins as $plugin) {
                        echo "- " . $plugin . "\n";
                    }
                    echo "\nSettings:\n";
                    print_r($options);
                ?></textarea>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}