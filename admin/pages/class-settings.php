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
        add_action('admin_init', array($this, 'init_settings'));            
    }
    
    /**
     * Render Settings page
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php _e('Property Manager Settings', 'property-manager-pro'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('property_manager_settings');
                do_settings_sections('property-manager-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('property_manager_settings', 'property_manager_options');
        
        // General Settings
        add_settings_section(
            'property_manager_general',
            __('General Settings', 'property-manager-pro'),
            null,
            'property-manager-settings'
        );
        
        add_settings_field(
            'immediate_image_download',
            __('Download Images Immediately', 'property-manager-pro'),
            array($this, 'field_immediate_image_download'),
            'property-manager-settings',
            'property_manager_general'
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
        
        // Email Settings
        add_settings_section(
            'property_manager_email',
            __('Email Settings', 'property-manager-pro'),
            null,
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
        
        // Map Settings
        add_settings_section(
            'property_manager_map',
            __('Map Settings', 'property-manager-pro'),
            null,
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
    }

    public function field_feed_url() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['feed_url']) ? $options['feed_url'] : '';
        
        echo '<input type="url" name="property_manager_options[feed_url]" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">' . __('Enter the URL to your Kyero XML feed.', 'property-manager-pro') . '</p>';
    }
    
    public function field_import_frequency() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['import_frequency']) ? $options['import_frequency'] : 'hourly';
        
        $frequencies = array(
            'hourly' => __('Hourly', 'property-manager-pro'),
            'twicedaily' => __('Twice Daily', 'property-manager-pro'),
            'daily' => __('Daily', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[import_frequency]">';
        foreach ($frequencies as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function field_results_per_page() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['results_per_page']) ? $options['results_per_page'] : 20;
        
        echo '<input type="number" name="property_manager_options[results_per_page]" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<p class="description">' . __('Number of properties to show per page in search results.', 'property-manager-pro') . '</p>';
    }
    
    public function field_default_view() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['default_view']) ? $options['default_view'] : 'grid';
        
        $views = array(
            'grid' => __('Grid View', 'property-manager-pro'),
            'list' => __('List View', 'property-manager-pro'),
            'map' => __('Map View', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[default_view]">';
        foreach ($views as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function field_admin_email() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        echo '<input type="email" name="property_manager_options[admin_email]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Email address for receiving property inquiries.', 'property-manager-pro') . '</p>';
    }
    
    public function field_email_verification() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['email_verification_required']) ? $options['email_verification_required'] : true;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[email_verification_required]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Require email verification before sending property alerts', 'property-manager-pro');
        echo '</label>';
    }
    
    public function field_enable_map() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['enable_map']) ? $options['enable_map'] : true;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[enable_map]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Enable map view for properties', 'property-manager-pro');
        echo '</label>';
    }
    
    public function field_immediate_image_download() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['immediate_image_download']) ? $options['immediate_image_download'] : false;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[immediate_image_download]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Download property images immediately during import (may slow down import process)', 'property-manager-pro');
        echo '</label>';
        echo '<p class="description">' . __('If unchecked, images will be processed in background via cron jobs.', 'property-manager-pro') . '</p>';
    }
    
    public function field_map_provider() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';
        
        $providers = array(
            'openstreetmap' => __('OpenStreetMap (Free)', 'property-manager-pro'),
            'mapbox' => __('Mapbox', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[map_provider]">';
        foreach ($providers as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
}