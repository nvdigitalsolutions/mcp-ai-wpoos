<?php
/**
 * E-commerce Product Search Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['placeholder'] ) ) {
	$shortcode_atts[] = 'placeholder="' . esc_attr( $attributes['placeholder'] ) . '"';
}

if ( ! empty( $attributes['show_filters'] ) ) {
	$shortcode_atts[] = 'show_filters="yes"';
}

if ( ! empty( $attributes['show_sorting'] ) ) {
	$shortcode_atts[] = 'show_sorting="yes"';
}

if ( ! empty( $attributes['results_per_page'] ) ) {
	$shortcode_atts[] = 'results_per_page="' . absint( $attributes['results_per_page'] ) . '"';
}

// Build shortcode string.
$shortcode = '[mcp_ecommerce_product_search ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
