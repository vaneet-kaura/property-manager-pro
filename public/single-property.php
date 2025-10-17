<?php
/**
 * Property Details
 */
if (get_query_var('property_id')) {
    $property_id = get_query_var('property_id');
    if (intval($property_id) > 0) {
		get_header();
		echo '<main>';
        echo do_shortcode('[property_single id='.$property_id.']');
		echo '</main>';
		get_footer();
    }
}