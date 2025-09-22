<?php
/**
 * Image Downloader Class - Downloads property images and saves to WP Media
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_ImageDownloader {
    
    private static $instance = null;
    private $upload_dir;
    private $max_file_size;
    private $allowed_types;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->upload_dir = wp_upload_dir();
        $this->max_file_size = 10 * 1024 * 1024; // 10MB
        $this->allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp');
        
        // Hook into cron for background processing
        add_action('property_manager_process_images', array($this, 'process_pending_images'));
        
        // Schedule image processing if not already scheduled
        add_action('init', array($this, 'maybe_schedule_image_processing'));
    }
    
    /**
     * Schedule image processing cron job
     */
    public function maybe_schedule_image_processing() {
        if (!wp_next_scheduled('property_manager_process_images')) {
            wp_schedule_event(time(), 'hourly', 'property_manager_process_images');
        }
    }
    
    /**
     * Download and process a single image
     */
    public function download_image($image_url, $property_id, $image_data = array()) {
        try {
            // Validate URL
            if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid image URL: ' . $image_url);
            }
            
            // Get file extension from URL
            $file_info = $this->get_file_info_from_url($image_url);
            
            // Download the image
            $temp_file = $this->download_to_temp($image_url);
            
            if (!$temp_file) {
                throw new Exception('Failed to download image from: ' . $image_url);
            }
            
            // Validate image
            $this->validate_image($temp_file);
            
            // Generate filename
            $filename = $this->generate_filename($property_id, $image_data, $file_info['extension']);
            
            // Save to WordPress media library
            $attachment_id = $this->save_to_media_library($temp_file, $filename, $image_data);
            
            // Clean up temp file
            unlink($temp_file);
            
            if (!$attachment_id) {
                throw new Exception('Failed to save image to media library');
            }
            
            return $attachment_id;
            
        } catch (Exception $e) {
            // Clean up temp file if it exists
            if (isset($temp_file) && file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            error_log('Property Manager Image Download Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Download image to temporary location
     */
    private function download_to_temp($url) {
        // Create temp filename
        $temp_file = wp_tempnam();
        
        if (!$temp_file) {
            return false;
        }
        
        // Download with wp_remote_get
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'stream' => true,
            'filename' => $temp_file,
            'user-agent' => 'Property Manager Pro/' . PROPERTY_MANAGER_VERSION
        ));
        
        if (is_wp_error($response)) {
            unlink($temp_file);
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            unlink($temp_file);
            return false;
        }
        
        // Check if file was actually downloaded
        if (!file_exists($temp_file) || filesize($temp_file) === 0) {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return false;
        }
        
        return $temp_file;
    }
    
    /**
     * Validate downloaded image
     */
    private function validate_image($file_path) {
        // Check file size
        $file_size = filesize($file_path);
        if ($file_size > $this->max_file_size) {
            throw new Exception('Image file too large: ' . $file_size . ' bytes');
        }
        
        if ($file_size < 100) { // Minimum 100 bytes
            throw new Exception('Image file too small: ' . $file_size . ' bytes');
        }
        
        // Check if it's actually an image
        $image_info = getimagesize($file_path);
        if ($image_info === false) {
            throw new Exception('File is not a valid image');
        }
        
        // Check MIME type
        $mime_type = $image_info['mime'];
        if (!in_array($mime_type, $this->allowed_types)) {
            throw new Exception('Unsupported image type: ' . $mime_type);
        }
        
        // Check minimum dimensions
        if ($image_info[0] < 50 || $image_info[1] < 50) {
            throw new Exception('Image dimensions too small: ' . $image_info[0] . 'x' . $image_info[1]);
        }
        
        return true;
    }
    
    /**
     * Get file info from URL
     */
    private function get_file_info_from_url($url) {
        $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
        $extension = isset($path_info['extension']) ? strtolower($path_info['extension']) : 'jpg';
        
        // Ensure we have a valid image extension
        $valid_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        if (!in_array($extension, $valid_extensions)) {
            $extension = 'jpg';
        }
        
        return array(
            'extension' => $extension,
            'basename' => isset($path_info['basename']) ? $path_info['basename'] : 'image.' . $extension
        );
    }
    
    /**
     * Generate filename for the image
     */
    private function generate_filename($property_id, $image_data, $extension) {
        $property = PropertyManager_Property::get_instance()->get_property($property_id);
        
        $filename_parts = array();
        
        // Add property reference or ID
        if ($property && !empty($property->ref)) {
            $filename_parts[] = sanitize_file_name($property->ref);
        } else {
            $filename_parts[] = 'property-' . $property_id;
        }
        
        // Add image ID if available
        if (isset($image_data['image_id'])) {
            $filename_parts[] = 'img-' . $image_data['image_id'];
        }
        
        // Add sort order if available
        if (isset($image_data['sort_order'])) {
            $filename_parts[] = sprintf('%02d', $image_data['sort_order']);
        }
        
        $filename = implode('-', $filename_parts) . '.' . $extension;
        
        return $filename;
    }
    
    /**
     * Save image to WordPress media library
     */
    private function save_to_media_library($temp_file, $filename, $image_data = array()) {
        // Include WordPress file handling functions
        if (!function_exists('wp_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Prepare file array for wp_handle_sideload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
            'size' => filesize($temp_file)
        );
        
        // Handle the sideload
        $sideload_result = wp_handle_sideload($file_array, array(
            'test_form' => false,
            'test_size' => true,
            'test_upload' => true
        ));
        
        if (isset($sideload_result['error'])) {
            throw new Exception('WordPress sideload error: ' . $sideload_result['error']);
        }
        
        // Create attachment post
        $attachment_data = array(
            'guid' => $sideload_result['url'],
            'post_mime_type' => $sideload_result['type'],
            'post_title' => isset($image_data['title']) ? $image_data['title'] : $this->generate_image_title($image_data),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data, $sideload_result['file']);
        
        if (is_wp_error($attachment_id)) {
            throw new Exception('Failed to create attachment: ' . $attachment_id->get_error_message());
        }
        
        // Generate attachment metadata
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $sideload_result['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        
        // Set alt text if provided
        if (isset($image_data['alt']) && !empty($image_data['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $image_data['alt']);
        }
        
        // Add custom meta to identify property images
        update_post_meta($attachment_id, '_property_manager_image', true);
        update_post_meta($attachment_id, '_property_id', isset($image_data['property_id']) ? $image_data['property_id'] : null);
        
        return $attachment_id;
    }
    
    /**
     * Generate image title
     */
    private function generate_image_title($image_data) {
        if (isset($image_data['title']) && !empty($image_data['title'])) {
            return $image_data['title'];
        }
        
        $title_parts = array();
        
        if (isset($image_data['property_ref'])) {
            $title_parts[] = $image_data['property_ref'];
        }
        
        if (isset($image_data['sort_order'])) {
            $title_parts[] = 'Image ' . ($image_data['sort_order'] + 1);
        }
        
        return !empty($title_parts) ? implode(' - ', $title_parts) : 'Property Image';
    }
    
    /**
     * Process pending images in background
     */
    public function process_pending_images($batch_size = 10) {
        $pending_images = PropertyManager_Database::get_pending_images($batch_size);
        
        if (empty($pending_images)) {
            return 0;
        }
        
        $processed = 0;
        
        foreach ($pending_images as $image_record) {
            try {
                // Prepare image data
                $image_data = array(
                    'property_id' => $image_record->property_id,
                    'image_id' => $image_record->image_id,
                    'title' => $image_record->image_title,
                    'alt' => $image_record->image_alt,
                    'sort_order' => $image_record->sort_order
                );
                
                // Download and save image
                $attachment_id = $this->download_image($image_record->original_url, $image_record->property_id, $image_data);
                
                if ($attachment_id) {
                    // Update database with attachment ID
                    $local_url = wp_get_attachment_url($attachment_id);
                    PropertyManager_Database::update_image_attachment($image_record->id, $attachment_id, $local_url);
                    $processed++;
                } else {
                    // Mark as failed
                    PropertyManager_Database::mark_image_failed($image_record->id);
                }
                
            } catch (Exception $e) {
                PropertyManager_Database::mark_image_failed($image_record->id, $e->getMessage());
                error_log('Property Manager: Failed to process image ID ' . $image_record->id . ': ' . $e->getMessage());
            }
            
            // Add small delay to prevent overwhelming the server
            usleep(100000); // 0.1 second
        }
        
        return $processed;
    }
    
    /**
     * Process images for a specific property
     */
    public function process_property_images($property_id, $force_redownload = false) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        $where_clause = "property_id = %d";
        $where_params = array($property_id);
        
        if (!$force_redownload) {
            $where_clause .= " AND download_status = 'pending'";
        }
        
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY sort_order ASC",
            $where_params
        ));
        
        $processed = 0;
        
        foreach ($images as $image_record) {
            try {
                $image_data = array(
                    'property_id' => $image_record->property_id,
                    'image_id' => $image_record->image_id,
                    'title' => $image_record->image_title,
                    'alt' => $image_record->image_alt,
                    'sort_order' => $image_record->sort_order
                );
                
                $attachment_id = $this->download_image($image_record->original_url, $image_record->property_id, $image_data);
                
                if ($attachment_id) {
                    $local_url = wp_get_attachment_url($attachment_id);
                    PropertyManager_Database::update_image_attachment($image_record->id, $attachment_id, $local_url);
                    $processed++;
                } else {
                    PropertyManager_Database::mark_image_failed($image_record->id);
                }
                
            } catch (Exception $e) {
                PropertyManager_Database::mark_image_failed($image_record->id, $e->getMessage());
            }
        }
        
        return $processed;
    }
    
    /**
     * Get image download statistics
     */
    public function get_image_stats() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        return array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE download_status = 'pending'"),
            'downloaded' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE download_status = 'downloaded'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE download_status = 'failed'")
        );
    }
    
    /**
     * Retry failed downloads
     */
    public function retry_failed_downloads($limit = 10) {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        // Reset failed images to pending for retry
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET download_status = 'pending' WHERE download_status = 'failed' LIMIT %d",
            $limit
        ));
        
        return $wpdb->rows_affected;
    }
    
    /**
     * Clean up orphaned attachments
     */
    public function cleanup_orphaned_attachments() {
        global $wpdb;
        
        $attachments = $wpdb->get_results("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND ID IN (
                SELECT meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_property_manager_image' 
                AND meta_value = '1'
            )
            AND ID NOT IN (
                SELECT attachment_id FROM " . PropertyManager_Database::get_table_name('property_images') . "
                WHERE attachment_id IS NOT NULL
            )
        ");
        
        $deleted = 0;
        foreach ($attachments as $attachment) {
            if (wp_delete_attachment($attachment->ID, true)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
}