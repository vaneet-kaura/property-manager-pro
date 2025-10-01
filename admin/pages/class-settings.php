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
        
        <script>
        jQuery(document).ready(function($) {
            // Test feed URL
            $('#test-feed-url').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var feedUrl = $('#feed_url').val();
                var resultDiv = $('#feed-url-test-result');
                
                if (!feedUrl) {
                    resultDiv.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Please enter a feed URL first.', 'property-manager-pro')); ?></p></div>');
                    return;
                }
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'property-manager-pro')); ?>');
                resultDiv.html('<p><?php echo esc_js(__('Testing feed URL...', 'property-manager-pro')); ?></p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'property_manager_test_feed_url',
                        nonce: '<?php echo wp_create_nonce('property_manager_admin_nonce'); ?>',
                        feed_url: feedUrl
                    },
                    success: function(response) {
                        if (response.success) {
                            resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        } else {
                            resultDiv.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Error testing feed URL.', 'property-manager-pro')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Test URL', 'property-manager-pro')); ?>');
                    }
                });
            });
            
            // Send test email
            $('#test-email-settings').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                
                if (!confirm('<?php echo esc_js(__('Send a test email to verify email settings?', 'property-manager-pro')); ?>')) {
                    return;
                }
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'property-manager-pro')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'property_manager_test_email',
                        nonce: '<?php echo wp_create_nonce('property_manager_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'property-manager-pro')); ?> ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Error sending test email.', 'property-manager-pro')); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Test Email', 'property-manager-pro')); ?>');
                    }
                });
            });
        });
        </script>
        
        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-bottom: 20px;
            }
            .card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .notice.inline {
                margin: 10px 0;
                padding: 10px;
            }
        </style>
        <?php
    }
    
    /**
     * Initialize settings fields
     * This method should be called from class-admin.php
     */
    public function init_settings() {
        // Register settings with sanitization callback
        register_setting(
            'property_manager_settings',
            'property_manager_options',
            array($this, 'sanitize_options')
        );
        
        // General Settings Section
        add_settings_section(
            'property_manager_general',
            __('General Settings', 'property-manager-pro'),
            array($this, 'general_settings_description'),
            'property-manager-settings'
        );
        
        add_settings_field(
            'feed_url',
            __('Kyero Feed URL', 'property-manager-pro'),
            array($this, 'field_feed_url'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'import_frequency',
            __('Import Frequency', 'property-manager-pro'),
            array($this, 'field_import_frequency'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'immediate_image_download',
            __('Download Images Immediately', 'property-manager-pro'),
            array($this, 'field_immediate_image_download'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'results_per_page',
            __('Results Per Page', 'property-manager-pro'),
            array($this, 'field_results_per_page'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'default_view',
            __('Default View', 'property-manager-pro'),
            array($this, 'field_default_view'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        // Email Settings Section
        add_settings_section(
            'property_manager_email',
            __('Email Settings', 'property-manager-pro'),
            array($this, 'email_settings_description'),
            'property-manager-settings'
        );
        
        add_settings_field(
            'admin_email',
            __('Admin Email', 'property-manager-pro'),
            array($this, 'field_admin_email'),
            'property-manager-settings',
            'property_manager_email'
        );
        
        add_settings_field(
            'email_verification_required',
            __('Email Verification Required', 'property-manager-pro'),
            array($this, 'field_email_verification'),
            'property-manager-settings',
            'property_manager_email'
        );
        
        add_settings_field(
            'alert_frequency_daily',
            __('Daily Alert Time', 'property-manager-pro'),
            array($this, 'field_daily_alert_time'),
            'property-manager-settings',
            'property_manager_email'
        );
        
        // Map Settings Section
        add_settings_section(
            'property_manager_map',
            __('Map Settings', 'property-manager-pro'),
            array($this, 'map_settings_description'),
            'property-manager-settings'
        );
        
        add_settings_field(
            'enable_map',
            __('Enable Map View', 'property-manager-pro'),
            array($this, 'field_enable_map'),
            'property-manager-settings',
            'property_manager_map'
        );
        
        add_settings_field(
            'map_provider',
            __('Map Provider', 'property-manager-pro'),
            array($this, 'field_map_provider'),
            'property-manager-settings',
            'property_manager_map'
        );
        
        add_settings_field(
            'default_map_zoom',
            __('Default Map Zoom', 'property-manager-pro'),
            array($this, 'field_default_map_zoom'),
            'property-manager-settings',
            'property_manager_map'
        );
    }
    
    /**
     * Settings section descriptions
     */
    public function general_settings_description() {
        echo '<p>' . esc_html__('Configure general plugin settings and property import options.', 'property-manager-pro') . '</p>';
    }
    
    public function email_settings_description() {
        echo '<p>' . esc_html__('Configure email notifications and verification settings for property alerts.', 'property-manager-pro') . '</p>';
    }
    
    public function map_settings_description() {
        echo '<p>' . esc_html__('Configure map display settings for property locations. Using OpenStreetMap (free, open-source).', 'property-manager-pro') . '</p>';
    }
    
    /**
     * Sanitize and validate all options
     * SECURITY FIX: Comprehensive input validation
     */
    public function sanitize_options($input) {
        $sanitized = array();
        $errors = array();
        
        // Feed URL validation
        if (isset($input['feed_url'])) {
            $feed_url = esc_url_raw($input['feed_url'], array('http', 'https'));
            
            if (!empty($feed_url)) {
                $parsed_url = parse_url($feed_url);
                if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
                    $errors[] = __('Feed URL must use HTTP or HTTPS protocol.', 'property-manager-pro');
                    $sanitized['feed_url'] = '';
                } else {
                    $sanitized['feed_url'] = $feed_url;
                }
            } else {
                $sanitized['feed_url'] = '';
            }
        }
        
        // Import frequency validation
        if (isset($input['import_frequency'])) {
            $allowed_frequencies = array('hourly', 'twicedaily', 'daily');
            $frequency = sanitize_text_field($input['import_frequency']);
            
            if (in_array($frequency, $allowed_frequencies, true)) {
                $sanitized['import_frequency'] = $frequency;
            } else {
                $sanitized['import_frequency'] = 'hourly';
                $errors[] = __('Invalid import frequency selected.', 'property-manager-pro');
            }
        }
        
        // Results per page validation
        if (isset($input['results_per_page'])) {
            $results_per_page = absint($input['results_per_page']);
            
            if ($results_per_page < 1 || $results_per_page > 100) {
                $errors[] = __('Results per page must be between 1 and 100.', 'property-manager-pro');
                $sanitized['results_per_page'] = 20;
            } else {
                $sanitized['results_per_page'] = $results_per_page;
            }
        }
        
        // Default view validation
        if (isset($input['default_view'])) {
            $allowed_views = array('grid', 'list', 'map');
            $view = sanitize_text_field($input['default_view']);
            
            if (in_array($view, $allowed_views, true)) {
                $sanitized['default_view'] = $view;
            } else {
                $sanitized['default_view'] = 'grid';
                $errors[] = __('Invalid default view selected.', 'property-manager-pro');
            }
        }
        
        // Admin email validation
        if (isset($input['admin_email'])) {
            $email = sanitize_email($input['admin_email']);
            
            if (is_email($email)) {
                $sanitized['admin_email'] = $email;
            } else {
                $errors[] = __('Invalid admin email address.', 'property-manager-pro');
                $sanitized['admin_email'] = get_option('admin_email');
            }
        }
        
        // Daily alert time validation
        if (isset($input['alert_frequency_daily'])) {
            $time = sanitize_text_field($input['alert_frequency_daily']);
            if (preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                $sanitized['alert_frequency_daily'] = $time;
            } else {
                $sanitized['alert_frequency_daily'] = '09:00';
                $errors[] = __('Invalid time format for daily alerts.', 'property-manager-pro');
            }
        }
        
        // Map zoom validation
        if (isset($input['default_map_zoom'])) {
            $zoom = absint($input['default_map_zoom']);
            if ($zoom < 1 || $zoom > 18) {
                $sanitized['default_map_zoom'] = 13;
                $errors[] = __('Map zoom must be between 1 and 18.', 'property-manager-pro');
            } else {
                $sanitized['default_map_zoom'] = $zoom;
            }
        }
        
        // Boolean settings
        $sanitized['email_verification_required'] = isset($input['email_verification_required']) ? 1 : 0;
        $sanitized['enable_map'] = isset($input['enable_map']) ? 1 : 0;
        $sanitized['immediate_image_download'] = isset($input['immediate_image_download']) ? 1 : 0;
        
        // Map provider validation
        if (isset($input['map_provider'])) {
            $allowed_providers = array('openstreetmap', 'mapbox');
            $provider = sanitize_text_field($input['map_provider']);
            
            if (in_array($provider, $allowed_providers, true)) {
                $sanitized['map_provider'] = $provider;
            } else {
                $sanitized['map_provider'] = 'openstreetmap';
                $errors[] = __('Invalid map provider selected.', 'property-manager-pro');
            }
        }
        
        // Display errors
        if (!empty($errors)) {
            foreach ($errors as $error) {
                add_settings_error(
                    'property_manager_options',
                    'property_manager_error',
                    $error,
                    'error'
                );
            }
        } else {
            add_settings_error(
                'property_manager_options',
                'property_manager_success',
                __('Settings saved successfully.', 'property-manager-pro'),
                'success'
            );
        }
        
        return $sanitized;
    }
    
    /**
     * Settings field callbacks
     */
    public function field_feed_url() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['feed_url']) ? $options['feed_url'] : '';
        ?>
        <input type="url" 
               name="property_manager_options[feed_url]" 
               id="feed_url"
               value="<?php echo esc_attr($value); ?>" 
               class="large-text" 
               placeholder="https://example.com/feed.xml" />
        <p class="description">
            <?php esc_html_e('Enter the URL to your Kyero XML feed.', 'property-manager-pro'); ?>
            <button type="button" class="button button-secondary" id="test-feed-url" style="margin-left: 10px;">
                <?php esc_html_e('Test URL', 'property-manager-pro'); ?>
            </button>
        </p>
        <div id="feed-url-test-result" style="margin-top: 10px;"></div>
        <?php
    }
    
    public function field_import_frequency() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['import_frequency']) ? $options['import_frequency'] : 'hourly';
        
        $frequencies = array(
            'hourly' => __('Hourly', 'property-manager-pro'),
            'twicedaily' => __('Twice Daily', 'property-manager-pro'),
            'daily' => __('Daily', 'property-manager-pro')
        );
        ?>
        <select name="property_manager_options[import_frequency]" id="import_frequency">
            <?php foreach ($frequencies as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('How often should the feed be imported automatically?', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_immediate_image_download() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['immediate_image_download']) ? $options['immediate_image_download'] : 0;
        ?>
        <label>
            <input type="checkbox" 
                   name="property_manager_options[immediate_image_download]" 
                   value="1" 
                   <?php checked($value, 1); ?> />
            <?php esc_html_e('Download images immediately during feed import', 'property-manager-pro'); ?>
        </label>
        <p class="description"><?php esc_html_e('If disabled, images will be queued for background processing (recommended for large feeds).', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_results_per_page() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['results_per_page']) ? $options['results_per_page'] : 20;
        ?>
        <input type="number" 
               name="property_manager_options[results_per_page]" 
               value="<?php echo esc_attr($value); ?>" 
               min="1" 
               max="100" 
               class="small-text" />
        <p class="description"><?php esc_html_e('Number of properties to display per page (1-100).', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_default_view() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['default_view']) ? $options['default_view'] : 'grid';
        
        $views = array(
            'grid' => __('Grid View', 'property-manager-pro'),
            'list' => __('List View', 'property-manager-pro'),
            'map' => __('Map View', 'property-manager-pro')
        );
        ?>
        <select name="property_manager_options[default_view]" id="default_view">
            <?php foreach ($views as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Default view for property search results.', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_admin_email() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        ?>
        <input type="email" 
               name="property_manager_options[admin_email]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <p class="description"><?php esc_html_e('Email address for receiving property inquiries and notifications.', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_email_verification() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['email_verification_required']) ? $options['email_verification_required'] : 1;
        ?>
        <label>
            <input type="checkbox" 
                   name="property_manager_options[email_verification_required]" 
                   value="1" 
                   <?php checked($value, 1); ?> />
            <?php esc_html_e('Require email verification before sending property alerts', 'property-manager-pro'); ?>
        </label>
        <p class="description"><?php esc_html_e('Users must verify their email address before receiving alert notifications.', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_daily_alert_time() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['alert_frequency_daily']) ? $options['alert_frequency_daily'] : '09:00';
        ?>
        <input type="time" 
               name="property_manager_options[alert_frequency_daily]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <p class="description"><?php esc_html_e('Time to send daily property alerts (24-hour format).', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_enable_map() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['enable_map']) ? $options['enable_map'] : 1;
        ?>
        <label>
            <input type="checkbox" 
                   name="property_manager_options[enable_map]" 
                   value="1" 
                   <?php checked($value, 1); ?> />
            <?php esc_html_e('Enable map view for properties', 'property-manager-pro'); ?>
        </label>
        <p class="description"><?php esc_html_e('Show property locations on interactive maps.', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_map_provider() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';
        
        $providers = array(
            'openstreetmap' => __('OpenStreetMap (Free, Open Source)', 'property-manager-pro'),
            'mapbox' => __('Mapbox (API Key Required)', 'property-manager-pro')
        );
        ?>
        <select name="property_manager_options[map_provider]">
            <?php foreach ($providers as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Choose the map provider for displaying property locations.', 'property-manager-pro'); ?></p>
        <?php
    }
    
    public function field_default_map_zoom() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['default_map_zoom']) ? $options['default_map_zoom'] : 13;
        ?>
        <input type="number" 
               name="property_manager_options[default_map_zoom]" 
               value="<?php echo esc_attr($value); ?>" 
               min="1" 
               max="18" 
               class="small-text" />
        <p class="description">
            <?php esc_html_e('Default zoom level for property maps (1-18). Lower numbers show larger areas.', 'property-manager-pro'); ?>
            <br>
            <small><?php esc_html_e('Recommended: 13 for city view, 15 for neighborhood view, 17 for street view.', 'property-manager-pro'); ?></small>
        </p>
        <?php
    }
}