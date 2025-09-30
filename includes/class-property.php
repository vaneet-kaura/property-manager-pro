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
        $images = $wpdb->get_results($wpdb->prepare(
            $active_only ? 
            "SELECT * FROM $table WHERE property_id = %d AND attachment_id IS NOT NULL ORDER BY sort_order ASC" :
            "SELECT * FROM $table WHERE property_id = %d ORDER BY sort_order ASC",
            $property_id
        ));
        
        // Process images to include WordPress attachment URLs
        foreach ($images as &$image) {
            if ($image->attachment_id && $image->download_status === 'downloaded') {
                // Use WordPress attachment URL (works with S3 offload plugins)
                $image->wp_image_url = wp_get_attachment_url($image->attachment_id);
                $image->wp_image_sizes = $this->get_image_sizes($image->attachment_id);
            } else {
                // Fallback to original URL if not downloaded yet
                $image->wp_image_url = $image->image_url;
                $image->wp_image_sizes = array();
            }
        }
        
        return $images;
    }
    
    /**
     * Get WordPress image sizes for an attachment
     */
    private function get_image_sizes($attachment_id) {
        $sizes = array();
        $image_sizes = get_intermediate_image_sizes();
        
        // Add full size
        $sizes['full'] = wp_get_attachment_image_url($attachment_id, 'full');
        
        // Add other sizes
        foreach ($image_sizes as $size) {
            $image_url = wp_get_attachment_image_url($attachment_id, $size);
            if ($image_url) {
                $sizes[$size] = $image_url;
            }
        }
        
        return $sizes;
    }
    
    /**
     * Get property featured image
     */
    public function get_property_featured_image($property_id, $size = 'large') {
        $images = $this->get_property_images($property_id);
        
        if (empty($images)) {
            return null;
        }
        
        // Return the first image as featured
        $featured_image = $images[0];
        
        $result = array(
            'url' => $featured_image->wp_image_url,
            'alt' => $featured_image->image_alt ?: $featured_image->image_title,
            'title' => $featured_image->image_title,
            'attachment_id' => $featured_image->attachment_id,
            'download_status' => $featured_image->download_status
        );
        
        // If we have an attachment and specific size requested
        if ($featured_image->attachment_id && $featured_image->download_status === 'downloaded' && $size !== 'full') {
            $sized_url = wp_get_attachment_image_url($featured_image->attachment_id, $size);
            if ($sized_url) {
                $result['url'] = $sized_url;
            }
        }
        
        return $result;
    }
    
    /**
     * Get property features
     */
    public function get_property_features($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_features');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT feature_name, feature_value FROM $table WHERE property_id = %d ORDER BY feature_name ASC",
            $property_id
        ));
    }
    
    /**
     * Search properties
     */
    public function search_properties($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'min_price' => null,
            'max_price' => null,
            'min_beds' => null,
            'max_beds' => null,
            'min_baths' => null,
            'max_baths' => null,
            'surface_min' => null,
            'surface_max' => null,
            'type' => null,
            'town' => null,
            'province' => null,
            'location' => null, // Generic location search
            'pool' => null,
            'new_build' => null,
            'featured' => null,
            'price_freq' => null,
            'currency' => null,
            'status' => 'active',
            'keyword' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        $where_clauses[] = "1=1";
        $table = PropertyManager_Database::get_table_name('properties');
        $where_values = array();
        
        // Price range
        if (!is_null($args['min_price']) && $args['min_price'] > 0) {
            $where_clauses[] = "price >= %f";
            $where_values[] = $args['min_price'];
        }
        
        if (!is_null($args['max_price']) && $args['max_price'] > 0) {
            $where_clauses[] = "price <= %f";
            $where_values[] = $args['max_price'];
        }
        
        // Beds range
        if (!is_null($args['min_beds']) && $args['min_beds'] > 0) {
            $where_clauses[] = "beds >= %d";
            $where_values[] = $args['min_beds'];
        }
        
        if (!is_null($args['max_beds']) && $args['max_beds'] > 0) {
            $where_clauses[] = "beds <= %d";
            $where_values[] = $args['max_beds'];
        }
        
        // Baths range
        if (!is_null($args['min_baths']) && $args['min_baths'] > 0) {
            $where_clauses[] = "baths >= %d";
            $where_values[] = $args['min_baths'];
        }
        
        if (!is_null($args['max_baths']) && $args['max_baths'] > 0) {
            $where_clauses[] = "baths <= %d";
            $where_values[] = $args['max_baths'];
        }
        
        // Property type
        if (!empty($args['type'])) {
            $where_clauses[] = "type = %s";
            $where_values[] = $args['type'];
        }
        
        // Location
        if (!empty($args['town'])) {
            $where_clauses[] = "town = %s";
            $where_values[] = $args['town'];
        }
        
        if (!empty($args['province'])) {
            $where_clauses[] = "province = %s";
            $where_values[] = $args['province'];
        }
        
        // Features
        if (!is_null($args['pool'])) {
            $where_clauses[] = "pool = %d";
            $where_values[] = (int) $args['pool'];
        }
        
        if (!is_null($args['new_build'])) {
            $where_clauses[] = "new_build = %d";
            $where_values[] = (int) $args['new_build'];
        }
        
        if (!is_null($args['featured'])) {
            $where_clauses[] = "featured = %d";
            $where_values[] = (int) $args['featured'];
        }
        
        // Handle generic location search (search in both town and province)
        if (!empty($args['location'])) {
            $where_clauses[] = "(town LIKE %s OR province LIKE %s)";
            $location_like = '%' . $wpdb->esc_like($args['location']) . '%';
            $where_values[] = $location_like;
            $where_values[] = $location_like;
        }
        
        // Surface area range
        if (!is_null($args['surface_min']) && $args['surface_min'] > 0) {
            $where_clauses[] = "surface_area_built >= %d";
            $where_values[] = $args['surface_min'];
        }
        
        if (!is_null($args['surface_max']) && $args['surface_max'] > 0) {
            $where_clauses[] = "surface_area_built <= %d";
            $where_values[] = $args['surface_max'];
        }
        
        // Price frequency (sale/rent)
        if (!empty($args['price_freq'])) {
            $where_clauses[] = "price_freq = %s";
            $where_values[] = $args['price_freq'];
        }
        
        // Property Status
        if (!empty($args['status']) && $args['status'] != "all") {
            $where_clauses[] = "status = %s";
            $where_values[] = $args['status'];
        }
        
        // Keyword search
        if (!empty($args['keyword'])) {
            $where_clauses[] = "(title LIKE %s OR description_en LIKE %s OR description_es LIKE %s OR ref LIKE %s OR town LIKE %s OR province LIKE %s)";
            $keyword_like = '%' . $wpdb->esc_like($args['keyword']) . '%';
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
        }
        
        // Build WHERE clause
        $where_sql = implode(' AND ', $where_clauses);
        
        // Pagination
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Order by
        $orderby_sql = $this->build_orderby_sql($args['orderby'], $args['order']);
        
        // Count total results
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        if (!empty($where_values)) {
            $count_sql = $wpdb->prepare($count_sql, $where_values);
        }
        $total_results = $wpdb->get_var($count_sql);
        
        // Get results
        $results_sql = "SELECT * FROM $table WHERE $where_sql $orderby_sql LIMIT %d OFFSET %d";
        $sql_values = array_merge($where_values, array($args['per_page'], $offset));
        $results_sql = $wpdb->prepare($results_sql, $sql_values);
        
        $properties = $wpdb->get_results($results_sql);
        
        // Add images to each property
        foreach ($properties as &$property) {
            $property->images = $this->get_property_images($property->id, true);
            $property->features = $this->get_property_features($property->id);
        }
        
        return array(
            'properties' => $properties,
            'total' => $total_results,
            'pages' => ceil($total_results / $args['per_page']),
            'current_page' => $args['page'],
            'per_page' => $args['per_page']
        );
    }
    
    /**
     * Build ORDER BY SQL clause
     */
    private function build_orderby_sql($orderby, $order) {
        $valid_orderby = array(
            'created_at', 'updated_at', 'price', 'beds', 'baths', 
            'town', 'province', 'type', 'views', 'ref'
        );
        
        $valid_order = array('ASC', 'DESC');
        
        if (!in_array($orderby, $valid_orderby)) {
            $orderby = 'created_at';
        }
        
        if (!in_array(strtoupper($order), $valid_order)) {
            $order = 'DESC';
        }
        
        return "ORDER BY $orderby $order";
    }
    
    /**
     * Get featured properties
     */
    public function get_featured_properties($limit = 6) {
        return $this->search_properties(array(
            'featured' => 1,
            'per_page' => $limit,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ));
    }
    
    /**
     * Get recent properties
     */
    public function get_recent_properties($limit = 12) {
        return $this->search_properties(array(
            'per_page' => $limit,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ));
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
        if (!empty($property->type)) {
            $search_args['type'] = $property->type;
        }
        
        if (!empty($property->town)) {
            $search_args['town'] = $property->town;
        }
        
        // Price range (±20%)
        if ($property->price > 0) {
            $search_args['min_price'] = $property->price * 0.8;
            $search_args['max_price'] = $property->price * 1.2;
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
            "UPDATE $table SET views = views + 1 WHERE id = %d",
            $property_id
        ));
        
        // Track in last viewed
        $this->track_last_viewed($property_id);
    }
    
    /**
     * Track last viewed property
     */
    private function track_last_viewed($property_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('last_viewed');
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $session_id = !$user_id ? session_id() : null;
        
        // Start session if not started
        if (!$user_id && !session_id()) {
            session_start();
            $session_id = session_id();
        }
        
        // Check if already viewed recently (within 1 hour)
        $recent_view = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE property_id = %d 
             AND " . ($user_id ? "user_id = %d" : "session_id = %s") . "
             AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $property_id,
            $user_id ? $user_id : $session_id
        ));
        
        if (!$recent_view) {
            // Insert new view record
            $wpdb->insert($table, array(
                'property_id' => $property_id,
                'user_id' => $user_id,
                'session_id' => $session_id,
                'viewed_at' => current_time('mysql')
            ));
        }
        
        // Clean up old records (keep only last 50 per user/session)
        $this->cleanup_last_viewed($user_id, $session_id);
    }
    
    /**
     * Clean up old last viewed records
     */
    private function cleanup_last_viewed($user_id, $session_id) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('last_viewed');
        
        $where_clause = $user_id ? "user_id = $user_id" : "session_id = '$session_id'";
        
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
     * Get last viewed properties for user/session
     */
    public function get_last_viewed_properties($limit = 10) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('last_viewed');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $session_id = !$user_id ? session_id() : null;
        
        if (!$user_id && !$session_id) {
            return array();
        }
        
        $where_clause = $user_id ? "lv.user_id = $user_id" : "lv.session_id = '$session_id'";
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT p.*, lv.viewed_at
            FROM $table lv
            JOIN $properties_table p ON lv.property_id = p.id
            WHERE $where_clause AND p.status = 'active'
            ORDER BY lv.viewed_at DESC
            LIMIT %d
        ", $limit));
    }
    
    /**
     * Get property types for dropdown
     */
    public function get_property_types() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_col("
            SELECT DISTINCT type 
            FROM $table 
            WHERE status = 'active' AND type IS NOT NULL AND type != ''
            ORDER BY type ASC
        ");
    }
    
    /**
     * Get towns for dropdown
     */
    public function get_towns($province = null) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $where_clause = "status = 'active' AND town IS NOT NULL AND town != ''";
        $params = array();
        
        if ($province) {
            $where_clause .= " AND province = %s";
            $params[] = $province;
        }
        
        $sql = "SELECT DISTINCT town FROM $table WHERE $where_clause ORDER BY town ASC";
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_col($sql);
    }
    
    /**
     * Get provinces for dropdown
     */
    public function get_provinces() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_col("
            SELECT DISTINCT province 
            FROM $table 
            WHERE status = 'active' AND province IS NOT NULL AND province != ''
            ORDER BY province ASC
        ");
    }
    
    /**
     * Get price ranges for dropdown
     */
    public function get_price_ranges() {
        return array(
            '0-100000' => __('Up to €100,000', 'property-manager-pro'),
            '100000-200000' => __('€100,000 - €200,000', 'property-manager-pro'),
            '200000-300000' => __('€200,000 - €300,000', 'property-manager-pro'),
            '300000-400000' => __('€300,000 - €400,000', 'property-manager-pro'),
            '400000-500000' => __('€400,000 - €500,000', 'property-manager-pro'),
            '500000-750000' => __('€500,000 - €750,000', 'property-manager-pro'),
            '750000-1000000' => __('€750,000 - €1,000,000', 'property-manager-pro'),
            '1000000-99999999' => __('€1,000,000+', 'property-manager-pro')
        );
    }
    
    /**
     * Format price for display
     */
    public function format_price($price, $currency = 'EUR') {
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
        $desc_field = 'description_' . $language;
        $description = isset($property->$desc_field) ? $property->$desc_field : '';
        
        if (empty($description) && $language !== 'en') {
            $description = isset($property->description_en) ? $property->description_en : '';
        }
        
        if (empty($description)) {
            return '';
        }
        
        return wp_trim_words(strip_tags($description), $length / 8, '...');
    }
    
    /**
     * Check if property has coordinates
     */
    public function has_coordinates($property) {
        return !empty($property->latitude) && !empty($property->longitude);
    }
    
    /**
     * Get property coordinate array for maps
     */
    public function get_property_coordinates($property) {
        if (!$this->has_coordinates($property)) {
            return null;
        }
        
        return array(
            'lat' => (float) $property->latitude,
            'lng' => (float) $property->longitude,
            'title' => $property->title,
            'price' => $this->format_price($property->price),
            'url' => $this->get_property_url($property)
        );
    }
    
    /**
     * Get properties for map display
     */
    public function get_properties_for_map($search_args = array()) {
        $search_args['per_page'] = 500; // Limit for performance
        $results = $this->search_properties($search_args);
        
        $coordinates = array();
        foreach ($results['properties'] as $property) {
            $coords = $this->get_property_coordinates($property);
            if ($coords) {
                $coordinates[] = $coords;
            }
        }
        
        return $coordinates;
    }
    
    /**
     * Submit property inquiry
     */
    public function submit_inquiry($property_id, $data) {
        global $wpdb;
        
        // Validate required fields
        $required = array('name', 'email', 'message');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', __('Please fill in all required fields.', 'property-manager-pro'));
            }
        }
        
        // Validate email
        if (!is_email($data['email'])) {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'property-manager-pro'));
        }
        
        // Check if property exists
        $property = $this->get_property($property_id);
        if (!$property) {
            return new WP_Error('invalid_property', __('Property not found.', 'property-manager-pro'));
        }
        
        // Insert inquiry
        $table = PropertyManager_Database::get_table_name('property_inquiries');
        
        $inquiry_data = array(
            'property_id' => $property_id,
            'name' => sanitize_text_field($data['name']),
            'email' => sanitize_email($data['email']),
            'phone' => isset($data['phone']) ? sanitize_text_field($data['phone']) : '',
            'message' => sanitize_textarea_field($data['message']),
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'status' => 'new',
            'created_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table, $inquiry_data);
        
        if ($result === false) {
            return new WP_Error('db_error', __('Failed to submit inquiry. Please try again.', 'property-manager-pro'));
        }
        
        // Send notification email
        $this->send_inquiry_notification($wpdb->insert_id, $property, $inquiry_data);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Send inquiry notification email
     */
    private function send_inquiry_notification($inquiry_id, $property, $inquiry_data) {
        $options = get_option('property_manager_options', array());
        $admin_email = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        $subject = sprintf(__('[%s] New Property Inquiry - %s', 'property-manager-pro'), 
            get_bloginfo('name'), 
            $property->title
        );
        
        $message = sprintf(
            __("A new property inquiry has been submitted:\n\nProperty: %s\nReference: %s\nPrice: %s\n\nFrom: %s\nEmail: %s\nPhone: %s\n\nMessage:\n%s\n\nView inquiry in admin: %s", 'property-manager-pro'),
            $property->title,
            $property->ref,
            $this->format_price($property->price),
            $inquiry_data['name'],
            $inquiry_data['email'],
            $inquiry_data['phone'],
            $inquiry_data['message'],
            admin_url('admin.php?page=property-manager-inquiries&inquiry_id=' . $inquiry_id)
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get property statistics
     */
    public function get_property_stats() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        $stats = array();
        
        // Total properties
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active'");
        
        // Properties by type
        $stats['by_type'] = $wpdb->get_results("
            SELECT type, COUNT(*) as count 
            FROM $table 
            WHERE status = 'active' AND type IS NOT NULL 
            GROUP BY type 
            ORDER BY count DESC
        ");
        
        // Properties by price range
        $stats['by_price'] = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN price < 100000 THEN 'Under 100K'
                    WHEN price < 200000 THEN '100K-200K'
                    WHEN price < 300000 THEN '200K-300K'
                    WHEN price < 500000 THEN '300K-500K'
                    WHEN price < 750000 THEN '500K-750K'
                    WHEN price < 1000000 THEN '750K-1M'
                    ELSE 'Over 1M'
                END as price_range,
                COUNT(*) as count
            FROM $table 
            WHERE status = 'active' AND price > 0
            GROUP BY price_range
            ORDER BY MIN(price) ASC
        ");
        
        // Average price
        $stats['avg_price'] = $wpdb->get_var("SELECT AVG(price) FROM $table WHERE status = 'active' AND price > 0");
        
        return $stats;
    }
}