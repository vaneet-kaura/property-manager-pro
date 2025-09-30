<?php
/**
 * Admin Import Page
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin_Import {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_property_manager_import_feed', array($this, 'ajax_import_feed'));
    }
    
    /**
     * Render import page
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php _e('Import Properties', 'property-manager-pro'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Kyero Feed Import', 'property-manager-pro'); ?></h2>
                <p><?php _e('Import properties from your configured Kyero XML feed.', 'property-manager-pro'); ?></p>
                
                <button type="button" class="button button-primary" id="manual-import-btn">
                    <?php _e('Import Now', 'property-manager-pro'); ?>
                </button>
                
                <div id="import-progress" style="display:none;">
                    <p><?php _e('Import in progress...', 'property-manager-pro'); ?></p>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                </div>
                
                <div id="import-results" style="display:none;"></div>
            </div>
        </div>
        <script type="text/javascript">
            ajax_nonce = '<?php echo wp_create_nonce('property_manager_import'); ?>'
        </script>
        <?php
    }


    /**
     * AJAX handlers
     */
    public function ajax_import_feed() {
        check_ajax_referer('property_manager_import', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $importer = PropertyManager_FeedImporter::get_instance();
        $result = $importer->manual_import();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Import completed successfully. Imported: %d, Updated: %d, Failed: %d', 'property-manager-pro'),
                    $result['imported'],
                    $result['updated'],
                    $result['failed']
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Import failed. Please check the error logs.', 'property-manager-pro')
            ));
        }
    }
}