<?php
/**
 * Image Downloader Class - Production Ready with S3 Offload Compatibility
 * Downloads property images and saves to WordPress Media Library
 * 
 * CRITICAL: S3 OFFLOAD INTEGRATION
 * =================================
 * This class is specifically designed to work seamlessly with WordPress S3 offload plugins:
 * - WP Offload Media (formerly WP Offload S3)
 * - Media Cloud
 * - WP-Stateless
 * - Any plugin that hooks into WordPress media upload actions
 * 
 * HOW IT WORKS:
 * 1. Images are downloaded from Kyero feed URLs
 * 2. Validated for security (file type, size, dimensions)
 * 3. Saved to WordPress Media Library using wp_insert_attachment()
 * 4. S3 offload plugin detects the new attachment (via 'add_attachment' hook)
 * 5. S3 plugin automatically uploads to S3 bucket in the background
 * 6. S3 plugin updates attachment metadata with S3 URL
 * 7. wp_get_attachment_url() returns S3 CDN URL automatically
 * 8. When property/image deleted, wp_delete_attachment() triggers S3 deletion
 * 
 * ZERO configuration needed - it just works! The attachment_id is the bridge.
 * 
 * @package PropertyManagerPro
 * @version 1.0.0
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
    private $min_dimensions;
    private $max_dimensions;
    
    // Processing constants
    private const BATCH_SIZE = 10;
    private const MAX_RETRIES = 3;
    private const DOWNLOAD_TIMEOUT = 60;
    private const PROCESSING_DELAY_MS = 100000; // 0.1 second between downloads
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->upload_dir = wp_upload_dir();
        $this->max_file_size = 10 * 1024 * 1024; // 10MB
        $this->allowed_types = array(
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp'
        );
        $this->min_dimensions = array('width' => 50, 'height' => 50);
        $this->max_dimensions = array('width' => 10000, 'height' => 10000);
        
        // Hook into cron for background processing
        add_action('property_manager_process_images', array($this, 'process_pending_images_cron'));
        
        // Schedule image processing if not already scheduled
        add_action('init', array($this, 'maybe_schedule_image_processing'));
        
        // Cleanup orphaned attachments
        add_action('property_manager_daily_cleanup', array($this, 'cleanup_orphaned_attachments'));
    }
    
    /**
     * Schedule image processing cron job
     */
    public function maybe_schedule_image_processing() {
        if (!wp_next_scheduled('property_manager_process_images')) {
            wp_schedule_event(time(), 'thirtyminutes', 'property_manager_process_images');
        }
    }
    
    /**
     * Download and process a single image
     * This is the MAIN function that integrates with S3 offload plugins
     */
    public function download_image($image_url, $property_id, $image_data = array()) {
        $temp_file = null;
        
        try {
            // Security validation
            if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid image URL: ' . $image_url);
            }
            
            // Validate URL scheme (only http/https)
            $parsed_url = parse_url($image_url);
            if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], array('http', 'https'))) {
                throw new Exception('Invalid URL scheme. Only HTTP/HTTPS allowed.');
            }
            
            // Validate domain isn't localhost or internal IP (security)
            if (isset($parsed_url['host'])) {
                if ($this->is_internal_url($parsed_url['host'])) {
                    throw new Exception('Cannot download from internal/localhost URLs');
                }
            }
            
            error_log('Property Manager: Downloading image from ' . $image_url);
            
            // Get file extension from URL
            $file_info = $this->get_file_info_from_url($image_url);
            
            // Download the image to temporary location
            $temp_file = $this->download_to_temp($image_url);
            
            if (!$temp_file || !file_exists($temp_file)) {
                throw new Exception('Failed to download image to temporary location');
            }
            
            error_log('Property Manager: Downloaded to temp file: ' . $temp_file);
            
            // Validate downloaded image (security checks)
            $this->validate_image($temp_file);
            
            error_log('Property Manager: Image validated successfully');
            
            // Add property reference to image data
            $image_data['property_id'] = $property_id;
            
            // Get property info for better filenames and metadata
            $property = $this->get_property_info($property_id);
            if ($property) {
                $image_data['property_ref'] = $property->ref;
                $image_data['property_title'] = $property->title;
            }
            
            // Generate unique filename
            $filename = $this->generate_filename($property_id, $image_data, $file_info['extension']);
            
            error_log('Property Manager: Saving as ' . $filename);
            
            // Save to WordPress media library
            // CRITICAL: This triggers S3 offload plugins automatically!
            $attachment_id = $this->save_to_media_library($temp_file, $filename, $image_data);
            
            // Clean up temp file
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            
            if (!$attachment_id) {
                throw new Exception('Failed to save image to media library');
            }
            
            error_log('Property Manager: Successfully created attachment ID ' . $attachment_id . ' (S3 offload will happen automatically)');
            
            return $attachment_id;
            
        } catch (Exception $e) {
            // Clean up temp file if it exists
            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            
            error_log('Property Manager Image Download Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if URL is internal/localhost (security measure)
     */
    private function is_internal_url($host) {
        // Check for localhost
        if (in_array($host, array('localhost', '127.0.0.1', '::1'))) {
            return true;
        }
        
        // Check for private IP ranges
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get property info for metadata
     */
    private function get_property_info($property_id) {
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, ref, title, town, province FROM {$table} WHERE id = %d",
            $property_id
        ));
    }
    
    /**
     * Download image to temporary location with security checks
     */
    private function download_to_temp($url) {
        // Create temp filename
        $temp_file = wp_tempnam('property-image-');
        
        if (!$temp_file) {
            error_log('Property Manager: Failed to create temp file');
            return false;
        }
        
        error_log('Property Manager: Downloading to temp file ' . $temp_file);
        
        // Download with wp_remote_get using stream mode
        $response = wp_remote_get($url, array(
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'stream' => true,
            'filename' => $temp_file,
            'redirection' => 3,
            'httpversion' => '1.1',
            'user-agent' => 'Property Manager Pro/' . PROPERTY_MANAGER_VERSION,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            error_log('Property Manager: Download error - ' . $response->get_error_message());
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            error_log('Property Manager: Download error - HTTP ' . $http_code);
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return false;
        }
        
        // Check if file was actually downloaded
        if (!file_exists($temp_file)) {
            error_log('Property Manager: Temp file not created');
            return false;
        }
        
        $file_size = filesize($temp_file);
        if ($file_size === 0) {
            error_log('Property Manager: Downloaded file is empty');
            @unlink($temp_file);
            return false;
        }
        
        error_log('Property Manager: Downloaded ' . size_format($file_size));
        
        return $temp_file;
    }
    
    /**
     * Validate downloaded image for security
     */
    private function validate_image($file_path) {
        // Check file exists
        if (!file_exists($file_path)) {
            throw new Exception('File does not exist');
        }
        
        // Check file size
        $file_size = filesize($file_path);
        
        if ($file_size > $this->max_file_size) {
            throw new Exception('Image file too large: ' . size_format($file_size) . ' (max: ' . size_format($this->max_file_size) . ')');
        }
        
        if ($file_size < 100) {
            throw new Exception('Image file too small: ' . $file_size . ' bytes');
        }
        
        // CRITICAL SECURITY: Verify it's actually an image
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            throw new Exception('File is not a valid image');
        }
        
        // Check MIME type against allowed types
        $mime_type = $image_info['mime'];
        if (!in_array($mime_type, $this->allowed_types, true)) {
            throw new Exception('Unsupported image type: ' . $mime_type);
        }
        
        // Check dimensions
        $width = $image_info[0];
        $height = $image_info[1];
        
        if ($width < $this->min_dimensions['width'] || $height < $this->min_dimensions['height']) {
            throw new Exception('Image dimensions too small: ' . $width . 'x' . $height . ' (min: ' . $this->min_dimensions['width'] . 'x' . $this->min_dimensions['height'] . ')');
        }
        
        if ($width > $this->max_dimensions['width'] || $height > $this->max_dimensions['height']) {
            throw new Exception('Image dimensions too large: ' . $width . 'x' . $height . ' (max: ' . $this->max_dimensions['width'] . 'x' . $this->max_dimensions['height'] . ')');
        }
        
        // Additional security: Check for PHP code in image (image upload vulnerability)
        $file_content = file_get_contents($file_path, false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=|<script/i', $file_content)) {
            throw new Exception('Security: Malicious code detected in image file');
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
        if (!in_array($extension, $valid_extensions, true)) {
            $extension = 'jpg'; // Default to jpg
        }
        
        return array(
            'extension' => $extension,
            'basename' => isset($path_info['basename']) ? sanitize_file_name($path_info['basename']) : 'image.' . $extension
        );
    }
    
    /**
     * Generate unique filename for the image
     */
    private function generate_filename($property_id, $image_data, $extension) {
        $filename_parts = array();
        
        // Add property reference or ID
        if (isset($image_data['property_ref']) && !empty($image_data['property_ref'])) {
            $filename_parts[] = sanitize_file_name($image_data['property_ref']);
        } else {
            $filename_parts[] = 'property-' . $property_id;
        }
        
        // Add image ID if available
        if (isset($image_data['image_id']) && $image_data['image_id']) {
            $filename_parts[] = 'img-' . intval($image_data['image_id']);
        }
        
        // Add sort order if available
        if (isset($image_data['sort_order'])) {
            $filename_parts[] = sprintf('%02d', intval($image_data['sort_order']));
        }
        
        // Add timestamp to ensure uniqueness
        $filename_parts[] = time();
        
        $filename = implode('-', $filename_parts) . '.' . $extension;
        
        // Ensure filename is safe
        $filename = sanitize_file_name($filename);
        
        return $filename;
    }
    
    /**
     * Save image to WordPress media library
     * CRITICAL: This is where S3 offload plugins hook in automatically!
     */
    private function save_to_media_library($temp_file, $filename, $image_data = array()) {
        // Include WordPress file handling functions
        if (!function_exists('wp_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Prepare file array for wp_handle_sideload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
            'size' => filesize($temp_file),
            'error' => 0
        );
        
        // Handle the sideload (moves file to uploads directory)
        $sideload_result = wp_handle_sideload($file_array, array(
            'test_form' => false,
            'test_size' => true,
            'test_upload' => true,
            'test_type' => true
        ));
        
        if (isset($sideload_result['error'])) {
            throw new Exception('WordPress sideload error: ' . $sideload_result['error']);
        }
        
        error_log('Property Manager: File sideloaded to ' . $sideload_result['file']);
        
        // Generate image title and alt text
        $image_title = $this->generate_image_title($image_data);
        $image_alt = $this->generate_image_alt($image_data);
        
        // Prepare attachment post data
        $attachment_data = array(
            'guid' => $sideload_result['url'],
            'post_mime_type' => $sideload_result['type'],
            'post_title' => $image_title,
            'post_content' => '',
            'post_excerpt' => $image_alt, // Caption
            'post_status' => 'inherit'
        );
        
        // Insert attachment post
        // CRITICAL: This triggers 'add_attachment' action which S3 plugins hook into!
        $attachment_id = wp_insert_attachment($attachment_data, $sideload_result['file']);
        
        if (is_wp_error($attachment_id)) {
            throw new Exception('Failed to create attachment: ' . $attachment_id->get_error_message());
        }
        
        error_log('Property Manager: Created attachment post ID ' . $attachment_id);
        
        // Generate attachment metadata (thumbnails, etc.)
        // S3 plugins will also upload these thumbnails automatically
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $sideload_result['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        
        error_log('Property Manager: Generated attachment metadata');
        
        // Set alt text as post meta
        if (!empty($image_alt)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($image_alt));
        }
        
        // Add custom meta to identify property manager images
        update_post_meta($attachment_id, '_property_manager_image', '1');
        update_post_meta($attachment_id, '_property_id', intval($image_data['property_id']));
        
        // Add property reference for easier management
        if (isset($image_data['property_ref'])) {
            update_post_meta($attachment_id, '_property_ref', sanitize_text_field($image_data['property_ref']));
        }
        
        // Add original feed URL for reference
        if (isset($image_data['original_url'])) {
            update_post_meta($attachment_id, '_original_feed_url', esc_url_raw($image_data['original_url']));
        }
        
        error_log('Property Manager: Attachment metadata saved. S3 offload will now process automatically if plugin is active.');
        
        /**
         * At this point, if an S3 offload plugin is active:
         * 1. It has detected the new attachment via 'add_attachment' hook
         * 2. It is uploading the file and thumbnails to S3 in the background
         * 3. It will update the attachment metadata with S3 URLs
         * 4. wp_get_attachment_url($attachment_id) will return S3 URL automatically
         * 
         * NO additional code needed - it just works!
         */
        
        return $attachment_id;
    }
    
    /**
     * Generate image title from property data
     */
    private function generate_image_title($image_data) {
        if (isset($image_data['title']) && !empty($image_data['title'])) {
            return sanitize_text_field($image_data['title']);
        }
        
        $title_parts = array();
        
        if (isset($image_data['property_title'])) {
            $title_parts[] = $image_data['property_title'];
        } elseif (isset($image_data['property_ref'])) {
            $title_parts[] = $image_data['property_ref'];
        } else {
            $title_parts[] = 'Property ' . $image_data['property_id'];
        }
        
        if (isset($image_data['sort_order'])) {
            $title_parts[] = 'Image ' . ($image_data['sort_order'] + 1);
        }
        
        return !empty($title_parts) ? implode(' - ', $title_parts) : 'Property Image';
    }
    
    /**
     * Generate image alt text from property data
     */
    private function generate_image_alt($image_data) {
        if (isset($image_data['alt']) && !empty($image_data['alt'])) {
            return sanitize_text_field($image_data['alt']);
        }
        
        $alt_parts = array();
        
        if (isset($image_data['property_title'])) {
            $alt_parts[] = $image_data['property_title'];
        }
        
        if (isset($image_data['property_town'])) {
            $alt_parts[] = $image_data['property_town'];
        }
        
        return !empty($alt_parts) ? implode(' in ', $alt_parts) : '';
    }
    
    /**
     * Process pending images in background (cron handler)
     */
    public function process_pending_images_cron() {
        $this->process_pending_images(self::BATCH_SIZE);
    }
    
    /**
     * Process pending images in batch
     * This runs via cron every 30 minutes
     */
    public function process_pending_images($batch_size = null) {
        if ($batch_size === null) {
            $batch_size = self::BATCH_SIZE;
        }
        
        $batch_size = max(1, min(50, intval($batch_size)));
        
        error_log('Property Manager: Processing up to ' . $batch_size . ' pending images');
        
        $pending_images = PropertyManager_Database::get_pending_images($batch_size);
        
        if (empty($pending_images)) {
            error_log('Property Manager: No pending images to process');
            return 0;
        }
        
        error_log('Property Manager: Found ' . count($pending_images) . ' pending images');
        
        $processed = 0;
        $failed = 0;
        
        foreach ($pending_images as $image_record) {
            try {
                error_log('Property Manager: Processing image ID ' . $image_record->id . ' for property ' . $image_record->property_id);
                
                // Prepare image data
                $image_data = array(
                    'property_id' => $image_record->property_id,
                    'image_id' => $image_record->image_id,
                    'title' => $image_record->image_title,
                    'alt' => $image_record->image_alt,
                    'sort_order' => $image_record->sort_order,
                    'original_url' => $image_record->original_url
                );
                
                // Mark as downloading to prevent concurrent processing
                global $wpdb;
                $table = PropertyManager_Database::get_table_name('property_images');
                $wpdb->update(
                    $table,
                    array('download_status' => 'downloading'),
                    array('id' => $image_record->id),
                    array('%s'),
                    array('%d')
                );
                
                // Download and save image
                $attachment_id = $this->download_image($image_record->original_url, $image_record->property_id, $image_data);
                
                if ($attachment_id) {
                    // Update database with attachment ID
                    $local_url = wp_get_attachment_url($attachment_id);
                    PropertyManager_Database::update_image_attachment($image_record->id, $attachment_id, $local_url);
                    $processed++;
                    
                    error_log('Property Manager: Successfully processed image ID ' . $image_record->id . ' -> attachment ' . $attachment_id);
                } else {
                    // Mark as failed
                    PropertyManager_Database::mark_image_failed($image_record->id, 'Download failed');
                    $failed++;
                    
                    error_log('Property Manager: Failed to process image ID ' . $image_record->id);
                }
                
            } catch (Exception $e) {
                PropertyManager_Database::mark_image_failed($image_record->id, $e->getMessage());
                $failed++;
                
                error_log('Property Manager: Exception processing image ID ' . $image_record->id . ': ' . $e->getMessage());
            }
            
            // Add small delay to prevent overwhelming the server
            usleep(self::PROCESSING_DELAY_MS);
        }
        
        error_log('Property Manager: Batch complete - Processed: ' . $processed . ', Failed: ' . $failed);
        
        return $processed;
    }
    
    /**
     * Process images for a specific property
     */
    public function process_property_images($property_id, $limit = null, $force_redownload = false) {
        global $wpdb;
        
        $property_id = intval($property_id);
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        $where_clause = "property_id = %d";
        $where_params = array($property_id);
        
        if (!$force_redownload) {
            $where_clause .= " AND download_status = 'pending'";
        }
        
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY sort_order ASC";
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        $images = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        
        if (empty($images)) {
            return 0;
        }
        
        $processed = 0;
        
        foreach ($images as $image_record) {
            try {
                $image_data = array(
                    'property_id' => $image_record->property_id,
                    'image_id' => $image_record->image_id,
                    'title' => $image_record->image_title,
                    'alt' => $image_record->image_alt,
                    'sort_order' => $image_record->sort_order,
                    'original_url' => $image_record->original_url
                );
                
                $attachment_id = $this->download_image($image_record->original_url, $image_record->property_id, $image_data);
                
                if ($attachment_id) {
                    $local_url = wp_get_attachment_url($attachment_id);
                    PropertyManager_Database::update_image_attachment($image_record->id, $attachment_id, $local_url);
                    $processed++;
                } else {
                    PropertyManager_Database::mark_image_failed($image_record->id, 'Download failed');
                }
                
            } catch (Exception $e) {
                PropertyManager_Database::mark_image_failed($image_record->id, $e->getMessage());
            }
            
            usleep(self::PROCESSING_DELAY_MS);
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
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE download_status = 'pending'"),
            'downloading' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE download_status = 'downloading'"),
            'downloaded' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE download_status = 'downloaded'"),
            'failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE download_status = 'failed'")
        );
    }
    
    /**
     * Retry failed downloads
     */
    public function retry_failed_downloads($limit = 10) {
        global $wpdb;
        
        $limit = max(1, min(100, intval($limit)));
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        // Reset failed images to pending for retry (only if attempts < max retries)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} 
             SET download_status = 'pending', download_attempts = 0 
             WHERE download_status = 'failed' 
             AND download_attempts < %d 
             LIMIT %d",
            self::MAX_RETRIES,
            $limit
        ));
        
        $affected = $wpdb->rows_affected;
        
        if ($affected > 0) {
            error_log('Property Manager: Queued ' . $affected . ' failed images for retry');
        }
        
        return $affected;
    }
    
    /**
     * Clean up orphaned attachments
     * Removes WordPress attachments that are no longer linked to any property
     */
    public function cleanup_orphaned_attachments() {
        global $wpdb;
        
        // Find property manager attachments that aren't in our images table
        $orphaned_attachments = $wpdb->get_results("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_property_manager_image'
            AND pm.meta_value = '1'
            AND p.ID NOT IN (
                SELECT attachment_id 
                FROM " . PropertyManager_Database::get_table_name('property_images') . "
                WHERE attachment_id IS NOT NULL
            )
        ");
        
        $deleted = 0;
        
        foreach ($orphaned_attachments as $attachment) {
            // This will also delete from S3 if offload plugin is active
            if (wp_delete_attachment($attachment->ID, true)) {
                $deleted++;
                error_log('Property Manager: Deleted orphaned attachment ' . $attachment->ID . ' (including S3 if offloaded)');
            }
        }
        
        if ($deleted > 0) {
            error_log('Property Manager: Cleaned up ' . $deleted . ' orphaned attachments');
        }
        
        return $deleted;
    }
    
    /**
     * Get failed images with details
     */
    public function get_failed_images($limit = 50) {
        global $wpdb;
        
        $limit = max(1, min(100, intval($limit)));
        
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.title as property_title, p.ref as property_ref 
             FROM {$images_table} i 
             LEFT JOIN {$properties_table} p ON i.property_id = p.id 
             WHERE i.download_status = 'failed' 
             ORDER BY i.updated_at DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Reset downloading status (stuck images)
     * If an image has been in 'downloading' status for too long, reset it to pending
     */
    public function reset_stuck_downloads() {
        global $wpdb;
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        // Reset images stuck in downloading status for more than 1 hour
        $wpdb->query(
            "UPDATE {$table} 
             SET download_status = 'pending' 
             WHERE download_status = 'downloading' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        
        $affected = $wpdb->rows_affected;
        
        if ($affected > 0) {
            error_log('Property Manager: Reset ' . $affected . ' stuck downloads');
        }
        
        return $affected;
    }
    
    /**
     * Force reprocess all images for a property
     */
    public function reprocess_property_images($property_id) {
        global $wpdb;
        
        $property_id = intval($property_id);
        
        $table = PropertyManager_Database::get_table_name('property_images');
        
        // Get all images for this property
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT id, attachment_id FROM {$table} WHERE property_id = %d",
            $property_id
        ));
        
        $deleted = 0;
        
        // Delete existing attachments
        foreach ($images as $image) {
            if ($image->attachment_id) {
                // This also deletes from S3 if offload is active
                if (wp_delete_attachment($image->attachment_id, true)) {
                    $deleted++;
                }
            }
        }
        
        // Reset all images to pending
        $wpdb->update(
            $table,
            array(
                'attachment_id' => null,
                'download_status' => 'pending',
                'download_attempts' => 0,
                'error_message' => null
            ),
            array('property_id' => $property_id),
            array('%d', '%s', '%d', '%s'),
            array('%d')
        );
        
        error_log('Property Manager: Reset ' . count($images) . ' images for property ' . $property_id . ' (deleted ' . $deleted . ' attachments)');
        
        // Process immediately
        return $this->process_property_images($property_id);
    }
    
    /**
     * Get download queue status
     */
    public function get_queue_status() {
        $stats = $this->get_image_stats();
        
        return array(
            'total_queued' => $stats['pending'] + $stats['downloading'],
            'pending' => $stats['pending'],
            'downloading' => $stats['downloading'],
            'completed' => $stats['downloaded'],
            'failed' => $stats['failed'],
            'total' => $stats['total'],
            'completion_percentage' => $stats['total'] > 0 ? round(($stats['downloaded'] / $stats['total']) * 100, 2) : 0
        );
    }
    
    /**
     * Test image download (for admin testing)
     */
    public function test_image_download($image_url) {
        try {
            // Validate URL
            if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                return array(
                    'success' => false,
                    'message' => 'Invalid URL format'
                );
            }
            
            // Try to download
            $temp_file = $this->download_to_temp($image_url);
            
            if (!$temp_file) {
                return array(
                    'success' => false,
                    'message' => 'Failed to download image'
                );
            }
            
            // Validate
            try {
                $this->validate_image($temp_file);
                $file_size = filesize($temp_file);
                $image_info = getimagesize($temp_file);
                
                @unlink($temp_file);
                
                return array(
                    'success' => true,
                    'message' => 'Image is valid and can be downloaded',
                    'size' => size_format($file_size),
                    'dimensions' => $image_info[0] . 'x' . $image_info[1],
                    'mime_type' => $image_info['mime']
                );
            } catch (Exception $e) {
                @unlink($temp_file);
                return array(
                    'success' => false,
                    'message' => 'Validation failed: ' . $e->getMessage()
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get S3 offload plugin status
     */
    public function get_s3_offload_status() {
        $status = array(
            's3_enabled' => false,
            'plugin_name' => 'None',
            'message' => 'No S3 offload plugin detected'
        );
        
        // Check for WP Offload Media
        if (class_exists('Amazon_S3_And_CloudFront') || class_exists('DeliciousBrains\WP_Offload_Media\Pro\Plugin')) {
            $status['s3_enabled'] = true;
            $status['plugin_name'] = 'WP Offload Media';
            $status['message'] = 'WP Offload Media detected - images will automatically upload to S3';
        }
        // Check for Media Cloud
        elseif (class_exists('ILAB\MediaCloud\Plugin')) {
            $status['s3_enabled'] = true;
            $status['plugin_name'] = 'Media Cloud';
            $status['message'] = 'Media Cloud detected - images will automatically upload to S3';
        }
        // Check for WP-Stateless
        elseif (class_exists('wpCloud\StatelessMedia\Bootstrap')) {
            $status['s3_enabled'] = true;
            $status['plugin_name'] = 'WP-Stateless';
            $status['message'] = 'WP-Stateless detected - images will automatically upload to Google Cloud Storage';
        }
        // Check for generic S3 uploads
        elseif (defined('AS3CF_PLUGIN_FILE_PATH') || defined('S3_UPLOADS_BUCKET')) {
            $status['s3_enabled'] = true;
            $status['plugin_name'] = 'S3 Plugin Detected';
            $status['message'] = 'S3 upload plugin detected - images will automatically offload';
        }
        
        return $status;
    }
    
    /**
     * Verify S3 offload is working for property images
     */
    public function verify_s3_offload() {
        global $wpdb;
        
        // Get a sample downloaded image
        $images_table = PropertyManager_Database::get_table_name('property_images');
        $sample_image = $wpdb->get_row(
            "SELECT attachment_id FROM {$images_table} 
             WHERE download_status = 'downloaded' 
             AND attachment_id IS NOT NULL 
             LIMIT 1"
        );
        
        if (!$sample_image) {
            return array(
                'verified' => false,
                'message' => 'No downloaded images found to verify'
            );
        }
        
        $attachment_url = wp_get_attachment_url($sample_image->attachment_id);
        
        if (!$attachment_url) {
            return array(
                'verified' => false,
                'message' => 'Could not get attachment URL'
            );
        }
        
        // Check if URL contains S3/cloud storage domain
        $is_offloaded = (
            strpos($attachment_url, 's3.amazonaws.com') !== false ||
            strpos($attachment_url, 'cloudfront.net') !== false ||
            strpos($attachment_url, 'storage.googleapis.com') !== false ||
            strpos($attachment_url, 'digitaloceanspaces.com') !== false ||
            strpos($attachment_url, '.r2.cloudflarestorage.com') !== false
        );
        
        if ($is_offloaded) {
            return array(
                'verified' => true,
                'message' => 'S3 offload is working! Image URL: ' . $attachment_url,
                'sample_url' => $attachment_url,
                'attachment_id' => $sample_image->attachment_id
            );
        } else {
            return array(
                'verified' => false,
                'message' => 'Images are not being offloaded to S3. URL: ' . $attachment_url,
                'sample_url' => $attachment_url,
                'attachment_id' => $sample_image->attachment_id
            );
        }
    }
}