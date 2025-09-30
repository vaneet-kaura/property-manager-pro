<?php
/**
 * Database Management Class - Production Ready with All Security & Performance Enhancements
 * Handles all database operations, schema management, and data integrity
 * 
 * IMPORTANT: S3 OFFLOAD COMPATIBILITY
 * ====================================
 * This plugin is designed to work seamlessly with WordPress S3 offload plugins such as:
 * - WP Offload Media (formerly WP Offload S3)
 * - Media Cloud
 * - WP-Stateless
 * 
 * All property images are stored in WordPress Media Library (wp_posts with post_type='attachment')
 * and the attachment_id is saved in the pm_property_images table. When you use an S3 offload plugin:
 * 
 * 1. Images are uploaded to WordPress Media Library using wp_insert_attachment()
 * 2. S3 offload plugin automatically detects the new attachment and uploads to S3
 * 3. The plugin updates the attachment URL to point to S3
 * 4. wp_get_attachment_url() returns the S3 URL automatically
 * 5. When deleting, wp_delete_attachment() triggers S3 deletion via the offload plugin
 * 
 * This approach ensures:
 * - Zero code changes needed when adding/removing S3 offload
 * - All WordPress media features work normally (thumbnails, metadata, etc)
 * - S3 offload happens transparently in the background
 * - Image URLs automatically use S3 CDN when offload is active
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Database {
    
    private static $instance = null;
    
    // Database version for migrations
    private const DB_VERSION = '1.0.0';
    private const DB_VERSION_OPTION = 'property_manager_db_version';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Schedule cleanup
        add_action('property_manager_cleanup', array($this, 'scheduled_cleanup'));
        add_action('property_manager_daily_cleanup', array($this, 'scheduled_cleanup'));
        
        // Check for database updates
        add_action('plugins_loaded', array($this, 'maybe_update_database'));
    }

    /**
     * Get table name with prefix
     */
    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'pm_' . $table;
    }

    /**
     * Create all database tables with proper indexes and foreign keys
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Properties table
        $properties_table = $wpdb->prefix . 'pm_properties';
        $properties_sql = "CREATE TABLE IF NOT EXISTS $properties_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id varchar(100) NOT NULL,
            ref varchar(100) DEFAULT NULL,
            title text DEFAULT NULL,
            price decimal(15,2) DEFAULT NULL,
            currency varchar(10) DEFAULT 'EUR',
            price_freq enum('sale','rent') DEFAULT 'sale',
            new_build tinyint(1) DEFAULT 0,
            property_type varchar(100) DEFAULT NULL,
            town varchar(100) DEFAULT NULL,
            province varchar(100) DEFAULT NULL,
            location_detail text DEFAULT NULL,
            beds int(11) DEFAULT NULL,
            baths int(11) DEFAULT NULL,
            pool tinyint(1) DEFAULT 0,
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            surface_area_built int(11) DEFAULT NULL,
            surface_area_plot int(11) DEFAULT NULL,
            energy_rating_consumption varchar(10) DEFAULT NULL,
            energy_rating_emissions varchar(10) DEFAULT NULL,
            url_en text DEFAULT NULL,
            url_es text DEFAULT NULL,
            url_de text DEFAULT NULL,
            url_fr text DEFAULT NULL,
            desc_en longtext DEFAULT NULL,
            desc_es longtext DEFAULT NULL,
            desc_de longtext DEFAULT NULL,
            desc_fr longtext DEFAULT NULL,
            status enum('active','inactive','sold','rented','deleted') DEFAULT 'active',
            featured tinyint(1) DEFAULT 0,
            view_count bigint(20) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY property_id (property_id),
            KEY ref (ref(50)),
            KEY property_type (property_type(50)),
            KEY town (town(50)),
            KEY province (province(50)),
            KEY price (price),
            KEY beds (beds),
            KEY baths (baths),
            KEY status (status),
            KEY featured (featured),
            KEY location (latitude,longitude),
            KEY price_freq (price_freq),
            KEY new_build (new_build),
            KEY pool (pool),
            KEY view_count (view_count),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            FULLTEXT KEY search_text (title,desc_en,desc_es,desc_de,desc_fr)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Property images table
        // IMPORTANT: attachment_id stores the WordPress Media Library attachment ID
        // This enables automatic S3 offloading via plugins like WP Offload Media
        // The offload plugin will automatically upload images to S3 and update URLs
        $images_table = $wpdb->prefix . 'pm_property_images';
        $images_sql = "CREATE TABLE IF NOT EXISTS $images_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            image_id int(11) NOT NULL,
            attachment_id bigint(20) unsigned DEFAULT NULL COMMENT 'WordPress Media Library attachment ID for S3 offload',
            image_url text NOT NULL COMMENT 'Current image URL (S3 URL if offloaded)',
            original_url text DEFAULT NULL COMMENT 'Original feed URL before download',
            image_title varchar(255) DEFAULT NULL,
            image_alt varchar(255) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            download_status enum('pending','downloading','downloaded','failed') DEFAULT 'pending',
            download_attempts int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY attachment_id (attachment_id),
            KEY sort_order (sort_order),
            KEY download_status (download_status),
            KEY download_attempts (download_attempts)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Property features table
        $features_table = $wpdb->prefix . 'pm_property_features';
        $features_sql = "CREATE TABLE IF NOT EXISTS $features_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            feature_name varchar(100) NOT NULL,
            feature_value varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY feature_name (feature_name(50))
        ) $charset_collate ENGINE=InnoDB;";
        
        // User favorites table
        $favorites_table = $wpdb->prefix . 'pm_user_favorites';
        $favorites_sql = "CREATE TABLE IF NOT EXISTS $favorites_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            property_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_property (user_id, property_id),
            KEY user_id (user_id),
            KEY property_id (property_id),
            KEY created_at (created_at)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Saved searches table
        $saved_searches_table = $wpdb->prefix . 'pm_saved_searches';
        $saved_searches_sql = "CREATE TABLE IF NOT EXISTS $saved_searches_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            search_name varchar(100) NOT NULL,
            search_criteria text NOT NULL,
            alert_enabled tinyint(1) DEFAULT 0,
            alert_frequency enum('daily','weekly','monthly') DEFAULT 'weekly',
            last_sent datetime DEFAULT NULL,
            status enum('active','paused') DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY alert_frequency (alert_frequency),
            KEY alert_enabled (alert_enabled),
            KEY last_sent (last_sent)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Property alerts table
        $alerts_table = $wpdb->prefix . 'pm_property_alerts';
        $alerts_sql = "CREATE TABLE IF NOT EXISTS $alerts_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            search_criteria text NOT NULL,
            frequency enum('daily','weekly','monthly') DEFAULT 'weekly',
            status enum('pending','active','paused','unsubscribed','deleted') DEFAULT 'pending',
            verification_token varchar(255) DEFAULT NULL,
            token_expires_at datetime DEFAULT NULL,
            email_verified tinyint(1) DEFAULT 0,
            unsubscribe_token varchar(255) NOT NULL,
            last_sent datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email(191)),
            KEY user_id (user_id),
            KEY status (status),
            KEY frequency (frequency),
            KEY email_verified (email_verified),
            KEY verification_token (verification_token(191)),
            KEY unsubscribe_token (unsubscribe_token(191)),
            KEY token_expires_at (token_expires_at),
            KEY last_sent (last_sent),
            KEY created_at (created_at)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Property views tracking table
        $views_table = $wpdb->prefix . 'pm_property_views';
        $views_sql = "CREATE TABLE IF NOT EXISTS $views_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            viewed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY user_id (user_id),
            KEY ip_address (ip_address),
            KEY viewed_at (viewed_at),
            KEY property_user (property_id, user_id)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Property inquiries table
        $inquiries_table = $wpdb->prefix . 'pm_property_inquiries';
        $inquiries_sql = "CREATE TABLE IF NOT EXISTS $inquiries_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            name varchar(100) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            message text NOT NULL,
            status enum('new','read','replied','closed') DEFAULT 'new',
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY email (email(191)),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY updated_at (updated_at)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Email logs table
        $email_logs_table = $wpdb->prefix . 'pm_email_logs';
        $email_logs_sql = "CREATE TABLE IF NOT EXISTS $email_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email_type varchar(50) NOT NULL,
            recipient_email varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            message longtext NOT NULL,
            status enum('sent','failed','pending') DEFAULT 'pending',
            error_message text DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email_type (email_type),
            KEY recipient_email (recipient_email(191)),
            KEY status (status),
            KEY sent_at (sent_at),
            KEY created_at (created_at)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Import logs table
        $import_logs_table = $wpdb->prefix . 'pm_import_logs';
        $import_logs_sql = "CREATE TABLE IF NOT EXISTS $import_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_type varchar(50) NOT NULL,
            status enum('started','completed','failed') DEFAULT 'started',
            properties_imported int(11) DEFAULT 0,
            properties_updated int(11) DEFAULT 0,
            properties_failed int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY import_type (import_type),
            KEY status (status),
            KEY started_at (started_at),
            KEY completed_at (completed_at)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Security logs table
        $security_logs_table = $wpdb->prefix . 'pm_security_logs';
        $security_logs_sql = "CREATE TABLE IF NOT EXISTS $security_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent varchar(255) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            data text DEFAULT NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY ip_address (ip_address),
            KEY user_id (user_id),
            KEY timestamp (timestamp)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Audit logs table
        $audit_logs_table = $wpdb->prefix . 'pm_audit_logs';
        $audit_logs_sql = "CREATE TABLE IF NOT EXISTS $audit_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            data text DEFAULT NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY timestamp (timestamp)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Search history table
        $search_history_table = $wpdb->prefix . 'pm_search_history';
        $search_history_sql = "CREATE TABLE IF NOT EXISTS $search_history_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            search_query varchar(500) NOT NULL,
            search_criteria text DEFAULT NULL,
            results_count int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY ip_address (ip_address),
            KEY created_at (created_at),
            FULLTEXT KEY search_query (search_query)
        ) $charset_collate ENGINE=InnoDB;";
        
        // Execute all table creations
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($properties_sql);
        dbDelta($images_sql);
        dbDelta($features_sql);
        dbDelta($favorites_sql);
        dbDelta($saved_searches_sql);
        dbDelta($alerts_sql);
        dbDelta($views_sql);
        dbDelta($inquiries_sql);
        dbDelta($email_logs_sql);
        dbDelta($import_logs_sql);
        dbDelta($security_logs_sql);
        dbDelta($audit_logs_sql);
        dbDelta($search_history_sql);
        
        // Add foreign key constraints manually (dbDelta doesn't support them)
        self::add_foreign_key_constraints();
        
        // Update database version
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        
        // Log database creation
        error_log('Property Manager: Database tables created successfully');
        
        return true;
    }

    /**
     * Add foreign key constraints (must be done after table creation)
     */
    private static function add_foreign_key_constraints() {
        global $wpdb;
        
        $properties_table = $wpdb->prefix . 'pm_properties';
        $images_table = $wpdb->prefix . 'pm_property_images';
        $features_table = $wpdb->prefix . 'pm_property_features';
        $favorites_table = $wpdb->prefix . 'pm_user_favorites';
        $views_table = $wpdb->prefix . 'pm_property_views';
        $inquiries_table = $wpdb->prefix . 'pm_property_inquiries';
        
        // Check if foreign keys already exist before adding
        $constraints = array(
            array(
                'table' => $images_table,
                'constraint' => 'fk_images_property',
                'sql' => "ALTER TABLE {$images_table} 
                         ADD CONSTRAINT fk_images_property 
                         FOREIGN KEY (property_id) REFERENCES {$properties_table}(id) 
                         ON DELETE CASCADE"
            ),
            array(
                'table' => $features_table,
                'constraint' => 'fk_features_property',
                'sql' => "ALTER TABLE {$features_table} 
                         ADD CONSTRAINT fk_features_property 
                         FOREIGN KEY (property_id) REFERENCES {$properties_table}(id) 
                         ON DELETE CASCADE"
            ),
            array(
                'table' => $favorites_table,
                'constraint' => 'fk_favorites_property',
                'sql' => "ALTER TABLE {$favorites_table} 
                         ADD CONSTRAINT fk_favorites_property 
                         FOREIGN KEY (property_id) REFERENCES {$properties_table}(id) 
                         ON DELETE CASCADE"
            ),
            array(
                'table' => $views_table,
                'constraint' => 'fk_views_property',
                'sql' => "ALTER TABLE {$views_table} 
                         ADD CONSTRAINT fk_views_property 
                         FOREIGN KEY (property_id) REFERENCES {$properties_table}(id) 
                         ON DELETE CASCADE"
            ),
            array(
                'table' => $inquiries_table,
                'constraint' => 'fk_inquiries_property',
                'sql' => "ALTER TABLE {$inquiries_table} 
                         ADD CONSTRAINT fk_inquiries_property 
                         FOREIGN KEY (property_id) REFERENCES {$properties_table}(id) 
                         ON DELETE CASCADE"
            )
        );
        
        foreach ($constraints as $constraint) {
            // Check if constraint already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT CONSTRAINT_NAME 
                 FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE CONSTRAINT_SCHEMA = %s 
                 AND TABLE_NAME = %s 
                 AND CONSTRAINT_NAME = %s 
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                DB_NAME,
                $constraint['table'],
                $constraint['constraint']
            ));
            
            if (!$exists) {
                // Add foreign key constraint
                $result = $wpdb->query($constraint['sql']);
                
                if ($result === false) {
                    error_log('Property Manager: Failed to add foreign key ' . $constraint['constraint'] . ' - ' . $wpdb->last_error);
                }
            }
        }
        
        return true;
    }

    /**
     * Drop all database tables (used during uninstall)
     */
    public static function drop_tables() {
        global $wpdb;
        
        // First, drop foreign key constraints
        self::drop_foreign_key_constraints();
        
        $tables = array(
            'pm_search_history',
            'pm_audit_logs',
            'pm_security_logs',
            'pm_import_logs',
            'pm_email_logs',
            'pm_property_inquiries',
            'pm_property_views',
            'pm_property_alerts',
            'pm_saved_searches',
            'pm_user_favorites',
            'pm_property_features',
            'pm_property_images',
            'pm_properties'
        );
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        }
        
        // Delete options
        delete_option(self::DB_VERSION_OPTION);
        
        error_log('Property Manager: Database tables dropped successfully');
        
        return true;
    }

    /**
     * Drop foreign key constraints
     */
    private static function drop_foreign_key_constraints() {
        global $wpdb;
        
        $constraints = array(
            array('table' => $wpdb->prefix . 'pm_property_images', 'constraint' => 'fk_images_property'),
            array('table' => $wpdb->prefix . 'pm_property_features', 'constraint' => 'fk_features_property'),
            array('table' => $wpdb->prefix . 'pm_user_favorites', 'constraint' => 'fk_favorites_property'),
            array('table' => $wpdb->prefix . 'pm_property_views', 'constraint' => 'fk_views_property'),
            array('table' => $wpdb->prefix . 'pm_property_inquiries', 'constraint' => 'fk_inquiries_property')
        );
        
        foreach ($constraints as $constraint) {
            // Check if constraint exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT CONSTRAINT_NAME 
                 FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE CONSTRAINT_SCHEMA = %s 
                 AND TABLE_NAME = %s 
                 AND CONSTRAINT_NAME = %s",
                DB_NAME,
                $constraint['table'],
                $constraint['constraint']
            ));
            
            if ($exists) {
                $wpdb->query("ALTER TABLE {$constraint['table']} DROP FOREIGN KEY {$constraint['constraint']}");
            }
        }
        
        return true;
    }

    /**
     * Maybe update database schema
     */
    public function maybe_update_database() {
        $current_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            // Run database updates
            self::create_tables();
            
            // Run any migration scripts
            $this->run_migrations($current_version);
            
            error_log('Property Manager: Database updated from ' . $current_version . ' to ' . self::DB_VERSION);
        }
    }

    /**
     * Run migration scripts for database updates
     */
    private function run_migrations($from_version) {
        // Example migration structure
        // if (version_compare($from_version, '1.1.0', '<')) {
        //     $this->migrate_to_1_1_0();
        // }
        
        // For now, just recreate tables
        return true;
    }

    /**
     * Insert or update property
     */
    public static function upsert_property($data) {
        global $wpdb;
        
        $table = self::get_table_name('properties');
        
        // Validate required fields
        if (empty($data['property_id'])) {
            error_log('Property Manager: Cannot upsert property - property_id is required');
            return false;
        }
        
        // Sanitize all data
        $sanitized_data = self::sanitize_property_data($data);
        
        // Check if property exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, updated_at FROM {$table} WHERE property_id = %s",
            $sanitized_data['property_id']
        ));
        
        if ($existing) {
            // Update existing property
            unset($sanitized_data['created_at']);
            $sanitized_data['updated_at'] = current_time('mysql', true);
            
            $result = $wpdb->update(
                $table,
                $sanitized_data,
                array('property_id' => $sanitized_data['property_id']),
                self::get_data_format($sanitized_data),
                array('%s')
            );
            
            if ($result === false) {
                error_log('Property Manager: Failed to update property ' . $sanitized_data['property_id'] . ' - ' . $wpdb->last_error);
                return false;
            }
            
            return $existing->id;
        } else {
            // Insert new property
            $sanitized_data['created_at'] = current_time('mysql', true);
            $sanitized_data['updated_at'] = current_time('mysql', true);
            
            $result = $wpdb->insert(
                $table,
                $sanitized_data,
                self::get_data_format($sanitized_data)
            );
            
            if ($result === false) {
                error_log('Property Manager: Failed to insert property ' . $sanitized_data['property_id'] . ' - ' . $wpdb->last_error);
                return false;
            }
            
            return $wpdb->insert_id;
        }
    }

    /**
     * Sanitize property data
     */
    private static function sanitize_property_data($data) {
        $sanitized = array();
        
        // String fields
        $string_fields = array('property_id', 'ref', 'currency', 'property_type', 'town', 'province', 'energy_rating_consumption', 'energy_rating_emissions');
        foreach ($string_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_text_field($data[$field]);
            }
        }
        
        // Text fields
        $text_fields = array('title', 'location_detail', 'url_en', 'url_es', 'url_de', 'url_fr');
        foreach ($text_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_textarea_field($data[$field]);
            }
        }
        
        // Long text fields (descriptions)
        $longtext_fields = array('desc_en', 'desc_es', 'desc_de', 'desc_fr');
        foreach ($longtext_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = wp_kses_post($data[$field]);
            }
        }
        
        // Numeric fields
        $numeric_fields = array('beds', 'baths', 'surface_area_built', 'surface_area_plot', 'view_count');
        foreach ($numeric_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = max(0, intval($data[$field]));
            }
        }
        
        // Decimal fields
        if (isset($data['price'])) {
            $sanitized['price'] = max(0, floatval($data['price']));
        }
        if (isset($data['latitude'])) {
            $sanitized['latitude'] = floatval($data['latitude']);
        }
        if (isset($data['longitude'])) {
            $sanitized['longitude'] = floatval($data['longitude']);
        }
        
        // Boolean fields
        $boolean_fields = array('new_build', 'pool', 'featured');
        foreach ($boolean_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = intval($data[$field]) === 1 ? 1 : 0;
            }
        }
        
        // Enum fields
        if (isset($data['price_freq']) && in_array($data['price_freq'], array('sale', 'rent'), true)) {
            $sanitized['price_freq'] = $data['price_freq'];
        }
        
        if (isset($data['status']) && in_array($data['status'], array('active', 'inactive', 'sold', 'rented', 'deleted'), true)) {
            $sanitized['status'] = $data['status'];
        }
        
        return $sanitized;
    }

    /**
     * Get data format for wpdb operations
     */
    private static function get_data_format($data) {
        $format = array();
        
        foreach ($data as $key => $value) {
            if (in_array($key, array('price', 'latitude', 'longitude'), true)) {
                $format[] = '%f';
            } elseif (in_array($key, array('beds', 'baths', 'surface_area_built', 'surface_area_plot', 'new_build', 'pool', 'featured', 'view_count'), true)) {
                $format[] = '%d';
            } else {
                $format[] = '%s';
            }
        }
        
        return $format;
    }

    /**
     * Delete property and all related data
     */
    public static function delete_property($property_id) {
        global $wpdb;
        
        if (!$property_id || $property_id < 1) {
            return false;
        }
        
        $table = self::get_table_name('properties');
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Clean up related data (foreign keys will handle this, but explicit is better)
            self::cleanup_property_data($property_id);
            
            // Delete the property
            $result = $wpdb->delete($table, array('id' => $property_id), array('%d'));
            
            if ($result === false) {
                throw new Exception('Failed to delete property: ' . $wpdb->last_error);
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return true;
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Property Manager: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up property-related data
     */
    public static function cleanup_property_data($property_id) {
        global $wpdb;
        
        // Delete property images and their WordPress attachments (important for S3 offloading)
        $images_table = self::get_table_name('property_images');
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT id, attachment_id FROM {$images_table} WHERE property_id = %d AND attachment_id IS NOT NULL",
            $property_id
        ));
        
        foreach ($images as $image) {
            if ($image->attachment_id) {
                // This will also delete from S3 if using WP Offload Media or similar plugins
                wp_delete_attachment($image->attachment_id, true);
                
                error_log(sprintf(
                    'Property Manager: Deleted attachment %d for property %d (S3 offload compatible)',
                    $image->attachment_id,
                    $property_id
                ));
            }
        }
        
        // Foreign key constraints will handle deletion of:
        // - property_images records
        // - property_features
        // - user_favorites
        // - property_views
        // - property_inquiries
        
        return true;
    }

    /**
     * Insert property images
     */
    public static function insert_property_images($property_id, $images) {
        global $wpdb;
        
        if (!$property_id || $property_id < 1 || empty($images)) {
            return false;
        }
        
        $table = self::get_table_name('property_images');
        $properties_table = self::get_table_name('properties');
        
        // Verify property exists
        $property_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$properties_table} WHERE id = %d",
            $property_id
        ));
        
        if (!$property_exists) {
            error_log('Property Manager: Cannot insert images - property ' . $property_id . ' does not exist');
            return false;
        }
        
        // Delete existing images for this property
        $wpdb->delete($table, array('property_id' => $property_id), array('%d'));
        
        $inserted = 0;
        foreach ($images as $image) {
            $result = $wpdb->insert(
                $table,
                array(
                    'property_id' => $property_id,
                    'image_id' => isset($image['id']) ? intval($image['id']) : 0,
                    'image_url' => esc_url_raw($image['url']),
                    'original_url' => esc_url_raw($image['url']),
                    'image_title' => isset($image['title']) ? sanitize_text_field($image['title']) : '',
                    'image_alt' => isset($image['alt']) ? sanitize_text_field($image['alt']) : '',
                    'sort_order' => isset($image['sort_order']) ? intval($image['sort_order']) : 0,
                    'download_status' => 'pending',
                    'created_at' => current_time('mysql', true)
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
            
            if ($result !== false) {
                $inserted++;
            }
        }
        
        return $inserted;
    }

    /**
     * Insert property features
     */
    public static function insert_property_features($property_id, $features) {
        global $wpdb;
        
        if (!$property_id || $property_id < 1 || empty($features)) {
            return false;
        }
        
        $table = self::get_table_name('property_features');
        $properties_table = self::get_table_name('properties');
        
        // Verify property exists
        $property_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$properties_table} WHERE id = %d",
            $property_id
        ));
        
        if (!$property_exists) {
            error_log('Property Manager: Cannot insert features - property ' . $property_id . ' does not exist');
            return false;
        }
        
        // Delete existing features for this property
        $wpdb->delete($table, array('property_id' => $property_id), array('%d'));
        
        $inserted = 0;
        foreach ($features as $feature) {
            $feature_name = is_array($feature) ? $feature['name'] : $feature;
            $feature_value = is_array($feature) && isset($feature['value']) ? $feature['value'] : null;
            
            $result = $wpdb->insert(
                $table,
                array(
                    'property_id' => $property_id,
                    'feature_name' => sanitize_text_field($feature_name),
                    'feature_value' => $feature_value ? sanitize_text_field($feature_value) : null,
                    'created_at' => current_time('mysql', true)
                ),
                array('%d', '%s', '%s', '%s')
            );
            
            if ($result !== false) {
                $inserted++;
            }
        }
        
        return $inserted;
    }

    /**
     * Update image attachment ID after download
     */
    public static function update_image_attachment($image_id, $attachment_id, $local_url = null) {
        global $wpdb;
        
        if (!$image_id || !$attachment_id) {
            return false;
        }
        
        $table = self::get_table_name('property_images');
        
        $update_data = array(
            'attachment_id' => $attachment_id,
            'download_status' => 'downloaded',
            'updated_at' => current_time('mysql', true)
        );
        
        $format = array('%d', '%s', '%s');
        
        if ($local_url) {
            $update_data['image_url'] = esc_url_raw($local_url);
            $format[] = '%s';
        }
        
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $image_id),
            $format,
            array('%d')
        );
        
        return $result !== false;
    }

    /**
     * Mark image download as failed
     */
    public static function mark_image_failed($image_id, $error_message = null) {
        global $wpdb;
        
        if (!$image_id) {
            return false;
        }
        
        $table = self::get_table_name('property_images');
        
        // Increment download attempts
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET download_attempts = download_attempts + 1 WHERE id = %d",
            $image_id
        ));
        
        $result = $wpdb->update(
            $table,
            array(
                'download_status' => 'failed',
                'error_message' => $error_message ? sanitize_textarea_field($error_message) : null,
                'updated_at' => current_time('mysql', true)
            ),
            array('id' => $image_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }

    /**
     * Get pending images for download
     */
    public static function get_pending_images($limit = 50) {
        global $wpdb;
        
        $table = self::get_table_name('property_images');
        $properties_table = self::get_table_name('properties');
        
        // Only get images for active properties, retry failed images with less than 3 attempts
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pi.* 
             FROM {$table} pi 
             INNER JOIN {$properties_table} p ON pi.property_id = p.id 
             WHERE p.status = 'active' 
             AND (
                 pi.download_status = 'pending' 
                 OR (pi.download_status = 'failed' AND pi.download_attempts < 3)
             )
             ORDER BY pi.download_attempts ASC, pi.created_at ASC 
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Clean up orphaned records
     */
    public static function cleanup_orphaned_records() {
        global $wpdb;
        
        $properties_table = self::get_table_name('properties');
        
        // Clean up orphaned images (should be handled by foreign keys)
        $images_table = self::get_table_name('property_images');
        $deleted_images = $wpdb->query(
            "DELETE pi FROM {$images_table} pi 
             LEFT JOIN {$properties_table} p ON pi.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        // Clean up orphaned features
        $features_table = self::get_table_name('property_features');
        $deleted_features = $wpdb->query(
            "DELETE pf FROM {$features_table} pf 
             LEFT JOIN {$properties_table} p ON pf.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        // Clean up orphaned favorites
        $favorites_table = self::get_table_name('user_favorites');
        $deleted_favorites = $wpdb->query(
            "DELETE uf FROM {$favorites_table} uf 
             LEFT JOIN {$properties_table} p ON uf.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        // Clean up orphaned views
        $views_table = self::get_table_name('property_views');
        $deleted_views = $wpdb->query(
            "DELETE pv FROM {$views_table} pv 
             LEFT JOIN {$properties_table} p ON pv.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        // Clean up orphaned inquiries
        $inquiries_table = self::get_table_name('property_inquiries');
        $deleted_inquiries = $wpdb->query(
            "DELETE pi FROM {$inquiries_table} pi 
             LEFT JOIN {$properties_table} p ON pi.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        if ($deleted_images || $deleted_features || $deleted_favorites || $deleted_views || $deleted_inquiries) {
            error_log(sprintf(
                'Property Manager: Cleaned up orphaned records - Images: %d, Features: %d, Favorites: %d, Views: %d, Inquiries: %d',
                $deleted_images,
                $deleted_features,
                $deleted_favorites,
                $deleted_views,
                $deleted_inquiries
            ));
        }
        
        return true;
    }

    /**
     * Scheduled cleanup - runs daily
     */
    public function scheduled_cleanup() {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Clean up orphaned records
            $this->cleanup_orphaned_records();
            
            // Clean up old property views (keep only 30 days)
            $views_table = self::get_table_name('property_views');
            $deleted_views = $wpdb->query(
                "DELETE FROM {$views_table} 
                 WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            
            // Clean up old email logs (keep only 90 days)
            $email_logs_table = self::get_table_name('email_logs');
            $deleted_emails = $wpdb->query(
                "DELETE FROM {$email_logs_table} 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
            
            // Clean up old security logs (keep only 90 days)
            $security_logs_table = self::get_table_name('security_logs');
            $deleted_security = $wpdb->query(
                "DELETE FROM {$security_logs_table} 
                 WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
            
            // Clean up old search history (keep only 90 days)
            $search_history_table = self::get_table_name('search_history');
            $deleted_searches = $wpdb->query(
                "DELETE FROM {$search_history_table} 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
            
            // Keep only last 100 import logs
            $import_logs_table = self::get_table_name('import_logs');
            $keep_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$import_logs_table} 
                 ORDER BY started_at DESC 
                 LIMIT %d",
                100
            ));
            
            if (!empty($keep_ids)) {
                $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
                $deleted_imports = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$import_logs_table} 
                     WHERE id NOT IN ({$placeholders})",
                    ...$keep_ids
                ));
            } else {
                $deleted_imports = 0;
            }
            
            // Clean up unverified alerts older than 48 hours
            $alerts_table = self::get_table_name('property_alerts');
            $deleted_alerts = $wpdb->query(
                "DELETE FROM {$alerts_table} 
                 WHERE email_verified = 0 
                 AND status = 'pending'
                 AND token_expires_at < NOW()"
            );
            
            // Optimize tables
            $this->optimize_tables();
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Log cleanup
            error_log(sprintf(
                'Property Manager: Cleanup completed - Views: %d, Emails: %d, Security: %d, Searches: %d, Imports: %d, Alerts: %d',
                $deleted_views,
                $deleted_emails,
                $deleted_security,
                $deleted_searches,
                $deleted_imports,
                $deleted_alerts
            ));
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Property Manager: Cleanup error - ' . $e->getMessage());
        }
    }

    /**
     * Optimize database tables
     */
    private function optimize_tables() {
        global $wpdb;
        
        $tables = array(
            'pm_properties',
            'pm_property_images',
            'pm_property_features',
            'pm_user_favorites',
            'pm_saved_searches',
            'pm_property_alerts',
            'pm_property_views',
            'pm_property_inquiries',
            'pm_email_logs',
            'pm_import_logs',
            'pm_security_logs',
            'pm_audit_logs',
            'pm_search_history'
        );
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("OPTIMIZE TABLE {$table_name}");
        }
        
        return true;
    }

    /**
     * Get database statistics
     */
    public static function get_database_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Properties count
        $properties_table = self::get_table_name('properties');
        $stats['properties_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$properties_table}");
        $stats['properties_active'] = $wpdb->get_var("SELECT COUNT(*) FROM {$properties_table} WHERE status = 'active'");
        $stats['properties_inactive'] = $wpdb->get_var("SELECT COUNT(*) FROM {$properties_table} WHERE status = 'inactive'");
        
        // Images count
        $images_table = self::get_table_name('property_images');
        $stats['images_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$images_table}");
        $stats['images_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$images_table} WHERE download_status = 'pending'");
        $stats['images_downloaded'] = $wpdb->get_var("SELECT COUNT(*) FROM {$images_table} WHERE download_status = 'downloaded'");
        $stats['images_failed'] = $wpdb->get_var("SELECT COUNT(*) FROM {$images_table} WHERE download_status = 'failed'");
        
        // Favorites count
        $favorites_table = self::get_table_name('user_favorites');
        $stats['favorites_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$favorites_table}");
        
        // Alerts count
        $alerts_table = self::get_table_name('property_alerts');
        $stats['alerts_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$alerts_table}");
        $stats['alerts_active'] = $wpdb->get_var("SELECT COUNT(*) FROM {$alerts_table} WHERE status = 'active'");
        
        // Inquiries count
        $inquiries_table = self::get_table_name('property_inquiries');
        $stats['inquiries_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$inquiries_table}");
        $stats['inquiries_new'] = $wpdb->get_var("SELECT COUNT(*) FROM {$inquiries_table} WHERE status = 'new'");
        
        // Database size
        $db_name = DB_NAME;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(data_length + index_length) as size 
             FROM information_schema.TABLES 
             WHERE table_schema = %s 
             AND table_name LIKE %s",
            $db_name,
            $wpdb->prefix . 'pm_%'
        ));
        
        $stats['database_size'] = $result ? $result->size : 0;
        $stats['database_size_mb'] = $result ? round($result->size / 1024 / 1024, 2) : 0;
        
        return $stats;
    }

    /**
     * Check database integrity
     */
    public static function check_database_integrity() {
        global $wpdb;
        
        $issues = array();
        
        // Check if all tables exist
        $required_tables = array(
            'pm_properties',
            'pm_property_images',
            'pm_property_features',
            'pm_user_favorites',
            'pm_saved_searches',
            'pm_property_alerts',
            'pm_property_views',
            'pm_property_inquiries',
            'pm_email_logs',
            'pm_import_logs',
            'pm_security_logs',
            'pm_audit_logs',
            'pm_search_history'
        );
        
        foreach ($required_tables as $table) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
                $issues[] = "Table {$table} does not exist";
            }
        }
        
        // Check for orphaned records
        $properties_table = self::get_table_name('properties');
        
        $orphaned_images = $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::get_table_name('property_images') . " pi 
             LEFT JOIN {$properties_table} p ON pi.property_id = p.id 
             WHERE p.id IS NULL"
        );
        
        if ($orphaned_images > 0) {
            $issues[] = "{$orphaned_images} orphaned image records found";
        }
        
        // Check for corrupted data
        $invalid_prices = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$properties_table} 
             WHERE price IS NOT NULL AND price < 0"
        );
        
        if ($invalid_prices > 0) {
            $issues[] = "{$invalid_prices} properties with invalid prices";
        }
        
        return array(
            'healthy' => empty($issues),
            'issues' => $issues
        );
    }

    /**
     * Repair database issues
     */
    public static function repair_database() {
        global $wpdb;
        
        $repaired = array();
        
        // Clean up orphaned records
        self::cleanup_orphaned_records();
        $repaired[] = 'Cleaned up orphaned records';
        
        // Fix invalid prices
        $properties_table = self::get_table_name('properties');
        $wpdb->query(
            "UPDATE {$properties_table} 
             SET price = 0 
             WHERE price IS NOT NULL AND price < 0"
        );
        $repaired[] = 'Fixed invalid prices';
        
        // Reset failed image downloads (max attempts)
        $images_table = self::get_table_name('property_images');
        $wpdb->query(
            "UPDATE {$images_table} 
             SET download_status = 'pending', download_attempts = 0 
             WHERE download_status = 'failed' AND download_attempts >= 3"
        );
        $repaired[] = 'Reset failed image downloads';
        
        // Optimize all tables
        $instance = self::get_instance();
        $instance->optimize_tables();
        $repaired[] = 'Optimized all tables';
        
        return $repaired;
    }

    /**
     * Backup database tables (export to SQL)
     */
    public static function backup_database() {
        global $wpdb;
        
        $tables = array(
            'pm_properties',
            'pm_property_images',
            'pm_property_features',
            'pm_user_favorites',
            'pm_saved_searches',
            'pm_property_alerts',
            'pm_property_views',
            'pm_property_inquiries'
        );
        
        $backup = array();
        $backup['timestamp'] = current_time('mysql', true);
        $backup['version'] = self::DB_VERSION;
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $rows = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);
            $backup['tables'][$table] = $rows;
        }
        
        return $backup;
    }

    /**
     * Get table row counts
     */
    public static function get_table_counts() {
        global $wpdb;
        
        $counts = array();
        
        $tables = array(
            'properties' => 'pm_properties',
            'images' => 'pm_property_images',
            'features' => 'pm_property_features',
            'favorites' => 'pm_user_favorites',
            'searches' => 'pm_saved_searches',
            'alerts' => 'pm_property_alerts',
            'views' => 'pm_property_views',
            'inquiries' => 'pm_property_inquiries',
            'email_logs' => 'pm_email_logs',
            'import_logs' => 'pm_import_logs',
            'security_logs' => 'pm_security_logs',
            'audit_logs' => 'pm_audit_logs',
            'search_history' => 'pm_search_history'
        );
        
        foreach ($tables as $key => $table) {
            $table_name = $wpdb->prefix . $table;
            $counts[$key] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }
        
        return $counts;
    }
}