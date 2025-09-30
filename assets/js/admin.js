var map, marker, ajax_nonce;
jQuery(document).ready(function($) {
    /*** Dashboard ***/
    // Process pending images
    $('#process-images-btn').click(function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Processing...');
                
        $.post(ajaxurl, {
            action: 'property_manager_process_images',
            nonce: ajax_nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Process Pending Images');
            if (response.success) {
                alert('Images processed ' + response.data.processed);
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
            nonce: ajax_nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Retry Failed Image');
            if (response.success) {
                alert('Failed images queued for retry: ' + response.data.retried);
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });

    /*** Feed Import ***/
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
                nonce: ajax_nonce
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

    /*** Enquiries ***/
    $('.show-full-message').click(function(e) {
        e.preventDefault();
        $(this).hide().siblings('.full-message').show();
    });

    /*** Properties ***/
    $('#cb-select-all-1').change(function() {
        $('input[name="properties[]"]').prop('checked', this.checked);
    });


    
            
    // Search address functionality
    $('#search_address_btn').click(function() {
        var address = $('#address_search').val();
        if (!address) {
            alert('Please enter an address to search');
            return;
        }
        $btn = $(this);
        $btn.attr("disabled", "disabled");

        // Geocode address using Nominatim
        $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            data: {
                format: 'json',
                q: address,
                limit: 1,
                addressdetails: 1
            },
            success: function(data) {
                $btn.removeAttr("disabled");
                if (data && data.length > 0) {
                    var result = data[0];
                    var lat = parseFloat(result.lat);
                    var lng = parseFloat(result.lon);
                            
                    // Center map on result
                    map.setView([lat, lng], 15);
                            
                    // Remove existing marker
                    if (marker) {
                        map.removeLayer(marker);
                    }
                            
                    // Add new marker
                    marker = L.marker([lat, lng], {
                        draggable: true
                    }).addTo(map);
                            
                    // Handle marker drag
                    marker.on('dragend', function(e) {
                        var position = marker.getLatLng();
                        updateLocation(position.lat, position.lng);
                    });
                            
                    // Update location
                    updateLocation(lat, lng);
                            
                    // Clear search field
                    $('#address_search').val('');
                } else {
                    alert('Address not found. Please try a different search term.');
                }
            },
            error: function() {
                $btn.removeAttr("disabled");
                alert('Search failed. Please try again.');
            }
        });
    });
            
    
    // Allow Enter key for address search
    $('#address_search').keypress(function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#search_address_btn').click();
        }
    });
            
    // Manual coordinates toggle
    $('#manual_coordinates').click(function() {
        var $lat = $('#latitude');
        var $lng = $('#longitude');
        var $town = $('#town');
        var $province = $('#province');
        var $location = $('#location_detail');
                
        if ($lat.prop('readonly')) {
            // Enable manual entry
            $lat.prop('readonly', false);
            $lng.prop('readonly', false);
            $town.prop('readonly', false);
            $province.prop('readonly', false);
            $location.prop('readonly', false);
            $(this).text('Use Map');
        } else {
            // Disable manual entry
            $lat.prop('readonly', true);
            $lng.prop('readonly', true);
            $town.prop('readonly', true);
            $province.prop('readonly', true);
            $location.prop('readonly', true);
            $(this).text('Manual Entry');
                    
            // Update map if coordinates changed
            var lat = parseFloat($lat.val());
            var lng = parseFloat($lng.val());
            if (!isNaN(lat) && !isNaN(lng)) {
                map.setView([lat, lng], 15);
                        
                if (marker) {
                    map.removeLayer(marker);
                }
                        
                marker = L.marker([lat, lng], {
                    draggable: true
                }).addTo(map);
                        
                marker.on('dragend', function(e) {
                    var position = marker.getLatLng();
                    updateLocation(position.lat, position.lng);
                });
            }
        }
    });
            
    // Description tabs
    $('.tab-button').click(function() {
        var tab = $(this).data('tab');
                
        $('.tab-button').removeClass('active');
        $(this).addClass('active');
                
        $('.tab-pane').removeClass('active');
        $('#desc-' + tab).addClass('active');
    });
            
    // Add feature
    var featureIndex = $('.feature-item').length;
    $('#add-feature').click(function() {
        var html = '<div class="feature-item">' +
            '<input type="text" name="features[' + featureIndex + ']" value="" class="widefat" />' +
            '<button type="button" class="button remove-feature">Remove</button>' +
            '</div>';
        $('#property-features').append(html);
        featureIndex++;
    });
            
    // Remove feature
    $(document).on('click', '.remove-feature', function() {
        $(this).closest('.feature-item').remove();
    });
            
    // Add image
    var imageIndex = $('.image-item').length;
    $('#add-image').click(function() {
        var frame = wp.media({
            title: 'Select Property Image',
            multiple: false,
            library: { type: 'image' },
            button: { text: 'Use this image' }
        });
                
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var html = '<div class="image-item" data-index="' + imageIndex + '">' +
                '<img src="' + attachment.url + '" alt="" style="max-width: 100px; height: auto;" />' +
                '<input type="hidden" name="property_images[' + imageIndex + '][url]" value="' + attachment.url + '" />' +
                '<input type="text" name="property_images[' + imageIndex + '][title]" value="' + (attachment.title || '') + '" placeholder="Image title" class="widefat" />' +
                '<input type="text" name="property_images[' + imageIndex + '][alt]" value="' + (attachment.alt || '') + '" placeholder="Alt text" class="widefat" />' +
                '<button type="button" class="button remove-image">Remove</button>' +
                '</div>';
            $('#property-images').append(html);
            imageIndex++;
        });
                
        frame.open();
    });
            
    // Remove image
    $(document).on('click', '.remove-image', function() {
        $(this).closest('.image-item').remove();
    });
            
    // Make images sortable
    $('#property-images').sortable({
        handle: 'img',
        cursor: 'move',
        update: function() {
            // Update input names to maintain order
            $('#property-images .image-item').each(function(index) {
                $(this).find('input').each(function() {
                    var name = $(this).attr('name');
                    if (name) {
                        var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                        $(this).attr('name', newName);
                    }
                });
            });
        }
    });
});


function initMap(initialLat, initialLng) {
    // Initialize Leaflet map
    map = L.map('property_location_map').setView([initialLat, initialLng], 15);
                
    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
                
    // Add marker if coordinates exist
    if (initialLat !== 37.8836 || initialLng !== -4.3242) {
        marker = L.marker([initialLat, initialLng], {
            draggable: true
        }).addTo(map);
                    
        // Handle marker drag
        marker.on('dragend', function(e) {
            var position = marker.getLatLng();
            updateLocation(position.lat, position.lng);
        });
    }
                
    // Handle map clicks
    map.on('click', function(e) {
        var lat = e.latlng.lat;
        var lng = e.latlng.lng;
                    
        // Remove existing marker
        if (marker) {
            map.removeLayer(marker);
        }
                    
        // Add new marker
        marker = L.marker([lat, lng], {
            draggable: true
        }).addTo(map);
                    
        // Handle marker drag
        marker.on('dragend', function(e) {
            var position = marker.getLatLng();
            updateLocation(position.lat, position.lng);
        });
                    
        // Update location
        updateLocation(lat, lng);
    });
}
            
// Update location fields with reverse geocoding
function updateLocation(lat, lng) {
    // Update coordinate fields
    jQuery('#latitude').val(lat.toFixed(8));
    jQuery('#longitude').val(lng.toFixed(8));
                
    // Reverse geocoding using Nominatim (OpenStreetMap)
    jQuery.ajax({
        url: 'https://nominatim.openstreetmap.org/reverse',
        data: {
            format: 'json',
            lat: lat,
            lon: lng,
            addressdetails: 1,
            accept_language: 'en'
        },
        success: function(data) {
            if (data && data.address) {
                var address = data.address;
                            
                // Extract location components
                var town = address.city || address.town || address.village || address.municipality || '';
                var province = address.state || address.province || address.county || '';
                var fullAddress = data.display_name || '';
                            
                // Update fields
                jQuery('#town').val(town);
                jQuery('#province').val(province);
                jQuery('#location_detail').val(fullAddress);
            }
        },
        error: function() {
            console.log('Geocoding failed');
        }
    });
}