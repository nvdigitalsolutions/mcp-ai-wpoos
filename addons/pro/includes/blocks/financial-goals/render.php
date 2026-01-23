<?php
/**
 * Financial Goals Block Render
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

if ( ! empty( $attributes['status'] ) && 'all' !== $attributes['status'] ) {
	$shortcode_atts[] = 'status="' . esc_attr( $attributes['status'] ) . '"';
}

if ( ! empty( $attributes['show_progress'] ) ) {
	$shortcode_atts[] = 'show_progress="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_financial_goals ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
