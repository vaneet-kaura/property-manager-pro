<?php
/**
 * Single Property Template
 * Display property details with image gallery, contact form, map and features
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get property data - $property should be passed to this template
if (!isset($property) || !$property) {
    echo '<div class="alert alert-danger">' . esc_html__('Property not found.', 'property-manager-pro') . '</div>';
    return;
}

// Get property images and features
$images = isset($property->images) ? $property->images : array();
$features = isset($property->features) ? $property->features : array();

// Get user favorite status
$is_favorite = false;
$user_id = get_current_user_id();
if ($user_id) {
    $favorites_manager = PropertyManager_Favorites::get_instance();
    $is_favorite = $favorites_manager->is_favorite($user_id, $property->id);
}

// Get settings
$options = get_option('property_manager_settings', array());
$enable_map = isset($options['enable_map']) ? $options['enable_map'] : true;
$map_provider = isset($options['map_provider']) ? $options['map_provider'] : 'openstreetmap';

// Get description based on current locale
$current_locale = get_locale();
$desc = '';
if (strpos($current_locale, 'es') !== false && !empty($property->desc_es)) {
    $desc = $property->desc_es;
} elseif (strpos($current_locale, 'de') !== false && !empty($property->desc_de)) {
    $desc = $property->desc_de;
} elseif (strpos($current_locale, 'fr') !== false && !empty($property->desc_fr)) {
    $desc = $property->desc_fr;
} else {
    $desc = !empty($property->desc_en) ? $property->desc_en : '';
}
?>

<div class="property-single-container">
    <!-- Image Gallery Slider -->
    <?php if (!empty($images)): ?>
        <div class="property-gallery mb-3">
            <div id="propertyGalleryCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-indicators">
                    <?php foreach ($images as $index => $image): ?>
                        <button type="button" 
                                data-bs-target="#propertyGalleryCarousel" 
                                data-bs-slide-to="<?php echo esc_attr($index); ?>" 
                                class="<?php echo $index === 0 ? 'active' : ''; ?>"
                                aria-label="<?php echo esc_attr(sprintf(__('Slide %d', 'property-manager-pro'), $index + 1)); ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
                        
                <div class="carousel-inner rounded">
                    <?php foreach ($images as $index => $image): ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <img src="<?php echo esc_url($image->image_url); ?>" 
                                    class="d-block w-100" 
                                    alt="<?php echo esc_attr($image->image_alt ? $image->image_alt : $property->title); ?>"
                                    style="max-height: 650px; object-fit: cover;">
                        </div>
                    <?php endforeach; ?>
                </div>                        
                <button class="carousel-control-prev" type="button" data-bs-target="#propertyGalleryCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="visually-hidden"><?php esc_html_e('Previous', 'property-manager-pro'); ?></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#propertyGalleryCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="visually-hidden"><?php esc_html_e('Next', 'property-manager-pro'); ?></span>
                </button>
            </div>
        </div>                
    <?php endif; ?>

    <div class="container py-4">
        <!-- Property Header -->
        <div class="property-header mb-4 <?php !empty($images) ? "floating-header" : ""?>">
            <div class="row align-items-start">
                <div class="col-md-8">
                    <h1 class="mb-2"><?php echo esc_html($property->title); ?></h1>
                    <p class="property-location text-muted mb-2">
                        <i class="bi bi-geo-alt"></i>
                        <?php 
                        echo esc_html($property->location_detail ? $property->location_detail : $property->town);
                        if ($property->province) {
                            echo ', ' . esc_html($property->province);
                        }
                        ?>
                    </p>
                    <?php if ($property->ref): ?>
                        <p class="property-ref text-muted small">
                            <?php esc_html_e('Ref:', 'property-manager-pro'); ?> 
                            <strong><?php echo esc_html($property->ref); ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="property-price mb-3">
                        <span class="price-amount h2 text-primary mb-0">
                            <?php echo esc_html($property->currency == "EUR" ? "&euro;" : ""); ?> 
                            <?php echo number_format($property->price, 0, '.', ','); ?>
                        </span>
                        <?php if ($property->price_freq === 'rent'): ?>
                            <span class="price-period text-muted">
                                / <?php esc_html_e('month', 'property-manager-pro'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Action Buttons
                    <div class="property-actions d-flex gap-2 justify-content-md-end">
                        <?php if ($user_id): ?>
                            <button type="button" 
                                    class="btn btn-outline-danger btn-favorite <?php echo $is_favorite ? 'active' : ''; ?>" 
                                    data-property-id="<?php echo esc_attr($property->id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('pm_favorite_nonce')); ?>">
                                <i class="bi <?php echo $is_favorite ? 'bi-heart-fill' : 'bi-heart'; ?>"></i>
                                <span class="favorite-text">
                                    <?php echo $is_favorite ? esc_html__('Saved', 'property-manager-pro') : esc_html__('Save', 'property-manager-pro'); ?>
                                </span>
                            </button>
                        <?php else: ?>
                            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" 
                               class="btn btn-outline-danger">
                                <i class="bi bi-heart"></i>
                                <?php esc_html_e('Save', 'property-manager-pro'); ?>
                            </a>
                        <?php endif; ?>
                    </div> -->
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Property Overview -->
                <div class="property-overview card mb-4">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-3">
                            <?php esc_html_e('Property Overview', 'property-manager-pro'); ?>
                        </h3>
                        <div class="row g-3">
                            <?php if ($property->beds): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-door-closed fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo esc_html($property->beds); ?></div>
                                        <div class="small text-muted"><?php esc_html_e('Bedrooms', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->baths): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-droplet fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo esc_html($property->baths); ?></div>
                                        <div class="small text-muted"><?php esc_html_e('Bathrooms', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->surface_area_built): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-rulers fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo esc_html($property->surface_area_built); ?> m<sup>2</sup></div>
                                        <div class="small text-muted"><?php esc_html_e('Built Area', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->surface_area_plot): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-square fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo esc_html($property->surface_area_plot); ?> m<sup>2</sup></div>
                                        <div class="small text-muted"><?php esc_html_e('Plot Area', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->property_type): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-house fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo esc_html($property->property_type); ?></div>
                                        <div class="small text-muted"><?php esc_html_e('Type', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->pool): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-water fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php esc_html_e('Yes', 'property-manager-pro'); ?></div>
                                        <div class="small text-muted"><?php esc_html_e('Pool', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->new_build): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-tools fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php esc_html_e('Yes', 'property-manager-pro'); ?></div>
                                        <div class="small text-muted"><?php esc_html_e('New Build', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($property->energy_rating_consumption || $property->energy_rating_emissions): ?>
                            <div class="col-6 col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-lightning-charge fs-4 text-primary me-2"></i>
                                    <div>
                                        <div class="fw-bold">
                                            <?php echo $property->energy_rating_consumption ? esc_html($property->energy_rating_consumption) : esc_html($property->energy_rating_emissions); ?>
                                        </div>
                                        <div class="small text-muted"><?php esc_html_e('Energy Rating', 'property-manager-pro'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Property Description -->
                <?php if ($desc): ?>
                <div class="property-description card mb-4">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-3">
                            <?php esc_html_e('Description', 'property-manager-pro'); ?>
                        </h3>
                        <div class="property-desc-content">
                            <?php echo wp_kses_post(wpautop($desc)); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Property Features -->
                <?php if (!empty($features)): ?>
                <div class="property-features card mb-4">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-3">
                            <?php esc_html_e('Features', 'property-manager-pro'); ?>
                        </h3>
                        <div class="row">
                            <?php foreach ($features as $feature): ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <span><?php echo esc_html($feature); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Location Map -->
                <?php if ($enable_map && $property->latitude && $property->longitude): ?>
                <div class="property-map card mb-4">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-3">
                            <?php esc_html_e('Location', 'property-manager-pro'); ?>
                        </h3>
                        <div id="propertyMap" 
                             class="property-map-container rounded" 
                             style="height: 400px; width: 100%;"
                             data-lat="<?php echo esc_attr($property->latitude); ?>"
                             data-lng="<?php echo esc_attr($property->longitude); ?>"
                             data-title="<?php echo esc_attr($property->title); ?>">
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="bi bi-info-circle"></i>
                            <?php esc_html_e('Map location is approximate', 'property-manager-pro'); ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                
                <!-- Property Info -->
                <div class="property-info card mb-4">
                    <div class="card-body">
                        <h3 class="h4 mb-3">
                            <?php esc_html_e('Property Information', 'property-manager-pro'); ?>
                        </h3>
                        <ul class="list-unstyled mb-0">
                            <?php if ($property->ref): ?>
                            <li class="mb-2">
                                <strong><?php esc_html_e('Reference:', 'property-manager-pro'); ?></strong>
                                <span class="float-end"><?php echo esc_html($property->ref); ?></span>
                            </li>
                            <?php endif; ?>
                            
                            <li class="mb-2">
                                <strong><?php esc_html_e('Type:', 'property-manager-pro'); ?></strong>
                                <span class="float-end"><?php echo esc_html($property->price_freq === 'rent' ? __('For Rent', 'property-manager-pro') : __('For Sale', 'property-manager-pro')); ?></span>
                            </li>
                            
                            <?php if ($property->town): ?>
                            <li class="mb-2">
                                <strong><?php esc_html_e('Town:', 'property-manager-pro'); ?></strong>
                                <span class="float-end"><?php echo esc_html($property->town); ?></span>
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($property->province): ?>
                            <li class="mb-2">
                                <strong><?php esc_html_e('Province:', 'property-manager-pro'); ?></strong>
                                <span class="float-end"><?php echo esc_html($property->province); ?></span>
                            </li>
                            <?php endif; ?>
                            
                            <li class="mb-2">
                                <strong><?php esc_html_e('Status:', 'property-manager-pro'); ?></strong>
                                <span class="float-end">
                                    <span class="badge bg-success"><?php esc_html_e('Available', 'property-manager-pro'); ?></span>
                                </span>
                            </li>
                            
                            <li class="mb-0">
                                <strong><?php esc_html_e('Updated:', 'property-manager-pro'); ?></strong>
                                <span class="float-end"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($property->updated_at))); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="property-contact-form card mb-4 sticky-top" style="top: 20px;">
                    <div class="card-body p-4">
                        <?php echo do_shortcode('[property_contact_form property_id='.$property->id.']')?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Initialize Map Script -->
<?php if ($enable_map && $property->latitude && $property->longitude): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const mapContainer = document.getElementById('propertyMap');
            if (!mapContainer) return;
    
            const lat = parseFloat(mapContainer.dataset.lat);
            const lng = parseFloat(mapContainer.dataset.lng);
            const title = mapContainer.dataset.title;
    
            <?php if ($map_provider === 'openstreetmap'): ?>
            // Initialize OpenStreetMap with Leaflet
            if (typeof L !== 'undefined') {
                const map = L.map('propertyMap').setView([lat, lng], 15);
        
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19
                }).addTo(map);
        
                const marker = L.marker([lat, lng]).addTo(map);
                marker.bindPopup('<strong>' + title + '</strong>').openPopup();
            }
            <?php endif; ?>
        });
    </script>
<?php endif; ?>