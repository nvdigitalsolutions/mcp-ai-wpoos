<?php
/**
 * Calendar Booking Form Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['service'] ) ) {
	$shortcode_atts[] = 'service="' . esc_attr( $attributes['service'] ) . '"';
}

if ( ! empty( $attributes['staff'] ) ) {
	$shortcode_atts[] = 'staff="' . esc_attr( $attributes['staff'] ) . '"';
}

if ( ! empty( $attributes['show_calendar'] ) ) {
	$shortcode_atts[] = 'show_calendar="yes"';
}

if ( ! empty( $attributes['show_time_slots'] ) ) {
	$shortcode_atts[] = 'show_time_slots="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_calendar_booking_form ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
