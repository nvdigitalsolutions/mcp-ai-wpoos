<?php
/**
 * E-commerce Orders Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['status'] ) ) {
	$shortcode_atts[] = 'status="' . esc_attr( $attributes['status'] ) . '"';
}

if ( ! empty( $attributes['limit'] ) ) {
	$shortcode_atts[] = 'limit="' . absint( $attributes['limit'] ) . '"';
}

if ( ! empty( $attributes['show_customer'] ) ) {
	$shortcode_atts[] = 'show_customer="yes"';
}

if ( ! empty( $attributes['show_total'] ) ) {
	$shortcode_atts[] = 'show_total="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_ecommerce_orders ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
