<?php
/**
 * Multilingual Translation Memory Block Render
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

if ( ! empty( $attributes['source_language'] ) ) {
	$shortcode_atts[] = 'source_language="' . esc_attr( $attributes['source_language'] ) . '"';
}

if ( ! empty( $attributes['target_language'] ) ) {
	$shortcode_atts[] = 'target_language="' . esc_attr( $attributes['target_language'] ) . '"';
}

if ( isset( $attributes['quality_score_min'] ) && '' !== $attributes['quality_score_min'] ) {
	$shortcode_atts[] = 'quality_score_min="' . floatval( $attributes['quality_score_min'] ) . '"';
}

if ( ! empty( $attributes['limit'] ) ) {
	$shortcode_atts[] = 'limit="' . absint( $attributes['limit'] ) . '"';
}

// Build shortcode string.
$shortcode = '[mcp_multilingual_translation_memory ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
