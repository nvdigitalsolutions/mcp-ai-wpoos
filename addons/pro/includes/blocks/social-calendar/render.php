<?php
/**
 * Social Media Calendar Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['view'] ) ) {
	$shortcode_atts[] = 'view="' . esc_attr( $attributes['view'] ) . '"';
}

if ( ! empty( $attributes['platform'] ) ) {
	$shortcode_atts[] = 'platform="' . esc_attr( $attributes['platform'] ) . '"';
}

if ( ! empty( $attributes['show_status'] ) ) {
	$shortcode_atts[] = 'show_status="yes"';
}

if ( ! empty( $attributes['show_preview'] ) ) {
	$shortcode_atts[] = 'show_preview="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_social_media_calendar ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
