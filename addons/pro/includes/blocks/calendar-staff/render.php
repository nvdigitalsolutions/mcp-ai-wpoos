<?php
/**
 * Calendar Staff Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['display'] ) ) {
	$shortcode_atts[] = 'display="' . esc_attr( $attributes['display'] ) . '"';
}

if ( ! empty( $attributes['columns'] ) && 'grid' === $attributes['display'] ) {
	$shortcode_atts[] = 'columns="' . absint( $attributes['columns'] ) . '"';
}

if ( ! empty( $attributes['show_bio'] ) ) {
	$shortcode_atts[] = 'show_bio="yes"';
}

if ( ! empty( $attributes['show_services'] ) ) {
	$shortcode_atts[] = 'show_services="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_calendar_staff ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
