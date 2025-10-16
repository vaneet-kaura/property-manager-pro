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