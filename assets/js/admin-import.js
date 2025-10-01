/**
 * Property Manager - Admin Import Page JavaScript
 * 
 * @package PropertyManagerPro
 */

(function ($) {
    'use strict';

    // Wait for DOM to be ready
    $(document).ready(function () {

        const $importBtn = $('#manual-import-btn');
        const $importProgress = $('#import-progress');
        const $importResults = $('#import-results');
        const $progressFill = $('.progress-fill');
        const $progressText = $('.progress-text');

        // Handle manual import button click
        $importBtn.on('click', function (e) {
            e.preventDefault();

            // Confirm before starting
            if (!confirm(propertyManagerImport.i18n.confirm)) {
                return;
            }

            startImport();
        });

        /**
         * Start the import process
         */
        function startImport() {
            // Disable button
            $importBtn.prop('disabled', true).addClass('disabled');

            // Hide previous results
            $importResults.hide().removeClass('success error');

            // Show progress
            $importProgress.show();
            $progressFill.css('width', '0%');
            $progressText.text(propertyManagerImport.i18n.importing);

            // Animate progress bar (indeterminate)
            animateProgressBar();

            // Make AJAX request
            $.ajax({
                url: propertyManagerImport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'property_manager_import_feed',
                    nonce: propertyManagerImport.nonce
                },
                timeout: 300000, // 5 minutes timeout
                success: function (response) {
                    if (response.success) {
                        handleImportSuccess(response.data);
                    } else {
                        handleImportError(response.data);
                    }
                },
                error: function (xhr, status, error) {
                    let errorMessage = propertyManagerImport.i18n.error;

                    if (status === 'timeout') {
                        errorMessage = 'Import timed out. The process may still be running in the background.';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    }

                    handleImportError({ message: errorMessage });
                },
                complete: function () {
                    // Re-enable button
                    $importBtn.prop('disabled', false).removeClass('disabled');

                    // Hide progress
                    setTimeout(function () {
                        $importProgress.hide();
                    }, 1000);
                }
            });
        }

        /**
         * Animate progress bar (indeterminate style)
         */
        function animateProgressBar() {
            let progress = 0;
            const interval = setInterval(function () {
                if (!$importProgress.is(':visible')) {
                    clearInterval(interval);
                    return;
                }

                progress += 2;
                if (progress > 90) {
                    progress = 90; // Cap at 90% until we get results
                }

                $progressFill.css('width', progress + '%');
            }, 200);
        }

        /**
         * Handle successful import
         */
        function handleImportSuccess(data) {
            // Complete progress bar
            $progressFill.css('width', '100%');
            $progressText.text(propertyManagerImport.i18n.success);

            // Show success message
            let resultHtml = '<div class="import-results-box">';
            resultHtml += '<h3>' + propertyManagerImport.i18n.success + '</h3>';
            resultHtml += '<div class="results-summary">';

            if (data.data) {
                resultHtml += '<div class="result-stat imported">';
                resultHtml += '<span class="number">' + data.data.imported + '</span>';
                resultHtml += '<span class="label">Imported</span>';
                resultHtml += '</div>';

                resultHtml += '<div class="result-stat updated">';
                resultHtml += '<span class="number">' + data.data.updated + '</span>';
                resultHtml += '<span class="label">Updated</span>';
                resultHtml += '</div>';

                if (data.data.failed > 0) {
                    resultHtml += '<div class="result-stat failed">';
                    resultHtml += '<span class="number">' + data.data.failed + '</span>';
                    resultHtml += '<span class="label">Failed</span>';
                    resultHtml += '</div>';
                }
            } else {
                resultHtml += '<p>' + data.message + '</p>';
            }

            resultHtml += '</div></div>';

            $importResults.html(resultHtml).addClass('success').show();

            // Reload page after 3 seconds to show updated statistics
            setTimeout(function () {
                location.reload();
            }, 3000);
        }

        /**
         * Handle import error
         */
        function handleImportError(data) {
            // Show error in progress
            $progressFill.css('width', '100%').css('background', '#d63638');
            $progressText.text('Import failed');

            // Show error message
            const errorMessage = data && data.message ? data.message : propertyManagerImport.i18n.error;

            let resultHtml = '<div class="import-results-box">';
            resultHtml += '<h3>Import Failed</h3>';
            resultHtml += '<p>' + escapeHtml(errorMessage) + '</p>';
            resultHtml += '<p class="description">Please check your feed URL and server error logs for more details.</p>';
            resultHtml += '</div>';

            $importResults.html(resultHtml).addClass('error').show();
        }

        /**
         * Escape HTML to prevent XSS
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        }

        /**
         * Auto-refresh import statistics every 30 seconds if import is running
         */
        function checkImportStatus() {
            // This could be enhanced to poll for import status
            // For now, we just reload the page if user navigates back
        }

        // Initialize
        checkImportStatus();
    });

})(jQuery);