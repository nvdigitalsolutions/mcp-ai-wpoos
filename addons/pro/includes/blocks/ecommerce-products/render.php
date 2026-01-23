<?php
/**
 * E-commerce Products Block Render
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

if ( ! empty( $attributes['limit'] ) ) {
	$shortcode_atts[] = 'limit="' . absint( $attributes['limit'] ) . '"';
}

if ( ! empty( $attributes['category'] ) ) {
	$shortcode_atts[] = 'category="' . esc_attr( $attributes['category'] ) . '"';
}

if ( ! empty( $attributes['orderby'] ) ) {
	$shortcode_atts[] = 'orderby="' . esc_attr( $attributes['orderby'] ) . '"';
}

if ( ! empty( $attributes['order'] ) ) {
	$shortcode_atts[] = 'order="' . esc_attr( $attributes['order'] ) . '"';
}

// Build shortcode string.
$shortcode = '[mcp_ecommerce_products ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
