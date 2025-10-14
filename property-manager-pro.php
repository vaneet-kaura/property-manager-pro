<?php
/**
 * Plugin Name: Property Manager Pro
 * Plugin URI: https://yourwebsite.com/property-manager-pro
 * Description: Complete property management system with Kyero feed integration, search functionality, user favorites, and property alerts.
 * Version: 1.0.1
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: property-manager-pro
 * Domain Path: /languages
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PROPERTY_MANAGER_VERSION', '1.0.1');
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
        load_plugin_textdomain('property-manager-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
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
        
        if (is_admin()) {
            require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/class-admin.php';
        }
        
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'public/class-public.php';
    }

    private function init_hooks() {
        add_action('init', array($this, 'init_components'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // FIXED: Register custom cron schedule before scheduling jobs
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('wp', array($this, 'schedule_cron_jobs'));
    }

    public function init_components() {
        PropertyManager_Database::get_instance();
        PropertyManager_ImageDownloader::get_instance();
        PropertyManager_FeedImporter::get_instance();
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
		wp_enqueue_style(
            'property-manager-style',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/property-manager.css',
            array(),
            PROPERTY_MANAGER_VERSION
        );        
		
        wp_enqueue_script(
            'property-manager-script',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/property-manager.js',
            array('jquery'),
            PROPERTY_MANAGER_VERSION,
            true
        );
		
		wp_localize_script('property-manager-script', 'property_manager_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('property_manager_nonce'),
        ));
    }

    public function admin_enqueue_scripts($hook) {
        wp_enqueue_style(
            'property-manager-admin-style',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PROPERTY_MANAGER_VERSION
        );
        
        wp_enqueue_script(
            'property-manager-admin-script',
            PROPERTY_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            PROPERTY_MANAGER_VERSION,
            true
        );
    }

    /**
     * Add custom cron schedules
     * FIXED: Register 'thirtyminutes' interval for image processing
     */
    public function add_cron_schedules($schedules) {
        // Add 30 minutes interval
        if (!isset($schedules['thirtyminutes'])) {
            $schedules['thirtyminutes'] = array(
                'interval' => 30 * 60, // 30 minutes in seconds
                'display' => __('Every 30 Minutes', 'property-manager-pro')
            );
        }
        
        // Add 15 minutes interval (optional - for more frequent processing)
        if (!isset($schedules['fifteenminutes'])) {
            $schedules['fifteenminutes'] = array(
                'interval' => 15 * 60, // 15 minutes in seconds
                'display' => __('Every 15 Minutes', 'property-manager-pro')
            );
        }
        
        return $schedules;
    }

    /**
     * Schedule cron jobs
     * FIXED: Better error handling and logging
     */
    public function schedule_cron_jobs() {
        // Feed import - hourly
        if (!wp_next_scheduled('property_manager_import_feed')) {
            $scheduled = wp_schedule_event(time(), 'hourly', 'property_manager_import_feed');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule feed import cron job');
            }
        }
        
        // Image processing - every 30 minutes (now that we registered the interval)
        // NOTE: If you want to use 'hourly' instead, change 'thirtyminutes' to 'hourly'
        if (!wp_next_scheduled('property_manager_process_images')) {
            $scheduled = wp_schedule_event(time(), 'thirtyminutes', 'property_manager_process_images');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule image processing cron job');
            }
        }
        
        // Daily alerts - 8 AM
        if (!wp_next_scheduled('property_manager_daily_alerts')) {
            $scheduled = wp_schedule_event(strtotime('tomorrow 08:00:00'), 'daily', 'property_manager_daily_alerts');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule daily alerts cron job');
            }
        }
        
        // Weekly alerts - Monday 8 AM
        if (!wp_next_scheduled('property_manager_weekly_alerts')) {
            $next_monday = strtotime('next Monday 08:00:00');
            $scheduled = wp_schedule_event($next_monday, 'weekly', 'property_manager_weekly_alerts');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule weekly alerts cron job');
            }
        }
        
        // Monthly alerts - First day of next month 8 AM
        if (!wp_next_scheduled('property_manager_monthly_alerts')) {
            $next_month = strtotime('first day of next month 08:00:00');
            $scheduled = wp_schedule_event($next_month, 'monthly', 'property_manager_monthly_alerts');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule monthly alerts cron job');
            }
        }
        
        // Daily cleanup - 2 AM
        if (!wp_next_scheduled('property_manager_daily_cleanup')) {
            $scheduled = wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'property_manager_daily_cleanup');
            if ($scheduled === false) {
                error_log('Property Manager Pro: Failed to schedule daily cleanup cron job');
            }
        }
    }

    /**
     * Plugin activation
     * FIXED: Added rewrite rules flush and better error handling
     */
    public function activate() {
        try {
            // Create database tables
            require_once PROPERTY_MANAGER_PLUGIN_PATH . 'includes/class-database.php';
            $db_result = PropertyManager_Database::create_tables();
            
            if (!$db_result) {
                error_log('Property Manager Pro: Warning - Database table creation may have encountered issues');
            }
            
            // Create default pages
            $this->create_default_pages();
            
            // Set default options
            $this->set_default_options();
            
            // Schedule cron jobs
            $this->schedule_cron_jobs();
            
            // FIXED: Flush rewrite rules to enable custom property URLs
            flush_rewrite_rules();
        } catch (Exception $e) {
            error_log('Property Manager Pro: Activation error - ' . $e->getMessage());
            wp_die(
                'Property Manager Pro activation failed: ' . $e->getMessage(),
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }
    }

    /**
     * Plugin deactivation
     * FIXED: Properly clear all cron jobs
     */
    public function deactivate() {
        // Clear all scheduled cron jobs
        $cron_hooks = array(
            'property_manager_import_feed',
            'property_manager_process_images',
            'property_manager_daily_alerts',
            'property_manager_weekly_alerts',
            'property_manager_monthly_alerts',
            'property_manager_daily_cleanup'
        );
        
        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            // Also clear any remaining scheduled events
            wp_clear_scheduled_hook($hook);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall (static method)
     * FIXED: More thorough cleanup
     */
    public static function uninstall() {
        // Drop all database tables
        require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';
        PropertyManager_Database::drop_tables();
        
        // Delete all plugin options
        $options_to_delete = array(
            'property_manager_options',
            'property_manager_feed_url',
            'property_manager_pages',
            'property_manager_db_version'
        );
        
        foreach ($options_to_delete as $option) {
            delete_option($option);
        }
        
        // Clear all cron jobs (backup)
        $cron_hooks = array(
            'property_manager_import_feed',
            'property_manager_process_images',
            'property_manager_daily_alerts',
            'property_manager_weekly_alerts',
            'property_manager_monthly_alerts',
            'property_manager_daily_cleanup'
        );
        
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        
        // Delete user meta created by plugin
        delete_metadata('user', 0, 'property_manager_preferences', '', true);
        delete_metadata('user', 0, 'property_manager_bio', '', true);
    }

    /**
     * Create default pages
     */
    private function create_default_pages() {
        $existing_pages = get_option('property_manager_pages', array());
        
        // Check if pages still exist and are published
        if (!empty($existing_pages)) {
            $pages_exist = true;
            foreach ($existing_pages as $page_id) {
                $page = get_post($page_id);
                if (!$page || $page->post_status !== 'publish') {
                    $pages_exist = false;
                    break;
                }
            }
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
            $existing_page = get_page_by_path($page_data['slug']);
            
            if ($existing_page && $existing_page->post_status === 'publish') {
                $created_pages[$page_key] = $existing_page->ID;
            } else {
                $page_id = wp_insert_post(array(
                    'post_title' => $page_data['title'],
                    'post_content' => $page_data['content'],
                    'post_name' => $page_data['slug'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_author' => get_current_user_id(),
                    'comment_status' => 'closed',
                    'ping_status' => 'closed'
                ));
                
                if ($page_id && !is_wp_error($page_id)) {
                    $created_pages[$page_key] = $page_id;
                } else {
                    error_log('Property Manager Pro: Failed to create page: ' . $page_data['title']);
                }
            }
        }
        
        update_option('property_manager_pages', $created_pages);
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $existing_options = get_option('property_manager_options');
        
        if ($existing_options === false) {
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
                'immediate_image_download' => false,
                'max_image_size' => 10, // MB
                'image_quality' => 85, // 1-100
                'enable_image_optimization' => true
            );
            
            add_option('property_manager_options', $default_options);
        } else {
            // Merge with defaults to add any new options
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
                'immediate_image_download' => false,
                'max_image_size' => 10,
                'image_quality' => 85,
                'enable_image_optimization' => true
            );
            
            $updated_options = wp_parse_args($existing_options, $default_options);
            update_option('property_manager_options', $updated_options);
        }
    }
}

// Initialize the plugin
PropertyManagerPro::get_instance();