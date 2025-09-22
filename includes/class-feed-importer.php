<?php
/**
 * Kyero Feed Importer Class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_FeedImporter {
    
    private static $instance = null;
    private $feed_url;
    private $import_log_id;
    
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
    }
    
    /**
     * Import properties from Kyero feed
     */
    public function import_feed($manual = false) {
        if (empty($this->feed_url)) {
            error_log('Property Manager: No feed URL configured');
            return false;
        }
        
        // Start import log
        $this->start_import_log();
        
        try {
            // Download and parse XML
            $xml_data = $this->download_feed();
            if (!$xml_data) {
                throw new Exception('Failed to download feed');
            }
            
            $xml = $this->parse_xml($xml_data);
            if (!$xml) {
                throw new Exception('Failed to parse XML');
            }
            
            // Import properties
            $result = $this->process_properties($xml);
            
            // Complete import log
            $this->complete_import_log('completed', $result);
            
            if (!$manual) {
                error_log(sprintf(
                    'Property Manager: Feed import completed. Imported: %d, Updated: %d, Failed: %d',
                    $result['imported'],
                    $result['updated'],
                    $result['failed']
                ));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->complete_import_log('failed', null, $e->getMessage());
            error_log('Property Manager: Feed import failed - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Download feed from URL
     */
    private function download_feed() {
        $response = wp_remote_get($this->feed_url, array(
            'timeout' => 300,
            'user-agent' => 'Property Manager Pro/' . PROPERTY_MANAGER_VERSION
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP Error: ' . $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new Exception('HTTP Error: ' . $http_code);
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Parse XML data
     */
    private function parse_xml($xml_data) {
        // Disable libxml errors
        libxml_use_internal_errors(true);
        
        $xml = simplexml_load_string($xml_data);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = array();
            foreach ($errors as $error) {
                $error_messages[] = trim($error->message);
            }
            throw new Exception('XML Parse Error: ' . implode(', ', $error_messages));
        }
        
        return $xml;
    }
    
    /**
     * Process properties from XML
     */
    private function process_properties($xml) {
        $imported = 0;
        $updated = 0;
        $failed = 0;
        
        foreach ($xml->property as $property_xml) {
            try {
                $property_data = $this->parse_property($property_xml);
                $property_id = PropertyManager_Database::upsert_property($property_data);
                
                if ($property_id) {
                    // Check if this is new or updated
                    global $wpdb;
                    $table = PropertyManager_Database::get_table_name('properties');
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE id = %d AND created_at = updated_at",
                        $property_id
                    ));
                    
                    if ($existing) {
                        $imported++;
                    } else {
                        $updated++;
                    }
                    
                    // Import images (they will be queued for download)
                    if (isset($property_xml->images) && $property_xml->images->image) {
                        $images = $this->parse_images($property_xml->images);
                        PropertyManager_Database::insert_property_images($property_id, $images);
                        
                        // Optionally process images immediately for new properties
                        $options = get_option('property_manager_options', array());
                        $immediate_download = isset($options['immediate_image_download']) ? $options['immediate_image_download'] : false;
                        
                        if ($immediate_download) {
                            $image_downloader = PropertyManager_ImageDownloader::get_instance();
                            $image_downloader->process_property_images($property_id);
                        }
                    }
                    
                    // Import features
                    if (isset($property_xml->features) && $property_xml->features->feature) {
                        $features = $this->parse_features($property_xml->features);
                        PropertyManager_Database::insert_property_features($property_id, $features);
                    }
                    
                } else {
                    $failed++;
                }
                
            } catch (Exception $e) {
                $failed++;
                error_log('Property Manager: Failed to import property - ' . $e->getMessage());
            }
        }
        
        return array(
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed
        );
    }
    
    /**
     * Parse single property from XML
     */
    private function parse_property($property_xml) {
        $data = array();
        
        // Basic property information
        $data['property_id'] = (string) $property_xml->id;
        $data['ref'] = (string) $property_xml->ref;
        $data['price'] = (float) $property_xml->price;
        $data['currency'] = (string) $property_xml->currency;
        $data['price_freq'] = (string) $property_xml->price_freq;
        $data['new_build'] = ((string) $property_xml->new_build === '1') ? 1 : 0;
        $data['type'] = (string) $property_xml->type;
        $data['town'] = (string) $property_xml->town;
        $data['province'] = (string) $property_xml->province;
        $data['location_detail'] = (string) $property_xml->location_detail;
        $data['beds'] = (int) $property_xml->beds;
        $data['baths'] = (int) $property_xml->baths;
        $data['pool'] = ((string) $property_xml->pool === '1') ? 1 : 0;
        
        // Location coordinates
        if (isset($property_xml->location->latitude)) {
            $latitude = (string) $property_xml->location->latitude;
            $longitude = (string) $property_xml->location->longitude;
            
            // Parse coordinates if in DMS format
            $data['latitude'] = $this->parse_coordinate($latitude);
            $data['longitude'] = $this->parse_coordinate($longitude);
        }
        
        // Surface area
        if (isset($property_xml->surface_area->built)) {
            $data['surface_area_built'] = (int) $property_xml->surface_area->built;
        }
        if (isset($property_xml->surface_area->plot)) {
            $data['surface_area_plot'] = (int) $property_xml->surface_area->plot;
        }
        
        // Energy rating
        if (isset($property_xml->energy_rating->consumption)) {
            $data['energy_rating_consumption'] = (string) $property_xml->energy_rating->consumption;
        }
        if (isset($property_xml->energy_rating->emissions)) {
            $data['energy_rating_emissions'] = (string) $property_xml->energy_rating->emissions;
        }
        
        // URLs
        if (isset($property_xml->url->en)) {
            $data['url_en'] = (string) $property_xml->url->en;
        }
        if (isset($property_xml->url->es)) {
            $data['url_es'] = (string) $property_xml->url->es;
        }
        if (isset($property_xml->url->de)) {
            $data['url_de'] = (string) $property_xml->url->de;
        }
        if (isset($property_xml->url->fr)) {
            $data['url_fr'] = (string) $property_xml->url->fr;
        }
        
        // Descriptions
        if (isset($property_xml->desc->en)) {
            $data['description_en'] = (string) $property_xml->desc->en;
        }
        if (isset($property_xml->desc->es)) {
            $data['description_es'] = (string) $property_xml->desc->es;
        }
        if (isset($property_xml->desc->de)) {
            $data['description_de'] = (string) $property_xml->desc->de;
        }
        if (isset($property_xml->desc->fr)) {
            $data['description_fr'] = (string) $property_xml->desc->fr;
        }
        
        // Generate title if not provided
        if (empty($data['title'])) {
            $data['title'] = $this->generate_property_title($data);
        }
        
        // Set status as active
        $data['status'] = 'active';
        
        return $data;
    }
    
    /**
     * Parse coordinate from DMS format to decimal
     */
    private function parse_coordinate($coordinate) {
        if (empty($coordinate)) {
            return null;
        }
        
        // If already in decimal format
        if (is_numeric($coordinate)) {
            return (float) $coordinate;
        }
        
        // Parse DMS format (e.g., 37°52'16.3"N)
        if (preg_match('/(\d+)°(\d+)\'([\d.]+)"([NSEW])/', $coordinate, $matches)) {
            $degrees = (float) $matches[1];
            $minutes = (float) $matches[2];
            $seconds = (float) $matches[3];
            $direction = $matches[4];
            
            $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
            
            if (in_array($direction, array('S', 'W'))) {
                $decimal = -$decimal;
            }
            
            return $decimal;
        }
        
        return null;
    }
    
    /**
     * Parse images from XML
     */
    private function parse_images($images_xml) {
        $images = array();
        
        foreach ($images_xml->image as $image_xml) {
            $images[] = array(
                'id' => (string) $image_xml['id'],
                'url' => (string) $image_xml->url,
                'sort_order' => (int) $image_xml['id']
            );
        }
        
        return $images;
    }
    
    /**
     * Parse features from XML
     */
    private function parse_features($features_xml) {
        $features = array();
        
        foreach ($features_xml->feature as $feature) {
            $features[] = (string) $feature;
        }
        
        return $features;
    }
    
    /**
     * Generate property title
     */
    private function generate_property_title($data) {
        $title_parts = array();
        
        if (!empty($data['type'])) {
            $title_parts[] = $data['type'];
        }
        
        if (!empty($data['town'])) {
            $title_parts[] = 'in ' . $data['town'];
        }
        
        if (!empty($data['beds'])) {
            $title_parts[] = $data['beds'] . ' bed';
        }
        
        if (!empty($data['ref'])) {
            $title_parts[] = 'Ref: ' . $data['ref'];
        }
        
        return implode(' ', $title_parts);
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
        
        $update_data = array(
            'status' => $status,
            'completed_at' => current_time('mysql')
        );
        
        if ($result) {
            $update_data['properties_imported'] = $result['imported'];
            $update_data['properties_updated'] = $result['updated'];
            $update_data['properties_failed'] = $result['failed'];
        }
        
        if ($error_message) {
            $update_data['error_message'] = $error_message;
        }
        
        $wpdb->update(
            $table,
            $update_data,
            array('id' => $this->import_log_id)
        );
    }
    
    /**
     * Get import statistics
     */
    public function get_import_stats($limit = 10) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('import_logs');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE import_type = 'kyero_feed' ORDER BY started_at DESC LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Clean up old properties (mark as inactive if not in feed)
     */
    public function cleanup_old_properties($days = 7) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('properties');
        
        // Mark properties as inactive if not updated for X days
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = 'inactive' 
             WHERE status = 'active' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $wpdb->rows_affected;
    }
    
    /**
     * Manual import trigger
     */
    public function manual_import() {
        return $this->import_feed(true);
    }
}