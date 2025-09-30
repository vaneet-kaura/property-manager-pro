<?php
/**
 * Property Search Class - FIXED VERSION
 * 
 * @package PropertyManagerPro
 * @version 1.0.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Search {
    
    private static $instance = null;
    private $search_params = array();
    private $fulltext_enabled = null; // Cache FULLTEXT availability
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize search parameters from request
        $this->init_search_params();
    }
    
    /**
     * Initialize search parameters from request
     */
    private function init_search_params() {
        $this->search_params = array(
            // Basic search
            'keyword' => $this->get_search_param('keyword'),
            'location' => $this->get_search_param('location'),
            'property_type' => $this->get_search_param('property_type'),
            'price_min' => $this->get_search_param('price_min'),
            'price_max' => $this->get_search_param('price_max'),
            'beds_min' => $this->get_search_param('beds_min'),
            'beds_max' => $this->get_search_param('beds_max'),
            'baths_min' => $this->get_search_param('baths_min'),
            'baths_max' => $this->get_search_param('baths_max'),
            
            // Advanced search
            'province' => $this->get_search_param('province'),
            'town' => $this->get_search_param('town'),
            'surface_min' => $this->get_search_param('surface_min'),
            'surface_max' => $this->get_search_param('surface_max'),
            'pool' => $this->get_search_param('pool'),
            'new_build' => $this->get_search_param('new_build'),
            'featured' => $this->get_search_param('featured'),
            'price_freq' => $this->get_search_param('price_freq', 'sale'), // sale or rent
            'currency' => $this->get_search_param('currency', 'EUR'),
            
            // Features
            'features' => $this->get_search_param('features', array()),
            
            // Sorting and display
            'orderby' => $this->get_search_param('orderby', 'created_at'),
            'order' => $this->get_search_param('order', 'DESC'),
            'view' => $this->get_search_param('view', 'grid'), // grid, list, map
            'per_page' => $this->get_search_param('per_page', 20),
            'page' => $this->get_search_param('page', 1),
            
            // Location-based
            'lat' => $this->get_search_param('lat'),
            'lng' => $this->get_search_param('lng'),
            'radius' => $this->get_search_param('radius')
        );
        
        // Validate and sanitize parameters
        $this->validate_params();
    }
    
    /**
     * Get search parameter from request
     */
    private function get_search_param($key, $default = null) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return $_GET[$key];
        }
        return $default;
    }
    
    /**
     * Validate search parameters
     */
    private function validate_params() {
        // Validate numeric parameters
        $numeric_params = array('price_min', 'price_max', 'beds_min', 'beds_max', 'baths_min', 'baths_max', 'surface_min', 'surface_max', 'page', 'per_page');
        foreach ($numeric_params as $param) {
            if (isset($this->search_params[$param])) {
                $this->search_params[$param] = max(0, intval($this->search_params[$param]));
            }
        }
        
        // Limit per_page
        $this->search_params['per_page'] = min(100, max(1, $this->search_params['per_page']));
        
        // Validate order direction
        $this->search_params['order'] = in_array(strtoupper($this->search_params['order']), array('ASC', 'DESC')) 
            ? strtoupper($this->search_params['order']) : 'DESC';
        
        // FIXED: Validate orderby field against whitelist
        $this->search_params['orderby'] = $this->validate_orderby($this->search_params['orderby']);
    }
    
    /**
     * Get allowed orderby fields (SECURITY: Whitelist)
     * FIXED: Strict whitelist to prevent SQL injection
     */
    private function get_allowed_orderby_fields() {
        return array(
            'created_at'    => 'created_at',
            'updated_at'    => 'updated_at',
            'price'         => 'price',
            'beds'          => 'beds',
            'baths'         => 'baths',
            'town'          => 'town',
            'province'      => 'province',
            'property_type' => 'property_type',
            'view_count'    => 'view_count',
            'ref'           => 'ref',
            'distance'      => 'distance', // For location-based searches
            'title'         => 'title'
        );
    }
    
    /**
     * Validate orderby parameter against whitelist
     * FIXED: Prevents SQL injection in ORDER BY clause
     */
    private function validate_orderby($orderby) {
        $allowed_fields = $this->get_allowed_orderby_fields();
        
        if (isset($allowed_fields[$orderby])) {
            return $allowed_fields[$orderby];
        }
        
        // Default to created_at if invalid
        return 'created_at';
    }
    
    /**
     * Build ORDER BY SQL clause with security
     * FIXED: Uses validated whitelist fields only
     */
    private function build_orderby_sql($orderby, $order) {
        // Validate orderby field
        $orderby = $this->validate_orderby($orderby);
        
        // Validate order direction
        $order = in_array(strtoupper($order), array('ASC', 'DESC')) ? strtoupper($order) : 'DESC';
        
        // SECURITY: Both values are now guaranteed safe
        return "ORDER BY {$orderby} {$order}";
    }
    
    /**
     * Check if FULLTEXT index exists on properties table
     * FIXED: Checks before using MATCH AGAINST
     */
    private function has_fulltext_index() {
        // Cache the result
        if ($this->fulltext_enabled !== null) {
            return $this->fulltext_enabled;
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('properties');
        
        // Check for FULLTEXT index named 'search_text'
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Index_type = 'FULLTEXT'");
        
        $this->fulltext_enabled = !empty($indexes);
        
        return $this->fulltext_enabled;
    }
    
    /**
     * Search properties with keyword using FULLTEXT or LIKE
     * FIXED: Falls back to LIKE if FULLTEXT not available
     */
    private function add_keyword_search(&$where_clauses, &$where_values, $keyword) {
        global $wpdb;
        
        if (empty($keyword)) {
            return;
        }
        
        $keyword = sanitize_text_field($keyword);
        
        // Use FULLTEXT if available
        if ($this->has_fulltext_index()) {
            // FULLTEXT search (more efficient)
            $where_clauses[] = "MATCH(title, desc_en, desc_es, desc_de, desc_fr) AGAINST(%s IN BOOLEAN MODE)";
            $where_values[] = $keyword . '*'; // Wildcard for partial matching
        } else {
            // Fallback to LIKE search
            $keyword_like = '%' . $wpdb->esc_like($keyword) . '%';
            $where_clauses[] = "(
                title LIKE %s OR 
                desc_en LIKE %s OR 
                desc_es LIKE %s OR 
                desc_de LIKE %s OR 
                desc_fr LIKE %s OR 
                ref LIKE %s
            )";
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
        }
    }
    
    /**
     * Execute property search
     */
    public function search($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'keyword' => '',
            'property_type' => '',
            'town' => '',
            'province' => '',
            'location' => '',
            'price_min' => 0,
            'price_max' => 0,
            'beds_min' => 0,
            'beds_max' => 0,
            'baths_min' => 0,
            'baths_max' => 0,
            'surface_min' => 0,
            'surface_max' => 0,
            'pool' => null,
            'new_build' => null,
            'featured' => null,
            'price_freq' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'page' => 1,
            'per_page' => 20
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Build WHERE clause
        $where_clauses = array("status = 'active'");
        $where_values = array();
        
        // Keyword search
        if (!empty($args['keyword'])) {
            $this->add_keyword_search($where_clauses, $where_values, $args['keyword']);
        }
        
        // Property type
        if (!empty($args['property_type'])) {
            $where_clauses[] = "property_type = %s";
            $where_values[] = sanitize_text_field($args['property_type']);
        }
        
        // Location filters
        if (!empty($args['town'])) {
            $where_clauses[] = "town = %s";
            $where_values[] = sanitize_text_field($args['town']);
        } elseif (!empty($args['province'])) {
            $where_clauses[] = "province = %s";
            $where_values[] = sanitize_text_field($args['province']);
        } elseif (!empty($args['location'])) {
            // Search in both town and province
            $location_like = '%' . $wpdb->esc_like(sanitize_text_field($args['location'])) . '%';
            $where_clauses[] = "(town LIKE %s OR province LIKE %s)";
            $where_values[] = $location_like;
            $where_values[] = $location_like;
        }
        
        // Price range
        if ($args['price_min'] > 0) {
            $where_clauses[] = "price >= %f";
            $where_values[] = floatval($args['price_min']);
        }
        
        if ($args['price_max'] > 0) {
            $where_clauses[] = "price <= %f";
            $where_values[] = floatval($args['price_max']);
        }
        
        // Beds range
        if ($args['beds_min'] > 0) {
            $where_clauses[] = "beds >= %d";
            $where_values[] = intval($args['beds_min']);
        }
        
        if ($args['beds_max'] > 0) {
            $where_clauses[] = "beds <= %d";
            $where_values[] = intval($args['beds_max']);
        }
        
        // Baths range
        if ($args['baths_min'] > 0) {
            $where_clauses[] = "baths >= %d";
            $where_values[] = intval($args['baths_min']);
        }
        
        if ($args['baths_max'] > 0) {
            $where_clauses[] = "baths <= %d";
            $where_values[] = intval($args['baths_max']);
        }
        
        // Surface area range
        if ($args['surface_min'] > 0) {
            $where_clauses[] = "surface_area_built >= %d";
            $where_values[] = intval($args['surface_min']);
        }
        
        if ($args['surface_max'] > 0) {
            $where_clauses[] = "surface_area_built <= %d";
            $where_values[] = intval($args['surface_max']);
        }
        
        // Boolean filters
        if ($args['pool'] !== null) {
            $where_clauses[] = "pool = %d";
            $where_values[] = intval($args['pool']);
        }
        
        if ($args['new_build'] !== null) {
            $where_clauses[] = "new_build = %d";
            $where_values[] = intval($args['new_build']);
        }
        
        if ($args['featured'] !== null) {
            $where_clauses[] = "featured = %d";
            $where_values[] = intval($args['featured']);
        }
        
        // Price frequency
        if (!empty($args['price_freq'])) {
            $where_clauses[] = "price_freq = %s";
            $where_values[] = sanitize_text_field($args['price_freq']);
        }
        
        // Build final WHERE clause
        $where_sql = implode(' AND ', $where_clauses);
        
        // Build ORDER BY clause (SECURED)
        $orderby_sql = $this->build_orderby_sql($args['orderby'], $args['order']);
        
        // Pagination
        $page = max(1, intval($args['page']));
        $per_page = min(100, max(1, intval($args['per_page'])));
        $offset = ($page - 1) * $per_page;
        
        // Count total results
        $count_sql = "SELECT COUNT(*) FROM {$properties_table} WHERE {$where_sql}";
        if (!empty($where_values)) {
            $count_sql = $wpdb->prepare($count_sql, $where_values);
        }
        $total_results = $wpdb->get_var($count_sql);
        
        // Get results
        $results_sql = "SELECT * FROM {$properties_table} WHERE {$where_sql} {$orderby_sql} LIMIT %d OFFSET %d";
        $sql_values = array_merge($where_values, array($per_page, $offset));
        $properties = $wpdb->get_results($wpdb->prepare($results_sql, $sql_values));
        
        // Add images to each property
        $property_manager = PropertyManager_Property::get_instance();
        foreach ($properties as &$property) {
            $property->images = $property_manager->get_property_images($property->id, true);
            $property->features = $property_manager->get_property_features($property->id);
        }
        
        return array(
            'properties' => $properties,
            'total' => $total_results,
            'pages' => ceil($total_results / $per_page),
            'current_page' => $page,
            'per_page' => $per_page
        );
    }
    
    /**
     * Get current search parameters
     */
    public function get_search_params() {
        return $this->search_params;
    }
    
    /**
     * Get specific search parameter
     */
    public function get_param($key, $default = null) {
        return isset($this->search_params[$key]) ? $this->search_params[$key] : $default;
    }
    
    /**
     * Set search parameter
     */
    public function set_param($key, $value) {
        $this->search_params[$key] = $value;
    }
    
    /**
     * Get search URL with parameters
     */
    public function get_search_url($additional_params = array(), $remove_params = array()) {
        $params = array_merge($this->search_params, $additional_params);
        
        // Remove specified parameters
        foreach ($remove_params as $remove_param) {
            unset($params[$remove_param]);
        }
        
        // Remove empty values
        $params = array_filter($params, function($value) {
            return $value !== '' && $value !== null && $value !== array();
        });
        
        // Get search page URL
        $options = get_option('property_manager_pages', array());
        $search_page_id = isset($options['property_search']) ? $options['property_search'] : null;
        
        if ($search_page_id) {
            $base_url = get_permalink($search_page_id);
            return add_query_arg($params, $base_url);
        }
        
        return add_query_arg($params, home_url('/'));
    }
    
    /**
     * Get property types for dropdown
     */
    public function get_property_types() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_col("
            SELECT DISTINCT property_type 
            FROM {$table} 
            WHERE status = 'active' AND property_type IS NOT NULL AND property_type != ''
            ORDER BY property_type ASC
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
            $params[] = sanitize_text_field($province);
        }
        
        $sql = "SELECT DISTINCT town FROM {$table} WHERE {$where_clause} ORDER BY town ASC";
        
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
            FROM {$table} 
            WHERE status = 'active' AND province IS NOT NULL AND province != ''
            ORDER BY province ASC
        ");
    }
}