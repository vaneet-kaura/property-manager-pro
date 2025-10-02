<?php
/**
 * Property management class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Property {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'create_property_post_type'));
        add_action('wp', array($this, 'track_property_view'));
    }
    
    /**
     * Create property custom post type (optional - we're using custom tables)
     */
    public function create_property_post_type() {
        // This is optional since we're using custom tables
        // But can be useful for WordPress integration
    }
    

    /**
     * Get property statistics
     * 
     * @return array Statistics about properties
     */
    public function get_property_stats() {
        global $wpdb;
    
        $table = PropertyManager_Database::get_table_name('properties');
    
        try {
            // Total properties
            $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %1s", $table));
            $total = $total !== null ? absint($total) : 0;
        
            // Active properties
            $active = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %1s WHERE status = %s",
                $table, 'active'
            ));
            $active = $active !== null ? absint($active) : 0;
        
            // Average price (only active properties with price > 0)
            $avg_price = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(price) FROM %1s WHERE status = %s AND price > 0",
                $table, 'active'
            ));
            $avg_price = $avg_price !== null ? floatval($avg_price) : 0;
        
            // Properties added this month
            $first_day_of_month = date('Y-m-01 00:00:00');
            $new_this_month = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %1s WHERE created_at >= %s",
                $table, $first_day_of_month
            ));
            $new_this_month = $new_this_month !== null ? absint($new_this_month) : 0;
        
            // Properties by type
            $by_type_results = $wpdb->get_results($wpdb->prepare(
                "SELECT property_type, COUNT(*) as count 
                 FROM %1s 
                 WHERE status = %s AND property_type IS NOT NULL AND property_type != '' 
                 GROUP BY property_type 
                 ORDER BY count DESC 
                 LIMIT 10",
                $table, 'active'
            ), ARRAY_A);
        
            $by_type = array();
            if ($by_type_results && is_array($by_type_results)) {
                foreach ($by_type_results as $row) {
                    $type = sanitize_text_field($row['property_type']);
                    $count = absint($row['count']);
                    if ($type && $count > 0) {
                        $by_type[] = (object)['type' => $type, 'count' => $count];
                    }
                }
            }
        
            // Check for database errors
            if ($wpdb->last_error) {
                error_log('Property Manager Pro: Database error in get_property_stats(): ' . $wpdb->last_error);
            }
        
            return array(
                'total' => $total,
                'active' => $active,
                'avg_price' => $avg_price,
                'new_this_month' => $new_this_month,
                'by_type' => $by_type
            );
        
        } catch (Exception $e) {
            error_log('Property Manager Pro: Exception in get_property_stats(): ' . $e->getMessage());
            return array(
                'total' => 0,
                'active' => 0,
                'avg_price' => 0,
                'new_this_month' => 0,
                'by_type' => array()
            );
        }
    }
    /**
     * Get property by ID
     */
    public function get_property($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status = 'active'",
            $property_id
        ));
        
        if ($property) {
            $property->images = $this->get_property_images($property_id);
            $property->features = $this->get_property_features($property_id);
        }
        
        return $property;
    }
    
    /**
     * Get property by reference
     */
    public function get_property_by_ref($ref) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE ref = %s AND status = 'active'",
            $ref
        ));
        
        if ($property) {
            $property->images = $this->get_property_images($property->id);
            $property->features = $this->get_property_features($property->id);
        }
        
        return $property;
    }
    
    /**
     * Get property images
     */
    public function get_property_images($property_id, $active_only = false) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        $where = "property_id = %d";
        if ($active_only) {
            $where .= " AND download_status = 'downloaded'";
        }
        
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %1s WHERE $where ORDER BY sort_order ASC",
            $table, $property_id
        ));
        
        // Get actual image URLs from WordPress Media Library if attachment_id exists
        foreach ($images as &$image) {
            if ($image->attachment_id) {
                $url = wp_get_attachment_url($image->attachment_id);
                if ($url) {
                    $image->image_url = $url;
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Get property features
     */
    public function get_property_features($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_features');
        
        $features = $wpdb->get_col($wpdb->prepare(
            "SELECT feature_name FROM %1s WHERE property_id = %d",
            $table, $property_id
        ));
        
        return $features;
    }
    
    /**
     * Search properties with filters
     */
    public function search_properties($args = array()) {
        $search = PropertyManager_Search::get_instance();
        return $search->search($args);
    }
    
    /**
     * Get featured properties
     */
    public function get_featured_properties($limit = 6) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $properties = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %1s 
             WHERE status = 'active' AND featured = 1 
             ORDER BY updated_at DESC 
             LIMIT %d",
            $table, $limit
        ));
        
        // Add images to each property
        foreach ($properties as &$property) {
            $property->images = $this->get_property_images($property->id, true);
            $property->features = $this->get_property_features($property->id);
        }
        
        return $properties;
    }
    
    /**
     * Get latest properties
     */
    public function get_latest_properties($limit = 6) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $properties = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE status = 'active' 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
        
        // Add images to each property
        foreach ($properties as &$property) {
            $property->images = $this->get_property_images($property->id, true);
            $property->features = $this->get_property_features($property->id);
        }
        
        return $properties;
    }
    
    /**
     * Get similar properties
     */
    public function get_similar_properties($property_id, $limit = 4) {
        $property = $this->get_property($property_id);
        
        if (!$property) {
            return array('properties' => array(), 'total' => 0);
        }
        
        $search_args = array(
            'per_page' => $limit,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        // Match type and town
        if (!empty($property->property_type)) {
            $search_args['property_type'] = $property->property_type;
        }
        
        if (!empty($property->town)) {
            $search_args['town'] = $property->town;
        }
        
        // Price range (±20%)
        if ($property->price > 0) {
            $search_args['price_min'] = $property->price * 0.8;
            $search_args['price_max'] = $property->price * 1.2;
        }
        
        $results = $this->search_properties($search_args);
        
        // Remove the current property from results
        $results['properties'] = array_filter($results['properties'], function($p) use ($property_id) {
            return $p->id != $property_id;
        });
        
        return $results;
    }
    
    /**
     * Track property view
     */
    public function track_property_view() {
        if (!is_singular() && !isset($_GET['property_id'])) {
            return;
        }
        
        $property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : null;
        
        if (!$property_id) {
            return;
        }
        
        // Update view count
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('properties');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET view_count = view_count + 1 WHERE id = %d",
            $property_id
        ));
        
        // Track in property views
        $this->track_last_viewed($property_id);
    }
    
    /**
     * Track last viewed property
     * FIXED: Now uses pm_property_views table instead of pm_last_viewed
     */
    private function track_last_viewed($property_id) {
        global $wpdb;
        
        // Use correct table name
        $table = PropertyManager_Database::get_table_name('property_views');
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $ip_address = $this->get_client_ip();
        
        // Check if already viewed recently (within 1 hour)
        $where_clause = $user_id ? 
            $wpdb->prepare("user_id = %d", $user_id) : 
            $wpdb->prepare("ip_address = %s AND user_id IS NULL", $ip_address);
        
        $recent_view = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE property_id = %d 
             AND " . $where_clause . "
             AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $property_id
        ));
        
        if (!$recent_view) {
            // Insert new view record
            $wpdb->insert($table, array(
                'property_id' => $property_id,
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'user_agent' => $this->get_user_agent(),
                'viewed_at' => current_time('mysql')
            ), array('%d', '%d', '%s', '%s', '%s'));
        }
        
        // Clean up old records (keep only last 50 per user/IP)
        $this->cleanup_last_viewed($user_id, $ip_address);
    }
    
    /**
     * Clean up old last viewed records
     * FIXED: Updated to use pm_property_views table structure
     */
    private function cleanup_last_viewed($user_id, $ip_address) {
        global $wpdb;
        
        // Use correct table name
        $table = PropertyManager_Database::get_table_name('property_views');
        
        // Build WHERE clause
        if ($user_id) {
            $where_clause = $wpdb->prepare("user_id = %d", $user_id);
        } else {
            $where_clause = $wpdb->prepare("ip_address = %s AND user_id IS NULL", $ip_address);
        }
        
        // Delete old records, keep only latest 50
        $wpdb->query("
            DELETE FROM $table 
            WHERE $where_clause 
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM $table 
                    WHERE $where_clause 
                    ORDER BY viewed_at DESC 
                    LIMIT 50
                ) as keep_records
            )
        ");
    }
    
    /**
     * Get last viewed properties for user/IP
     * FIXED: Updated to use pm_property_views table
     */
    public function get_last_viewed_properties($limit = 10) {
        global $wpdb;
        
        // Use correct table names
        $views_table = PropertyManager_Database::get_table_name('property_views');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $ip_address = $this->get_client_ip();
        
        // Build WHERE clause
        if ($user_id) {
            $where_clause = $wpdb->prepare("v.user_id = %d", $user_id);
        } else {
            $where_clause = $wpdb->prepare("v.ip_address = %s AND v.user_id IS NULL", $ip_address);
        }
        
        // Get properties with latest view time
        $properties = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, MAX(v.viewed_at) as last_viewed
             FROM $views_table v
             INNER JOIN $properties_table p ON v.property_id = p.id
             WHERE $where_clause AND p.status = 'active'
             GROUP BY p.id
             ORDER BY last_viewed DESC
             LIMIT %d",
            $limit
        ));
        
        // Add images and features to each property
        foreach ($properties as &$property) {
            $property->images = $this->get_property_images($property->id, true);
            $property->features = $this->get_property_features($property->id);
        }
        
        return $properties;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Get user agent
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? 
            substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : 
            '';
    }
    
    /**
     * Format price with currency symbol
     */
    public function format_price($price, $currency = 'EUR') {
        if (!$price || $price <= 0) {
            return __('Price on request', 'property-manager-pro');
        }
        
        $options = get_option('property_manager_options', array());
        $currency_symbol = isset($options['currency_symbol']) ? $options['currency_symbol'] : '€';
        
        if ($price >= 1000000) {
            return $currency_symbol . number_format($price / 1000000, 1) . 'M';
        } elseif ($price >= 1000) {
            return $currency_symbol . number_format($price / 1000, 0) . 'K';
        } else {
            return $currency_symbol . number_format($price, 0);
        }
    }
    
    /**
     * Get property URL
     */
    public function get_property_url($property, $language = 'en') {
        $options = get_option('property_manager_pages', array());
        $search_page_id = isset($options['property_search']) ? $options['property_search'] : null;
        
        if ($search_page_id) {
            $base_url = get_permalink($search_page_id);
            return add_query_arg(array(
                'property_id' => $property->id,
                'action' => 'view'
            ), $base_url);
        }
        
        // Fallback to external URL if available
        $url_field = 'url_' . $language;
        if (isset($property->$url_field) && !empty($property->$url_field)) {
            return $property->$url_field;
        }
        
        return '#';
    }
    
    /**
     * Generate property description excerpt
     */
    public function get_property_excerpt($property, $language = 'en', $length = 150) {
        $desc_field = 'desc_' . $language;
        $description = isset($property->$desc_field) ? $property->$desc_field : '';
        
        if (empty($description) && $language !== 'en') {
            $description = isset($property->desc_en) ? $property->desc_en : '';
        }
        
        if (empty($description)) {
            return '';
        }
        
        $description = wp_strip_all_tags($description);
        
        if (strlen($description) > $length) {
            $description = substr($description, 0, $length);
            $description = substr($description, 0, strrpos($description, ' ')) . '...';
        }
        
        return $description;
    }
}