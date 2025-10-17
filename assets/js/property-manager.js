jQuery(document).ready(function ($) {
    // Location autocomplete
    $('#location').on('input', function () {
        var query = $(this).val();
        if (query.length >= 2) {
            $.ajax({
                url: property_manager_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'property_search_suggestions',
                    query: query,
                    nonce: property_manager_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var suggestions = $('#location-suggestions');
                        suggestions.empty();

                        if (response.data.length > 0) {
                            $.each(response.data, function (index, item) {
                                suggestions.append('<div class="suggestion-item" data-value="' + item.value + '">' + item.label + '</div>');
                            });
                            suggestions.show();
                        } else {
                            suggestions.hide();
                        }
                    }
                }
            });
        } else {
            $('#location-suggestions').hide();
        }
    });

    // Handle suggestion clicks
    $(document).on('click', '.suggestion-item', function () {
        $('#location').val($(this).data('value'));
        $('#location-suggestions').hide();
    });

    // Hide suggestions when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.position-relative').length) {
            $('#location-suggestions').hide();
        }
    });

    // Advanced search toggle icon rotation
    $('[data-bs-toggle="collapse"]').on('click', function () {
        var icon = $(this).find('i');
        if ($($(this).data('bs-target')).hasClass('show')) {
            icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        }
    });

    /***************************************************/

    $('#user-profile-form').on('submit', function (e) {
        e.preventDefault();

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'update_user_profile',
                nonce: property_manager_ajax.nonce,
                first_name: $('#first_name').val(),
                last_name: $('#last_name').val(),
                phone: $('#phone').val(),
                location: $('#location').val(),
                bio: $('#bio').val()
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Change password form
    $('#change-password-form').on('submit', function (e) {
        e.preventDefault();

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'change_user_password',
                nonce: property_manager_ajax.nonce,
                current_password: $('#current_password').val(),
                new_password: $('#new_password').val(),
                confirm_password: $('#confirm_password').val()
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#change-password-form')[0].reset();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Resend verification email
    $('#resend-verification').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true);

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'resend_verification_email',
                nonce: btn.data('nonce')
            },
            success: function (response) {
                alert(response.data.message);
                btn.prop('disabled', false);
            }
        });
    });

    // Export data
    $('#export-data-btn').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true);

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'export_user_data',
                nonce: btn.data('nonce')
            },
            success: function (response) {
                if (response.success) {
                    var dataStr = JSON.stringify(response.data.data, null, 2);
                    var dataBlob = new Blob([dataStr], { type: 'application/json' });
                    var url = URL.createObjectURL(dataBlob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = response.data.filename;
                    link.click();

                    alert(response.data.message);
                }
                btn.prop('disabled', false);
            }
        });
    });

    // Delete account
    $('#confirm-delete-account').on('click', function () {
        if (!$('#confirm_delete').is(':checked')) {
            alert('Please confirm that you understand this action is permanent.');
            return;
        }

        var password = $('#delete_password').val();
        if (!password) {
            alert('Please enter your password.');
            return;
        }

        if (!confirm('Are you absolutely sure you want to delete your account?')) {
            return;
        }

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_user_account',
                nonce: property_manager_ajax.nonce,
                password: password
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.href = response.data.redirect;
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    /***************************************************/

    $('.property-alert-form').on('submit', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $result = $('#alert-signup-result');

        // Get current search criteria from main search form if exists
        var searchCriteria = {};
        var $searchForm = $('.property-search-form-inner');
        if ($searchForm.length) {
            $searchForm.serializeArray().forEach(function (item) {
                if (item.value) {
                    if (searchCriteria[item.name]) {
                        if (Array.isArray(searchCriteria[item.name])) {
                            searchCriteria[item.name].push(item.value);
                        } else {
                            searchCriteria[item.name] = [searchCriteria[item.name], item.value];
                        }
                    } else {
                        searchCriteria[item.name] = item.value;
                    }
                }
            });
        }

        $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Subscribing...');

        var formData = $form.serialize() + '&action=property_create_alert&nonce=' + property_manager_ajax.nonce + '&search_criteria=' + encodeURIComponent(JSON.stringify(searchCriteria));
        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                $button.prop('disabled', false).html('<i class="fas fa-bell me-2"></i>Subscribe to Alerts');

                if (response.success) {
                    $result.html('<div class="alert alert-success">' + response.data.message + '</div>').show();
                    $form[0].reset();
                } else {
                    $result.html('<div class="alert alert-danger">' + response.data.message + '</div>').show();
                }
            },
            error: function () {
                $button.prop('disabled', false).html('<i class="fas fa-bell me-2"></i>Subscribe to Alerts');
                $result.html('<div class="alert alert-danger">An error occurred.Please try again.</div>').show();
            }
        });
    });

    /***************************************************/
    $('#save-search-btn').on('click', function () {
        $('#saveSearchModal').modal('show');
    });

    $('#enable_alerts').on('change', function () {
        if ($(this).is(':checked')) {
            $('#alert-frequency').show();
        } else {
            $('#alert-frequency').hide();
        }
    });

    $('#confirm-save-search').on('click', function () {
        var formData = new FormData($('.property-search-form-inner').get(0));
        formData.append('action', 'property_save_search');
        formData.append('search_name', $('#search_name').val());
        formData.append('enable_alerts', $('#enable_alerts').is(':checked') ? '1' : '0');
        formData.append('alert_frequency', $('#alert_freq').val());
        formData.append('nonce', property_manager_ajax.nonce);

        $.ajax({
            url: property_manager_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    $('#saveSearchModal').modal('hide');
                    alert('Search saved successfully!');
                } else {
                    alert('Error saving search. Please try again.');
                }
            }
        });
    });

    /***************************************************/
});


/**
 * Property Manager Pro - Map Functionality
 */

(function ($) {
    'use strict';

    // Property Map Handler
    const PropertyMap = {
        map: null,
        markers: [],
        markerClusterGroup: null,

        /**
         * Initialize map view
         */
        init: function () {
            const $mapContainer = $('#properties-map');

            if ($mapContainer.length === 0 || typeof L === 'undefined') {
                return;
            }

            this.initializeMap($mapContainer);
        },

        /**
         * Initialize Leaflet map
         */
        initializeMap: function ($container) {
            const propertiesData = $container.data('properties');

            if (!propertiesData || propertiesData.length === 0) {
                this.showNoPropertiesMessage($container);
                return;
            }

            // Create map centered on first property or default location
            const centerLat = propertiesData[0].latitude || 37.8;
            const centerLng = propertiesData[0].longitude || -0.8;

            this.map = L.map('properties-map').setView([centerLat, centerLng], 10);

            // Add OpenStreetMap tile layer (free)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(this.map);

            // Add property markers
            this.addPropertyMarkers(propertiesData);

            // Fit map bounds to show all markers
            if (this.markers.length > 0) {
                const group = new L.featureGroup(this.markers);
                this.map.fitBounds(group.getBounds().pad(0.1));
            }

            // Fix map display issue when container is initially hidden
            setTimeout(() => {
                this.map.invalidateSize();
            }, 100);
        },

        /**
         * Add property markers to map
         */
        addPropertyMarkers: function (properties) {
            const self = this;

            properties.forEach(function (property) {
                if (!property.latitude || !property.longitude) {
                    return;
                }

                // Create custom icon for property markers
                const propertyIcon = L.divIcon({
                    className: 'custom-property-marker',
                    html: '<div class="marker-pin"><i class="fas fa-home"></i></div>',
                    iconSize: [40, 40],
                    iconAnchor: [20, 40],
                    popupAnchor: [0, -40]
                });

                // Create marker
                const marker = L.marker([property.latitude, property.longitude], {
                    icon: propertyIcon,
                    title: property.title
                });

                // Create popup content
                const popupContent = self.createPopupContent(property);
                marker.bindPopup(popupContent, {
                    maxWidth: 300,
                    className: 'property-popup'
                });

                // Add marker to map and store reference
                marker.addTo(self.map);
                self.markers.push(marker);

                // Handle marker click to highlight property card if in list/grid view
                marker.on('click', function () {
                    self.highlightProperty(property.id);
                });
            });
        },

        /**
         * Create popup content for property marker
         */
        createPopupContent: function (property) {
            let html = '<div class="property-map-popup">';

            // Property image
            if (property.image) {
                html += '<div class="popup-image">';
                html += '<img src="' + this.escapeHtml(property.image) + '" alt="' + this.escapeHtml(property.title) + '">';
                html += '</div>';
            }

            // Property details
            html += '<div class="popup-content">';
            html += '<h6 class="popup-title">' + this.escapeHtml(property.title) + '</h6>';

            // Property features
            if (property.beds || property.baths) {
                html += '<div class="popup-features">';
                if (property.beds) {
                    html += '<span><i class="fas fa-bed"></i> ' + property.beds + '</span>';
                }
                if (property.baths) {
                    html += '<span><i class="fas fa-bath"></i> ' + property.baths + '</span>';
                }
                html += '</div>';
            }

            // Location
            if (property.town) {
                html += '<div class="popup-location">';
                html += '<i class="fas fa-map-marker-alt"></i> ' + this.escapeHtml(property.town);
                if (property.province) {
                    html += ', ' + this.escapeHtml(property.province);
                }
                html += '</div>';
            }

            // Price
            html += '<div class="popup-price">' + this.escapeHtml(property.price) + '</div>';

            // View button
            html += '<a href="' + this.escapeHtml(property.url) + '" class="btn btn-warning text-white btn-sm mt-3">';
            html += 'View Details <i class="fas fa-arrow-right"></i>';
            html += '</a>';

            html += '</div>';
            html += '</div>';

            return html;
        },

        /**
         * Highlight property card when marker is clicked
         */
        highlightProperty: function (propertyId) {
            // Remove previous highlights
            $('.property-card, .property-list-item').removeClass('highlighted');

            // Add highlight to matching property
            const $property = $('[data-property-id="' + propertyId + '"]').closest('.property-card, .property-list-item');
            if ($property.length) {
                $property.addClass('highlighted');

                // Scroll to property if not in viewport
                if (!this.isInViewport($property[0])) {
                    $('html, body').animate({
                        scrollTop: $property.offset().top - 100
                    }, 500);
                }

                // Remove highlight after 3 seconds
                setTimeout(function () {
                    $property.removeClass('highlighted');
                }, 3000);
            }
        },

        /**
         * Check if element is in viewport
         */
        isInViewport: function (element) {
            const rect = element.getBoundingClientRect();
            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );
        },

        /**
         * Show message when no properties with coordinates
         */
        showNoPropertiesMessage: function ($container) {
            $container.html(
                '<div class="alert alert-info">' +
                '<i class="fas fa-info-circle"></i> ' +
                'No properties with valid coordinates to display on map.' +
                '</div>'
            );
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        }
    };

    // Initialize map when document is ready
    $(document).ready(function () {
        PropertyMap.init();

        // Reinitialize map if view is switched to map
        $(document).on('click', '.view-switcher a[href*="view=map"]', function () {
            setTimeout(function () {
                if ($('#properties-map').length) {
                    PropertyMap.init();
                }
            }, 200);
        });
    });

    // Make PropertyMap globally accessible if needed
    window.PropertyMap = PropertyMap;

})(jQuery);