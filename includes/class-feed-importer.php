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
            error_log('Property Manager: No feed URL configured');
            return false;
        }
        
        if (!filter_var($this->feed_url, FILTER_VALIDATE_URL)) {
            error_log('Property Manager: Invalid feed URL format');
            return false;
        }
        
        // Prevent concurrent imports
        if (get_transient('property_manager_import_in_progress')) {
            error_log('Property Manager: Import already in progress, skipping...');
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
                    'Property Manager: Feed import completed successfully. Imported: %d, Updated: %d, Failed: %d',
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
            
            error_log('Property Manager: Feed import failed - ' . $e->getMessage());
            
            // Release lock
            delete_transient('property_manager_import_in_progress');
            
            return false;
        }
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
            error_log('Property Manager: Missing XML declaration');
            return false;
        }
        
        // Check for basic XML structure
        if (strpos($xml_data, '<root>') === false && strpos($xml_data, '<properties>') === false) {
            error_log('Property Manager: Invalid XML root element');
            return false;
        }
        
        return true;
    }
    
    /**
     * Parse XML data with error handling
     */
    private function parse_xml($xml_data) {
        // Disable libxml errors
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        
        // Additional security: Disable entity loading
        $old_value = libxml_disable_entity_loader(true);
        
        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        
        // Restore entity loader setting
        libxml_disable_entity_loader($old_value);
        
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
            error_log('Property Manager: Warning - Feed version not specified');
            return;
        }
        
        $feed_version = (string) $xml->kyero->feed_version;
        
        if ($feed_version !== '3') {
            error_log('Property Manager: Warning - Unexpected feed version: ' . $feed_version . ' (expected 3)');
        }
    }
    
    /**
     * Process properties from XML with transaction support
     */
    private function process_properties($xml) {
        $imported = 0;
        $updated = 0;
        $failed = 0;
        $processed_ids = array();
        
        if (!isset($xml->property) || count($xml->property) === 0) {
            error_log('Property Manager: No properties found in feed');
            return array('imported' => 0, 'updated' => 0, 'failed' => 0);
        }
        
        $total_properties = count($xml->property);
        
        if ($total_properties > self::MAX_PROPERTIES_PER_BATCH) {
            error_log('Property Manager: Warning - Feed contains ' . $total_properties . ' properties (max recommended: ' . self::MAX_PROPERTIES_PER_BATCH . ')');
        }
        
        global $wpdb;
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        foreach ($xml->property as $property_xml) {
            try {
                // Parse property data
                $property_data = $this->parse_property($property_xml);
                
                if (!$property_data || empty($property_data['property_id'])) {
                    $failed++;
                    error_log('Property Manager: Skipping property - invalid data');
                    continue;
                }
                
                // Check for duplicate in current batch
                if (in_array($property_data['property_id'], $processed_ids)) {
                    error_log('Property Manager: Duplicate property in feed: ' . $property_data['property_id']);
                    continue;
                }
                
                $processed_ids[] = $property_data['property_id'];
                
                // Start transaction for each property
                $wpdb->query('START TRANSACTION');
                
                try {
                    // Upsert property
                    $property_id = PropertyManager_Database::upsert_property($property_data);
                    
                    if (!$property_id) {
                        throw new Exception('Failed to save property');
                    }
                    
                    // Check if new or updated
                    $is_new = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$properties_table} 
                         WHERE id = %d 
                         AND DATE(created_at) = DATE(updated_at)",
                        $property_id
                    ));
                    
                    if ($is_new) {
                        $imported++;
                    } else {
                        $updated++;
                    }
                    
                    // Import images (queued for download)
                    if (isset($property_xml->images) && $property_xml->images->image) {
                        $images = $this->parse_images($property_xml->images);
                        
                        if (!empty($images)) {
                            PropertyManager_Database::insert_property_images($property_id, $images);
                            
                            // Optionally process images immediately
                            $options = get_option('property_manager_options', array());
                            if (!empty($options['immediate_image_download'])) {
                                $image_downloader = PropertyManager_ImageDownloader::get_instance();
                                $image_downloader->process_property_images($property_id, 5); // Process first 5 images
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
                    
                } catch (Exception $e) {
                    // Rollback on error
                    $wpdb->query('ROLLBACK');
                    throw $e;
                }
                
            } catch (Exception $e) {
                $failed++;
                error_log('Property Manager: Failed to import property - ' . $e->getMessage());
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
     */
    private function parse_property($property_xml) {
        $data = array();
        
        try {
            // Required fields with validation
            $data['property_id'] = $this->get_xml_value($property_xml->id);
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
            
            // Location coordinates with DMS support
            if (isset($property_xml->location)) {
                $latitude = $this->get_xml_value($property_xml->location->latitude);
                $longitude = $this->get_xml_value($property_xml->location->longitude);
                
                // Handle case where both coordinates are in latitude field (space-separated)
                if (!empty($latitude) && empty($longitude) && strpos($latitude, ' ') !== false) {
                    $coords = preg_split('/\s+/', trim($latitude), 2);
                    if (count($coords) === 2) {
                        $data['latitude'] = $this->parse_coordinate($coords[0]);
                        $data['longitude'] = $this->parse_coordinate($coords[1]);
                    }
                } else {
                    $data['latitude'] = $this->parse_coordinate($latitude);
                    $data['longitude'] = $this->parse_coordinate($longitude);
                }
            }
            
            // Surface area
            if (isset($property_xml->surface_area->built)) {
                $data['surface_area_built'] = $this->parse_int($property_xml->surface_area->built);
            }
            if (isset($property_xml->surface_area->plot)) {
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
            error_log('Property Manager: Error parsing property - ' . $e->getMessage());
            return null;
        }
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
     * Parse coordinate from DMS format to decimal
     * Handles formats like: 37°52'16.3"N or 37.8697
     */
    private function parse_coordinate($coordinate) {
        if (empty($coordinate)) {
            return null;
        }
        
        $coordinate = trim($coordinate);
        
        // If already in decimal format
        if (is_numeric($coordinate)) {
            return floatval($coordinate);
        }
        
        // Parse DMS format (e.g., 37°52'16.3"N or 0°47'44.8"W)
        if (preg_match('/^(\d+)°(\d+)\'([\d.]+)"([NSEW])$/', $coordinate, $matches)) {
            $degrees = floatval($matches[1]);
            $minutes = floatval($matches[2]);
            $seconds = floatval($matches[3]);
            $direction = $matches[4];
            
            // Convert to decimal
            $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
            
            // Apply direction (South and West are negative)
            if (in_array($direction, array('S', 'W'))) {
                $decimal = -$decimal;
            }
            
            return $decimal;
        }
        
        // If we can't parse it, log and return null
        error_log('Property Manager: Unable to parse coordinate: ' . $coordinate);
        return null;
    }
    
    /**
     * Parse images from XML
     */
    private function parse_images($images_xml) {
        $images = array();
        
        if (!isset($images_xml->image)) {
            return $images;
        }
        
        foreach ($images_xml->image as $image_xml) {
            $image_id = $this->get_xml_value($image_xml['id']);
            $image_url = $this->get_xml_value($image_xml->url);
            
            // Validate image URL
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                error_log('Property Manager: Invalid image URL: ' . $image_url);
                continue;
            }
            
            // Validate URL scheme (only http/https)
            $parsed_url = parse_url($image_url);
            if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
                error_log('Property Manager: Invalid image URL scheme: ' . $image_url);
                continue;
            }
            
            $images[] = array(
                'id' => $image_id ? intval($image_id) : 0,
                'url' => $image_url,
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
        
        // Limit title length
        return substr($title, 0, 200);
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
            'started_at' => current_time('mysql', true)
        ), array('%s', '%s', '%s'));
        
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
        
        $update_data = array(
            'status' => $status,
            'completed_at' => current_time('mysql', true)
        );
        
        $format = array('%s', '%s');
        
        if ($result && is_array($result)) {
            $update_data['properties_imported'] = isset($result['imported']) ? $result['imported'] : 0;
            $update_data['properties_updated'] = isset($result['updated']) ? $result['updated'] : 0;
            $update_data['properties_failed'] = isset($result['failed']) ? $result['failed'] : 0;
            $format = array_merge($format, array('%d', '%d', '%d'));
        }
        
        if ($error_message) {
            $update_data['error_message'] = substr($error_message, 0, 1000); // Limit error message length
            $format[] = '%s';
        }
        
        $wpdb->update(
            $table,
            $update_data,
            array('id' => $this->import_log_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Get import statistics
     */
    public function get_import_stats($limit = 10) {
        global $wpdb;
        
        $limit = max(1, min(100, intval($limit)));
        
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE import_type = 'kyero_feed' 
             ORDER BY started_at DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Clean up old properties (mark as inactive if not in recent feed)
     */
    public function cleanup_old_properties($days = 7) {
        global $wpdb;
        
        $days = max(1, min(90, intval($days)));
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        // Mark properties as inactive if not updated for X days
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'inactive' 
             WHERE status = 'active' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        if ($affected > 0) {
            error_log('Property Manager: Marked ' . $affected . ' properties as inactive (not updated in ' . $days . ' days)');
        }
        
        return $affected;
    }
    
    /**
     * Cleanup old properties after import (automatic)
     */
    public function cleanup_old_properties_after_import() {
        $options = get_option('property_manager_options', array());
        $cleanup_days = isset($options['cleanup_inactive_days']) ? intval($options['cleanup_inactive_days']) : 7;
        
        if ($cleanup_days > 0) {
            $this->cleanup_old_properties($cleanup_days);
        }
    }
    
    /**
     * Manual import trigger
     */
    public function manual_import() {
        return $this->import_feed(true);
    }
    
    /**
     * Test feed connection
     */
    public function test_feed_connection() {
        if (empty($this->feed_url)) {
            return array(
                'success' => false,
                'message' => __('No feed URL configured', 'property-manager-pro')
            );
        }
        
        try {
            $response = wp_remote_head($this->feed_url, array(
                'timeout' => 10,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => $response->get_error_message()
                );
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code === 200) {
                return array(
                    'success' => true,
                    'message' => __('Feed connection successful', 'property-manager-pro')
                );
            } else {
                return array(
                    'success' => false,
                    'message' => sprintf(__('Feed returned status code: %d', 'property-manager-pro'), $status_code)
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
}