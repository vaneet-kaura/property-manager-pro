<?php
/**
 * AJAX Search Handlers
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_AjaxSearch {
    
    private static $instance = null;

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('wp_ajax_get_towns_by_province', array($this, 'get_towns_by_province'));
		add_action('wp_ajax_nopriv_get_towns_by_province', array($this, 'get_towns_by_province'));
		
		add_action('wp_ajax_save_search_alert', array($this, 'save_search_alert'));
		add_action('wp_ajax_nopriv_save_search_alert', array($this, 'save_search_alert'));
		
		add_action('wp_ajax_load_more_properties', array($this, 'load_more_properties'));
		add_action('wp_ajax_nopriv_load_more_properties', array($this, 'load_more_properties'));
		
		add_action('wp_ajax_get_property_details', array($this, 'get_property_details'));
		add_action('wp_ajax_nopriv_get_property_details', array($this, 'get_property_details'));
	}

	public function get_towns_by_province() {
		if (!check_ajax_referer('property_manager_nonce', 'nonce', false)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'property-manager-pro')));
		}
		
		$province = isset($_POST['province']) ? sanitize_text_field($_POST['province']) : '';
		
		if (empty($province)) {
			wp_send_json_error(array('message' => __('Province is required.', 'property-manager-pro')));
		}
		
		$property_manager = PropertyManager_Property::get_instance();
		$towns = $property_manager->get_towns($province);
		
		wp_send_json_success(array(
			'towns' => $towns,
			'message' => sprintf(__('Found %d towns in %s', 'property-manager-pro'), count($towns), $province)
		));
	}

	public function save_search_alert() {
		if (!isset($_POST['search_alert_nonce']) || !wp_verify_nonce($_POST['search_alert_nonce'], 'save_search_alert')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'property-manager-pro')));
		}
		
		$alert_name = isset($_POST['alert_name']) ? sanitize_text_field($_POST['alert_name']) : '';
		$alert_email = isset($_POST['alert_email']) ? sanitize_email($_POST['alert_email']) : '';
		$alert_frequency = isset($_POST['alert_frequency']) ? sanitize_text_field($_POST['alert_frequency']) : 'weekly';
		$search_criteria = isset($_POST['search_criteria']) ? wp_unslash($_POST['search_criteria']) : '';
		
		if (empty($alert_name) || empty($alert_email) || empty($search_criteria)) {
			wp_send_json_error(array('message' => __('Please fill in all required fields.', 'property-manager-pro')));
		}
		
		if (!is_email($alert_email)) {
			wp_send_json_error(array('message' => __('Please enter a valid email address.', 'property-manager-pro')));
		}
		
		$valid_frequencies = array('daily', 'weekly', 'monthly');
		if (!in_array($alert_frequency, $valid_frequencies)) {
			$alert_frequency = 'weekly';
		}
		
		$criteria = json_decode($search_criteria, true);
		if (!is_array($criteria)) {
			wp_send_json_error(array('message' => __('Invalid search criteria.', 'property-manager-pro')));
		}
		
		$criteria = $this->sanitize_search_criteria($criteria);
		
		global $wpdb;
		$alerts_table = PropertyManager_Database::get_table_name('property_alerts');
		
		$existing_alert = $wpdb->get_row($wpdb->prepare(
			"SELECT id FROM $alerts_table WHERE email = %s AND search_criteria = %s",
			$alert_email,
			wp_json_encode($criteria)
		));
		
		if ($existing_alert) {
			wp_send_json_error(array('message' => __('You already have an alert set up for this search.', 'property-manager-pro')));
		}
		
		$verification_token = wp_generate_password(32, false);
		$unsubscribe_token = wp_generate_password(32, false);
		
		$result = $wpdb->insert($alerts_table, array(
			'email' => $alert_email,
			'user_id' => is_user_logged_in() ? get_current_user_id() : null,
			'search_criteria' => wp_json_encode($criteria),
			'frequency' => $alert_frequency,
			'status' => 'active',
			'verification_token' => $verification_token,
			'email_verified' => 0,
			'unsubscribe_token' => $unsubscribe_token,
			'created_at' => current_time('mysql')
		), array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
		
		if ($result === false) {
			wp_send_json_error(array('message' => __('Failed to save search alert. Please try again.', 'property-manager-pro')));
		}
		
		$alert_id = $wpdb->insert_id;
		$this->send_verification_email($alert_id, $alert_email, $alert_name, $verification_token);
		
		wp_send_json_success(array(
			'message' => __('Search alert saved successfully! Please check your email to verify your subscription.', 'property-manager-pro'),
			'alert_id' => $alert_id
		));
	}

	private function sanitize_search_criteria($criteria) {
		$sanitized = array();
		$allowed_keys = array(
			'keyword', 'location', 'property_type', 'price_min', 'price_max',
			'beds_min', 'beds_max', 'baths_min', 'baths_max',
			'province', 'town', 'surface_min', 'surface_max',
			'pool', 'new_build', 'featured', 'price_freq', 'currency'
		);
		
		foreach ($criteria as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				if (in_array($key, array('price_min', 'price_max', 'surface_min', 'surface_max'))) {
					$sanitized[$key] = floatval($value);
				} elseif (in_array($key, array('beds_min', 'beds_max', 'baths_min', 'baths_max', 'pool', 'new_build', 'featured'))) {
					$sanitized[$key] = intval($value);
				} else {
					$sanitized[$key] = sanitize_text_field($value);
				}
			}
		}
		
		return $sanitized;
	}

	public function load_more_properties() {
		$transient_key = 'property_load_more_' . $this->get_client_ip();
		$request_count = get_transient($transient_key);
		
		if ($request_count && $request_count > 30) {
			wp_send_json_error(array('message' => __('Too many requests. Please try again later.', 'property-manager-pro')));
		}
		
		set_transient($transient_key, ($request_count ? $request_count + 1 : 1), MINUTE_IN_SECONDS);
		
		$page = isset($_POST['page']) ? absint($_POST['page']) : 1;
		$search_params = isset($_POST['search_params']) && is_array($_POST['search_params']) ? $_POST['search_params'] : array();
		
		$search_params = $this->sanitize_search_criteria($search_params);
		
		if ($page < 1 || $page > 100) {
			$page = 1;
		}
		
		$search = PropertyManager_Search::get_instance();
		foreach ($search_params as $key => $value) {
			$search->set_param($key, $value);
		}
		$search->set_param('page', $page);
		
		$results = $search->search_properties();
		
		if (empty($results['properties'])) {
			wp_send_json_success(array(
				'html' => '',
				'has_more' => false,
				'message' => __('No more properties found.', 'property-manager-pro')
			));
		}
		
		ob_start();
		foreach ($results['properties'] as $property) {
			$this->render_property_card($property);
		}
		$html = ob_get_clean();
		
		wp_send_json_success(array(
			'html' => $html,
			'has_more' => ($results['current_page'] < $results['pages']),
			'current_page' => $results['current_page'],
			'total_pages' => $results['pages'],
			'total_results' => $results['total']
		));
	}

	public function get_property_details() {
		$property_id = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;
		
		if (!$property_id) {
			wp_send_json_error(array('message' => __('Property ID is required.', 'property-manager-pro')));
		}
		
		$property_manager = PropertyManager_Property::get_instance();
		$property = $property_manager->get_property($property_id);
		
		if (!$property) {
			wp_send_json_error(array('message' => __('Property not found.', 'property-manager-pro')));
		}
		
		ob_start();
		$this->render_property_details($property);
		$html = ob_get_clean();
		
		wp_send_json_success(array(
			'html' => $html,
			'property' => array(
				'id' => $property->id,
				'title' => $property->title,
				'price' => $property->price,
				'ref' => $property->ref
			)
		));
	}

	private function get_client_ip() {
		$ip = '';
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return sanitize_text_field($ip);
	}

	private function send_verification_email($alert_id, $email, $alert_name, $verification_token) {
		$verification_url = add_query_arg(array(
			'action' => 'verify_alert',
			'token' => $verification_token,
			'alert_id' => $alert_id
		), home_url());
		
		$subject = sprintf(__('[%s] Verify Your Property Alert', 'property-manager-pro'), get_bloginfo('name'));
		
		$message = sprintf(
			__("Hi there,\n\nYou've created a property search alert: \"%s\"\n\nTo start receiving alerts, please verify your email address by clicking the link below:\n\n%s\n\nIf you didn't create this alert, you can safely ignore this email.\n\nBest regards,\n%s", 'property-manager-pro'),
			sanitize_text_field($alert_name),
			esc_url($verification_url),
			get_bloginfo('name')
		);
		
		wp_mail($email, $subject, $message);
	}

	private function render_property_card($property) {
		$property_manager = PropertyManager_Property::get_instance();
		$featured_image = $property_manager->get_property_featured_image($property->id, 'medium');
		$property_url = $property_manager->get_property_url($property);
		?>
		<div class="property-card" data-property-id="<?php echo absint($property->id); ?>">
			<div class="property-image">
				<?php if ($featured_image && $featured_image['url']): ?>
					<img src="<?php echo esc_url($featured_image['url']); ?>" 
						 alt="<?php echo esc_attr($featured_image['alt']); ?>"
						 loading="lazy">
				<?php else: ?>
					<div class="no-image-placeholder">
						<span class="dashicons dashicons-camera"></span>
					</div>
				<?php endif; ?>
				
				<div class="property-badges">
					<?php if ($property->new_build): ?>
						<span class="badge badge-new"><?php _e('New Build', 'property-manager-pro'); ?></span>
					<?php endif; ?>
					<?php if ($property->featured): ?>
						<span class="badge badge-featured"><?php _e('Featured', 'property-manager-pro'); ?></span>
					<?php endif; ?>
				</div>
				
				<div class="property-overlay">
					<a href="<?php echo esc_url($property_url); ?>" class="btn btn-primary">
						<?php _e('View Details', 'property-manager-pro'); ?>
					</a>
				</div>
			</div>
			
			<div class="property-content">
				<div class="property-price">
					<?php echo esc_html($property_manager->format_price($property->price, $property->currency)); ?>
					<?php if ($property->price_freq === 'rent'): ?>
						<span class="price-freq">/<?php _e('month', 'property-manager-pro'); ?></span>
					<?php endif; ?>
				</div>
				
				<h3 class="property-title">
					<a href="<?php echo esc_url($property_url); ?>">
						<?php echo esc_html($property->title ?: $property->ref); ?>
					</a>
				</h3>
				
				<div class="property-location">
					<span class="dashicons dashicons-location"></span>
					<?php echo esc_html($property->town . ', ' . $property->province); ?>
				</div>
				
				<div class="property-details">
					<?php if ($property->beds): ?>
						<span class="detail beds">
							<span class="dashicons dashicons-admin-multisite"></span>
							<?php echo absint($property->beds); ?> <?php _e('beds', 'property-manager-pro'); ?>
						</span>
					<?php endif; ?>
					
					<?php if ($property->baths): ?>
						<span class="detail baths">
							<span class="dashicons dashicons-admin-tools"></span>
							<?php echo absint($property->baths); ?> <?php _e('baths', 'property-manager-pro'); ?>
						</span>
					<?php endif; ?>
					
					<?php if ($property->surface_area_built): ?>
						<span class="detail surface">
							<span class="dashicons dashicons-editor-expand"></span>
							<?php echo number_format(absint($property->surface_area_built)); ?>m²
						</span>
					<?php endif; ?>
					
					<?php if ($property->pool): ?>
						<span class="detail pool">
							<span class="dashicons dashicons-admin-site-alt3"></span>
							<?php _e('Pool', 'property-manager-pro'); ?>
						</span>
					<?php endif; ?>
				</div>
				
				<div class="property-actions">
					<button type="button" class="btn btn-outline-secondary btn-sm favorite-btn" 
							data-property-id="<?php echo absint($property->id); ?>">
						<span class="dashicons dashicons-heart"></span>
						<?php _e('Save', 'property-manager-pro'); ?>
					</button>
					
					<button type="button" class="btn btn-outline-secondary btn-sm share-btn" 
							data-property-id="<?php echo absint($property->id); ?>">
						<span class="dashicons dashicons-share"></span>
						<?php _e('Share', 'property-manager-pro'); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_property_details($property) {
		$property_manager = PropertyManager_Property::get_instance();
		$images = $property_manager->get_property_images($property->id);
		$features = $property_manager->get_property_features($property->id);
		?>
		<div class="property-details-modal">
			<div class="property-gallery">
				<?php if (!empty($images)): ?>
					<div class="main-image">
						<img src="<?php echo esc_url($images[0]->wp_image_url); ?>" 
							 alt="<?php echo esc_attr($images[0]->image_alt); ?>"
							 id="main-property-image">
					</div>
					
					<?php if (count($images) > 1): ?>
					<div class="thumbnail-gallery">
						<?php foreach ($images as $index => $image): ?>
							<img src="<?php echo esc_url($image->wp_image_url); ?>" 
								 alt="<?php echo esc_attr($image->image_alt); ?>"
								 class="thumbnail <?php echo $index === 0 ? 'active' : ''; ?>"
								 onclick="changeMainImage('<?php echo esc_js($image->wp_image_url); ?>', this)">
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			
			<div class="property-info">
				<div class="property-header">
					<div class="price">
						<?php echo esc_html($property_manager->format_price($property->price, $property->currency)); ?>
						<?php if ($property->price_freq === 'rent'): ?>
							<span class="price-freq">/<?php _e('month', 'property-manager-pro'); ?></span>
						<?php endif; ?>
					</div>
					
					<h2 class="title"><?php echo esc_html($property->title ?: $property->ref); ?></h2>
					
					<div class="location">
						<span class="dashicons dashicons-location"></span>
						<?php echo esc_html($property->town . ', ' . $property->province); ?>
					</div>
				</div>
				
				<div class="property-specs">
					<div class="spec-grid">
						<?php if ($property->beds): ?>
						<div class="spec-item">
							<span class="label"><?php _e('Bedrooms', 'property-manager-pro'); ?></span>
							<span class="value"><?php echo absint($property->beds); ?></span>
						</div>
						<?php endif; ?>
						
						<?php if ($property->baths): ?>
						<div class="spec-item">
							<span class="label"><?php _e('Bathrooms', 'property-manager-pro'); ?></span>
							<span class="value"><?php echo absint($property->baths); ?></span>
						</div>
						<?php endif; ?>
						
						<?php if ($property->surface_area_built): ?>
						<div class="spec-item">
							<span class="label"><?php _e('Built Area', 'property-manager-pro'); ?></span>
							<span class="value"><?php echo number_format(absint($property->surface_area_built)); ?>m²</span>
						</div>
						<?php endif; ?>
						
						<?php if ($property->type): ?>
						<div class="spec-item">
							<span class="label"><?php _e('Property Type', 'property-manager-pro'); ?></span>
							<span class="value"><?php echo esc_html($property->type); ?></span>
						</div>
						<?php endif; ?>
					</div>
				</div>
				
				<?php if ($property->description_en): ?>
				<div class="property-description">
					<h3><?php _e('Description', 'property-manager-pro'); ?></h3>
					<div class="description-content">
						<?php echo wp_kses_post(nl2br($property->description_en)); ?>
					</div>
				</div>
				<?php endif; ?>
				
				<?php if (!empty($features)): ?>
				<div class="property-features">
					<h3><?php _e('Features', 'property-manager-pro'); ?></h3>
					<ul class="features-list">
						<?php foreach ($features as $feature): ?>
							<li><?php echo esc_html($feature->feature_name); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				
				<div class="property-actions">
					<a href="<?php echo esc_url($property_manager->get_property_url($property)); ?>" 
					   class="btn btn-primary btn-lg">
						<?php _e('View Full Details', 'property-manager-pro'); ?>
					</a>
					
					<button type="button" class="btn btn-outline-secondary favorite-btn" 
							data-property-id="<?php echo absint($property->id); ?>">
						<span class="dashicons dashicons-heart"></span>
						<?php _e('Add to Favorites', 'property-manager-pro'); ?>
					</button>
				</div>
			</div>
		</div>
		
		<script>
		function changeMainImage(src, thumbnail) {
			document.getElementById('main-property-image').src = src;
			var thumbnails = document.querySelectorAll('.thumbnail');
			thumbnails.forEach(function(thumb) {
				thumb.classList.remove('active');
			});
			thumbnail.classList.add('active');
		}
		</script>
		<?php
	}
}