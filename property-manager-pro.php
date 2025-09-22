<?php
/**
 * Plugin Name: Property Manager Pro
 * Plugin URI: https://yourwebsite.com/property-manager-pro
 * Description: Complete property management system with Kyero feed integration, search functionality, user favorites, and property alerts.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: property-manager-pro
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PROPERTY_MANAGER_VERSION', '1.0.0');
define('PROPERTY_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PROPERTY_MANAGER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PROPERTY_MANAGER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Main plugin class
class PropertyManagerPro {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('PropertyManagerPro', 'uninstall'));
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('property-manager-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize components
        $this->includes();
        $this->init_hooks();
    }
    
    private function includes() {
        // Core classes
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-database.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-image-downloader.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-feed-importer.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-property.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-search.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-search-forms.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-ajax-search.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-user-manager.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-favorites.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-alerts.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-email.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-shortcodes.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-ajax.php';
        
        // Admin classes
        if (is_admin()) {
            require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/class-admin.php';
        }
        
        // Public classes
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'public/class-public.php';
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'init_components'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Schedule cron jobs
        add_action('wp', array($this, 'schedule_cron_jobs'));
    }
    
    public function init_components() {
        // Initialize core components
        PropertyManager_Database::get_instance();
        PropertyManager_ImageDownloader::get_instance();
        PropertyManager_Property::get_instance();
        PropertyManager_Search::get_instance();
        PropertyManager_SearchForms::get_instance();
        PropertyManager_AjaxSearch::get_instance();
        PropertyManager_UserManager::get_instance();
        PropertyManager_Favorites::get_instance();
        PropertyManager_Alerts::get_instance();
        PropertyManager_Email::get_instance();
        PropertyManager_Shortcodes::get_instance();
        PropertyManager_Ajax::get_instance();
        
        if (is_admin()) {
            PropertyManager_Admin::get_instance();
        } else {
            PropertyManager_Public::get_instance();
        }
    }
    
    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style(
            'property-manager-style',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/property-manager.css',
            array(),
            PROPERTY_MANAGER_VERSION
        );
        
        // JavaScript
        wp_enqueue_script(
            'property-manager-script',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/property-manager.js',
            array('jquery'),
            PROPERTY_MANAGER_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('property-manager-script', 'property_manager_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('property_manager_nonce'),
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        // Admin CSS
        wp_enqueue_style(
            'property-manager-admin-style',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PROPERTY_MANAGER_VERSION
        );
        
        // Admin JavaScript
        wp_enqueue_script(
            'property-manager-admin-script',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            PROPERTY_MANAGER_VERSION,
            true
        );
    }
    
    public function schedule_cron_jobs() {
        // Schedule feed import (hourly)
        if (!wp_next_scheduled('property_manager_import_feed')) {
            wp_schedule_event(time(), 'hourly', 'property_manager_import_feed');
        }
        
        // Schedule image processing (every 30 minutes)
        if (!wp_next_scheduled('property_manager_process_images')) {
            wp_schedule_event(time(), 'thirtyminutes', 'property_manager_process_images');
        }
        
        // Schedule daily alerts
        if (!wp_next_scheduled('property_manager_daily_alerts')) {
            wp_schedule_event(strtotime('08:00:00'), 'daily', 'property_manager_daily_alerts');
        }
        
        // Schedule weekly alerts
        if (!wp_next_scheduled('property_manager_weekly_alerts')) {
            wp_schedule_event(strtotime('Monday 08:00:00'), 'weekly', 'property_manager_weekly_alerts');
        }
        
        // Schedule monthly alerts
        if (!wp_next_scheduled('property_manager_monthly_alerts')) {
            wp_schedule_event(strtotime('first day of next month 08:00:00'), 'monthly', 'property_manager_monthly_alerts');
        }
        
        // Add custom cron interval for image processing
        add_filter('cron_schedules', function($schedules) {
            $schedules['thirtyminutes'] = array(
                'interval' => 30 * 60,
                'display' => __('Every 30 Minutes', 'property-manager-pro')
            );
            return $schedules;
        });
		
		if(!wp_next_scheduled('property_manager_weekly_alerts')) {
            wp_schedule_event(strtotime('Monday 08:00:00'), 'weekly', 'property_manager_weekly_alerts');
        }
        
        // Schedule monthly alerts
        if (!wp_next_scheduled('property_manager_monthly_alerts')) {
            wp_schedule_event(strtotime('first day of next month 08:00:00'), 'monthly', 'property_manager_monthly_alerts');
        }
    }
    
    public function activate() {
		require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-database.php';
		
        // Create database tables
        PropertyManager_Database::create_tables();
        
        // Create default pages
        $this->create_default_pages();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('property_manager_import_feed');
        wp_clear_scheduled_hook('property_manager_process_images');
        wp_clear_scheduled_hook('property_manager_daily_alerts');
        wp_clear_scheduled_hook('property_manager_weekly_alerts');
        wp_clear_scheduled_hook('property_manager_monthly_alerts');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        // Remove database tables
        PropertyManager_Database::drop_tables();
        
        // Optionally remove created pages (uncomment if you want to delete pages on uninstall)
        /*
        $created_pages = get_option('property_manager_pages', array());
        foreach ($created_pages as $page_id) {
            wp_delete_post($page_id, true); // true = force delete permanently
        }
        */
        
        // Remove options
        delete_option('property_manager_options');
        delete_option('property_manager_feed_url');
        delete_option('property_manager_pages');
        delete_option('property_manager_db_version');
        
        // Clear any remaining scheduled events
        wp_clear_scheduled_hook('property_manager_import_feed');
        wp_clear_scheduled_hook('property_manager_process_images');
        wp_clear_scheduled_hook('property_manager_daily_alerts');
        wp_clear_scheduled_hook('property_manager_weekly_alerts');
        wp_clear_scheduled_hook('property_manager_monthly_alerts');
    }
    
    private function create_default_pages() {
        // Check if pages are already created
        $existing_pages = get_option('property_manager_pages', array());
        
        // If pages option exists and has content, check if pages still exist
        if (!empty($existing_pages)) {
            $pages_exist = true;
            foreach ($existing_pages as $page_id) {
                if (!get_post($page_id)) {
                    $pages_exist = false;
                    break;
                }
            }
            
            // If all pages still exist, don't create new ones
            if ($pages_exist) {
                return;
            }
        }
        
        $pages = array(
            'property_search' => array(
                'title' => __('Property Search', 'property-manager-pro'),
                'content' => '[property_search_form] [property_search_results]',
                'slug' => 'property-search'
            ),
            'property_advanced_search' => array(
                'title' => __('Advanced Property Search', 'property-manager-pro'),
                'content' => '[property_advanced_search_form] [property_search_results]',
                'slug' => 'advanced-property-search'
            ),
            'user_dashboard' => array(
                'title' => __('User Dashboard', 'property-manager-pro'),
                'content' => '[property_user_dashboard]',
                'slug' => 'user-dashboard'
            ),
            'user_favorites' => array(
                'title' => __('My Favorites', 'property-manager-pro'),
                'content' => '[property_user_favorites]',
                'slug' => 'my-favorites'
            ),
            'saved_searches' => array(
                'title' => __('Saved Searches', 'property-manager-pro'),
                'content' => '[property_saved_searches]',
                'slug' => 'saved-searches'
            ),
            'property_alerts' => array(
                'title' => __('Property Alerts', 'property-manager-pro'),
                'content' => '[property_alerts_management]',
                'slug' => 'property-alerts'
            ),
            'last_viewed' => array(
                'title' => __('Last Viewed Properties', 'property-manager-pro'),
                'content' => '[property_last_viewed]',
                'slug' => 'last-viewed-properties'
            )
        );
        
        $created_pages = array();
        
        foreach ($pages as $page_key => $page_data) {
            // Check if a page with this slug already exists
            $existing_page = get_page_by_path($page_data['slug']);
            
            if ($existing_page) {
                // Page already exists, use the existing page ID
                $created_pages[$page_key] = $existing_page->ID;
            } else {
                // Create new page
                $page_id = wp_insert_post(array(
                    'post_title' => $page_data['title'],
                    'post_content' => $page_data['content'],
                    'post_name' => $page_data['slug'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_author' => get_current_user_id()
                ));
                
                if ($page_id && !is_wp_error($page_id)) {
                    $created_pages[$page_key] = $page_id;
                }
            }
        }
        
        update_option('property_manager_pages', $created_pages);
    }
    
    private function set_default_options() {
        // Only set defaults if options don't exist
        $existing_options = get_option('property_manager_options');
        
        if ($existing_options === false) {
            // First time activation - set all defaults
            $default_options = array(
                'feed_url' => 'https://frontlinepropertiesspain.com/xml/kyero.php?f=6878c2aa69875',
                'import_frequency' => 'hourly',
                'results_per_page' => 20,
                'default_view' => 'grid',
                'enable_map' => true,
                'map_provider' => 'openstreetmap',
                'email_verification_required' => true,
                'admin_email' => get_option('admin_email'),
                'currency_symbol' => '€',
                'default_language' => 'en',
                'immediate_image_download' => false
            );
            
            add_option('property_manager_options', $default_options);
        } else {
            // Plugin reactivation - only add missing options
            $default_options = array(
                'feed_url' => 'https://frontlinepropertiesspain.com/xml/kyero.php?f=6878c2aa69875',
                'import_frequency' => 'hourly',
                'results_per_page' => 20,
                'default_view' => 'grid',
                'enable_map' => true,
                'map_provider' => 'openstreetmap',
                'email_verification_required' => true,
                'admin_email' => get_option('admin_email'),
                'currency_symbol' => '€',
                'default_language' => 'en',
                'immediate_image_download' => false
            );
            
            $updated_options = wp_parse_args($existing_options, $default_options);
            update_option('property_manager_options', $updated_options);
        }
    }
}

// Initialize the plugin
PropertyManagerPro::get_instance();