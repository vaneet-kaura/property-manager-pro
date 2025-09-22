<?php
/**
 * Search Forms Helper Class
 * 
 * @package PropertyManagerPro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PropertyManager_SearchForms {
    
    private static $instance = null;
    private $search;
    private $property;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->search = PropertyManager_Search::get_instance();
        $this->property = PropertyManager_Property::get_instance();
    }
    
    /**
     * Generate basic search form
     */
    public function basic_search_form($args = array()) {
        $defaults = array(
            'show_location' => true,
            'show_type' => true,
            'show_price' => true,
            'show_beds' => true,
            'show_keyword' => true,
            'form_class' => 'property-search-form basic-search',
            'submit_text' => __('Search Properties', 'property-manager-pro')
        );
        
        $args = wp_parse_args($args, $defaults);
        $search_params = $this->search->get_search_params();
        
        ob_start();
        ?>
        <form method="get" class="<?php echo esc_attr($args['form_class']); ?>" action="<?php echo esc_url($this->search->get_search_url()); ?>">
            <div class="search-form-row">
                
                <?php if ($args['show_keyword']): ?>
                <div class="search-field">
                    <label for="keyword"><?php _e('Keyword', 'property-manager-pro'); ?></label>
                    <input type="text" 
                           id="keyword" 
                           name="keyword" 
                           value="<?php echo esc_attr($this->search->get_param('keyword')); ?>" 
                           placeholder="<?php _e('Property reference, description...', 'property-manager-pro'); ?>">
                </div>
                <?php endif; ?>
                
                <?php if ($args['show_location']): ?>
                <div class="search-field">
                    <label for="location"><?php _e('Location', 'property-manager-pro'); ?></label>
                    <input type="text" 
                           id="location" 
                           name="location" 
                           value="<?php echo esc_attr($this->search->get_param('location')); ?>" 
                           placeholder="<?php _e('City, Province...', 'property-manager-pro'); ?>">
                </div>
                <?php endif; ?>
                
                <?php if ($args['show_type']): ?>
                <div class="search-field">
                    <label for="property_type"><?php _e('Property Type', 'property-manager-pro'); ?></label>
                    <select id="property_type" name="property_type">
                        <option value=""><?php _e('Any Type', 'property-manager-pro'); ?></option>
                        <?php foreach ($this->property->get_property_types() as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>" 
                                    <?php selected($this->search->get_param('property_type'), $type); ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($args['show_price']): ?>
                <div class="search-field price-range">
                    <label><?php _e('Price Range', 'property-manager-pro'); ?></label>
                    <div class="price-inputs">
                        <input type="number" 
                               name="price_min" 
                               value="<?php echo esc_attr($this->search->get_param('price_min')); ?>" 
                               placeholder="<?php _e('Min Price', 'property-manager-pro'); ?>"
                               min="0" step="1000">
                        <span class="price-separator">-</span>
                        <input type="number" 
                               name="price_max" 
                               value="<?php echo esc_attr($this->search->get_param('price_max')); ?>" 
                               placeholder="<?php _e('Max Price', 'property-manager-pro'); ?>"
                               min="0" step="1000">
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($args['show_beds']): ?>
                <div class="search-field">
                    <label for="beds_min"><?php _e('Min Bedrooms', 'property-manager-pro'); ?></label>
                    <select id="beds_min" name="beds_min">
                        <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <option value="<?php echo $i; ?>" 
                                    <?php selected($this->search->get_param('beds_min'), $i); ?>>
                                <?php echo $i; ?>+
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>
                
            </div>
            
            <div class="search-form-actions">
                <button type="submit" class="btn btn-primary search-submit">
                    <?php echo esc_html($args['submit_text']); ?>
                </button>
                
                <?php if ($this->search->has_active_filters()): ?>
                <a href="<?php echo esc_url($this->search->get_clear_filters_url()); ?>" class="btn btn-secondary clear-filters">
                    <?php _e('Clear Filters', 'property-manager-pro'); ?>
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Preserve current view and sorting -->
            <input type="hidden" name="view" value="<?php echo esc_attr($this->search->get_param('view', 'grid')); ?>">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($this->search->get_param('orderby', 'created_at')); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($this->search->get_param('order', 'DESC')); ?>">
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate advanced search form
     */
    public function advanced_search_form($args = array()) {
        $defaults = array(
            'form_class' => 'property-search-form advanced-search',
            'submit_text' => __('Search Properties', 'property-manager-pro'),
            'collapsible' => true
        );
        
        $args = wp_parse_args($args, $defaults);
        
        ob_start();
        ?>
        <form method="get" class="<?php echo esc_attr($args['form_class']); ?>" action="<?php echo esc_url($this->search->get_search_url()); ?>">
            
            <!-- Basic Search Section -->
            <div class="search-section basic-section">
                <div class="search-row">
                    <div class="search-field col-md-3">
                        <label for="keyword"><?php _e('Keyword', 'property-manager-pro'); ?></label>
                        <input type="text" 
                               id="keyword" 
                               name="keyword" 
                               value="<?php echo esc_attr($this->search->get_param('keyword')); ?>" 
                               placeholder="<?php _e('Reference, description...', 'property-manager-pro'); ?>">
                    </div>
                    
                    <div class="search-field col-md-3">
                        <label for="property_type"><?php _e('Property Type', 'property-manager-pro'); ?></label>
                        <select id="property_type" name="property_type">
                            <option value=""><?php _e('Any Type', 'property-manager-pro'); ?></option>
                            <?php foreach ($this->property->get_property_types() as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" 
                                        <?php selected($this->search->get_param('property_type'), $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="search-field col-md-3">
                        <label for="price_freq"><?php _e('For', 'property-manager-pro'); ?></label>
                        <select id="price_freq" name="price_freq">
                            <option value=""><?php _e('Sale or Rent', 'property-manager-pro'); ?></option>
                            <option value="sale" <?php selected($this->search->get_param('price_freq'), 'sale'); ?>>
                                <?php _e('Sale', 'property-manager-pro'); ?>
                            </option>
                            <option value="rent" <?php selected($this->search->get_param('price_freq'), 'rent'); ?>>
                                <?php _e('Rent', 'property-manager-pro'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="search-field col-md-3">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block">
                            <?php _e('Search', 'property-manager-pro'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Advanced Filters Section -->
            <?php if ($args['collapsible']): ?>
            <div class="advanced-toggle">
                <button type="button" class="btn btn-link" data-toggle="collapse" data-target="#advanced-filters">
                    <span class="toggle-text"><?php _e('Advanced Search', 'property-manager-pro'); ?></span>
                    <span class="toggle-icon">▼</span>
                </button>
            </div>
            <?php endif; ?>
            
            <div id="advanced-filters" class="search-section advanced-section <?php echo $args['collapsible'] ? 'collapse' : ''; ?>">
                
                <!-- Location Section -->
                <div class="search-subsection">
                    <h4><?php _e('Location', 'property-manager-pro'); ?></h4>
                    <div class="search-row">
                        <div class="search-field col-md-4">
                            <label for="province"><?php _e('Province', 'property-manager-pro'); ?></label>
                            <select id="province" name="province">
                                <option value=""><?php _e('Any Province', 'property-manager-pro'); ?></option>
                                <?php foreach ($this->property->get_provinces() as $province): ?>
                                    <option value="<?php echo esc_attr($province); ?>" 
                                            <?php selected($this->search->get_param('province'), $province); ?>>
                                        <?php echo esc_html($province); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="search-field col-md-4">
                            <label for="town"><?php _e('Town/City', 'property-manager-pro'); ?></label>
                            <select id="town" name="town">
                                <option value=""><?php _e('Any Town', 'property-manager-pro'); ?></option>
                                <?php 
                                $selected_province = $this->search->get_param('province');
                                $towns = $this->property->get_towns($selected_province);
                                foreach ($towns as $town): ?>
                                    <option value="<?php echo esc_attr($town); ?>" 
                                            <?php selected($this->search->get_param('town'), $town); ?>>
                                        <?php echo esc_html($town); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="search-field col-md-4">
                            <label for="location"><?php _e('Or search location', 'property-manager-pro'); ?></label>
                            <input type="text" 
                                   id="location" 
                                   name="location" 
                                   value="<?php echo esc_attr($this->search->get_param('location')); ?>" 
                                   placeholder="<?php _e('City, area...', 'property-manager-pro'); ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Price & Size Section -->
                <div class="search-subsection">
                    <h4><?php _e('Price & Size', 'property-manager-pro'); ?></h4>
                    <div class="search-row">
                        <div class="search-field col-md-6">
                            <label><?php _e('Price Range (€)', 'property-manager-pro'); ?></label>
                            <div class="range-inputs">
                                <input type="number" 
                                       name="price_min" 
                                       value="<?php echo esc_attr($this->search->get_param('price_min')); ?>" 
                                       placeholder="<?php _e('Min', 'property-manager-pro'); ?>"
                                       min="0" step="1000">
                                <span>-</span>
                                <input type="number" 
                                       name="price_max" 
                                       value="<?php echo esc_attr($this->search->get_param('price_max')); ?>" 
                                       placeholder="<?php _e('Max', 'property-manager-pro'); ?>"
                                       min="0" step="1000">
                            </div>
                        </div>
                        
                        <div class="search-field col-md-6">
                            <label><?php _e('Size (m²)', 'property-manager-pro'); ?></label>
                            <div class="range-inputs">
                                <input type="number" 
                                       name="surface_min" 
                                       value="<?php echo esc_attr($this->search->get_param('surface_min')); ?>" 
                                       placeholder="<?php _e('Min', 'property-manager-pro'); ?>"
                                       min="0">
                                <span>-</span>
                                <input type="number" 
                                       name="surface_max" 
                                       value="<?php echo esc_attr($this->search->get_param('surface_max')); ?>" 
                                       placeholder="<?php _e('Max', 'property-manager-pro'); ?>"
                                       min="0">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Rooms Section -->
                <div class="search-subsection">
                    <h4><?php _e('Rooms', 'property-manager-pro'); ?></h4>
                    <div class="search-row">
                        <div class="search-field col-md-3">
                            <label for="beds_min"><?php _e('Min Bedrooms', 'property-manager-pro'); ?></label>
                            <select id="beds_min" name="beds_min">
                                <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                            <?php selected($this->search->get_param('beds_min'), $i); ?>>
                                        <?php echo $i; ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="search-field col-md-3">
                            <label for="beds_max"><?php _e('Max Bedrooms', 'property-manager-pro'); ?></label>
                            <select id="beds_max" name="beds_max">
                                <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                            <?php selected($this->search->get_param('beds_max'), $i); ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="search-field col-md-3">
                            <label for="baths_min"><?php _e('Min Bathrooms', 'property-manager-pro'); ?></label>
                            <select id="baths_min" name="baths_min">
                                <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                            <?php selected($this->search->get_param('baths_min'), $i); ?>>
                                        <?php echo $i; ?>+
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="search-field col-md-3">
                            <label for="baths_max"><?php _e('Max Bathrooms', 'property-manager-pro'); ?></label>
                            <select id="baths_max" name="baths_max">
                                <option value=""><?php _e('Any', 'property-manager-pro'); ?></option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                            <?php selected($this->search->get_param('baths_max'), $i); ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Features Section -->
                <div class="search-subsection">
                    <h4><?php _e('Features', 'property-manager-pro'); ?></h4>
                    <div class="search-row">
                        <div class="search-field col-md-4">
                            <label class="checkbox-label">
                                <input type="checkbox" name="pool" value="1" 
                                       <?php checked($this->search->get_param('pool'), 1); ?>>
                                <?php _e('Swimming Pool', 'property-manager-pro'); ?>
                            </label>
                        </div>
                        
                        <div class="search-field col-md-4">
                            <label class="checkbox-label">
                                <input type="checkbox" name="new_build" value="1" 
                                       <?php checked($this->search->get_param('new_build'), 1); ?>>
                                <?php _e('New Build', 'property-manager-pro'); ?>
                            </label>
                        </div>
                        
                        <div class="search-field col-md-4">
                            <label class="checkbox-label">
                                <input type="checkbox" name="featured" value="1" 
                                       <?php checked($this->search->get_param('featured'), 1); ?>>
                                <?php _e('Featured Properties', 'property-manager-pro'); ?>
                            </label>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <!-- Form Actions -->
            <div class="search-form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo esc_html($args['submit_text']); ?>
                </button>
                
                <?php if ($this->search->has_active_filters()): ?>
                <a href="<?php echo esc_url($this->search->get_clear_filters_url()); ?>" class="btn btn-secondary">
                    <?php _e('Clear All Filters', 'property-manager-pro'); ?>
                </a>
                <?php endif; ?>
                
                <button type="button" class="btn btn-outline-secondary save-search" data-toggle="modal" data-target="#save-search-modal">
                    <?php _e('Save This Search', 'property-manager-pro'); ?>
                </button>
            </div>
            
            <!-- Hidden fields to preserve state -->
            <input type="hidden" name="view" value="<?php echo esc_attr($this->search->get_param('view', 'grid')); ?>">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($this->search->get_param('orderby', 'created_at')); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($this->search->get_param('order', 'DESC')); ?>">
        </form>
        
        <!-- JavaScript for dynamic town loading -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var provinceSelect = document.getElementById('province');
            var townSelect = document.getElementById('town');
            
            if (provinceSelect && townSelect) {
                provinceSelect.addEventListener('change', function() {
                    var province = this.value;
                    
                    // Clear town options
                    townSelect.innerHTML = '<option value=""><?php _e('Loading...', 'property-manager-pro'); ?></option>';
                    
                    // Load towns for selected province
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_towns_by_province&province=' + encodeURIComponent(province)
                    })
                    .then(response => response.json())
                    .then(data => {
                        townSelect.innerHTML = '<option value=""><?php _e('Any Town', 'property-manager-pro'); ?></option>';
                        
                        if (data.success && data.data.towns) {
                            data.data.towns.forEach(function(town) {
                                var option = document.createElement('option');
                                option.value = town;
                                option.textContent = town;
                                townSelect.appendChild(option);
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error loading towns:', error);
                        townSelect.innerHTML = '<option value=""><?php _e('Any Town', 'property-manager-pro'); ?></option>';
                    });
                });
            }
            
            // Toggle advanced filters
            var toggleBtn = document.querySelector('.advanced-toggle button');
            var advancedSection = document.getElementById('advanced-filters');
            var toggleIcon = document.querySelector('.toggle-icon');
            
            if (toggleBtn && advancedSection) {
                toggleBtn.addEventListener('click', function() {
                    advancedSection.classList.toggle('collapse');
                    toggleIcon.textContent = advancedSection.classList.contains('collapse') ? '▼' : '▲';
                });
                
                // Show advanced section if filters are active
                <?php if ($this->search->has_active_filters()): ?>
                advancedSection.classList.remove('collapse');
                toggleIcon.textContent = '▲';
                <?php endif; ?>
            }
        });
        </script>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate search results toolbar (sorting, view toggle, etc.)
     */
    public function search_results_toolbar($total_results) {
        $sort_options = $this->search->get_sort_options();
        $current_sort = $this->search->get_current_sort();
        $current_view = $this->search->get_param('view', 'grid');
        
        ob_start();
        ?>
        <div class="search-results-toolbar">
            <div class="toolbar-left">
                <div class="results-count">
                    <?php echo $this->search->get_search_summary($total_results); ?>
                </div>
                
                <?php if ($this->search->has_active_filters()): ?>
                <div class="active-filters">
                    <?php foreach ($this->search->get_active_filters() as $filter): ?>
                    <span class="active-filter">
                        <span class="filter-label"><?php echo esc_html($filter['label']); ?>:</span>
                        <span class="filter-value"><?php echo esc_html($filter['value']); ?></span>
                        <a href="<?php echo esc_url($filter['remove_url']); ?>" class="remove-filter">×</a>
                    </span>
                    <?php endforeach; ?>
                    
                    <a href="<?php echo esc_url($this->search->get_clear_filters_url()); ?>" class="clear-all-filters">
                        <?php _e('Clear All', 'property-manager-pro'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="toolbar-right">
                <!-- View Toggle -->
                <div class="view-toggle">
                    <a href="<?php echo esc_url($this->search->get_search_url(array('view' => 'grid'))); ?>" 
                       class="view-btn <?php echo $current_view === 'grid' ? 'active' : ''; ?>" 
                       title="<?php _e('Grid View', 'property-manager-pro'); ?>">
                        <span class="dashicons dashicons-grid-view"></span>
                    </a>
                    <a href="<?php echo esc_url($this->search->get_search_url(array('view' => 'list'))); ?>" 
                       class="view-btn <?php echo $current_view === 'list' ? 'active' : ''; ?>" 
                       title="<?php _e('List View', 'property-manager-pro'); ?>">
                        <span class="dashicons dashicons-list-view"></span>
                    </a>
                    <?php 
                    $options = get_option('property_manager_options', array());
                    if (isset($options['enable_map']) && $options['enable_map']): ?>
                    <a href="<?php echo esc_url($this->search->get_search_url(array('view' => 'map'))); ?>" 
                       class="view-btn <?php echo $current_view === 'map' ? 'active' : ''; ?>" 
                       title="<?php _e('Map View', 'property-manager-pro'); ?>">
                        <span class="dashicons dashicons-location"></span>
                    </a>
                    <?php endif; ?>
                </div>
                
                <!-- Sort Options -->
                <div class="sort-options">
                    <label for="sort-select"><?php _e('Sort by:', 'property-manager-pro'); ?></label>
                    <select id="sort-select" onchange="changeSortOrder(this.value)">
                        <?php foreach ($sort_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" 
                                    <?php selected($current_sort, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Results per page -->
                <div class="per-page-options">
                    <label for="per-page-select"><?php _e('Show:', 'property-manager-pro'); ?></label>
                    <select id="per-page-select" onchange="changePerPage(this.value)">
                        <?php 
                        $per_page_options = array(12, 20, 36, 60);
                        $current_per_page = $this->search->get_param('per_page', 20);
                        foreach ($per_page_options as $option): ?>
                            <option value="<?php echo $option; ?>" 
                                    <?php selected($current_per_page, $option); ?>>
                                <?php echo $option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <script>
        function changeSortOrder(value) {
            var parts = value.split('_');
            var orderby = parts[0];
            var order = parts[1];
            
            var url = new URL(window.location);
            url.searchParams.set('orderby', orderby);
            url.searchParams.set('order', order);
            url.searchParams.set('page', '1'); // Reset to first page
            
            window.location.href = url.toString();
        }
        
        function changePerPage(value) {
            var url = new URL(window.location);
            url.searchParams.set('per_page', value);
            url.searchParams.set('page', '1'); // Reset to first page
            
            window.location.href = url.toString();
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate pagination HTML
     */
    public function pagination($total_pages, $current_page) {
        if ($total_pages <= 1) {
            return '';
        }
        
        $pagination_links = $this->search->get_pagination_links($total_pages, $current_page);
        
        ob_start();
        ?>
        <nav class="property-pagination" aria-label="<?php _e('Search Results Pagination', 'property-manager-pro'); ?>">
            <ul class="pagination">
                
                <?php if (isset($pagination_links['prev'])): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo esc_url($pagination_links['prev']['url']); ?>">
                        <?php echo $pagination_links['prev']['text']; ?>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (isset($pagination_links['pages'])): ?>
                    <?php foreach ($pagination_links['pages'] as $page): ?>
                    <li class="page-item <?php echo $page['current'] ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo esc_url($page['url']); ?>">
                            <?php echo $page['text']; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (isset($pagination_links['next'])): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo esc_url($pagination_links['next']['url']); ?>">
                        <?php echo $pagination_links['next']['text']; ?>
                    </a>
                </li>
                <?php endif; ?>
                
            </ul>
        </nav>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate search filters sidebar
     */
    public function search_filters_sidebar() {
        ob_start();
        ?>
        <div class="search-filters-sidebar">
            <h4><?php _e('Refine Search', 'property-manager-pro'); ?></h4>
            
            <!-- Price Range Filter -->
            <div class="filter-group">
                <h5><?php _e('Price Range', 'property-manager-pro'); ?></h5>
                <div class="price-range-filter">
                    <?php foreach ($this->property->get_price_ranges() as $range => $label): 
                        $range_parts = explode('-', $range);
                        $min_price = $range_parts[0];
                        $max_price = isset($range_parts[1]) ? $range_parts[1] : null;
                        
                        $url = $this->search->get_search_url(array(
                            'price_min' => $min_price,
                            'price_max' => $max_price,
                            'page' => 1
                        ));
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="filter-option">
                        <?php echo esc_html($label); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Property Types Filter -->
            <div class="filter-group">
                <h5><?php _e('Property Type', 'property-manager-pro'); ?></h5>
                <div class="property-types-filter">
                    <?php foreach ($this->property->get_property_types() as $type): 
                        $url = $this->search->get_search_url(array('property_type' => $type, 'page' => 1));
                        $is_active = ($this->search->get_param('property_type') === $type);
                    ?>
                    <a href="<?php echo esc_url($url); ?>" 
                       class="filter-option <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo esc_html($type); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Bedrooms Filter -->
            <div class="filter-group">
                <h5><?php _e('Bedrooms', 'property-manager-pro'); ?></h5>
                <div class="bedrooms-filter">
                    <?php for ($i = 1; $i <= 5; $i++): 
                        $url = $this->search->get_search_url(array('beds_min' => $i, 'page' => 1));
                        $is_active = ($this->search->get_param('beds_min') == $i);
                    ?>
                    <a href="<?php echo esc_url($url); ?>" 
                       class="filter-option <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo $i; ?>+ <?php _e('beds', 'property-manager-pro'); ?>
                    </a>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Features Filter -->
            <div class="filter-group">
                <h5><?php _e('Features', 'property-manager-pro'); ?></h5>
                <div class="features-filter">
                    <a href="<?php echo esc_url($this->search->get_search_url(array('pool' => 1, 'page' => 1))); ?>" 
                       class="filter-option <?php echo $this->search->get_param('pool') ? 'active' : ''; ?>">
                        <?php _e('Swimming Pool', 'property-manager-pro'); ?>
                    </a>
                    <a href="<?php echo esc_url($this->search->get_search_url(array('new_build' => 1, 'page' => 1))); ?>" 
                       class="filter-option <?php echo $this->search->get_param('new_build') ? 'active' : ''; ?>">
                        <?php _e('New Build', 'property-manager-pro'); ?>
                    </a>
                </div>
            </div>
            
            <?php if ($this->search->has_active_filters()): ?>
            <!-- Clear Filters -->
            <div class="filter-group">
                <a href="<?php echo esc_url($this->search->get_clear_filters_url()); ?>" 
                   class="btn btn-outline-secondary btn-sm clear-filters-btn">
                    <?php _e('Clear All Filters', 'property-manager-pro'); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate quick search widget (for homepage, etc.)
     */
    public function quick_search_widget($args = array()) {
        $defaults = array(
            'title' => __('Find Your Dream Property', 'property-manager-pro'),
            'subtitle' => __('Search from thousands of properties', 'property-manager-pro'),
            'show_title' => true,
            'show_subtitle' => true,
            'form_class' => 'property-quick-search-widget',
            'submit_text' => __('Search Properties', 'property-manager-pro')
        );
        
        $args = wp_parse_args($args, $defaults);
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($args['form_class']); ?>">
            <?php if ($args['show_title'] && $args['title']): ?>
            <h3 class="widget-title"><?php echo esc_html($args['title']); ?></h3>
            <?php endif; ?>
            
            <?php if ($args['show_subtitle'] && $args['subtitle']): ?>
            <p class="widget-subtitle"><?php echo esc_html($args['subtitle']); ?></p>
            <?php endif; ?>
            
            <form method="get" action="<?php echo esc_url($this->search->get_search_url()); ?>" class="quick-search-form">
                <div class="search-fields">
                    <div class="search-field location-field">
                        <input type="text" 
                               name="location" 
                               placeholder="<?php _e('Enter location...', 'property-manager-pro'); ?>"
                               value="<?php echo esc_attr($this->search->get_param('location')); ?>">
                    </div>
                    
                    <div class="search-field type-field">
                        <select name="property_type">
                            <option value=""><?php _e('Property Type', 'property-manager-pro'); ?></option>
                            <?php foreach ($this->property->get_property_types() as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>">
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="search-field price-field">
                        <select name="price_range" onchange="setPriceRange(this.value)">
                            <option value=""><?php _e('Price Range', 'property-manager-pro'); ?></option>
                            <?php foreach ($this->property->get_price_ranges() as $range => $label): ?>
                                <option value="<?php echo esc_attr($range); ?>">
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="price_min" id="price_min">
                        <input type="hidden" name="price_max" id="price_max">
                    </div>
                    
                    <div class="search-field beds-field">
                        <select name="beds_min">
                            <option value=""><?php _e('Bedrooms', 'property-manager-pro'); ?></option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?>+</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="search-actions">
                    <button type="submit" class="btn btn-primary btn-lg search-btn">
                        <?php echo esc_html($args['submit_text']); ?>
                    </button>
                    
                    <a href="<?php echo esc_url($this->get_advanced_search_url()); ?>" class="advanced-search-link">
                        <?php _e('Advanced Search', 'property-manager-pro'); ?>
                    </a>
                </div>
            </form>
        </div>
        
        <script>
        function setPriceRange(range) {
            var parts = range.split('-');
            var minInput = document.getElementById('price_min');
            var maxInput = document.getElementById('price_max');
            
            if (parts.length >= 1) {
                minInput.value = parts[0];
            }
            if (parts.length >= 2) {
                maxInput.value = parts[1];
            }
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get advanced search page URL
     */
    public function get_advanced_search_url() {
        $options = get_option('property_manager_pages', array());
        $advanced_search_page_id = isset($options['property_advanced_search']) ? $options['property_advanced_search'] : null;
        
        if ($advanced_search_page_id) {
            return get_permalink($advanced_search_page_id);
        }
        
        return $this->search->get_search_url();
    }
    
    /**
     * Generate saved search alert signup form
     */
    public function search_alert_form($args = array()) {
        $defaults = array(
            'title' => __('Save This Search', 'property-manager-pro'),
            'description' => __('Get email alerts when new properties match your criteria', 'property-manager-pro'),
            'form_class' => 'search-alert-form'
        );
        
        $args = wp_parse_args($args, $defaults);
        $search_params = $this->search->get_search_params();
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($args['form_class']); ?>">
            <h4><?php echo esc_html($args['title']); ?></h4>
            <p><?php echo esc_html($args['description']); ?></p>
            
            <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" class="ajax-form" data-action="save_search_alert">
                <?php wp_nonce_field('save_search_alert', 'search_alert_nonce'); ?>
                
                <input type="hidden" name="action" value="save_search_alert">
                <input type="hidden" name="search_criteria" value="<?php echo esc_attr(json_encode($search_params)); ?>">
                
                <div class="form-group">
                    <label for="alert_name"><?php _e('Search Name', 'property-manager-pro'); ?></label>
                    <input type="text" 
                           id="alert_name" 
                           name="alert_name" 
                           class="form-control" 
                           placeholder="<?php _e('My Property Search', 'property-manager-pro'); ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="alert_email"><?php _e('Email Address', 'property-manager-pro'); ?></label>
                    <input type="email" 
                           id="alert_email" 
                           name="alert_email" 
                           class="form-control" 
                           value="<?php echo is_user_logged_in() ? wp_get_current_user()->user_email : ''; ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="alert_frequency"><?php _e('Alert Frequency', 'property-manager-pro'); ?></label>
                    <select id="alert_frequency" name="alert_frequency" class="form-control">
                        <option value="daily"><?php _e('Daily', 'property-manager-pro'); ?></option>
                        <option value="weekly" selected><?php _e('Weekly', 'property-manager-pro'); ?></option>
                        <option value="monthly"><?php _e('Monthly', 'property-manager-pro'); ?></option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php _e('Save Search Alert', 'property-manager-pro'); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <?php _e('Cancel', 'property-manager-pro'); ?>
                    </button>
                </div>
                
                <div class="form-message"></div>
            </form>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var forms = document.querySelectorAll('.ajax-form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = new FormData(form);
                    var messageDiv = form.querySelector('.form-message');
                    var submitBtn = form.querySelector('button[type="submit"]');
                    
                    submitBtn.disabled = true;
                    submitBtn.textContent = '<?php _e('Saving...', 'property-manager-pro'); ?>';
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            messageDiv.innerHTML = '<div class="alert alert-success">' + data.data.message + '</div>';
                            form.reset();
                        } else {
                            messageDiv.innerHTML = '<div class="alert alert-danger">' + data.data.message + '</div>';
                        }
                    })
                    .catch(error => {
                        messageDiv.innerHTML = '<div class="alert alert-danger"><?php _e('An error occurred. Please try again.', 'property-manager-pro'); ?></div>';
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = '<?php _e('Save Search Alert', 'property-manager-pro'); ?>';
                    });
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}