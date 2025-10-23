/**
 * Property Manager Pro - Admin JavaScript
 * 
 * Handles all admin-side interactions including AJAX requests,
 * form validations, and UI enhancements.
 * 
 */

jQuery(document).ready(function ($) {
    // Refresh stats
    $('#refresh-stats-btn').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true).find('.dashicons').addClass('dashicons-update-spinning');

        // Clear cache and reload
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'property_manager_clear_dashboard_cache',
                nonce: btn.data('nonce')
            },
            complete: function () {
                location.reload();
            }
        });
    });

    // Process pending images
    $('#process-images-btn').on('click', function () {
        var btn = $(this);
        var nonce = btn.data('nonce');

        btn.prop('disabled', true).text('Processing...');
        $('#image-action-result').html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'property_manager_process_images',
                nonce: nonce,
                batch_size: 10
            },
            success: function (response) {
                if (response.success) {
                    $('#image-action-result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    // Reload page after 2 seconds to update stats
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
                    $('#image-action-result').html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Process Pending Images');
                }
            },
            error: function () {
                $('#image-action-result').html('<div class="notice notice-error inline"><p>An error occurred.</p></div>');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Process Pending Images');
            }
        });
    });

    // Retry failed images
    $('#retry-failed-images-btn').on('click', function () {
        var btn = $(this);
        var nonce = btn.data('nonce');

        btn.prop('disabled', true).text('Retrying...');
        $('#image-action-result').html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'property_manager_retry_failed_images',
                nonce: nonce,
                batch_size: 20
            },
            success: function (response) {
                if (response.success) {
                    $('#image-action-result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
                    $('#image-action-result').html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate" style="margin-top: 3px;"></span> Retry Failed Images');
                }
            },
            error: function () {
                $('#image-action-result').html('<div class="notice notice-error inline"><p>An error occurred.</p></div>');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate" style="margin-top: 3px;"></span> Retry Failed Images');
            }
        });
    });
    
    // Properties - Select all checkboxes
    $('#cb-select-all-1, #cb-select-all-2').on('change', function () {
        var checked = $(this).prop('checked');
        $('tbody input[type="checkbox"]').prop('checked', checked);
    });

    // Update select all when individual checkboxes change
    $('tbody input[type="checkbox"]').on('change', function () {
        var total = $('tbody input[type="checkbox"]').length;
        var checked = $('tbody input[type="checkbox"]:checked').length;
        $('#cb-select-all-1, #cb-select-all-2').prop('checked', total === checked);
    });    

    // Show full message toggle
    $('.show-full-message').on('click', function (e) {
        e.preventDefault();
        var inquiryId = $(this).data('inquiry-id');
        var fullMessage = $('#full-message-' + inquiryId);

        if (fullMessage.is(':visible')) {
            fullMessage.slideUp();
            $(this).text('Show full message');
        } else {
            fullMessage.slideDown();
            $(this).text('Hide full message');
        }
    });    
});