/**
 * Property Manager Pro - Admin JavaScript
 * 
 * Handles all admin-side interactions including AJAX requests,
 * form validations, and UI enhancements.
 * 
 * @package PropertyManagerPro
 * @version 1.0.1
 */

(function ($) {
    'use strict';

    // Admin object
    const PropertyManagerAdmin = {

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initComponents();
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function () {
            // Dashboard
            $(document).on('click', '#process-images-btn', this.processImages);
            $(document).on('click', '#retry-failed-images-btn', this.retryFailedImages);

            // Import
            $(document).on('click', '#manual-import-btn', this.startImport);
            $(document).on('click', '#test-feed-url', this.testFeedUrl);

            // Settings
            $(document).on('click', '#test-email-settings', this.testEmail);

            // Properties
            $(document).on('click', '.delete-property', this.confirmDelete);
            $(document).on('change', '#cb-select-all-1', this.selectAllCheckboxes);

            // Images
            $(document).on('click', '.retry-image', this.retrySingleImage);
            $(document).on('click', '.delete-image', this.deleteSingleImage);

            // Form validation
            $(document).on('submit', 'form[data-validate]', this.validateForm);
        },

        /**
         * Initialize components
         */
        initComponents: function () {
            // Initialize tooltips if available
            if (typeof $.fn.tooltip === 'function') {
                $('[data-toggle="tooltip"]').tooltip();
            }

            // Auto-dismiss notices after 5 seconds
            setTimeout(function () {
                $('.notice.is-dismissible').fadeOut();
            }, 5000);

            // Confirm before leaving page with unsaved changes
            this.trackFormChanges();
        },

        /**
         * Process pending images
         */
        processImages: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const $result = $('#image-action-result');
            const batchSize = $('#batch-size').val() || 10;

            if (!confirm(propertyManagerAdmin.strings.confirmImport)) {
                return;
            }

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update spin"></span> ' + propertyManagerAdmin.strings.processing);
            $result.html('');

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_process_images',
                    nonce: propertyManagerAdmin.nonce,
                    batch_size: batchSize
                },
                timeout: 120000, // 2 minutes
                success: function (response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success inline"><p>' +
                            response.data.message + '</p></div>');

                        // Reload after 2 seconds to update stats
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' +
                            response.data.message + '</p></div>');
                        PropertyManagerAdmin.resetButton($btn, 'Process Images');
                    }
                },
                error: function (xhr, status) {
                    let errorMsg = propertyManagerAdmin.strings.error;
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. Images may still be processing in the background.';
                    }
                    $result.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                    PropertyManagerAdmin.resetButton($btn, 'Process Images');
                }
            });
        },

        /**
         * Retry failed image downloads
         */
        retryFailedImages: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const $result = $('#image-action-result');

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update spin"></span> ' + propertyManagerAdmin.strings.processing);
            $result.html('');

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_retry_failed_images',
                    nonce: propertyManagerAdmin.nonce,
                    batch_size: 10
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success inline"><p>' +
                            response.data.message + '</p></div>');

                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' +
                            response.data.message + '</p></div>');
                        PropertyManagerAdmin.resetButton($btn, 'Retry Failed Images');
                    }
                },
                error: function () {
                    $result.html('<div class="notice notice-error inline"><p>' +
                        propertyManagerAdmin.strings.error + '</p></div>');
                    PropertyManagerAdmin.resetButton($btn, 'Retry Failed Images');
                }
            });
        },

        /**
         * Start feed import
         */
        startImport: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const $progress = $('#import-progress');
            const $results = $('#import-results');

            if (!confirm(propertyManagerAdmin.strings.confirmImport)) {
                return;
            }

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update spin"></span> ' + propertyManagerAdmin.strings.processing);
            $progress.show();
            $results.hide().removeClass('success error');

            // Animate progress bar
            PropertyManagerAdmin.animateProgressBar();

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_import_feed',
                    nonce: propertyManagerAdmin.nonce
                },
                timeout: 300000, // 5 minutes
                success: function (response) {
                    $progress.hide();

                    if (response.success) {
                        PropertyManagerAdmin.showImportResults(response.data, 'success');

                        // Reload after 3 seconds
                        setTimeout(function () {
                            location.reload();
                        }, 3000);
                    } else {
                        PropertyManagerAdmin.showImportResults(response.data, 'error');
                        PropertyManagerAdmin.resetButton($btn, 'Start Import');
                    }
                },
                error: function (xhr, status) {
                    $progress.hide();

                    let errorMsg = propertyManagerAdmin.strings.error;
                    if (status === 'timeout') {
                        errorMsg = 'Import timed out. The process may still be running in the background.';
                    }

                    PropertyManagerAdmin.showImportResults({ message: errorMsg }, 'error');
                    PropertyManagerAdmin.resetButton($btn, 'Start Import');
                }
            });
        },

        /**
         * Test feed URL
         */
        testFeedUrl: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const feedUrl = $('#feed_url').val();
            const $result = $('#feed-url-test-result');

            if (!feedUrl) {
                $result.html('<div class="notice notice-error inline"><p>Please enter a feed URL first.</p></div>');
                return;
            }

            $btn.prop('disabled', true).text('Testing...');
            $result.html('<p>Testing feed URL...</p>');

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_test_feed_url',
                    nonce: propertyManagerAdmin.nonce,
                    feed_url: feedUrl
                },
                timeout: 30000,
                success: function (response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success inline"><p>' +
                            response.data.message + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' +
                            response.data.message + '</p></div>');
                    }
                },
                error: function (xhr, status) {
                    let errorMsg = 'Error testing feed URL.';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The feed URL may be slow to respond.';
                    }
                    $result.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Test URL');
                }
            });
        },

        /**
         * Send test email
         */
        testEmail: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const email = $('#admin_email').val() || propertyManagerAdmin.adminEmail;

            if (!confirm('Send a test email to ' + email + '?')) {
                return;
            }

            $btn.prop('disabled', true).text('Sending...');

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_test_email',
                    nonce: propertyManagerAdmin.nonce,
                    email: email
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                error: function () {
                    alert(propertyManagerAdmin.strings.error);
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Send Test Email');
                }
            });
        },

        /**
         * Confirm delete action
         */
        confirmDelete: function (e) {
            if (!confirm(propertyManagerAdmin.strings.confirmDelete)) {
                e.preventDefault();
                return false;
            }
        },

        /**
         * Select all checkboxes
         */
        selectAllCheckboxes: function () {
            const isChecked = $(this).prop('checked');
            $(this).closest('table').find('tbody input[type="checkbox"]').prop('checked', isChecked);
        },

        /**
         * Retry single image download
         */
        retrySingleImage: function (e) {
            e.preventDefault();

            const $btn = $(this);
            const imageId = $btn.data('image-id');

            $btn.prop('disabled', true).text('Retrying...');

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_retry_single_image',
                    nonce: propertyManagerAdmin.nonce,
                    image_id: imageId
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false).text('Retry');
                    }
                },
                error: function () {
                    alert(propertyManagerAdmin.strings.error);
                    $btn.prop('disabled', false).text('Retry');
                }
            });
        },

        /**
         * Delete single image
         */
        deleteSingleImage: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to delete this image?')) {
                return;
            }

            const $btn = $(this);
            const imageId = $btn.data('image-id');

            $btn.prop('disabled', true);

            $.ajax({
                url: propertyManagerAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_delete_image',
                    nonce: propertyManagerAdmin.nonce,
                    image_id: imageId
                },
                success: function (response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(function () {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false);
                    }
                },
                error: function () {
                    alert(propertyManagerAdmin.strings.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * Validate form before submission
         */
        validateForm: function (e) {
            const $form = $(this);
            let isValid = true;
            let errors = [];

            // Check required fields
            $form.find('[required]').each(function () {
                const $field = $(this);
                if (!$field.val()) {
                    isValid = false;
                    errors.push($field.attr('name') + ' is required');
                    $field.addClass('error');
                } else {
                    $field.removeClass('error');
                }
            });

            // Check email fields
            $form.find('input[type="email"]').each(function () {
                const $field = $(this);
                const email = $field.val();
                if (email && !PropertyManagerAdmin.isValidEmail(email)) {
                    isValid = false;
                    errors.push('Invalid email address: ' + email);
                    $field.addClass('error');
                }
            });

            // Check URL fields
            $form.find('input[type="url"]').each(function () {
                const $field = $(this);
                const url = $field.val();
                if (url && !PropertyManagerAdmin.isValidUrl(url)) {
                    isValid = false;
                    errors.push('Invalid URL: ' + url);
                    $field.addClass('error');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
        },

        /**
         * Track form changes
         */
        trackFormChanges: function () {
            let formChanged = false;

            $('form').on('change input', 'input, select, textarea', function () {
                formChanged = true;
            });

            $(window).on('beforeunload', function () {
                if (formChanged) {
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });

            $('form').on('submit', function () {
                formChanged = false;
            });
        },

        /**
         * Show import results
         */
        showImportResults: function (data, type) {
            const $results = $('#import-results');
            let html = '<div class="import-results-box">';

            if (type === 'success') {
                html += '<h3><span class="dashicons dashicons-yes-alt"></span> Import Completed</h3>';

                if (data.imported !== undefined) {
                    html += '<div class="import-stats">';
                    html += '<div class="stat-item"><span class="number">' + data.imported + '</span><span class="label">Imported</span></div>';
                    html += '<div class="stat-item"><span class="number">' + data.updated + '</span><span class="label">Updated</span></div>';
                    if (data.failed > 0) {
                        html += '<div class="stat-item error"><span class="number">' + data.failed + '</span><span class="label">Failed</span></div>';
                    }
                    html += '</div>';
                } else {
                    html += '<p>' + data.message + '</p>';
                }
            } else {
                html += '<h3><span class="dashicons dashicons-warning"></span> Import Failed</h3>';
                html += '<p class="error-message">' + PropertyManagerAdmin.escapeHtml(data.message) + '</p>';
                html += '<p class="description">Please check your feed URL and server error logs.</p>';
            }

            html += '</div>';

            $results.html(html).addClass(type).show();
        },

        /**
         * Animate progress bar
         */
        animateProgressBar: function () {
            const $progressFill = $('.progress-fill');
            const $progressText = $('.progress-text');

            let progress = 0;
            const interval = setInterval(function () {
                progress += Math.random() * 10;
                if (progress > 90) {
                    progress = 90;
                    clearInterval(interval);
                }
                $progressFill.css('width', progress + '%');
                $progressText.text('Processing... ' + Math.round(progress) + '%');
            }, 500);
        },

        /**
         * Reset button state
         */
        resetButton: function ($btn, text) {
            $btn.prop('disabled', false)
                .html(text);
        },

        /**
         * Validate email
         */
        isValidEmail: function (email) {
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },

        /**
         * Validate URL
         */
        isValidUrl: function (url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
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

    // Initialize when document is ready
    $(document).ready(function () {		
        PropertyManagerAdmin.init();
    });

    // Add spin animation for dashicons
    $('<style>')
        .text('.dashicons.spin { animation: spin 1s linear infinite; } ' +
            '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }')
        .appendTo('head');

})(jQuery);