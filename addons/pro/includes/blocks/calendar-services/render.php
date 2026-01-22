<?php
/**
 * Calendar Services Block Render
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

if ( ! empty( $attributes['show_price'] ) ) {
	$shortcode_atts[] = 'show_price="yes"';
}

if ( ! empty( $attributes['show_duration'] ) ) {
	$shortcode_atts[] = 'show_duration="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_calendar_services ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
