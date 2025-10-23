<?php
/**
 * Main Admin Interface Class - Property Manager Pro
 * 
 * Handles all admin functionality with complete security measures
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin {
    
    private static $instance = null;
    
    // Rate limiting settings
    private $rate_limit_attempts = 5;
    private $rate_limit_window = 300; // 5 minutes
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers with security
        add_action('wp_ajax_property_manager_import_feed', array($this, 'ajax_import_feed'));
        add_action('wp_ajax_property_manager_process_images', array($this, 'ajax_process_images'));
        add_action('wp_ajax_property_manager_retry_failed_images', array($this, 'ajax_retry_failed_images'));
        add_action('wp_ajax_property_manager_clear_dashboard_cache', array($this, 'ajax_clear_dashboard_cache'));
        add_action('wp_ajax_property_manager_test_email', array($this, 'ajax_test_email'));
        add_action('wp_ajax_property_manager_delete_property', array($this, 'ajax_delete_property'));
        add_action('wp_ajax_property_manager_test_feed_url', array($this, 'ajax_test_feed_url'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Load admin page classes
        $this->load_admin_pages();
        $this->init_components();
    }
    
    /**
     * Load admin page classes
     */
    private function load_admin_pages() {
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-dashboard.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-import.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-properties.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-property-edit.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-images.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-alerts.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-inquiries.php';
        require_once PROPERTY_MANAGER_PLUGIN_PATH . 'admin/pages/class-settings.php';
    }

    private function init_components() {
        PropertyManager_Admin_Dashboard::get_instance();
        PropertyManager_Admin_Import::get_instance();
        PropertyManager_Admin_Properties::get_instance();
        PropertyManager_Admin_Property_Edit::get_instance();
        PropertyManager_Admin_Images::get_instance();
        PropertyManager_Admin_Alerts::get_instance();
        PropertyManager_Admin_Inquiries::get_instance();
        PropertyManager_Admin_Settings::get_instance();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('Property Manager', 'property-manager-pro'),
            __('Properties', 'property-manager-pro'),
            'manage_options',
            'property-manager',
            array($this, 'dashboard_page'),
            'dashicons-building',
            30
        );
        
        // Dashboard (rename first submenu)
        add_submenu_page(
            'property-manager',
            __('Dashboard', 'property-manager-pro'),
            __('Dashboard', 'property-manager-pro'),
            'manage_options',
            'property-manager',
            array($this, 'dashboard_page')
        );
        
        // All Properties
        add_submenu_page(
            'property-manager',
            __('All Properties', 'property-manager-pro'),
            __('All Properties', 'property-manager-pro'),
            'manage_options',
            'property-manager-properties',
            array($this, 'properties_page')
        );
        
         // Add New Property (links to edit page with no ID)
        add_submenu_page(
            'property-manager',
            __('Add New Property', 'property-manager-pro'),
            __('Add New Property', 'property-manager-pro'),
            'manage_options',
            'property-manager-edit',
            array($this, 'edit_property_page')
        );

        // Image Management
        add_submenu_page(
            'property-manager',
            __('Image Management', 'property-manager-pro'),
            __('Images', 'property-manager-pro'),
            'manage_options',
            'property-manager-images',
            array($this, 'images_page')
        );
        
        // Import Feed
        add_submenu_page(
            'property-manager',
            __('Import Feed', 'property-manager-pro'),
            __('Import Feed', 'property-manager-pro'),
            'manage_options',
            'property-manager-import',
            array($this, 'import_page')
        );
        
        // Property Alerts
        /*add_submenu_page(
            'property-manager',
            __('Property Alerts', 'property-manager-pro'),
            __('Alerts', 'property-manager-pro'),
            'manage_options',
            'property-manager-alerts',
            array($this, 'alerts_page')
        );*/
        
        // Inquiries
        add_submenu_page(
            'property-manager',
            __('Inquiries', 'property-manager-pro'),
            __('Inquiries', 'property-manager-pro'),
            'manage_options',
            'property-manager-inquiries',
            array($this, 'inquiries_page')
        );
        
        // Settings
        add_submenu_page(
            'property-manager',
            __('Settings', 'property-manager-pro'),
            __('Settings', 'property-manager-pro'),
            'manage_options',
            'property-manager-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'property-manager') === false) {
            return;
        }
        
        // Localize script
        wp_localize_script('property-manager-admin', 'propertyManagerAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('property_manager_admin_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this item? This cannot be undone.', 'property-manager-pro'),
                'confirmImport' => __('Start property import?', 'property-manager-pro'),
                'processing' => __('Processing...', 'property-manager-pro'),
                'success' => __('Success!', 'property-manager-pro'),
                'error' => __('An error occurred. Please try again.', 'property-manager-pro')
            )
        ));

        // Page-specific assets
        if ($hook === 'properties_page_property-manager-edit') {
            // WordPress media uploader
            wp_enqueue_media();
            
            // jQuery UI for sortable
            wp_enqueue_script('jquery-ui-sortable');
            
            // Leaflet for maps
            wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
            wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
        }
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
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
        
        /*add_settings_field(
            'email_verification_required',
            __('Email Verification Required', 'property-manager-pro'),
            array($this, 'field_email_verification'),
            'property-manager-settings',
            'property_manager_email'
        );*/
        
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
    }
    
    /**
     * Settings section descriptions
     */
    public function general_settings_description() {
        echo '<p>' . esc_html__('Configure general plugin settings and property import options.', 'property-manager-pro') . '</p>';
    }
    
    public function email_settings_description() {
        echo '<p>' . esc_html__('Configure email notifications and verification settings.', 'property-manager-pro') . '</p>';
    }
    
    public function map_settings_description() {
        echo '<p>' . esc_html__('Configure map display settings for property locations.', 'property-manager-pro') . '</p>';
    }
    
    /**
     * Sanitize options with validation
     */
    public function sanitize_options($input) {
        $sanitized = array();
        $errors = array();
        
        // Feed URL
        if (isset($input['feed_url'])) {
            $feed_url = esc_url_raw($input['feed_url'], array('http', 'https'));
            
            // Validate URL scheme
            if (!empty($feed_url)) {
                $parsed_url = parse_url($feed_url);
                if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
                    $errors[] = __('Feed URL must use HTTP or HTTPS protocol.', 'property-manager-pro');
                } else {
                    $sanitized['feed_url'] = $feed_url;
                }
            }
        }
        
        // Import frequency
        if (isset($input['import_frequency'])) {
            $allowed_frequencies = array('hourly', 'twicedaily', 'daily');
            $frequency = sanitize_text_field($input['import_frequency']);
            
            if (in_array($frequency, $allowed_frequencies)) {
                $sanitized['import_frequency'] = $frequency;
            } else {
                $sanitized['import_frequency'] = 'hourly';
                $errors[] = __('Invalid import frequency selected.', 'property-manager-pro');
            }
        }
        
        // Results per page
        if (isset($input['results_per_page'])) {
            $results_per_page = absint($input['results_per_page']);
            
            if ($results_per_page < 1 || $results_per_page > 100) {
                $errors[] = __('Results per page must be between 1 and 100.', 'property-manager-pro');
                $sanitized['results_per_page'] = 20;
            } else {
                $sanitized['results_per_page'] = $results_per_page;
            }
        }
        
        // Default view
        if (isset($input['default_view'])) {
            $allowed_views = array('grid', 'list', 'map');
            $view = sanitize_text_field($input['default_view']);
            
            if (in_array($view, $allowed_views)) {
                $sanitized['default_view'] = $view;
            } else {
                $sanitized['default_view'] = 'grid';
                $errors[] = __('Invalid default view selected.', 'property-manager-pro');
            }
        }
        
        // Admin email
        if (isset($input['admin_email'])) {
            $email = sanitize_email($input['admin_email']);
            
            if (is_email($email)) {
                $sanitized['admin_email'] = $email;
            } else {
                $errors[] = __('Invalid admin email address.', 'property-manager-pro');
                $sanitized['admin_email'] = get_option('admin_email');
            }
        }
        
        // Boolean settings
        $sanitized['email_verification_required'] = isset($input['email_verification_required']) ? 1 : 0;
        $sanitized['enable_map'] = isset($input['enable_map']) ? 1 : 0;
        $sanitized['immediate_image_download'] = isset($input['immediate_image_download']) ? 1 : 0;
        
        // Map provider
        if (isset($input['map_provider'])) {
            $allowed_providers = array('openstreetmap', 'mapbox');
            $provider = sanitize_text_field($input['map_provider']);
            
            if (in_array($provider, $allowed_providers)) {
                $sanitized['map_provider'] = $provider;
            } else {
                $sanitized['map_provider'] = 'openstreetmap';
                $errors[] = __('Invalid map provider selected.', 'property-manager-pro');
            }
        }
        
        // Display errors
        if (!empty($errors)) {
            foreach ($errors as $error) {
                add_settings_error('property_manager_options', 'property_manager_error', $error, 'error');
            }
        }
        
        // Log settings change
        $this->log_admin_action('settings_updated', array(
            'changes' => array_keys($input)
        ));
        
        $sanitized['enable_user_registration'] = false;
        $sanitized['currency_symbol'] = "&euro;";
        $sanitized['default_language'] = "en";
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
            <?php esc_html_e('Download images immediately during feed import (slower but complete)', 'property-manager-pro'); ?>
        </label>
        <p class="description"><?php esc_html_e('If disabled, images will be queued for background processing.', 'property-manager-pro'); ?></p>
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
        <?php
    }
    
    public function field_admin_email() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        ?>
        <input type="email" id="admin_email"
               name="property_manager_options[admin_email]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <p class="description"><?php esc_html_e('Email address for receiving property inquiries.', 'property-manager-pro'); ?></p>
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
            <?php esc_html_e('Enable map view for property search results', 'property-manager-pro'); ?>
        </label>
        <?php
    }
    
    public function field_map_provider() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';
        
        $providers = array(
            'openstreetmap' => __('OpenStreetMap (Free)', 'property-manager-pro'),
            'mapbox' => __('Mapbox (API Key Required)', 'property-manager-pro')
        );
        ?>
        <select name="property_manager_options[map_provider]" id="map_provider">
            <?php foreach ($providers as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    /**
     * Handle admin actions (non-AJAX)
     */
    public function handle_admin_actions() {
        // Only process on our admin pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'property-manager-properties') === false) {
            return;
        }
        
        // Check for bulk actions
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $this->handle_bulk_action();
        }
        
        // Check for single actions
        if (isset($_GET['action'])) {
            $this->handle_single_action();
        }
    }
    
    /**
     * Handle bulk actions
     */
    private function handle_bulk_action() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'bulk-properties')) {
            wp_die(__('Security check failed.', 'property-manager-pro'));
        }
        
        // Check capability
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $action = sanitize_text_field($_POST['action']);
        $ids = isset($_POST['property']) ? array_map('absint', $_POST['property']) : array();
        
        if (empty($ids)) {
            $this->add_admin_notice('error', __('No items selected.', 'property-manager-pro'));
            return;
        }
        
        switch ($action) {
            case 'delete':
                $this->bulk_delete_properties($ids);
                break;
            case 'activate':
                $this->bulk_update_status($ids, 'active');
                break;
            case 'deactivate':
                $this->bulk_update_status($ids, 'inactive');
                break;
        }
    }
    
    /**
     * Handle single actions
     */
    private function handle_single_action() {
        $action = sanitize_text_field($_GET['action']);
        $property_id = intval(sanitize_text_field($_GET['property_id']));
        
        // Actions that require nonce verification
        $nonce_required_actions = array('delete', 'activate', 'deactivate');
        
        if (in_array($action, $nonce_required_actions)) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'property_action_' . $action)) {
                wp_die(__('Security check failed.', 'property-manager-pro'));
            }
            
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
            }
        }

        $this->bulk_delete_properties([$property_id]);
        wp_redirect(add_query_arg(
            array(
                'page' => 'property-manager-properties',
                'message' => 'deleted'
            ),
            admin_url('admin.php')
        ));
        exit;
    }
    
    /**
     * Bulk delete properties
     */
    private function bulk_delete_properties($ids) {
        global $wpdb;
        
        $db_manager = PropertyManager_Database::get_instance();
        $deleted_count = 0;
        
        foreach ($ids as $id) {
            if ($db_manager->delete_property($id)) {
                $deleted_count++;
            }
        }
        
        // Log action
        $this->log_admin_action('bulk_delete_properties', array(
            'count' => $deleted_count,
            'ids' => $ids
        ));
        
        $this->add_admin_notice('success', sprintf(
            _n('%d property deleted.', '%d properties deleted.', $deleted_count, 'property-manager-pro'),
            $deleted_count
        ));
    }
    
    /**
     * Bulk update status
     */
    private function bulk_update_status($ids, $status) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        $updated_count = 0;
        
        foreach ($ids as $id) {
            $result = $wpdb->update(
                $table,
                array('status' => $status, 'updated_at' => current_time('mysql')),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $updated_count++;
            }
        }
        
        // Log action
        $this->log_admin_action('bulk_update_status', array(
            'status' => $status,
            'count' => $updated_count,
            'ids' => $ids
        ));
        
        $this->add_admin_notice('success', sprintf(
            _n('%d property updated.', '%d properties updated.', $updated_count, 'property-manager-pro'),
            $updated_count
        ));
    }
    
    /**
     * AJAX: Import feed
     */
    public function ajax_import_feed() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        // Rate limiting
        if (!$this->check_rate_limit('feed_import')) {
            wp_send_json_error(array('message' => __('Please wait before starting another import.', 'property-manager-pro')));
        }
        
        // Start import
        $importer = PropertyManager_FeedImporter::get_instance();
        $result = $importer->import_feed(true);
        
        if ($result) {
            // Log action
            $this->log_admin_action('feed_import', array(
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'deactivated' => $result['deactivated']
            ));
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Import completed: %d imported, %d updated, %d failed, %d deactivated.', 'property-manager-pro'),
                    $result['imported'],
                    $result['updated'],
                    $result['failed'],
                    $result['deactivated']
                ),
                'stats' => $result
            ));
        } else {
            wp_send_json_error(array('message' => __('Import failed. Please check the error log.', 'property-manager-pro')));
        }
    }
    
    /**
     * AJAX: Process images
     */
    public function ajax_process_images() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        // Rate limiting
        if (!$this->check_rate_limit('process_images')) {
            wp_send_json_error(array('message' => __('Please wait before processing more images.', 'property-manager-pro')));
        }
        
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 10;
        $batch_size = max(1, min(50, $batch_size)); // Limit between 1-50
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $processed = $image_downloader->process_pending_images($batch_size);
        
        // Log action
        $this->log_admin_action('process_images', array('count' => $processed));
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d images processed.', 'property-manager-pro'), $processed),
            'processed' => $processed
        ));
    }
    
    /**
     * AJAX: Retry failed images
     */
    public function ajax_retry_failed_images() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        // Rate limiting
        if (!$this->check_rate_limit('retry_images')) {
            wp_send_json_error(array('message' => __('Please wait before retrying images.', 'property-manager-pro')));
        }
        
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 20;
        $batch_size = max(1, min(50, $batch_size));
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $retried = $image_downloader->retry_failed_downloads($batch_size);
        
        // Log action
        $this->log_admin_action('retry_failed_images', array('count' => $retried));
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d failed images queued for retry.', 'property-manager-pro'), $retried),
            'retried' => $retried
        ));
    }
    
    
    /**
     * AJAX: Clear dashboard stats cache
     */
    public function ajax_clear_dashboard_cache() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        delete_transient('property_manager_dashboard_stats');
        
        wp_send_json_success(array('message' => __('Cache cleared successfully!', 'property-manager-pro')));
    }
    
    /**
     * AJAX: Test email
     */
    public function ajax_test_email() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        // Rate limiting
        if (!$this->check_rate_limit('test_email')) {
            wp_send_json_error(array('message' => __('Please wait before sending another test email.', 'property-manager-pro')));
        }
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if (!is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address.', 'property-manager-pro')));
        }
        
        $subject = sprintf(__('[%s] Test Email', 'property-manager-pro'), get_bloginfo('name'));
        $message = __('This is a test email from Property Manager Pro. If you received this, your email configuration is working correctly.', 'property-manager-pro');
        
        $result = wp_mail($email, $subject, $message);
        
        // Log action
        $this->log_admin_action('test_email', array('email' => $email, 'success' => $result));
        
        if ($result) {
            wp_send_json_success(array('message' => __('Test email sent successfully!', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send test email. Please check your email configuration.', 'property-manager-pro')));
        }
    }
    
    /**
     * AJAX: Delete property
     */
    public function ajax_delete_property() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        $property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
        
        if (!$property_id) {
            wp_send_json_error(array('message' => __('Invalid property ID.', 'property-manager-pro')));
        }
        
        $property_manager = PropertyManager_Property::get_instance();
        $result = $property_manager->delete_property($property_id);
        
        if ($result) {
            // Log action
            $this->log_admin_action('delete_property', array('property_id' => $property_id));
            
            wp_send_json_success(array('message' => __('Property deleted successfully.', 'property-manager-pro')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete property.', 'property-manager-pro')));
        }
    }
    
    /**
     * AJAX: Test feed URL
     */
    public function ajax_test_feed_url() {
        // Security checks
        check_ajax_referer('property_manager_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
        }
        
        // Rate limiting
        if (!$this->check_rate_limit('test_feed_url')) {
            wp_send_json_error(array('message' => __('Please wait before testing the feed URL again.', 'property-manager-pro')));
        }
        
        $feed_url = isset($_POST['feed_url']) ? esc_url_raw($_POST['feed_url'], array('http', 'https')) : '';
        
        if (empty($feed_url)) {
            wp_send_json_error(array('message' => __('Please enter a feed URL.', 'property-manager-pro')));
        }
        
        // Test the URL
        $response = wp_remote_get($feed_url, array(
            'timeout' => 30,
            'redirection' => 5,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => sprintf(__('Error: %s', 'property-manager-pro'), $response->get_error_message())));
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($http_code !== 200) {
            wp_send_json_error(array('message' => sprintf(__('HTTP Error: %d', 'property-manager-pro'), $http_code)));
        }
        
        if (empty($body)) {
            wp_send_json_error(array('message' => __('Feed is empty.', 'property-manager-pro')));
        }
        
        // Try to parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_msg = !empty($errors) ? $errors[0]->message : __('Invalid XML format.', 'property-manager-pro');
            libxml_clear_errors();
            wp_send_json_error(array('message' => $error_msg));
        }
        
        // Count properties
        $property_count = 0;
        if (isset($xml->property)) {
            $property_count = count($xml->property);
        }
        
        // Log action
        $this->log_admin_action('test_feed_url', array('url' => $feed_url, 'success' => true));
        
        wp_send_json_success(array(
            'message' => sprintf(__('Feed is valid! Found %d properties.', 'property-manager-pro'), $property_count),
            'property_count' => $property_count
        ));
    }
    
    /**
     * Check rate limit
     */
    private function check_rate_limit($action) {
        $user_id = get_current_user_id();
        $key = 'property_manager_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            set_transient($key, 1, $this->rate_limit_window);
            return true;
        }
        
        if ($attempts >= $this->rate_limit_attempts) {
            return false;
        }
        
        set_transient($key, $attempts + 1, $this->rate_limit_window);
        return true;
    }
    
    /**
     * Log admin action
     */
    private function log_admin_action($action, $metadata = array()) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('admin_logs');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }
        
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        
        $wpdb->insert($table, array(
            'user_id' => $user_id,
            'username' => $user ? $user->user_login : 'unknown',
            'action' => sanitize_text_field($action),
            'metadata' => maybe_serialize($metadata),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'created_at' => current_time('mysql')
        ), array('%d', '%s', '%s', '%s', '%s', '%s', '%s'));
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
    
    /**
     * Get user agent
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
    }
    
    /**
     * Add admin notice
     */
    private function add_admin_notice($type, $message) {
        $notices = get_transient('property_manager_admin_notices');
        
        if (!is_array($notices)) {
            $notices = array();
        }
        
        $notices[] = array(
            'type' => $type,
            'message' => $message
        );
        
        set_transient('property_manager_admin_notices', $notices, 60);
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $notices = get_transient('property_manager_admin_notices');
        
        if (!is_array($notices) || empty($notices)) {
            return;
        }
        
        foreach ($notices as $notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            ?>
            <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>
            <?php
        }
        
        delete_transient('property_manager_admin_notices');
    }
    
    /**
     * Admin page callbacks
     */
    public function dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $dashboard = PropertyManager_Admin_Dashboard::get_instance();
        $dashboard->render();
    }
    
    public function properties_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $properties = PropertyManager_Admin_Properties::get_instance();
        $properties->render();
    }

    public function edit_property_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $edit_page = PropertyManager_Admin_Property_Edit::get_instance();
        $edit_page->render();
    }
    
    public function images_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $images = PropertyManager_Admin_Images::get_instance();
        $images->render();
    }
    
    public function import_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $importer = PropertyManager_Admin_Import::get_instance();
        $importer->render();
    }
    
    public function alerts_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $alerts = PropertyManager_Admin_Alerts::get_instance();
        $alerts->render();
    }
    
    public function inquiries_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $inquiries = PropertyManager_Admin_Inquiries::get_instance();
        $inquiries->render();
    }
    
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'property-manager-pro'));
        }
        
        $settings = PropertyManager_Admin_Settings::get_instance();
        $settings->render();
    }
}