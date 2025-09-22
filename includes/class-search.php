<?php
/**
 * Property Search Class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Search {
    
    private static $instance = null;
    private $search_params = array();
    
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
            'radius' => $this->get_search_param('radius'), // km
        );
        
        // Clean up parameters
        $this->clean_search_params();
    }
    
    /**
     * Get search parameter with sanitization
     */
    private function get_search_param($key, $default = null) {
        $value = null;
        
        // Check POST first (form submission)
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
        }
        // Then check GET (URL parameters)
        elseif (isset($_GET[$key])) {
            $value = $_GET[$key];
        }
        
        if ($value === null) {
            return $default;
        }
        
        // Sanitize based on parameter type
        switch ($key) {
            case 'keyword':
            case 'location':
            case 'property_type':
            case 'province':
            case 'town':
            case 'orderby':
            case 'order':
            case 'view':
            case 'currency':
            case 'price_freq':
                return sanitize_text_field($value);
                
            case 'price_min':
            case 'price_max':
            case 'surface_min':
            case 'surface_max':
            case 'lat':
            case 'lng':
            case 'radius':
                return is_numeric($value) ? floatval($value) : $default;
                
            case 'beds_min':
            case 'beds_max':
            case 'baths_min':
            case 'baths_max':
            case 'per_page':
            case 'page':
                return is_numeric($value) ? intval($value) : $default;
                
            case 'pool':
            case 'new_build':
            case 'featured':
                return is_numeric($value) ? intval($value) : $default;
                
            case 'features':
                if (is_array($value)) {
                    return array_map('sanitize_text_field', $value);
                }
                return $default;
                
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Clean up search parameters
     */
    private function clean_search_params() {
        // Remove empty values
        $this->search_params = array_filter($this->search_params, function($value) {
            return $value !== '' && $value !== null && $value !== array();
        });
        
        // Validate numeric ranges
        if (isset($this->search_params['price_min']) && isset($this->search_params['price_max'])) {
            if ($this->search_params['price_min'] > $this->search_params['price_max']) {
                // Swap if min > max
                $temp = $this->search_params['price_min'];
                $this->search_params['price_min'] = $this->search_params['price_max'];
                $this->search_params['price_max'] = $temp;
            }
        }
        
        // Same for other ranges
        $ranges = array('beds', 'baths', 'surface');
        foreach ($ranges as $range) {
            $min_key = $range . '_min';
            $max_key = $range . '_max';
            
            if (isset($this->search_params[$min_key]) && isset($this->search_params[$max_key])) {
                if ($this->search_params[$min_key] > $this->search_params[$max_key]) {
                    $temp = $this->search_params[$min_key];
                    $this->search_params[$min_key] = $this->search_params[$max_key];
                    $this->search_params[$max_key] = $temp;
                }
            }
        }
        
        // Validate per_page limits
        if (isset($this->search_params['per_page'])) {
            $this->search_params['per_page'] = max(1, min(100, $this->search_params['per_page']));
        }
        
        // Validate page number
        if (isset($this->search_params['page'])) {
            $this->search_params['page'] = max(1, $this->search_params['page']);
        }
        
        // Validate order direction
        if (isset($this->search_params['order'])) {
            $this->search_params['order'] = in_array(strtoupper($this->search_params['order']), array('ASC', 'DESC')) 
                ? strtoupper($this->search_params['order']) : 'DESC';
        }
        
        // Validate orderby field
        $valid_orderby = array('created_at', 'updated_at', 'price', 'beds', 'baths', 'town', 'province', 'type', 'views', 'ref');
        if (isset($this->search_params['orderby'])) {
            if (!in_array($this->search_params['orderby'], $valid_orderby)) {
                $this->search_params['orderby'] = 'created_at';
            }
        }
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
     * Execute property search
     */
    public function search_properties() {
        $property_manager = PropertyManager_Property::get_instance();
        
        // Build search arguments for property manager
        $search_args = array(
            'page' => $this->get_param('page', 1),
            'per_page' => $this->get_param('per_page', 20),
            'orderby' => $this->get_param('orderby', 'created_at'),
            'order' => $this->get_param('order', 'DESC')
        );
        
        // Add search filters
        if ($keyword = $this->get_param('keyword')) {
            $search_args['keyword'] = $keyword;
        }
        
        if ($price_min = $this->get_param('price_min')) {
            $search_args['min_price'] = $price_min;
        }
        
        if ($price_max = $this->get_param('price_max')) {
            $search_args['max_price'] = $price_max;
        }
        
        if ($beds_min = $this->get_param('beds_min')) {
            $search_args['min_beds'] = $beds_min;
        }
        
        if ($beds_max = $this->get_param('beds_max')) {
            $search_args['max_beds'] = $beds_max;
        }
        
        if ($baths_min = $this->get_param('baths_min')) {
            $search_args['min_baths'] = $baths_min;
        }
        
        if ($baths_max = $this->get_param('baths_max')) {
            $search_args['max_baths'] = $baths_max;
        }
        
        if ($property_type = $this->get_param('property_type')) {
            $search_args['type'] = $property_type;
        }
        
        if ($town = $this->get_param('town')) {
            $search_args['town'] = $town;
        }
        
        if ($province = $this->get_param('province')) {
            $search_args['province'] = $province;
        }
        
        if ($pool = $this->get_param('pool')) {
            $search_args['pool'] = $pool;
        }
        
        if ($new_build = $this->get_param('new_build')) {
            $search_args['new_build'] = $new_build;
        }
        
        if ($featured = $this->get_param('featured')) {
            $search_args['featured'] = $featured;
        }
        
        if ($price_freq = $this->get_param('price_freq')) {
            $search_args['price_freq'] = $price_freq;
        }
        
        // Handle location-based search
        $location = $this->get_param('location');
        if ($location && !$this->get_param('town') && !$this->get_param('province')) {
            // Search in both town and province if specific location not set
            $search_args['location'] = $location;
        }
        
        // Execute search with potential location filtering
        if ($this->get_param('lat') && $this->get_param('lng') && $this->get_param('radius')) {
            return $this->search_properties_by_location($search_args);
        } else {
            return $property_manager->search_properties($search_args);
        }
    }
    
    /**
     * Search properties by location (radius-based)
     */
    private function search_properties_by_location($search_args) {
        global $wpdb;
        
        $lat = $this->get_param('lat');
        $lng = $this->get_param('lng');
        $radius = $this->get_param('radius');
        
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Use Haversine formula to find properties within radius
        $distance_sql = "
            (6371 * acos(
                cos(radians(%f)) * 
                cos(radians(latitude)) * 
                cos(radians(longitude) - radians(%f)) + 
                sin(radians(%f)) * 
                sin(radians(latitude))
            ))
        ";
        
        // Build WHERE clause for location
        $where_clauses = array("status = 'active'");
        $where_values = array();
        
        // Add location filter
        $where_clauses[] = sprintf($distance_sql, $lat, $lng, $lat) . " <= %f";
        $where_values[] = $radius;
        
        // Add other search filters
        $this->add_search_filters_to_query($where_clauses, $where_values, $search_args);
        
        // Build final query
        $where_sql = implode(' AND ', $where_clauses);
        $orderby_sql = $this->build_orderby_sql($search_args['orderby'], $search_args['order']);
        
        // Pagination
        $offset = ($search_args['page'] - 1) * $search_args['per_page'];
        
        // Count total results
        $count_sql = "SELECT COUNT(*) FROM $properties_table WHERE $where_sql";
        $total_results = $wpdb->get_var($wpdb->prepare($count_sql, $where_values));
        
        // Get results with distance
        $results_sql = "
            SELECT *, " . sprintf($distance_sql, $lat, $lng, $lat) . " as distance 
            FROM $properties_table 
            WHERE $where_sql 
            $orderby_sql 
            LIMIT %d OFFSET %d
        ";
        
        $sql_values = array_merge($where_values, array($search_args['per_page'], $offset));
        $properties = $wpdb->get_results($wpdb->prepare($results_sql, $sql_values));
        
        // Add images to each property
        foreach ($properties as &$property) {
            $property_manager = PropertyManager_Property::get_instance();
            $property->images = $property_manager->get_property_images($property->id);
            $property->features = $property_manager->get_property_features($property->id);
        }
        
        return array(
            'properties' => $properties,
            'total' => $total_results,
            'pages' => ceil($total_results / $search_args['per_page']),
            'current_page' => $search_args['page'],
            'per_page' => $search_args['per_page']
        );
    }
    
    /**
     * Add search filters to SQL query
     */
    private function add_search_filters_to_query(&$where_clauses, &$where_values, $search_args) {
        // Price range
        if (isset($search_args['min_price']) && $search_args['min_price'] > 0) {
            $where_clauses[] = "price >= %f";
            $where_values[] = $search_args['min_price'];
        }
        
        if (isset($search_args['max_price']) && $search_args['max_price'] > 0) {
            $where_clauses[] = "price <= %f";
            $where_values[] = $search_args['max_price'];
        }
        
        // Beds range
        if (isset($search_args['min_beds']) && $search_args['min_beds'] > 0) {
            $where_clauses[] = "beds >= %d";
            $where_values[] = $search_args['min_beds'];
        }
        
        if (isset($search_args['max_beds']) && $search_args['max_beds'] > 0) {
            $where_clauses[] = "beds <= %d";
            $where_values[] = $search_args['max_beds'];
        }
        
        // Add other filters as needed
        if (isset($search_args['type'])) {
            $where_clauses[] = "type = %s";
            $where_values[] = $search_args['type'];
        }
        
        if (isset($search_args['town'])) {
            $where_clauses[] = "town = %s";
            $where_values[] = $search_args['town'];
        }
        
        if (isset($search_args['province'])) {
            $where_clauses[] = "province = %s";
            $where_values[] = $search_args['province'];
        }
        
        // Add keyword search
        if (isset($search_args['keyword'])) {
            global $wpdb;
            $where_clauses[] = "(title LIKE %s OR description_en LIKE %s OR description_es LIKE %s OR ref LIKE %s)";
            $keyword_like = '%' . $wpdb->esc_like($search_args['keyword']) . '%';
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
            $where_values[] = $keyword_like;
        }
    }
    
    /**
     * Build ORDER BY SQL clause
     */
    private function build_orderby_sql($orderby, $order) {
        $valid_orderby = array(
            'created_at', 'updated_at', 'price', 'beds', 'baths', 
            'town', 'province', 'type', 'views', 'ref', 'distance'
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
        
        // Get current page URL
        $options = get_option('property_manager_pages', array());
        $search_page_id = isset($options['property_search']) ? $options['property_search'] : null;
        
        if ($search_page_id) {
            $base_url = get_permalink($search_page_id);
            return add_query_arg($params, $base_url);
        }
        
        return add_query_arg($params, home_url('/'));
    }
    
    /**
     * Get pagination links
     */
    public function get_pagination_links($total_pages, $current_page) {
        $links = array();
        
        if ($total_pages <= 1) {
            return $links;
        }
        
        // Previous link
        if ($current_page > 1) {
            $links['prev'] = array(
                'url' => $this->get_search_url(array('page' => $current_page - 1)),
                'text' => __('&laquo; Previous', 'property-manager-pro')
            );
        }
        
        // Page numbers
        $start = max(1, $current_page - 2);
        $end = min($total_pages, $current_page + 2);
        
        for ($i = $start; $i <= $end; $i++) {
            $links['pages'][$i] = array(
                'url' => $this->get_search_url(array('page' => $i)),
                'text' => $i,
                'current' => ($i == $current_page)
            );
        }
        
        // Next link
        if ($current_page < $total_pages) {
            $links['next'] = array(
                'url' => $this->get_search_url(array('page' => $current_page + 1)),
                'text' => __('Next &raquo;', 'property-manager-pro')
            );
        }
        
        return $links;
    }
    
    /**
     * Get sorting options
     */
    public function get_sort_options() {
        return array(
            'created_at_DESC' => __('Newest First', 'property-manager-pro'),
            'created_at_ASC' => __('Oldest First', 'property-manager-pro'),
            'price_ASC' => __('Price: Low to High', 'property-manager-pro'),
            'price_DESC' => __('Price: High to Low', 'property-manager-pro'),
            'beds_DESC' => __('Most Beds', 'property-manager-pro'),
            'beds_ASC' => __('Fewest Beds', 'property-manager-pro'),
            'views_DESC' => __('Most Popular', 'property-manager-pro'),
            'town_ASC' => __('Location A-Z', 'property-manager-pro'),
            'town_DESC' => __('Location Z-A', 'property-manager-pro')
        );
    }
    
    /**
     * Get current sort option
     */
    public function get_current_sort() {
        $orderby = $this->get_param('orderby', 'created_at');
        $order = $this->get_param('order', 'DESC');
        return $orderby . '_' . $order;
    }
    
    /**
     * Get search summary text
     */
    public function get_search_summary($total_results) {
        $summary_parts = array();
        
        if ($keyword = $this->get_param('keyword')) {
            $summary_parts[] = sprintf(__('"%s"', 'property-manager-pro'), esc_html($keyword));
        }
        
        if ($property_type = $this->get_param('property_type')) {
            $summary_parts[] = esc_html($property_type);
        }
        
        if ($town = $this->get_param('town')) {
            $summary_parts[] = sprintf(__('in %s', 'property-manager-pro'), esc_html($town));
        } elseif ($province = $this->get_param('province')) {
            $summary_parts[] = sprintf(__('in %s', 'property-manager-pro'), esc_html($province));
        } elseif ($location = $this->get_param('location')) {
            $summary_parts[] = sprintf(__('near %s', 'property-manager-pro'), esc_html($location));
        }
        
        if ($price_min = $this->get_param('price_min')) {
            if ($price_max = $this->get_param('price_max')) {
                $summary_parts[] = sprintf(__('€%s - €%s', 'property-manager-pro'), 
                    number_format($price_min), number_format($price_max));
            } else {
                $summary_parts[] = sprintf(__('from €%s', 'property-manager-pro'), number_format($price_min));
            }
        } elseif ($price_max = $this->get_param('price_max')) {
            $summary_parts[] = sprintf(__('up to €%s', 'property-manager-pro'), number_format($price_max));
        }
        
        $summary_text = '';
        if (!empty($summary_parts)) {
            $summary_text = sprintf(__('Properties %s', 'property-manager-pro'), implode(' ', $summary_parts));
        } else {
            $summary_text = __('All Properties', 'property-manager-pro');
        }
        
        return sprintf(__('%s - %d results found', 'property-manager-pro'), $summary_text, $total_results);
    }
    
    /**
     * Check if any search filters are active
     */
    public function has_active_filters() {
        $filter_params = array_diff_key($this->search_params, array_flip(array(
            'page', 'per_page', 'orderby', 'order', 'view'
        )));
        
        return !empty($filter_params);
    }
    
    /**
     * Get active filters for display
     */
    public function get_active_filters() {
        $filters = array();
        
        if ($keyword = $this->get_param('keyword')) {
            $filters['keyword'] = array(
                'label' => __('Keyword', 'property-manager-pro'),
                'value' => $keyword,
                'remove_url' => $this->get_search_url(array(), array('keyword'))
            );
        }
        
        if ($property_type = $this->get_param('property_type')) {
            $filters['property_type'] = array(
                'label' => __('Type', 'property-manager-pro'),
                'value' => $property_type,
                'remove_url' => $this->get_search_url(array(), array('property_type'))
            );
        }
        
        // Add more filters as needed...
        
        return $filters;
    }
    
    /**
     * Clear all search filters
     */
    public function get_clear_filters_url() {
        $options = get_option('property_manager_pages', array());
        $search_page_id = isset($options['property_search']) ? $options['property_search'] : null;
        
        if ($search_page_id) {
            return get_permalink($search_page_id);
        }
        
        return home_url('/');
    }
}