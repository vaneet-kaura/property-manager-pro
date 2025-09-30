<?php
/**
 * Favorites Management Class - Property Manager Pro
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_Favorites {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // AJAX handlers
        add_action('wp_ajax_toggle_property_favorite', array($this, 'ajax_toggle_favorite'));
        add_action('wp_ajax_nopriv_toggle_property_favorite', array($this, 'ajax_toggle_favorite'));
        add_action('wp_ajax_remove_property_favorite', array($this, 'ajax_remove_favorite'));
        add_action('wp_ajax_get_user_favorites', array($this, 'ajax_get_user_favorites'));
        
        // Shortcode hooks
        add_action('init', array($this, 'init_hooks'));
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Add favorites button to property displays
        add_action('wp_footer', array($this, 'add_favorites_javascript'));
    }
    
    /**
     * Add or remove property from user favorites
     */
    public function toggle_favorite($property_id, $user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return new WP_Error('not_logged_in', __('You must be logged in to save favorites.', 'property-manager-pro'));
            }
            $user_id = get_current_user_id();
        }
        
        // Validate property exists
        $property_manager = PropertyManager_Property::get_instance();
        $property = $property_manager->get_property($property_id);
        
        if (!$property) {
            return new WP_Error('invalid_property', __('Property not found.', 'property-manager-pro'));
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        // Check if already favorited
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND property_id = %d",
            $user_id, $property_id
        ));
        
        if ($existing) {
            // Remove from favorites
            $result = $wpdb->delete($table, array(
                'user_id' => $user_id,
                'property_id' => $property_id
            ));
            
            if ($result !== false) {
                return array(
                    'action' => 'removed',
                    'message' => __('Property removed from favorites.', 'property-manager-pro'),
                    'is_favorite' => false
                );
            } else {
                return new WP_Error('db_error', __('Failed to remove from favorites.', 'property-manager-pro'));
            }
        } else {
            // Add to favorites
            $result = $wpdb->insert($table, array(
                'user_id' => $user_id,
                'property_id' => $property_id,
                'created_at' => current_time('mysql')
            ));
            
            if ($result !== false) {
                return array(
                    'action' => 'added',
                    'message' => __('Property added to favorites.', 'property-manager-pro'),
                    'is_favorite' => true
                );
            } else {
                return new WP_Error('db_error', __('Failed to add to favorites.', 'property-manager-pro'));
            }
        }
    }
    
    /**
     * Check if property is in user favorites
     */
    public function is_favorite($property_id, $user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return false;
            }
            $user_id = get_current_user_id();
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND property_id = %d",
            $user_id, $property_id
        ));
        
        return (bool) $exists;
    }
    
    /**
     * Get user's favorite properties
     */
    public function get_user_favorites($user_id = null, $args = array()) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return array('properties' => array(), 'total' => 0);
            }
            $user_id = get_current_user_id();
        }
        
        $defaults = array(
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        global $wpdb;
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        // Build ORDER BY clause
        $order_field = 'f.created_at';
        switch ($args['orderby']) {
            case 'title':
                $order_field = 'p.title';
                break;
            case 'price':
                $order_field = 'p.price';
                break;
            case 'beds':
                $order_field = 'p.beds';
                break;
            case 'updated':
                $order_field = 'p.updated_at';
                break;
            default:
                $order_field = 'f.created_at';
        }
        
        $order_direction = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby_sql = "$order_field $order_direction";
        
        // Pagination
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Count total favorites
        $total_sql = "
            SELECT COUNT(*) 
            FROM $favorites_table f 
            INNER JOIN $properties_table p ON f.property_id = p.id 
            WHERE f.user_id = %d AND p.status = 'active'
        ";
        $total = $wpdb->get_var($wpdb->prepare($total_sql, $user_id));
        
        // Get favorite properties
        $properties_sql = "
            SELECT p.*, f.created_at as favorited_at
            FROM $favorites_table f 
            INNER JOIN $properties_table p ON f.property_id = p.id 
            WHERE f.user_id = %d AND p.status = 'active'
            ORDER BY $orderby_sql
            LIMIT %d OFFSET %d
        ";
        
        $properties = $wpdb->get_results($wpdb->prepare(
            $properties_sql, 
            $user_id, 
            $args['per_page'], 
            $offset
        ));
        
        // Add images and features to each property
        $property_manager = PropertyManager_Property::get_instance();
        foreach ($properties as &$property) {
            $property->images = $property_manager->get_property_images($property->id);
            $property->features = $property_manager->get_property_features($property->id);
        }
        
        return array(
            'properties' => $properties,
            'total' => intval($total),
            'pages' => ceil($total / $args['per_page']),
            'current_page' => $args['page'],
            'per_page' => $args['per_page']
        );
    }
    
    /**
     * Get favorites count for user
     */
    public function get_favorites_count($user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return 0;
            }
            $user_id = get_current_user_id();
        }
        
        global $wpdb;
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM $favorites_table f 
             INNER JOIN $properties_table p ON f.property_id = p.id 
             WHERE f.user_id = %d AND p.status = 'active'",
            $user_id
        ));
    }
    
    /**
     * Remove property from favorites
     */
    public function remove_favorite($property_id, $user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return new WP_Error('not_logged_in', __('You must be logged in to manage favorites.', 'property-manager-pro'));
            }
            $user_id = get_current_user_id();
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        $result = $wpdb->delete($table, array(
            'user_id' => $user_id,
            'property_id' => $property_id
        ));
        
        if ($result !== false) {
            return true;
        } else {
            return new WP_Error('db_error', __('Failed to remove from favorites.', 'property-manager-pro'));
        }
    }
    
    /**
     * Clear all favorites for user
     */
    public function clear_all_favorites($user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return new WP_Error('not_logged_in', __('You must be logged in to manage favorites.', 'property-manager-pro'));
            }
            $user_id = get_current_user_id();
        }
        
        global $wpdb;
        $table = PropertyManager_Database::get_table_name('user_favorites');
        
        $result = $wpdb->delete($table, array('user_id' => $user_id));
        
        if ($result !== false) {
            return $result; // Number of deleted records
        } else {
            return new WP_Error('db_error', __('Failed to clear favorites.', 'property-manager-pro'));
        }
    }
    
    /**
     * Get most favorited properties
     */
    public function get_popular_properties($limit = 10) {
        global $wpdb;
        $favorites_table = PropertyManager_Database::get_table_name('user_favorites');
        $properties_table = PropertyManager_Database::get_table_name('properties');
        
        $sql = "
            SELECT p.*, COUNT(f.id) as favorite_count
            FROM $properties_table p
            INNER JOIN $favorites_table f ON p.id = f.property_id
            WHERE p.status = 'active'
            GROUP BY p.id
            ORDER BY favorite_count DESC, p.created_at DESC
            LIMIT %d
        ";
        
        $properties = $wpdb->get_results($wpdb->prepare($sql, $limit));
        
        // Add images and features
        $property_manager = PropertyManager_Property::get_instance();
        foreach ($properties as &$property) {
            $property->images = $property_manager->get_property_images($property->id);
            $property->features = $property_manager->get_property_features($property->id);
        }
        
        return $properties;
    }
    
    /**
     * AJAX: Toggle property favorite status
     */
    public function ajax_toggle_favorite() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'property_manager_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'property-manager-pro')
            ));
        }
        
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        
        if (!$property_id) {
            wp_send_json_error(array(
                'message' => __('Invalid property ID.', 'property-manager-pro')
            ));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please login to save favorites.', 'property-manager-pro'),
                'login_required' => true
            ));
        }
        
        $result = $this->toggle_favorite($property_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * AJAX: Remove property from favorites
     */
    public function ajax_remove_favorite() {
        if (!wp_verify_nonce($_POST['nonce'], 'property_manager_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'property-manager-pro')
            ));
        }
        
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        
        if (!$property_id) {
            wp_send_json_error(array(
                'message' => __('Invalid property ID.', 'property-manager-pro')
            ));
        }
        
        $result = $this->remove_favorite($property_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Property removed from favorites.', 'property-manager-pro')
            ));
        }
    }
    
    /**
     * AJAX: Get user favorites
     */
    public function ajax_get_user_favorites() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You must be logged in to view favorites.', 'property-manager-pro')
            ));
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 12;
        $orderby = isset($_POST['orderby']) ? sanitize_text_field($_POST['orderby']) : 'created_at';
        $order = isset($_POST['order']) ? sanitize_text_field($_POST['order']) : 'DESC';
        
        $args = array(
            'page' => $page,
            'per_page' => $per_page,
            'orderby' => $orderby,
            'order' => $order
        );
        
        $favorites = $this->get_user_favorites(null, $args);
        
        // Generate HTML for properties
        ob_start();
        if (!empty($favorites['properties'])) {
            foreach ($favorites['properties'] as $property) {
                $this->render_favorite_property_card($property);
            }
        } else {
            echo '<div class="no-favorites text-center py-5">';
            echo '<p class="text-muted">' . __('You haven\'t added any properties to your favorites yet.', 'property-manager-pro') . '</p>';
            echo '<a href="' . get_permalink(get_option('property_manager_pages')['property_search'] ?? '') . '" class="btn btn-primary">';
            echo __('Browse Properties', 'property-manager-pro');
            echo '</a>';
            echo '</div>';
        }
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'total' => $favorites['total'],
            'pages' => $favorites['pages'],
            'current_page' => $favorites['current_page']
        ));
    }
    
    /**
     * Render favorite property card
     */
    private function render_favorite_property_card($property) {
        $property_manager = PropertyManager_Property::get_instance();
        $featured_image = $property_manager->get_property_featured_image($property->id, 'medium');
        $property_url = $property_manager->get_property_url($property);
        
        ?>
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="property-card favorite-property-card" data-property-id="<?php echo $property->id; ?>">
                <div class="property-image">
                    <a href="<?php echo esc_url($property_url); ?>">
                        <?php if ($featured_image && $featured_image['url']): ?>
                            <img src="<?php echo esc_url($featured_image['url']); ?>" 
                                 alt="<?php echo esc_attr($featured_image['alt']); ?>"
                                 class="card-img-top"
                                 loading="lazy">
                        <?php else: ?>
                            <div class="no-image-placeholder">
                                <span class="dashicons dashicons-camera"></span>
                            </div>
                        <?php endif; ?>
                    </a>
                    
                    <div class="property-badges">
                        <?php if ($property->new_build): ?>
                            <span class="badge badge-success"><?php _e('New Build', 'property-manager-pro'); ?></span>
                        <?php endif; ?>
                        <?php if ($property->featured): ?>
                            <span class="badge badge-primary"><?php _e('Featured', 'property-manager-pro'); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="property-overlay">
                        <button type="button" class="btn btn-outline-light btn-sm remove-favorite-btn" 
                                data-property-id="<?php echo $property->id; ?>"
                                title="<?php _e('Remove from Favorites', 'property-manager-pro'); ?>">
                            <span class="dashicons dashicons-heart"></span>
                        </button>
                    </div>
                </div>
                
                <div class="card-body">
                    <div class="property-price">
                        <?php echo $property_manager->format_price($property->price, $property->currency); ?>
                        <?php if ($property->price_freq === 'rent'): ?>
                            <span class="price-freq text-muted">/<?php _e('month', 'property-manager-pro'); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <h5 class="property-title">
                        <a href="<?php echo esc_url($property_url); ?>" class="text-decoration-none">
                            <?php echo esc_html($property->title ?: $property->ref); ?>
                        </a>
                    </h5>
                    
                    <div class="property-location text-muted mb-2">
                        <small>
                            <span class="dashicons dashicons-location"></span>
                            <?php echo esc_html($property->town . ', ' . $property->province); ?>
                        </small>
                    </div>
                    
                    <div class="property-details">
                        <?php if ($property->beds): ?>
                            <span class="detail">
                                <span class="dashicons dashicons-admin-multisite"></span>
                                <?php echo $property->beds; ?> <?php _e('beds', 'property-manager-pro'); ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($property->baths): ?>
                            <span class="detail">
                                <span class="dashicons dashicons-admin-tools"></span>
                                <?php echo $property->baths; ?> <?php _e('baths', 'property-manager-pro'); ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($property->surface_area_built): ?>
                            <span class="detail">
                                <span class="dashicons dashicons-editor-expand"></span>
                                <?php echo number_format($property->surface_area_built); ?>m²
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="favorite-meta text-muted mt-3">
                        <small>
                            <?php printf(__('Added %s', 'property-manager-pro'), 
                                        human_time_diff(strtotime($property->favorited_at)) . ' ' . __('ago', 'property-manager-pro')); ?>
                        </small>
                    </div>
                    
                    <div class="property-actions mt-3">
                        <a href="<?php echo esc_url($property_url); ?>" class="btn btn-primary btn-sm">
                            <?php _e('View Details', 'property-manager-pro'); ?>
                        </a>
                        
                        <button type="button" class="btn btn-outline-secondary btn-sm share-btn" 
                                data-property-id="<?php echo $property->id; ?>">
                            <span class="dashicons dashicons-share"></span>
                            <?php _e('Share', 'property-manager-pro'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add favorites JavaScript functionality
     */
    public function add_favorites_javascript() {
        if (!wp_script_is('property-manager-shortcodes', 'enqueued')) {
            return;
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle favorite button clicks
            document.addEventListener('click', function(e) {
                if (e.target.closest('.favorite-btn')) {
                    e.preventDefault();
                    handleFavoriteToggle(e.target.closest('.favorite-btn'));
                }
                
                if (e.target.closest('.remove-favorite-btn')) {
                    e.preventDefault();
                    handleFavoriteRemove(e.target.closest('.remove-favorite-btn'));
                }
            });
            
            function handleFavoriteToggle(btn) {
                var propertyId = btn.getAttribute('data-property-id');
                var heartIcon = btn.querySelector('.dashicons-heart');
                
                if (!propertyId) return;
                
                btn.disabled = true;
                
                fetch(propertyManager.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'toggle_property_favorite',
                        property_id: propertyId,
                        nonce: propertyManager.nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update button appearance
                        if (data.data.is_favorite) {
                            heartIcon.classList.add('favorited');
                            btn.classList.add('favorited');
                            btn.setAttribute('title', propertyManager.strings.removeFromFavorites);
                        } else {
                            heartIcon.classList.remove('favorited');
                            btn.classList.remove('favorited');
                            btn.setAttribute('title', propertyManager.strings.addToFavorites);
                        }
                        
                        // Show success message
                        showNotification(data.data.message, 'success');
                    } else {
                        if (data.data && data.data.login_required) {
                            showLoginPrompt();
                        } else {
                            showNotification(data.data ? data.data.message : 'Error occurred', 'error');
                        }
                    }
                })
                .catch(error => {
                    showNotification('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                });
            }
            
            function handleFavoriteRemove(btn) {
                var propertyId = btn.getAttribute('data-property-id');
                var propertyCard = btn.closest('.favorite-property-card');
                
                if (!propertyId) return;
                
                if (!confirm('<?php _e('Are you sure you want to remove this property from your favorites?', 'property-manager-pro'); ?>')) {
                    return;
                }
                
                btn.disabled = true;
                
                fetch(propertyManager.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'remove_property_favorite',
                        property_id: propertyId,
                        nonce: propertyManager.nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove property card with animation
                        if (propertyCard) {
                            propertyCard.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                            propertyCard.style.opacity = '0';
                            propertyCard.style.transform = 'scale(0.95)';
                            
                            setTimeout(() => {
                                var col = propertyCard.closest('.col-lg-4, .col-md-6, .col-12');
                                if (col) {
                                    col.remove();
                                } else {
                                    propertyCard.remove();
                                }
                            }, 300);
                        }
                        
                        showNotification(data.data.message, 'success');
                    } else {
                        showNotification(data.data ? data.data.message : 'Error occurred', 'error');
                    }
                })
                .catch(error => {
                    showNotification('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                });
            }
            
            function showNotification(message, type) {
                // Create notification element
                var notification = document.createElement('div');
                notification.className = 'property-notification ' + type;
                notification.textContent = message;
                
                // Style notification
                Object.assign(notification.style, {
                    position: 'fixed',
                    top: '20px',
                    right: '20px',
                    padding: '15px 20px',
                    borderRadius: '5px',
                    color: 'white',
                    fontWeight: 'bold',
                    zIndex: '9999',
                    opacity: '0',
                    transform: 'translateY(-20px)',
                    transition: 'all 0.3s ease'
                });
                
                if (type === 'success') {
                    notification.style.backgroundColor = '#28a745';
                } else {
                    notification.style.backgroundColor = '#dc3545';
                }
                
                document.body.appendChild(notification);
                
                // Show notification
                setTimeout(() => {
                    notification.style.opacity = '1';
                    notification.style.transform = 'translateY(0)';
                }, 100);
                
                // Hide and remove notification
                setTimeout(() => {
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateY(-20px)';
                    
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }, 3000);
            }
            
            function showLoginPrompt() {
                if (confirm('<?php _e('You need to be logged in to save favorites. Would you like to login now?', 'property-manager-pro'); ?>')) {
                    window.location.href = '<?php echo wp_login_url(); ?>';
                }
            }
        });
        </script>
        
        <style>
        .favorite-btn.favorited .dashicons-heart,
        .favorited .dashicons-heart {
            color: #dc3545 !important;
        }
        
        .favorite-property-card {
            transition: all 0.3s ease;
        }
        
        .property-notification {
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        </style>
        <?php
    }
}