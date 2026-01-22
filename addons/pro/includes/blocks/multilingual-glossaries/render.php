<?php
/**
 * Multilingual Glossaries Block Render
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

if ( ! empty( $attributes['industry'] ) ) {
	$shortcode_atts[] = 'industry="' . esc_attr( $attributes['industry'] ) . '"';
}

if ( ! empty( $attributes['limit'] ) ) {
	$shortcode_atts[] = 'limit="' . absint( $attributes['limit'] ) . '"';
}

// Build shortcode string.
$shortcode = '[mcp_multilingual_glossaries ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
