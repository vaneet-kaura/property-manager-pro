<?php
/**
 * Main Admin interface class
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
		
		add_submenu_page('property-manager', __('Image Management', 'property-manager-pro'), __('Images', 'property-manager-pro'), 'manage_options', 'property-manager-images', array($this, 'images_page'));
		add_submenu_page('property-manager', __('Dashboard', 'property-manager-pro'), __('Dashboard', 'property-manager-pro'), 'manage_options', 'property-manager', array($this, 'dashboard_page'));
		add_submenu_page('property-manager', __('All Properties', 'property-manager-pro'), __('All Properties', 'property-manager-pro'), 'manage_options', 'property-manager-properties', array($this, 'properties_page'));
		add_submenu_page('property-manager', __('Import Feed', 'property-manager-pro'), __('Import Feed', 'property-manager-pro'), 'manage_options', 'property-manager-import', array($this, 'import_page'));
		add_submenu_page('property-manager', __('Property Alerts', 'property-manager-pro'), __('Property Alerts', 'property-manager-pro'), 'manage_options', 'property-manager-alerts', array($this, 'alerts_page'));
		add_submenu_page('property-manager', __('Inquiries', 'property-manager-pro'), __('Inquiries', 'property-manager-pro'), 'manage_options', 'property-manager-inquiries', array($this, 'inquiries_page'));
		add_submenu_page('property-manager', __('Settings', 'property-manager-pro'), __('Settings', 'property-manager-pro'), 'manage_options', 'property-manager-settings', array($this, 'settings_page'));
	}

	public function init_settings() {
		register_setting('property_manager_settings', 'property_manager_options', array($this, 'sanitize_options'));
		
		add_settings_section('property_manager_general', __('General Settings', 'property-manager-pro'), null, 'property-manager-settings');
		add_settings_field('immediate_image_download', __('Download Images Immediately', 'property-manager-pro'), array($this, 'field_immediate_image_download'), 'property-manager-settings', 'property_manager_general');
		add_settings_field('feed_url', __('Kyero Feed URL', 'property-manager-pro'), array($this, 'field_feed_url'), 'property-manager-settings', 'property_manager_general');
		add_settings_field('import_frequency', __('Import Frequency', 'property-manager-pro'), array($this, 'field_import_frequency'), 'property-manager-settings', 'property_manager_general');
		add_settings_field('results_per_page', __('Results Per Page', 'property-manager-pro'), array($this, 'field_results_per_page'), 'property-manager-settings', 'property_manager_general');
		add_settings_field('default_view', __('Default View', 'property-manager-pro'), array($this, 'field_default_view'), 'property-manager-settings', 'property_manager_general');
		
		add_settings_section('property_manager_email', __('Email Settings', 'property-manager-pro'), null, 'property-manager-settings');
		add_settings_field('admin_email', __('Admin Email', 'property-manager-pro'), array($this, 'field_admin_email'), 'property-manager-settings', 'property_manager_email');
		add_settings_field('email_verification_required', __('Email Verification Required', 'property-manager-pro'), array($this, 'field_email_verification'), 'property-manager-settings', 'property_manager_email');
		
		add_settings_section('property_manager_map', __('Map Settings', 'property-manager-pro'), null, 'property-manager-settings');
		add_settings_field('enable_map', __('Enable Map View', 'property-manager-pro'), array($this, 'field_enable_map'), 'property-manager-settings', 'property_manager_map');
		add_settings_field('map_provider', __('Map Provider', 'property-manager-pro'), array($this, 'field_map_provider'), 'property-manager-settings', 'property_manager_map');
	}

	public function sanitize_options($input) {
		$sanitized = array();
		
		if (isset($input['feed_url'])) {
			$sanitized['feed_url'] = esc_url_raw($input['feed_url']);
		}
		
		if (isset($input['import_frequency'])) {
			$allowed = array('hourly', 'twicedaily', 'daily');
			$sanitized['import_frequency'] = in_array($input['import_frequency'], $allowed) ? $input['import_frequency'] : 'hourly';
		}
		
		if (isset($input['results_per_page'])) {
			$sanitized['results_per_page'] = max(1, min(100, absint($input['results_per_page'])));
		}
		
		if (isset($input['default_view'])) {
			$allowed = array('grid', 'list', 'map');
			$sanitized['default_view'] = in_array($input['default_view'], $allowed) ? $input['default_view'] : 'grid';
		}
		
		if (isset($input['admin_email'])) {
			$sanitized['admin_email'] = sanitize_email($input['admin_email']);
		}
		
		$sanitized['email_verification_required'] = isset($input['email_verification_required']) ? 1 : 0;
		$sanitized['enable_map'] = isset($input['enable_map']) ? 1 : 0;
		$sanitized['immediate_image_download'] = isset($input['immediate_image_download']) ? 1 : 0;
		
		if (isset($input['map_provider'])) {
			$allowed = array('openstreetmap', 'mapbox');
			$sanitized['map_provider'] = in_array($input['map_provider'], $allowed) ? $input['map_provider'] : 'openstreetmap';
		}
		
		return $sanitized;
	}

	public function dashboard_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		$property_manager = PropertyManager_Property::get_instance();
		$stats = $property_manager->get_property_stats();
		$importer = PropertyManager_FeedImporter::get_instance();
		$import_logs = $importer->get_import_stats(5);
		$image_downloader = PropertyManager_ImageDownloader::get_instance();
		$image_stats = $image_downloader->get_image_stats();
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/dashboard.php';
	}

	public function properties_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		$property_manager = PropertyManager_Property::get_instance();
		
		if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['properties'])) {
			check_admin_referer('property_manager_bulk_action');
			$this->handle_bulk_delete($_POST['properties']);
		}
		
		$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$per_page = 20;
		$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		
		$search_args = array('page' => $page, 'per_page' => $per_page, 'orderby' => 'updated_at', 'order' => 'DESC');
		if (!empty($search)) {
			$search_args['keyword'] = $search;
		}
		
		$results = $property_manager->search_properties($search_args);
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/properties.php';
	}

	public function import_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/import.php';
	}

	public function alerts_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		global $wpdb;
		$alerts_table = PropertyManager_Database::get_table_name('property_alerts');
		$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$per_page = 20;
		$offset = ($page - 1) * $per_page;
		
		$alerts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $alerts_table ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
		$total_alerts = $wpdb->get_var("SELECT COUNT(*) FROM $alerts_table");
		$total_pages = ceil($total_alerts / $per_page);
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/alerts.php';
	}

	public function inquiries_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		global $wpdb;
		$inquiries_table = PropertyManager_Database::get_table_name('property_inquiries');
		$properties_table = PropertyManager_Database::get_table_name('properties');
		
		if (isset($_POST['update_status']) && isset($_POST['inquiry_id']) && isset($_POST['status'])) {
			check_admin_referer('update_inquiry_status');
			$inquiry_id = absint($_POST['inquiry_id']);
			$status = sanitize_text_field($_POST['status']);
			$allowed = array('new', 'read', 'replied');
			if (in_array($status, $allowed)) {
				$wpdb->update($inquiries_table, array('status' => $status, 'updated_at' => current_time('mysql')), array('id' => $inquiry_id), array('%s', '%s'), array('%d'));
				echo '<div class="notice notice-success"><p>' . esc_html__('Inquiry status updated.', 'property-manager-pro') . '</p></div>';
			}
		}
		
		$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$per_page = 20;
		$offset = ($page - 1) * $per_page;
		
		$inquiries = $wpdb->get_results($wpdb->prepare("SELECT i.*, p.title as property_title, p.ref as property_ref FROM $inquiries_table i LEFT JOIN $properties_table p ON i.property_id = p.id ORDER BY i.created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
		$total_inquiries = $wpdb->get_var("SELECT COUNT(*) FROM $inquiries_table");
		$total_pages = ceil($total_inquiries / $per_page);
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/inquiries.php';
	}

	public function settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		?>
		<div class="wrap">
			<h1><?php _e('Property Manager Settings', 'property-manager-pro'); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('property_manager_settings'); ?>
				<?php do_settings_sections('property-manager-settings'); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function images_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions.', 'property-manager-pro'));
		}
		
		$image_downloader = PropertyManager_ImageDownloader::get_instance();
		$image_stats = $image_downloader->get_image_stats();
		
		include PROPERTY_MANAGER_PLUGIN_PATH . 'admin/views/images.php';
	}

	public function field_feed_url() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['feed_url']) ? $options['feed_url'] : '';
		echo '<input type="url" name="property_manager_options[feed_url]" value="' . esc_attr($value) . '" class="large-text" />';
		echo '<p class="description">' . esc_html__('Enter the URL to your Kyero XML feed.', 'property-manager-pro') . '</p>';
	}

	public function field_import_frequency() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['import_frequency']) ? $options['import_frequency'] : 'hourly';
		$frequencies = array('hourly' => __('Hourly', 'property-manager-pro'), 'twicedaily' => __('Twice Daily', 'property-manager-pro'), 'daily' => __('Daily', 'property-manager-pro'));
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
		echo '<p class="description">' . esc_html__('Number of properties per page.', 'property-manager-pro') . '</p>';
	}

	public function field_default_view() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['default_view']) ? $options['default_view'] : 'grid';
		$views = array('grid' => __('Grid View', 'property-manager-pro'), 'list' => __('List View', 'property-manager-pro'), 'map' => __('Map View', 'property-manager-pro'));
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
		echo '<p class="description">' . esc_html__('Email for property inquiries.', 'property-manager-pro') . '</p>';
	}

	public function field_email_verification() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['email_verification_required']) ? $options['email_verification_required'] : true;
		echo '<label><input type="checkbox" name="property_manager_options[email_verification_required]" value="1" ' . checked($value, true, false) . ' /> ' . esc_html__('Require email verification', 'property-manager-pro') . '</label>';
	}

	public function field_enable_map() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['enable_map']) ? $options['enable_map'] : true;
		echo '<label><input type="checkbox" name="property_manager_options[enable_map]" value="1" ' . checked($value, true, false) . ' /> ' . esc_html__('Enable map view', 'property-manager-pro') . '</label>';
	}

	public function field_immediate_image_download() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['immediate_image_download']) ? $options['immediate_image_download'] : false;
		echo '<label><input type="checkbox" name="property_manager_options[immediate_image_download]" value="1" ' . checked($value, true, false) . ' /> ' . esc_html__('Download images immediately', 'property-manager-pro') . '</label>';
		echo '<p class="description">' . esc_html__('Images will be processed via cron if unchecked.', 'property-manager-pro') . '</p>';
	}

	public function field_map_provider() {
		$options = get_option('property_manager_options', array());
		$value = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';
		$providers = array('openstreetmap' => __('OpenStreetMap (Free)', 'property-manager-pro'), 'mapbox' => __('Mapbox', 'property-manager-pro'));
		echo '<select name="property_manager_options[map_provider]">';
		foreach ($providers as $key => $label) {
			echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
	}

	public function ajax_import_feed() {
		check_ajax_referer('property_manager_import', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
		}
		
		$importer = PropertyManager_FeedImporter::get_instance();
		$result = $importer->manual_import();
		
		if ($result) {
			wp_send_json_success(array('message' => sprintf(__('Import completed. Imported: %d, Updated: %d, Failed: %d', 'property-manager-pro'), $result['imported'], $result['updated'], $result['failed'])));
		} else {
			wp_send_json_error(array('message' => __('Import failed.', 'property-manager-pro')));
		}
	}

	public function ajax_process_images() {
		check_ajax_referer('property_manager_images', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
		}
		
		$image_downloader = PropertyManager_ImageDownloader::get_instance();
		$processed = $image_downloader->process_pending_images(20);
		wp_send_json_success(array('processed' => $processed, 'message' => sprintf(__('Processed %d images', 'property-manager-pro'), $processed)));
	}

	public function ajax_retry_failed_images() {
		check_ajax_referer('property_manager_images', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
		}
		
		$image_downloader = PropertyManager_ImageDownloader::get_instance();
		$retried = $image_downloader->retry_failed_downloads(20);
		wp_send_json_success(array('retried' => $retried, 'message' => sprintf(__('Queued %d failed images', 'property-manager-pro'), $retried)));
	}

	public function ajax_test_email() {
		check_ajax_referer('property_manager_test_email', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'property-manager-pro')));
		}
		
		$email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
		if (!is_email($email)) {
			wp_send_json_error(array('message' => __('Invalid email.', 'property-manager-pro')));
		}
		
		$result = wp_mail($email, __('Test Email', 'property-manager-pro'), __('This is a test email.', 'property-manager-pro'));
		
		if ($result) {
			wp_send_json_success(array('message' => __('Test email sent!', 'property-manager-pro')));
		} else {
			wp_send_json_error(array('message' => __('Failed to send.', 'property-manager-pro')));
		}
	}

	private function handle_bulk_delete($property_ids) {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions.', 'property-manager-pro'));
		}
		
		if (!is_array($property_ids)) {
			return;
		}
		
		$deleted = 0;
		foreach ($property_ids as $property_id) {
			$property_id = absint($property_id);
			if ($property_id > 0 && PropertyManager_Database::delete_property($property_id)) {
				$deleted++;
			}
		}
		
		add_action('admin_notices', function() use ($deleted) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d properties deleted.', 'property-manager-pro'), $deleted) . '</p></div>';
		});
	}	
}