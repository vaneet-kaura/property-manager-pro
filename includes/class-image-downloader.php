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
        
        // FIXED: Schedule image processing - changed from 'thirtyminutes' to 'hourly'
        add_action('init', array($this, 'maybe_schedule_image_processing'));
        
        // Cleanup orphaned attachments
        add_action('property_manager_daily_cleanup', array($this, 'cleanup_orphaned_attachments'));
    }
    
    /**
     * Schedule image processing cron job
     * FIXED: Changed from 'thirtyminutes' to 'hourly' (valid WordPress interval)
     */
    public function maybe_schedule_image_processing() {
        if (!wp_next_scheduled('property_manager_process_images')) {
            // Use 'hourly' instead of non-existent 'thirtyminutes'
            // Note: If you need 30-minute intervals, register custom interval in main plugin file
            wp_schedule_event(time(), 'hourly', 'property_manager_process_images');
        }
    }
    
    /**
     * Download and process a single image
     * FIXED: Improved temp file cleanup with proper error handling
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
            
            if (!$attachment_id) {
                throw new Exception('Failed to save image to media library');
            }
            
            error_log('Property Manager: Successfully created attachment ID ' . $attachment_id . ' (S3 offload will happen automatically)');
            
            return $attachment_id;
            
        } catch (Exception $e) {
            error_log('Property Manager Image Download Error: ' . $e->getMessage());
            return false;
            
        } finally {
            // FIXED: Always clean up temp file in finally block
            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
                error_log('Property Manager: Cleaned up temp file');
            }
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
            @unlink($temp_file);
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            error_log('Property Manager: HTTP error code: ' . $http_code);
            @unlink($temp_file);
            return false;
        }
        
        // Verify file was created and has content
        if (!file_exists($temp_file) || filesize($temp_file) === 0) {
            error_log('Property Manager: Downloaded file is empty or does not exist');
            @unlink($temp_file);
            return false;
        }
        
        return $temp_file;
    }
    
    /**
     * Validate image file (security and quality checks)
     */
    private function validate_image($file_path) {
        // Check file exists
        if (!file_exists($file_path)) {
            throw new Exception('Image file does not exist');
        }
        
        // Check file size
        $file_size = filesize($file_path);
        if ($file_size === 0) {
            throw new Exception('Image file is empty');
        }
        
        if ($file_size > $this->max_file_size) {
            throw new Exception('Image file too large: ' . size_format($file_size));
        }
        
        // Get image info
        $image_info = @getimagesize($file_path);
        
        if ($image_info === false) {
            throw new Exception('Not a valid image file');
        }
        
        // Check MIME type
        if (!in_array($image_info['mime'], $this->allowed_types)) {
            throw new Exception('Invalid image type: ' . $image_info['mime']);
        }
        
        // Check dimensions
        $width = $image_info[0];
        $height = $image_info[1];
        
        if ($width < $this->min_dimensions['width'] || $height < $this->min_dimensions['height']) {
            throw new Exception('Image too small: ' . $width . 'x' . $height);
        }
        
        if ($width > $this->max_dimensions['width'] || $height > $this->max_dimensions['height']) {
            throw new Exception('Image too large: ' . $width . 'x' . $height);
        }
        
        return true;
    }
    
    /**
     * Get file info from URL
     */
    private function get_file_info_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Default to jpg if no extension
        if (empty($extension)) {
            $extension = 'jpg';
        }
        
        // Ensure extension is valid
        $valid_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        if (!in_array(strtolower($extension), $valid_extensions)) {
            $extension = 'jpg';
        }
        
        return array(
            'extension' => strtolower($extension),
            'filename' => basename($path)
        );
    }
    
    /**
     * Generate unique filename for property image
     */
    private function generate_filename($property_id, $image_data, $extension) {
        $filename_parts = array('property');
        
        // Add property reference if available
        if (isset($image_data['property_ref']) && $image_data['property_ref']) {
            $filename_parts[] = sanitize_file_name($image_data['property_ref']);
        } else {
            $filename_parts[] = 'id-' . $property_id;
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
     * FIXED: Added property_id to attachment metadata
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
        
        // FIXED: Validate attachment was created
        if (!$attachment_id || is_wp_error($attachment_id)) {
            throw new Exception('Failed to create attachment post');
        }
        
        // Generate attachment metadata (thumbnails, etc.)
        $attach_metadata = wp_generate_attachment_metadata($attachment_id, $sideload_result['file']);
        wp_update_attachment_metadata($attachment_id, $attach_metadata);
        
        // FIXED: Store property_id in attachment metadata for easy lookup
        if (isset($image_data['property_id'])) {
            update_post_meta($attachment_id, '_property_id', intval($image_data['property_id']));
            update_post_meta($attachment_id, '_property_manager_image', 1);
        }
        
        // Store original feed URL if available
        if (isset($image_data['original_url'])) {
            update_post_meta($attachment_id, '_original_feed_url', esc_url_raw($image_data['original_url']));
        }
        
        // Store property reference if available
        if (isset($image_data['property_ref'])) {
            update_post_meta($attachment_id, '_property_ref', sanitize_text_field($image_data['property_ref']));
        }
        
        error_log('Property Manager: Attachment metadata saved for attachment ID ' . $attachment_id);
        
        return $attachment_id;
    }
    
    /**
     * Generate image title from property data
     */
    private function generate_image_title($image_data) {
        $title_parts = array();
        
        if (isset($image_data['property_title']) && !empty($image_data['property_title'])) {
            $title_parts[] = $image_data['property_title'];
        } elseif (isset($image_data['property_ref']) && !empty($image_data['property_ref'])) {
            $title_parts[] = 'Property ' . $image_data['property_ref'];
        } else {
            $title_parts[] = 'Property Image';
        }
        
        if (isset($image_data['sort_order'])) {
            $title_parts[] = 'Image ' . ($image_data['sort_order'] + 1);
        }
        
        return implode(' - ', $title_parts);
    }
    
    /**
     * Generate image alt text from property data
     */
    private function generate_image_alt($image_data) {
        $alt_parts = array();
        
        if (isset($image_data['property_type']) && !empty($image_data['property_type'])) {
            $alt_parts[] = $image_data['property_type'];
        }
        
        if (isset($image_data['property_title']) && !empty($image_data['property_title'])) {
            $alt_parts[] = $image_data['property_title'];
        }
        
        if (empty($alt_parts)) {
            return 'Property image';
        }
        
        return implode(' - ', $alt_parts);
    }
    
    /**
     * Process pending images via cron
     */
    public function process_pending_images_cron() {
        global $wpdb;
        
        $images_table = PropertyManager_Database::get_table_name('property_images');
        
        // Get pending images (limit to batch size)
        $pending_images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$images_table} 
             WHERE download_status = 'pending' 
             AND download_attempts < %d 
             ORDER BY created_at ASC 
             LIMIT %d",
            self::MAX_RETRIES,
            self::BATCH_SIZE
        ));
        
        if (empty($pending_images)) {
            return;
        }
        
        error_log('Property Manager: Processing ' . count($pending_images) . ' pending images');
        
        foreach ($pending_images as $image) {
            // Mark as downloading
            $wpdb->update(
                $images_table,
                array(
                    'download_status' => 'downloading',
                    'download_attempts' => $image->download_attempts + 1
                ),
                array('id' => $image->id),
                array('%s', '%d'),
                array('%d')
            );
            
            // Download image
            $image_data = array(
                'image_id' => $image->image_id,
                'sort_order' => $image->sort_order,
                'original_url' => $image->original_url
            );
            
            $attachment_id = $this->download_image($image->original_url, $image->property_id, $image_data);
            
            if ($attachment_id) {
                // Success - update record
                $attachment_url = wp_get_attachment_url($attachment_id);
                
                $wpdb->update(
                    $images_table,
                    array(
                        'attachment_id' => $attachment_id,
                        'image_url' => $attachment_url,
                        'download_status' => 'downloaded',
                        'error_message' => null
                    ),
                    array('id' => $image->id),
                    array('%d', '%s', '%s', '%s'),
                    array('%d')
                );
                
                error_log('Property Manager: Successfully processed image ID ' . $image->id);
            } else {
                // Failed - mark as failed if max retries reached
                $status = ($image->download_attempts + 1 >= self::MAX_RETRIES) ? 'failed' : 'pending';
                
                $wpdb->update(
                    $images_table,
                    array(
                        'download_status' => $status,
                        'error_message' => 'Download failed after ' . ($image->download_attempts + 1) . ' attempts'
                    ),
                    array('id' => $image->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                error_log('Property Manager: Failed to process image ID ' . $image->id);
            }
            
            // Small delay between downloads to avoid overwhelming the server
            usleep(self::PROCESSING_DELAY_MS);
        }
        
        error_log('Property Manager: Finished processing image batch');
    }
    
    /**
     * Get image processing statistics
     */
    public function get_processing_stats() {
        global $wpdb;
        
        $images_table = PropertyManager_Database::get_table_name('property_images');
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN download_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN download_status = 'downloading' THEN 1 ELSE 0 END) as downloading,
                SUM(CASE WHEN download_status = 'downloaded' THEN 1 ELSE 0 END) as downloaded,
                SUM(CASE WHEN download_status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$images_table}
        ", ARRAY_A);
        
        if (!$stats) {
            return array(
                'total' => 0,
                'pending' => 0,
                'downloading' => 0,
                'downloaded' => 0,
                'failed' => 0,
                'progress' => 0
            );
        }
        
        return array_merge($stats, array(
            'progress' => $stats['total'] > 0 ? round(($stats['downloaded'] / $stats['total']) * 100, 2) : 0
        ));
    }
    
    /**
     * Cleanup orphaned attachments (daily cron)
     */
    public function cleanup_orphaned_attachments() {
        global $wpdb;
        
        // Find attachments with _property_manager_image meta but no property record
        $orphaned = $wpdb->get_col("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->prefix}pm_property_images pi ON p.ID = pi.attachment_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_property_manager_image'
            AND pm.meta_value = '1'
            AND pi.id IS NULL
        ");
        
        if (!empty($orphaned)) {
            foreach ($orphaned as $attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }
            
            error_log('Property Manager: Cleaned up ' . count($orphaned) . ' orphaned attachments');
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
}