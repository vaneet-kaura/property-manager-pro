jQuery(document).ready(function($) {
	// Location autocomplete
	$('#location').on('input', function() {
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
				success: function(response) {
					if (response.success) {
						var suggestions = $('#location-suggestions');
						suggestions.empty();
						
						if (response.data.length > 0) {
							$.each(response.data, function(index, item) {
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
	$(document).on('click', '.suggestion-item', function() {
		$('#location').val($(this).data('value'));
		$('#location-suggestions').hide();
	});
	
	// Hide suggestions when clicking outside
	$(document).on('click', function(e) {
		if (!$(e.target).closest('.position-relative').length) {
			$('#location-suggestions').hide();
		}
	});
	
	// Advanced search toggle icon rotation
	$('[data-bs-toggle="collapse"]').on('click', function() {
		var icon = $(this).find('i');
		if ($($(this).data('bs-target')).hasClass('show')) {
			icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
		} else {
			icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
		}
	});
});