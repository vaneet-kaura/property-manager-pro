<?php
/**
 * Database management class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Database {
    
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('property_manager_cleanup', array($this, 'scheduled_cleanup'));
    }

    public function scheduled_cleanup() {
        global $wpdb;
    
        $wpdb->query('START TRANSACTION');
    
        try {
            $this->cleanup_orphaned_records();
        
            $last_viewed_table = self::get_table_name('last_viewed');
            $wpdb->query("DELETE FROM $last_viewed_table WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        
            $email_logs_table = self::get_table_name('email_logs');
            $wpdb->query("DELETE FROM $email_logs_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        
            $import_logs_table = self::get_table_name('import_logs');
            $keep_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $import_logs_table ORDER BY started_at DESC LIMIT %d",
                100
            ));
        
            if (!empty($keep_ids)) {
                $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $import_logs_table WHERE id NOT IN ($placeholders)",
                    $keep_ids
                ));
            }
        
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Property Manager cleanup error: ' . $e->getMessage());
        }
    }

    public static function create_tables() {
        global $wpdb;
    
        $charset_collate = $wpdb->get_charset_collate();
    
        $properties_table = $wpdb->prefix . 'pm_properties';
        $properties_sql = "CREATE TABLE $properties_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id varchar(100) NOT NULL,
            ref varchar(100) DEFAULT NULL,
            title text DEFAULT NULL,
            price decimal(15,2) DEFAULT NULL,
            currency varchar(10) DEFAULT 'EUR',
            price_freq enum('sale','rent') DEFAULT 'sale',
            new_build tinyint(1) DEFAULT 0,
            type varchar(100) DEFAULT NULL,
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
            description_en text DEFAULT NULL,
            description_es text DEFAULT NULL,
            description_de text DEFAULT NULL,
            description_fr text DEFAULT NULL,
            features text DEFAULT NULL,
            images text DEFAULT NULL,
            status enum('active','inactive','sold','rented') DEFAULT 'active',
            featured tinyint(1) DEFAULT 0,
            views bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY property_id (property_id),
            KEY ref (ref),
            KEY type (type),
            KEY town (town),
            KEY province (province),
            KEY price (price),
            KEY beds (beds),
            KEY baths (baths),
            KEY status (status),
            KEY featured (featured),
            KEY location (latitude,longitude)
        ) $charset_collate;";
    
        $images_table = $wpdb->prefix . 'pm_property_images';
        $images_sql = "CREATE TABLE $images_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            image_id int(11) NOT NULL,
            attachment_id bigint(20) unsigned DEFAULT NULL,
            image_url text NOT NULL,
            original_url text DEFAULT NULL,
            image_title varchar(255) DEFAULT NULL,
            image_alt varchar(255) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            download_status enum('pending','downloaded','failed') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY attachment_id (attachment_id),
            KEY sort_order (sort_order),
            KEY download_status (download_status)
        ) $charset_collate;";
    
        $features_table = $wpdb->prefix . 'pm_property_features';
        $features_sql = "CREATE TABLE $features_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            feature_name varchar(100) NOT NULL,
            feature_value varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY feature_name (feature_name)
        ) $charset_collate;";
    
        $favorites_table = $wpdb->prefix . 'pm_user_favorites';
        $favorites_sql = "CREATE TABLE $favorites_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            property_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_property (user_id, property_id),
            KEY user_id (user_id),
            KEY property_id (property_id)
        ) $charset_collate;";
    
        $saved_searches_table = $wpdb->prefix . 'pm_saved_searches';
        $saved_searches_sql = "CREATE TABLE $saved_searches_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            search_name varchar(100) NOT NULL,
            search_criteria text NOT NULL,
            email_alerts tinyint(1) DEFAULT 0,
            alert_frequency enum('daily','weekly','monthly') DEFAULT 'weekly',
            last_sent datetime DEFAULT NULL,
            status enum('active','paused') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY alert_frequency (alert_frequency)
        ) $charset_collate;";
    
        $alerts_table = $wpdb->prefix . 'pm_property_alerts';
        $alerts_sql = "CREATE TABLE $alerts_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            search_criteria text NOT NULL,
            frequency enum('daily','weekly','monthly') DEFAULT 'weekly',
            status enum('active','paused','unsubscribed') DEFAULT 'active',
            verification_token varchar(100) DEFAULT NULL,
            email_verified tinyint(1) DEFAULT 0,
            unsubscribe_token varchar(100) NOT NULL,
            last_sent datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY user_id (user_id),
            KEY status (status),
            KEY frequency (frequency),
            KEY email_verified (email_verified),
            UNIQUE KEY unsubscribe_token (unsubscribe_token)
        ) $charset_collate;";
    
        $last_viewed_table = $wpdb->prefix . 'pm_last_viewed';
        $last_viewed_sql = "CREATE TABLE $last_viewed_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            property_id bigint(20) unsigned NOT NULL,
            viewed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY property_id (property_id),
            KEY viewed_at (viewed_at)
        ) $charset_collate;";
    
        $inquiries_table = $wpdb->prefix . 'pm_property_inquiries';
        $inquiries_sql = "CREATE TABLE $inquiries_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            message text NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            status enum('new','read','replied') DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY email (email),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
    
        $email_logs_table = $wpdb->prefix . 'pm_email_logs';
        $email_logs_sql = "CREATE TABLE $email_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email_type varchar(50) NOT NULL,
            recipient_email varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            message text NOT NULL,
            status enum('sent','failed','pending') DEFAULT 'pending',
            error_message text DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email_type (email_type),
            KEY recipient_email (recipient_email),
            KEY status (status),
            KEY sent_at (sent_at)
        ) $charset_collate;";
    
        $import_logs_table = $wpdb->prefix . 'pm_import_logs';
        $import_logs_sql = "CREATE TABLE $import_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_type varchar(50) NOT NULL,
            status enum('started','completed','failed') DEFAULT 'started',
            properties_imported int(11) DEFAULT 0,
            properties_updated int(11) DEFAULT 0,
            properties_failed int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY import_type (import_type),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
        dbDelta($properties_sql);
        dbDelta($images_sql);
        dbDelta($features_sql);
        dbDelta($favorites_sql);
        dbDelta($saved_searches_sql);
        dbDelta($alerts_sql);
        dbDelta($last_viewed_sql);
        dbDelta($inquiries_sql);
        dbDelta($email_logs_sql);
        dbDelta($import_logs_sql);
    
        update_option('property_manager_db_version', PROPERTY_MANAGER_VERSION);
    }

    public static function drop_tables() {
        global $wpdb;
    
        $tables = array(
            $wpdb->prefix . 'pm_import_logs',
            $wpdb->prefix . 'pm_email_logs',
            $wpdb->prefix . 'pm_property_inquiries',
            $wpdb->prefix . 'pm_last_viewed',
            $wpdb->prefix . 'pm_property_alerts',
            $wpdb->prefix . 'pm_saved_searches',
            $wpdb->prefix . 'pm_user_favorites',
            $wpdb->prefix . 'pm_property_features',
            $wpdb->prefix . 'pm_property_images',
            $wpdb->prefix . 'pm_properties'
        );
    
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    
        delete_option('property_manager_db_version');
    }

    public static function maybe_update_database() {
        $current_version = get_option('property_manager_db_version');
    
        if (version_compare($current_version, PROPERTY_MANAGER_VERSION, '<')) {
            self::create_tables();
        }
    }

    public static function cleanup_property_data($property_id) {
        global $wpdb;
    
        $tables_to_clean = array(
            'pm_property_images',
            'pm_property_features', 
            'pm_user_favorites',
            'pm_last_viewed',
            'pm_property_inquiries'
        );
    
        foreach ($tables_to_clean as $table_name) {
            $table = self::get_table_name(str_replace('pm_', '', $table_name));
            $wpdb->delete($table, array('property_id' => $property_id), array('%d'));
        }
    
        return true;
    }

    public static function cleanup_orphaned_records() {
        global $wpdb;
    
        $properties_table = self::get_table_name('properties');
    
        $images_table = self::get_table_name('property_images');
        $wpdb->query("DELETE pi FROM $images_table pi LEFT JOIN $properties_table p ON pi.property_id = p.id WHERE p.id IS NULL");
    
        $features_table = self::get_table_name('property_features');
        $wpdb->query("DELETE pf FROM $features_table pf LEFT JOIN $properties_table p ON pf.property_id = p.id WHERE p.id IS NULL");
    
        $favorites_table = self::get_table_name('user_favorites');
        $wpdb->query("DELETE uf FROM $favorites_table uf LEFT JOIN $properties_table p ON uf.property_id = p.id WHERE p.id IS NULL");
    
        $last_viewed_table = self::get_table_name('last_viewed');
        $wpdb->query("DELETE lv FROM $last_viewed_table lv LEFT JOIN $properties_table p ON lv.property_id = p.id WHERE p.id IS NULL");
    
        $inquiries_table = self::get_table_name('property_inquiries');
        $wpdb->query("DELETE pi FROM $inquiries_table pi LEFT JOIN $properties_table p ON pi.property_id = p.id WHERE p.id IS NULL");
    
        return true;
    }

    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'pm_' . $table;
    }

    public static function upsert_property($data) {
        global $wpdb;
    
        $table = self::get_table_name('properties');
    
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE property_id = %s",
            $data['property_id']
        ));
    
        if ($existing) {
            $data['updated_at'] = current_time('mysql');
            $wpdb->update($table, $data, array('property_id' => $data['property_id']));
            return $existing->id;
        } else {
            $data['created_at'] = current_time('mysql');
            $data['updated_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $data);
        
            if ($result !== false) {
                return $wpdb->insert_id;
            }
        }
    
        return false;
    }

    public static function delete_property($property_id) {
        global $wpdb;
    
        $table = self::get_table_name('properties');
    
        self::cleanup_property_data($property_id);
    
        $result = $wpdb->delete($table, array('id' => $property_id), array('%d'));
    
        return $result !== false;
    }

    public static function insert_property_images($property_id, $images) {
        global $wpdb;
    
        $table = self::get_table_name('property_images');
    
        $properties_table = self::get_table_name('properties');
        $property_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $properties_table WHERE id = %d",
            $property_id
        ));
    
        if (!$property_exists) {
            return false;
        }
    
        $wpdb->delete($table, array('property_id' => $property_id), array('%d'));
    
        foreach ($images as $image) {
            $wpdb->insert($table, array(
                'property_id' => $property_id,
                'image_id' => $image['id'],
                'image_url' => $image['url'],
                'original_url' => $image['url'],
                'image_title' => isset($image['title']) ? $image['title'] : '',
                'image_alt' => isset($image['alt']) ? $image['alt'] : '',
                'sort_order' => isset($image['sort_order']) ? $image['sort_order'] : 0,
                'download_status' => 'pending',
                'created_at' => current_time('mysql')
            ), array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
        }
    
        return true;
    }

    public static function insert_property_features($property_id, $features) {
        global $wpdb;
    
        $table = self::get_table_name('property_features');
    
        $properties_table = self::get_table_name('properties');
        $property_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $properties_table WHERE id = %d",
            $property_id
        ));
    
        if (!$property_exists) {
            return false;
        }
    
        $wpdb->delete($table, array('property_id' => $property_id), array('%d'));
    
        foreach ($features as $feature) {
            $wpdb->insert($table, array(
                'property_id' => $property_id,
                'feature_name' => $feature,
                'created_at' => current_time('mysql')
            ), array('%d', '%s', '%s'));
        }
    
        return true;
    }

    public static function update_image_attachment($image_id, $attachment_id, $local_url = null) {
        global $wpdb;
    
        $table = self::get_table_name('property_images');
    
        $update_data = array(
            'attachment_id' => $attachment_id,
            'download_status' => 'downloaded',
            'updated_at' => current_time('mysql')
        );
    
        $format = array('%d', '%s', '%s');
    
        if ($local_url) {
            $update_data['image_url'] = $local_url;
            $format[] = '%s';
        }
    
        return $wpdb->update($table, $update_data, array('id' => $image_id), $format, array('%d'));
    }

    public static function mark_image_failed($image_id, $error_message = null) {
        global $wpdb;
    
        $table = self::get_table_name('property_images');
    
        return $wpdb->update(
            $table,
            array(
                'download_status' => 'failed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $image_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function get_pending_images($limit = 50) {
        global $wpdb;
    
        $table = self::get_table_name('property_images');
        $properties_table = self::get_table_name('properties');
    
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pi.* FROM $table pi 
             INNER JOIN $properties_table p ON pi.property_id = p.id 
             WHERE pi.download_status = 'pending' AND p.status = 'active'
             ORDER BY pi.created_at ASC 
             LIMIT %d",
            $limit
        ));
    }
}