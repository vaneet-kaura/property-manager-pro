<?php
/**
 * Admin interface class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('wp_ajax_property_manager_import_feed', array($this, 'ajax_import_feed'));
        add_action('wp_ajax_property_manager_process_images', array($this, 'ajax_process_images'));
        add_action('wp_ajax_property_manager_retry_failed_images', array($this, 'ajax_retry_failed_images'));
        add_action('wp_ajax_property_manager_test_email', array($this, 'ajax_test_email'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Property Manager', 'property-manager-pro'),
            __('Properties', 'property-manager-pro'),
            'manage_options',
            'property-manager',
            array($this, 'dashboard_page'),
            'dashicons-building',
            30
        );
        
        add_submenu_page(
            'property-manager',
            __('Image Management', 'property-manager-pro'),
            __('Images', 'property-manager-pro'),
            'manage_options',
            'property-manager-images',
            array($this, 'images_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('Dashboard', 'property-manager-pro'),
            __('Dashboard', 'property-manager-pro'),
            'manage_options',
            'property-manager',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('All Properties', 'property-manager-pro'),
            __('All Properties', 'property-manager-pro'),
            'manage_options',
            'property-manager-properties',
            array($this, 'properties_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('Import Feed', 'property-manager-pro'),
            __('Import Feed', 'property-manager-pro'),
            'manage_options',
            'property-manager-import',
            array($this, 'import_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('Property Alerts', 'property-manager-pro'),
            __('Property Alerts', 'property-manager-pro'),
            'manage_options',
            'property-manager-alerts',
            array($this, 'alerts_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('Inquiries', 'property-manager-pro'),
            __('Inquiries', 'property-manager-pro'),
            'manage_options',
            'property-manager-inquiries',
            array($this, 'inquiries_page')
        );
        
        add_submenu_page(
            'property-manager',
            __('Settings', 'property-manager-pro'),
            __('Settings', 'property-manager-pro'),
            'manage_options',
            'property-manager-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('property_manager_settings', 'property_manager_options');
        
        // General Settings
        add_settings_section(
            'property_manager_general',
            __('General Settings', 'property-manager-pro'),
            null,
            'property-manager-settings'
        );
        
        add_settings_field(
            'immediate_image_download',
            __('Download Images Immediately', 'property-manager-pro'),
            array($this, 'field_immediate_image_download'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'feed_url',
            __('Kyero Feed URL', 'property-manager-pro'),
            array($this, 'field_feed_url'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'import_frequency',
            __('Import Frequency', 'property-manager-pro'),
            array($this, 'field_import_frequency'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'results_per_page',
            __('Results Per Page', 'property-manager-pro'),
            array($this, 'field_results_per_page'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        add_settings_field(
            'default_view',
            __('Default View', 'property-manager-pro'),
            array($this, 'field_default_view'),
            'property-manager-settings',
            'property_manager_general'
        );
        
        // Email Settings
        add_settings_section(
            'property_manager_email',
            __('Email Settings', 'property-manager-pro'),
            null,
            'property-manager-settings'
        );
        
        add_settings_field(
            'admin_email',
            __('Admin Email', 'property-manager-pro'),
            array($this, 'field_admin_email'),
            'property-manager-settings',
            'property_manager_email'
        );
        
        add_settings_field(
            'email_verification_required',
            __('Email Verification Required', 'property-manager-pro'),
            array($this, 'field_email_verification'),
            'property-manager-settings',
            'property_manager_email'
        );
        
        // Map Settings
        add_settings_section(
            'property_manager_map',
            __('Map Settings', 'property-manager-pro'),
            null,
            'property-manager-settings'
        );
        
        add_settings_field(
            'enable_map',
            __('Enable Map View', 'property-manager-pro'),
            array($this, 'field_enable_map'),
            'property-manager-settings',
            'property_manager_map'
        );
        
        add_settings_field(
            'map_provider',
            __('Map Provider', 'property-manager-pro'),
            array($this, 'field_map_provider'),
            'property-manager-settings',
            'property_manager_map'
        );
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $property_manager = PropertyManager_Property::get_instance();
        $stats = $property_manager->get_property_stats();
        
        $importer = PropertyManager_FeedImporter::get_instance();
        $import_logs = $importer->get_import_stats(5);
        
        $image_downloader = PropertyManager_ImageDownloader::get_instance();
        $image_stats = $image_downloader->get_image_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Manager Dashboard', 'property-manager-pro'); ?></h1>
            
            <div class="property-manager-dashboard">
                <div class="dashboard-widgets">
                    <div class="dashboard-widget">
                        <h3><?php _e('Property Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                                <div class="stat-label"><?php _e('Total Properties', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo $stats['avg_price'] ? '€' . number_format($stats['avg_price']) : 'N/A'; ?></div>
                                <div class="stat-label"><?php _e('Average Price', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($stats['by_type'])): ?>
                        <h4><?php _e('Properties by Type', 'property-manager-pro'); ?></h4>
                        <table class="widefat">
                            <?php foreach ($stats['by_type'] as $type_stat): ?>
                            <tr>
                                <td><?php echo esc_html($type_stat->type); ?></td>
                                <td><?php echo number_format($type_stat->count); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Image Statistics', 'property-manager-pro'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['total']); ?></div>
                                <div class="stat-label"><?php _e('Total Images', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['downloaded']); ?></div>
                                <div class="stat-label"><?php _e('Downloaded', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['pending']); ?></div>
                                <div class="stat-label"><?php _e('Pending', 'property-manager-pro'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo number_format($image_stats['failed']); ?></div>
                                <div class="stat-label"><?php _e('Failed', 'property-manager-pro'); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($image_stats['pending'] > 0 || $image_stats['failed'] > 0): ?>
                        <div class="image-actions" style="margin-top: 15px;">
                            <?php if ($image_stats['pending'] > 0): ?>
                            <button type="button" class="button button-secondary" id="process-images-btn">
                                <?php _e('Process Pending Images', 'property-manager-pro'); ?>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($image_stats['failed'] > 0): ?>
                            <button type="button" class="button button-secondary" id="retry-failed-images-btn">
                                <?php _e('Retry Failed Images', 'property-manager-pro'); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Recent Import Logs', 'property-manager-pro'); ?></h3>
                        <?php if (!empty($import_logs)): ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'property-manager-pro'); ?></th>
                                    <th><?php _e('Status', 'property-manager-pro'); ?></th>
                                    <th><?php _e('Results', 'property-manager-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($import_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log->started_at)); ?></td>
                                    <td>
                                        <span class="status-<?php echo $log->status; ?>">
                                            <?php echo ucfirst($log->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log->status === 'completed'): ?>
                                            <?php printf(
                                                __('Imported: %d, Updated: %d, Failed: %d', 'property-manager-pro'),
                                                $log->properties_imported,
                                                $log->properties_updated,
                                                $log->properties_failed
                                            ); ?>
                                        <?php elseif ($log->error_message): ?>
                                            <?php echo esc_html($log->error_message); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p><?php _e('No import logs found.', 'property-manager-pro'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Process pending images
            $('#process-images-btn').click(function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Processing...');
                
                $.post(ajaxurl, {
                    action: 'property_manager_process_images',
                    nonce: '<?php echo wp_create_nonce('property_manager_images'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false).text('Process Pending Images');
                    if (response.success) {
                        alert('Images processed: ' + response.data.processed);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
            
            // Retry failed images
            $('#retry-failed-images-btn').click(function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Retrying...');
                
                $.post(ajaxurl, {
                    action: 'property_manager_retry_failed_images',
                    nonce: '<?php echo wp_create_nonce('property_manager_images'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false).text('Retry Failed Images');
                    if (response.success) {
                        alert('Failed images queued for retry: ' + response.data.retried);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        
        <style>
        .property-manager-dashboard {
            margin-top: 20px;
        }
        .dashboard-widgets {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .dashboard-widget {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
        }
        .dashboard-widget h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            text-align: center;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        .status-completed { color: #46b450; }
        .status-failed { color: #dc3232; }
        .status-started { color: #ffb900; }
        .image-actions button {
            margin-right: 10px;
        }
        </style>
        <?php
    }
    
    /**
     * Properties page
     */
    public function properties_page() {
        $property_manager = PropertyManager_Property::get_instance();
        
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['properties'])) {
            $this->handle_bulk_delete($_POST['properties']);
        }
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        // Search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        $search_args = array(
            'page' => $page,
            'per_page' => $per_page,
            'orderby' => 'updated_at',
            'order' => 'DESC'
        );
        
        if (!empty($search)) {
            $search_args['keyword'] = $search;
        }
        
        $results = $property_manager->search_properties($search_args);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Properties', 'property-manager-pro'); ?></h1>
            
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="property-manager-properties">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search properties...', 'property-manager-pro'); ?>">
                    <input type="submit" class="button" value="<?php _e('Search', 'property-manager-pro'); ?>">
                </p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('property_manager_bulk_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value=""><?php _e('Bulk Actions', 'property-manager-pro'); ?></option>
                            <option value="bulk_delete"><?php _e('Delete', 'property-manager-pro'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php _e('Apply', 'property-manager-pro'); ?>">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <th><?php _e('Title', 'property-manager-pro'); ?></th>
                            <th><?php _e('Type', 'property-manager-pro'); ?></th>
                            <th><?php _e('Location', 'property-manager-pro'); ?></th>
                            <th><?php _e('Price', 'property-manager-pro'); ?></th>
                            <th><?php _e('Beds/Baths', 'property-manager-pro'); ?></th>
                            <th><?php _e('Status', 'property-manager-pro'); ?></th>
                            <th><?php _e('Updated', 'property-manager-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results['properties'])): ?>
                            <?php foreach ($results['properties'] as $property): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="properties[]" value="<?php echo $property->id; ?>">
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . $property->id); ?>">
                                            <?php echo esc_html($property->title ?: $property->ref); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo admin_url('admin.php?page=property-manager-properties&action=edit&property_id=' . $property->id); ?>">
                                                <?php _e('Edit', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="view">
                                            <a href="<?php echo $property_manager->get_property_url($property); ?>" target="_blank">
                                                <?php _e('View', 'property-manager-pro'); ?>
                                            </a>
                                        </span> |
                                        <span class="delete">
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=property-manager-properties&action=delete&property_id=' . $property->id), 'delete_property'); ?>" 
                                               onclick="return confirm('<?php _e('Are you sure you want to delete this property?', 'property-manager-pro'); ?>')">
                                                <?php _e('Delete', 'property-manager-pro'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($property->type); ?></td>
                                <td><?php echo esc_html($property->town . ', ' . $property->province); ?></td>
                                <td><?php echo $property_manager->format_price($property->price); ?></td>
                                <td><?php echo $property->beds . '/' . $property->baths; ?></td>
                                <td>
                                    <span class="status-<?php echo $property->status; ?>">
                                        <?php echo ucfirst($property->status); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($property->updated_at)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8"><?php _e('No properties found.', 'property-manager-pro'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php
                // Pagination
                if ($results['pages'] > 1) {
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $results['pages'],
                        'current' => $page
                    );
                    
                    echo '<div class="tablenav bottom">';
                    echo '<div class="tablenav-pages">';
                    echo paginate_links($pagination_args);
                    echo '</div>';
                    echo '</div>';
                }
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Import page
     */
    public function import_page() {
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
            
            <script>
            jQuery(document).ready(function($) {
                $('#manual-import-btn').click(function() {
                    var $btn = $(this);
                    var $progress = $('#import-progress');
                    var $results = $('#import-results');
                    
                    $btn.prop('disabled', true);
                    $progress.show();
                    $results.hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'property_manager_import_feed',
                            nonce: '<?php echo wp_create_nonce('property_manager_import'); ?>'
                        },
                        success: function(response) {
                            $progress.hide();
                            $btn.prop('disabled', false);
                            
                            if (response.success) {
                                $results.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
                            } else {
                                $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
                            }
                        },
                        error: function() {
                            $progress.hide();
                            $btn.prop('disabled', false);
                            $results.html('<div class="notice notice-error"><p>Import failed. Please try again.</p></div>').show();
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Alerts page
     */
    public function alerts_page() {
        global $wpdb;
        
        $alerts_table = PropertyManager_Database::get_table_name('property_alerts');
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get alerts
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $alerts_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_alerts = $wpdb->get_var("SELECT COUNT(*) FROM $alerts_table");
        $total_pages = ceil($total_alerts / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Alerts', 'property-manager-pro'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Email', 'property-manager-pro'); ?></th>
                        <th><?php _e('Frequency', 'property-manager-pro'); ?></th>
                        <th><?php _e('Status', 'property-manager-pro'); ?></th>
                        <th><?php _e('Verified', 'property-manager-pro'); ?></th>
                        <th><?php _e('Last Sent', 'property-manager-pro'); ?></th>
                        <th><?php _e('Created', 'property-manager-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($alerts)): ?>
                        <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td><?php echo esc_html($alert->email); ?></td>
                            <td><?php echo ucfirst($alert->frequency); ?></td>
                            <td>
                                <span class="status-<?php echo $alert->status; ?>">
                                    <?php echo ucfirst($alert->status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($alert->email_verified): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-no-alt" style="color: red;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $alert->last_sent ? date('Y-m-d H:i', strtotime($alert->last_sent)) : __('Never', 'property-manager-pro'); ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($alert->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6"><?php _e('No property alerts found.', 'property-manager-pro'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Inquiries page
     */
    public function inquiries_page() {
        global $wpdb;
        
        $inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Handle status update
        if (isset($_POST['update_status']) && isset($_POST['inquiry_id']) && isset($_POST['status'])) {
            $inquiry_id = intval($_POST['inquiry_id']);
            $status = sanitize_text_field($_POST['status']);
            
            $wpdb->update(
                $inquiries_table,
                array('status' => $status, 'updated_at' => current_time('mysql')),
                array('id' => $inquiry_id)
            );
            
            echo '<div class="notice notice-success"><p>' . __('Inquiry status updated.', 'property-manager-pro') . '</p></div>';
        }
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get inquiries
        $inquiries = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.title as property_title, p.ref as property_ref 
             FROM $inquiries_table i 
             LEFT JOIN $properties_table p ON i.property_id = p.id 
             ORDER BY i.created_at DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_inquiries = $wpdb->get_var("SELECT COUNT(*) FROM $inquiries_table");
        $total_pages = ceil($total_inquiries / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Property Inquiries', 'property-manager-pro'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Property', 'property-manager-pro'); ?></th>
                        <th><?php _e('Contact', 'property-manager-pro'); ?></th>
                        <th><?php _e('Message', 'property-manager-pro'); ?></th>
                        <th><?php _e('Status', 'property-manager-pro'); ?></th>
                        <th><?php _e('Date', 'property-manager-pro'); ?></th>
                        <th><?php _e('Actions', 'property-manager-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inquiries)): ?>
                        <?php foreach ($inquiries as $inquiry): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($inquiry->property_title ?: $inquiry->property_ref); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo esc_html($inquiry->name); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr($inquiry->email); ?>"><?php echo esc_html($inquiry->email); ?></a>
                                <?php if ($inquiry->phone): ?>
                                    <br><?php echo esc_html($inquiry->phone); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html(wp_trim_words($inquiry->message, 20)); ?>
                                <?php if (strlen($inquiry->message) > 100): ?>
                                    <br><a href="#" class="show-full-message"><?php _e('Show full message', 'property-manager-pro'); ?></a>
                                    <div class="full-message" style="display:none;">
                                        <?php echo nl2br(esc_html($inquiry->message)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-<?php echo $inquiry->status; ?>">
                                    <?php echo ucfirst($inquiry->status); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($inquiry->created_at)); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="inquiry_id" value="<?php echo $inquiry->id; ?>">
                                    <select name="status">
                                        <option value="new" <?php selected($inquiry->status, 'new'); ?>><?php _e('New', 'property-manager-pro'); ?></option>
                                        <option value="read" <?php selected($inquiry->status, 'read'); ?>><?php _e('Read', 'property-manager-pro'); ?></option>
                                        <option value="replied" <?php selected($inquiry->status, 'replied'); ?>><?php _e('Replied', 'property-manager-pro'); ?></option>
                                    </select>
                                    <input type="submit" name="update_status" class="button button-small" value="<?php _e('Update', 'property-manager-pro'); ?>">
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6"><?php _e('No inquiries found.', 'property-manager-pro'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <script>
            jQuery(document).ready(function($) {
                $('.show-full-message').click(function(e) {
                    e.preventDefault();
                    $(this).hide().siblings('.full-message').show();
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Property Manager Settings', 'property-manager-pro'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('property_manager_settings');
                do_settings_sections('property-manager-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Settings field callbacks
     */
    public function field_feed_url() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['feed_url']) ? $options['feed_url'] : '';
        
        echo '<input type="url" name="property_manager_options[feed_url]" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">' . __('Enter the URL to your Kyero XML feed.', 'property-manager-pro') . '</p>';
    }
    
    public function field_import_frequency() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['import_frequency']) ? $options['import_frequency'] : 'hourly';
        
        $frequencies = array(
            'hourly' => __('Hourly', 'property-manager-pro'),
            'twicedaily' => __('Twice Daily', 'property-manager-pro'),
            'daily' => __('Daily', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[import_frequency]">';
        foreach ($frequencies as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function field_results_per_page() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['results_per_page']) ? $options['results_per_page'] : 20;
        
        echo '<input type="number" name="property_manager_options[results_per_page]" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<p class="description">' . __('Number of properties to show per page in search results.', 'property-manager-pro') . '</p>';
    }
    
    public function field_default_view() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['default_view']) ? $options['default_view'] : 'grid';
        
        $views = array(
            'grid' => __('Grid View', 'property-manager-pro'),
            'list' => __('List View', 'property-manager-pro'),
            'map' => __('Map View', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[default_view]">';
        foreach ($views as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function field_admin_email() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['admin_email']) ? $options['admin_email'] : get_option('admin_email');
        
        echo '<input type="email" name="property_manager_options[admin_email]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Email address for receiving property inquiries.', 'property-manager-pro') . '</p>';
    }
    
    public function field_email_verification() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['email_verification_required']) ? $options['email_verification_required'] : true;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[email_verification_required]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Require email verification before sending property alerts', 'property-manager-pro');
        echo '</label>';
    }
    
    public function field_enable_map() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['enable_map']) ? $options['enable_map'] : true;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[enable_map]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Enable map view for properties', 'property-manager-pro');
        echo '</label>';
    }
    
    public function field_immediate_image_download() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['immediate_image_download']) ? $options['immediate_image_download'] : false;
        
        echo '<label>';
        echo '<input type="checkbox" name="property_manager_options[immediate_image_download]" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Download property images immediately during import (may slow down import process)', 'property-manager-pro');
        echo '</label>';
        echo '<p class="description">' . __('If unchecked, images will be processed in background via cron jobs.', 'property-manager-pro') . '</p>';
    }
    
    public function field_map_provider() {
        $options = get_option('property_manager_options', array());
        $value = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';
        
        $providers = array(
            'openstreetmap' => __('OpenStreetMap (Free)', 'property-manager-pro'),
            'mapbox' => __('Mapbox', 'property-manager-pro')
        );
        
        echo '<select name="property_manager_options[map_provider]">';
        foreach ($providers as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
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
    
    /**
     * Handle bulk delete
     */
    private function handle_bulk_delete($property_ids) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'property_manager_bulk_action')) {
            wp_die(__('Security check failed.', 'property-manager-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
        }
        
        $deleted = 0;
        foreach ($property_ids as $property_id) {
            $result = PropertyManager_Database::delete_property(intval($property_id));
            if ($result) {
                $deleted++;
            }
        }
        
        add_action('admin_notices', function() use ($deleted) {
            echo '<div class="notice notice-success"><p>';
            printf(__('%d properties deleted successfully.', 'property-manager-pro'), $deleted);
            echo '</p></div>';
        });
    }
}