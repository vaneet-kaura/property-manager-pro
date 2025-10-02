<?php
/**
 * Kyero Feed Importer Class - Production Ready with All Security & Performance Enhancements
 * Handles XML feed import, parsing, and property synchronization
 * 
 * IMPORTANT: Image Processing Strategy
 * =====================================
 * All property images are queued for download and processing by the PropertyManager_ImageDownloader class.
 * Images are downloaded and added to WordPress Media Library, which enables automatic S3 offloading
 * when using plugins like WP Offload Media, Media Cloud, or WP-Stateless.
 * 
 * The workflow is:
 * 1. Feed import creates property records
 * 2. Image URLs are stored in pm_property_images with status='pending'
 * 3. Cron job processes pending images (downloads → WP Media Library → S3 offload)
 * 4. attachment_id links our records to WordPress attachments
 * 5. S3 plugin automatically handles upload and CDN URL updates
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_FeedImporter {
    
    private static $instance = null;
    private $feed_url;
    private $import_log_id;
    
    // Import constants
    private const MAX_IMPORT_TIME = 300; // 5 minutes
    private const MAX_PROPERTIES_PER_BATCH = 1000;
    private const RETRY_FAILED_DOWNLOADS = 3;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $options = get_option('property_manager_options', array());
        $this->feed_url = isset($options['feed_url']) ? $options['feed_url'] : '';
        
        // Hook into cron job
        add_action('property_manager_import_feed', array($this, 'import_feed'));
        
        // Cleanup old properties after import
        add_action('property_manager_import_feed', array($this, 'cleanup_old_properties_after_import'), 20);
    }
    
    /**
     * Import properties from Kyero feed
     */
    public function import_feed($manual = false) {
        // Validate feed URL
        if (empty($this->feed_url)) {
            error_log('Property Manager Pro: No feed URL configured');
            return false;
        }
        
        if (!filter_var($this->feed_url, FILTER_VALIDATE_URL)) {
            error_log('Property Manager Pro: Invalid feed URL format');
            return false;
        }
        
        // Prevent concurrent imports
        if (get_transient('property_manager_import_in_progress')) {
            error_log('Property Manager Pro: Import already in progress, skipping...');
            return false;
        }
        
        // Set import lock
        set_transient('property_manager_import_in_progress', true, self::MAX_IMPORT_TIME);
        
        // Set time limit
        set_time_limit(self::MAX_IMPORT_TIME);
        
        // Start import log
        $this->start_import_log();
        
        try {
            // Download and parse XML
            $xml_data = $this->download_feed();
            if (!$xml_data) {
                throw new Exception('Failed to download feed');
            }
            
            // Validate XML before parsing
            if (!$this->validate_xml_structure($xml_data)) {
                throw new Exception('Invalid XML structure');
            }
            
            $xml = $this->parse_xml($xml_data);
            if (!$xml) {
                throw new Exception('Failed to parse XML');
            }
            
            // Validate Kyero feed version
            $this->validate_feed_version($xml);
            
            // Import properties
            $result = $this->process_properties($xml);
            
            // Complete import log
            $this->complete_import_log('completed', $result);
            
            // Log success
            if (!$manual) {
                error_log(sprintf(
                    'Property Manager Pro: Feed import completed successfully. Imported: %d, Updated: %d, Failed: %d',
                    $result['imported'],
                    $result['updated'],
                    $result['failed']
                ));
            }
            
            // Release lock
            delete_transient('property_manager_import_in_progress');
            
            return $result;
            
        } catch (Exception $e) {
            // Complete import log with error
            $this->complete_import_log('failed', null, $e->getMessage());
            
            error_log('Property Manager Pro: Feed import failed - ' . $e->getMessage());
            
            // Release lock
            delete_transient('property_manager_import_in_progress');
            
            return false;
        }
    }

    /**
     * Get import statistics
     */
    public function get_import_stats($limit = 10) {
        global $wpdb;
    
        $table = PropertyManager_Database::get_table_name('import_logs');
        $limit = absint($limit);
    
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             ORDER BY started_at DESC 
             LIMIT %d",
            $limit
        ), OBJECT);
    
        if ($wpdb->last_error) {
            error_log('Property Manager Pro: Error getting import stats - ' . $wpdb->last_error);
            return array();
        }
    
        return $logs;
    }
    
    /**
     * Download feed from URL with security checks
     */
    private function download_feed() {
        $response = wp_remote_get($this->feed_url, array(
            'timeout' => 60,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent' => 'Property Manager Pro/' . PROPERTY_MANAGER_VERSION,
            'sslverify' => true,
            'headers' => array(
                'Accept' => 'application/xml, text/xml, */*'
            )
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP Error: ' . $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new Exception('HTTP Error: Received status code ' . $http_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            throw new Exception('Empty feed response');
        }
        
        // Check file size (max 50MB for XML feed)
        $content_length = strlen($body);
        if ($content_length > 52428800) {
            throw new Exception('Feed file too large: ' . size_format($content_length));
        }
        
        return $body;
    }
    
    /**
     * Validate XML structure before parsing
     */
    private function validate_xml_structure($xml_data) {
        // Check for XML declaration
        if (strpos(trim($xml_data), '<?xml') !== 0) {
            error_log('Property Manager Pro: Missing XML declaration');
            return false;
        }
        
        // Check for basic XML structure
        if (strpos($xml_data, '<root>') === false && strpos($xml_data, '<properties>') === false) {
            error_log('Property Manager Pro: Invalid XML root element');
            return false;
        }
        
        return true;
    }
    
    /**
     * Parse XML data with error handling
     * FIXED: Removed deprecated libxml_disable_entity_loader() for PHP 8.0+
     */
    private function parse_xml($xml_data) {
        // Disable libxml errors
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        
        // Additional security: Disable entity loading (FIXED for PHP 8.0+)
        // The libxml_disable_entity_loader() function is deprecated in PHP 8.0+
        // Entity loading is now disabled by default in PHP 8.0+
        if (PHP_VERSION_ID < 80000) {
            $old_value = libxml_disable_entity_loader(true);
        }
        
        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        
        // Restore entity loader setting (only for PHP < 8.0)
        if (PHP_VERSION_ID < 80000 && isset($old_value)) {
            libxml_disable_entity_loader($old_value);
        }
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = array();
            
            foreach ($errors as $error) {
                $error_messages[] = sprintf(
                    'Line %d: %s',
                    $error->line,
                    trim($error->message)
                );
            }
            
            libxml_clear_errors();
            
            throw new Exception('XML Parse Error: ' . implode('; ', $error_messages));
        }
        
        return $xml;
    }
    
    /**
     * Validate Kyero feed version
     */
    private function validate_feed_version($xml) {
        if (!isset($xml->kyero->feed_version)) {
            error_log('Property Manager Pro: Warning - Feed version not specified');
            return;
        }
        
        $feed_version = (string) $xml->kyero->feed_version;
        
        if ($feed_version !== '3') {
            error_log('Property Manager Pro: Warning - Unexpected feed version: ' . $feed_version . ' (expected 3)');
        }
    }
    
    /**
     * Process properties from XML with transaction support
     * FIXED: Improved transaction handling with try-finally
     */
    private function process_properties($xml) {
        $imported = 0;
        $updated = 0;
        $failed = 0;
        $processed_ids = array();
        
        if (!isset($xml->property) || count($xml->property) === 0) {
            error_log('Property Manager Pro: No properties found in feed');
            return array('imported' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0);
        }
        
        $total_properties = count($xml->property);
        
        if ($total_properties > self::MAX_PROPERTIES_PER_BATCH) {
            error_log('Property Manager Pro: Warning - Feed contains ' . $total_properties . ' properties (max recommended: ' . self::MAX_PROPERTIES_PER_BATCH . ')');
        }
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        foreach ($xml->property as $property_xml) {
            $transaction_started = false;
            
            try {
                // Parse property data
                $property_data = $this->parse_property($property_xml);
                
                if (!$property_data || empty($property_data['property_id'])) {
                    $failed++;
                    error_log('Property Manager Pro: Skipping property - invalid data');
                    continue;
                }
                
                // Check for duplicate in current batch
                if (in_array($property_data['property_id'], $processed_ids)) {
                    error_log('Property Manager Pro: Duplicate property in feed: ' . $property_data['property_id']);
                    continue;
                }
                
                $processed_ids[] = $property_data['property_id'];
                
                // Start transaction
                $wpdb->query('START TRANSACTION');
                $transaction_started = true;
                
                try {
                    // Check if property exists
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM $properties_table WHERE property_id = %s",
                        $property_data['property_id']
                    ));
                    
                    if ($existing) {
                        // Update existing property
                        $wpdb->update(
                            $properties_table,
                            $property_data,
                            array('property_id' => $property_data['property_id']),
                            $this->get_property_format(),
                            array('%s')
                        );
                        $property_id = $existing->id;
                        $updated++;
                    } else {
                        // Insert new property
                        $wpdb->insert(
                            $properties_table,
                            $property_data,
                            $this->get_property_format()
                        );
                        $property_id = $wpdb->insert_id;
                        $imported++;
                    }
                    
                    // Import images
                    if (isset($property_xml->images) && $property_xml->images->image) {
                        $images = $this->parse_images($property_xml->images);
                        
                        if (!empty($images)) {
                            $result = PropertyManager_Database::insert_update_property_images($property_id, $images);
                            
                            // Limit to first 5 images for performance
                            if (count($images) > 5) {
                                $images = array_slice($images, 0, 5);
                            }
                        }
                    }
                    
                    // Import features
                    if (isset($property_xml->features) && $property_xml->features->feature) {
                        $features = $this->parse_features($property_xml->features);
                        
                        if (!empty($features)) {
                            PropertyManager_Database::insert_property_features($property_id, $features);
                        }
                    }
                    
                    // Commit transaction
                    $wpdb->query('COMMIT');
                    $transaction_started = false;
                    
                } catch (Exception $e) {
                    // Rollback on error
                    if ($transaction_started) {
                        $wpdb->query('ROLLBACK');
                    }
                    throw $e;
                }
                
            } catch (Exception $e) {
                $failed++;
                error_log('Property Manager Pro: Failed to import property - ' . $e->getMessage());
                
                // Ensure rollback if transaction was started
                if ($transaction_started) {
                    $wpdb->query('ROLLBACK');
                }
            }
        }
        
        return array(
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed,
            'total' => $total_properties
        );
    }
    
    /**
     * Parse single property from XML with comprehensive validation
     * FIXED: Added coordinate parser for both decimal and DMS formats
     */
    private function parse_property($property_xml) {
        $data = array();
        
        try {
            // Required fields with validation
            $data['property_id'] = "kyero_feed_".$this->get_xml_value($property_xml->id);
            if (empty($data['property_id'])) {
                throw new Exception('Missing property ID');
            }
            
            // Basic property information
            $data['ref'] = $this->get_xml_value($property_xml->ref);
            $data['price'] = $this->parse_float($property_xml->price);
            $data['currency'] = $this->get_xml_value($property_xml->currency, 'EUR');
            $data['price_freq'] = $this->get_xml_value($property_xml->price_freq, 'sale');
            $data['new_build'] = $this->parse_boolean($property_xml->new_build);
            $data['property_type'] = $this->get_xml_value($property_xml->type);
            $data['town'] = $this->get_xml_value($property_xml->town);
            $data['province'] = $this->get_xml_value($property_xml->province);
            $data['location_detail'] = $this->get_xml_value($property_xml->location_detail);
            $data['beds'] = $this->parse_int($property_xml->beds);
            $data['baths'] = $this->parse_int($property_xml->baths);
            $data['pool'] = $this->parse_boolean($property_xml->pool);
            
            // Location coordinates - FIXED: Handle both decimal and DMS formats
            if (isset($property_xml->location)) {
                $coordinates = $this->parse_coordinates($property_xml->location);
                $data['latitude'] = $coordinates['latitude'];
                $data['longitude'] = $coordinates['longitude'];
            }
            
            // Surface area
            if (isset($property_xml->surface_area)) {
                $data['surface_area_built'] = $this->parse_int($property_xml->surface_area->built);
                $data['surface_area_plot'] = $this->parse_int($property_xml->surface_area->plot);
            }
            
            // Energy rating
            if (isset($property_xml->energy_rating)) {
                $data['energy_rating_consumption'] = $this->get_xml_value($property_xml->energy_rating->consumption);
                $data['energy_rating_emissions'] = $this->get_xml_value($property_xml->energy_rating->emissions);
            }
            
            // Multi-language URLs
            if (isset($property_xml->url)) {
                $data['url_en'] = $this->get_xml_value($property_xml->url->en);
                $data['url_es'] = $this->get_xml_value($property_xml->url->es);
                $data['url_de'] = $this->get_xml_value($property_xml->url->de);
                $data['url_fr'] = $this->get_xml_value($property_xml->url->fr);
            }
            
            // Multi-language descriptions
            if (isset($property_xml->desc)) {
                $data['desc_en'] = $this->get_xml_value($property_xml->desc->en);
                $data['desc_es'] = $this->get_xml_value($property_xml->desc->es);
                $data['desc_de'] = $this->get_xml_value($property_xml->desc->de);
                $data['desc_fr'] = $this->get_xml_value($property_xml->desc->fr);
            }
            
            // Generate title if not provided
            $data['title'] = $this->generate_property_title($data);
            
            // Set status as active
            $data['status'] = 'active';
            
            return $data;
            
        } catch (Exception $e) {
            error_log('Property Manager Pro: Error parsing property - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Parse coordinates - handles both decimal and DMS formats
     * NEW METHOD: Supports both "37.8712, -0.7958" and "37°52'16.3"N 0°47'44.8"W"
     */
    private function parse_coordinates($location_xml) {
        $coordinates = array(
            'latitude' => null,
            'longitude' => null
        );
        
        // Get latitude and longitude values
        $latitude_str = $this->get_xml_value($location_xml->latitude);
        $longitude_str = $this->get_xml_value($location_xml->longitude);
        
        if (empty($latitude_str)) {
            return $coordinates;
        }
        
        // Check if it's DMS format (contains degree symbol)
        if (strpos($latitude_str, '°') !== false || strpos($latitude_str, '\'') !== false) {
            // DMS format: 37°52'16.3"N 0°47'44.8"W
            $coordinates['latitude'] = $this->convert_dms_to_decimal($latitude_str);
            
            if (!empty($longitude_str)) {
                $coordinates['longitude'] = $this->convert_dms_to_decimal($longitude_str);
            }
        } else {
            // Decimal format: 37.8712, -0.7958
            $coordinates['latitude'] = $this->parse_float_coordinate($latitude_str);
            $coordinates['longitude'] = $this->parse_float_coordinate($longitude_str);
        }
        
        // Validate coordinates are within valid ranges
        if ($coordinates['latitude'] !== null) {
            $coordinates['latitude'] = max(-90, min(90, $coordinates['latitude']));
        }
        
        if ($coordinates['longitude'] !== null) {
            $coordinates['longitude'] = max(-180, min(180, $coordinates['longitude']));
        }
        
        return $coordinates;
    }
    
    /**
     * Convert DMS (Degrees Minutes Seconds) to Decimal
     * Example: 37°52'16.3"N -> 37.871194
     */
    private function convert_dms_to_decimal($dms_string) {
        if (empty($dms_string)) {
            return null;
        }
        
        // Remove extra whitespace
        $dms_string = trim($dms_string);
        
        // Pattern: 37°52'16.3"N or 37°52'16.3"
        $pattern = '/(\d+)°(\d+)\'([\d.]+)"?\s*([NSEW])?/i';
        
        if (preg_match($pattern, $dms_string, $matches)) {
            $degrees = floatval($matches[1]);
            $minutes = floatval($matches[2]);
            $seconds = floatval($matches[3]);
            $direction = isset($matches[4]) ? strtoupper($matches[4]) : '';
            
            // Convert to decimal
            $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
            
            // Apply direction (South and West are negative)
            if ($direction === 'S' || $direction === 'W') {
                $decimal = -$decimal;
            }
            
            return round($decimal, 8);
        }
        
        // If pattern doesn't match, try to parse as decimal
        return $this->parse_float_coordinate($dms_string);
    }
    
    /**
     * Parse float coordinate value
     */
    private function parse_float_coordinate($value) {
        if (empty($value)) {
            return null;
        }
        
        $value = trim($value);
        
        // Remove any non-numeric characters except dot, minus, and comma
        $value = preg_replace('/[^\d.,-]/', '', $value);
        
        // Replace comma with dot (European format)
        $value = str_replace(',', '.', $value);
        
        $float_value = floatval($value);
        
        // Validate it's a reasonable coordinate value
        if ($float_value === 0.0 && $value !== '0' && $value !== '0.0') {
            return null;
        }
        
        return round($float_value, 8);
    }
    
    /**
     * Safely get XML value
     */
    private function get_xml_value($element, $default = null) {
        if (!isset($element) || $element === null) {
            return $default;
        }
        
        $value = trim((string) $element);
        return !empty($value) ? $value : $default;
    }
    
    /**
     * Parse integer from XML
     */
    private function parse_int($element) {
        $value = $this->get_xml_value($element);
        return $value !== null ? max(0, intval($value)) : null;
    }
    
    /**
     * Parse float from XML
     */
    private function parse_float($element) {
        $value = $this->get_xml_value($element);
        return $value !== null ? max(0, floatval($value)) : null;
    }
    
    /**
     * Parse boolean from XML
     */
    private function parse_boolean($element) {
        $value = $this->get_xml_value($element);
        return $value === '1' || $value === 'true' || $value === 'yes' ? 1 : 0;
    }
    
    /**
     * Parse images from XML
     */
    private function parse_images($images_xml) {
        $images = array();
        
        if (!isset($images_xml->image)) {
            return $images;
        }
        
        foreach ($images_xml->image as $image) {
            $image_id = isset($image['id']) ? (string) $image['id'] : 0;
            $image_url = $this->get_xml_value($image->url);
            
            // Validate image URL
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                error_log('Property Manager Pro: Invalid image URL: ' . $image_url);
                continue;
            }
            
            // Validate URL scheme (only http/https)
            $parsed_url = parse_url($image_url);
            if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
                error_log('Property Manager Pro: Invalid image URL scheme: ' . $image_url);
                continue;
            }
            
            $images[] = array(
                'id' => $image_id ? intval($image_id) : 0,
                'url' => $image_url,
                'download_status' => 'pending',
                'sort_order' => $image_id ? intval($image_id) : 0,
                'title' => '',
                'alt' => ''
            );
        }
        
        return $images;
    }
    
    /**
     * Parse features from XML
     */
    private function parse_features($features_xml) {
        $features = array();
        
        if (!isset($features_xml->feature)) {
            return $features;
        }
        
        foreach ($features_xml->feature as $feature) {
            $feature_value = $this->get_xml_value($feature);
            
            if (!empty($feature_value)) {
                $features[] = $feature_value;
            }
        }
        
        // Remove duplicates
        $features = array_unique($features);
        
        return $features;
    }
    
    /**
     * Generate property title from data
     */
    private function generate_property_title($data) {
        $title_parts = array();
        
        // Property type
        if (!empty($data['property_type'])) {
            $title_parts[] = $data['property_type'];
        }
        
        // Location
        if (!empty($data['town'])) {
            $title_parts[] = 'in ' . $data['town'];
        } elseif (!empty($data['province'])) {
            $title_parts[] = 'in ' . $data['province'];
        }
        
        // Beds
        if (!empty($data['beds'])) {
            $title_parts[] = $data['beds'] . ' bed';
        }
        
        // Reference
        if (!empty($data['ref'])) {
            $title_parts[] = 'Ref: ' . $data['ref'];
        }
        
        $title = !empty($title_parts) ? implode(' ', $title_parts) : 'Property ' . $data['property_id'];
        
        return substr($title, 0, 255); // Limit to database field length
    }
    
    /**
     * Get property data format for wpdb
     */
    private function get_property_format() {
        return array(
            '%s', // property_id
            '%s', // ref
            '%f', // price
            '%s', // currency
            '%s', // price_freq
            '%d', // new_build
            '%s', // property_type
            '%s', // town
            '%s', // province
            '%s', // location_detail
            '%d', // beds
            '%d', // baths
            '%d', // pool
            '%f', // latitude
            '%f', // longitude
            '%d', // surface_area_built
            '%d', // surface_area_plot
            '%s', // energy_rating_consumption
            '%s', // energy_rating_emissions
            '%s', // url_en
            '%s', // url_es
            '%s', // url_de
            '%s', // url_fr
            '%s', // desc_en
            '%s', // desc_es
            '%s', // desc_de
            '%s', // desc_fr
            '%s', // title
            '%s'  // status
        );
    }
    
    /**
     * Start import log
     */
    private function start_import_log() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        $wpdb->insert($table, array(
            'import_type' => 'kyero_feed',
            'status' => 'started',
            'started_at' => current_time('mysql')
        ));
        
        $this->import_log_id = $wpdb->insert_id;
    }
    
    /**
     * Complete import log
     */
    private function complete_import_log($status, $result = null, $error_message = null) {
        if (!$this->import_log_id) {
            return;
        }
        
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        $data = array(
            'status' => $status,
            'completed_at' => current_time('mysql')
        );
        
        if ($result) {
            $data['properties_imported'] = $result['imported'];
            $data['properties_updated'] = $result['updated'];
            $data['properties_failed'] = $result['failed'];
        }
        
        if ($error_message) {
            $data['error_message'] = substr($error_message, 0, 1000); // Limit length
        }
        
        $wpdb->update($table, $data, array('id' => $this->import_log_id));
    }
    
    /**
     * Cleanup old properties after import
     */
    public function cleanup_old_properties_after_import() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        // Mark properties not updated in last 30 days as inactive
        $wpdb->query("
            UPDATE $table 
            SET status = 'inactive' 
            WHERE status = 'active' 
            AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $affected_rows = $wpdb->rows_affected;
        
        if ($affected_rows > 0) {
            error_log('Property Manager Pro: Marked ' . $affected_rows . ' old properties as inactive');
        }
    }
}