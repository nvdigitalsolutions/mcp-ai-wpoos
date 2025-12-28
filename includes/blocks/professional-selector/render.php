<?php
/**
 * Server-side rendering of the `wp-mcp-ai/professional-selector` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$default_professional     = isset( $attributes['defaultProfessional'] ) ? sanitize_text_field( $attributes['defaultProfessional'] ) : '';
$default_provider         = isset( $attributes['defaultProvider'] ) ? sanitize_key( $attributes['defaultProvider'] ) : '';
$default_model            = isset( $attributes['defaultModel'] ) ? sanitize_text_field( $attributes['defaultModel'] ) : '';
$show_temperature         = ! empty( $attributes['showTemperature'] );
$allow_guests             = ! empty( $attributes['allowGuests'] );
$save_transcript          = isset( $attributes['saveTranscript'] ) ? $attributes['saveTranscript'] : true;
$enable_streaming         = isset( $attributes['enableStreaming'] ) ? $attributes['enableStreaming'] : true;
$allow_sensitive_tools    = ! empty( $attributes['allowSensitiveTools'] );
$template                 = isset( $attributes['template'] ) ? sanitize_key( $attributes['template'] ) : 'classic';

// Build shortcode attributes.
$shortcode_atts = array();

if ( $default_professional ) {
	$shortcode_atts[] = 'default_professional="' . esc_attr( $default_professional ) . '"';
}

if ( $default_provider ) {
	$shortcode_atts[] = 'default_provider="' . esc_attr( $default_provider ) . '"';
}

if ( $default_model ) {
	$shortcode_atts[] = 'default_model="' . esc_attr( $default_model ) . '"';
}

if ( $show_temperature ) {
	$shortcode_atts[] = 'show_temperature="true"';
}

if ( $allow_guests ) {
	$shortcode_atts[] = 'allow_guests="true"';
}

if ( ! $save_transcript ) {
	$shortcode_atts[] = 'save_transcript="false"';
}

if ( $enable_streaming ) {
	$shortcode_atts[] = 'enable_streaming="true"';
}

if ( $allow_sensitive_tools ) {
	$shortcode_atts[] = 'allow_sensitive_tools="true"';
}

if ( $template && 'classic' !== $template ) {
	$shortcode_atts[] = 'template="' . esc_attr( $template ) . '"';
}

$shortcode = '[mcp_ai_professional_selector ' . implode( ' ', $shortcode_atts ) . ']';

// Get wrapper attributes - handle both block and non-block contexts.
$wrapper_class = 'wp-block-wp-mcp-ai-professional-selector';

if ( function_exists( 'get_block_wrapper_attributes' ) && isset( $block ) && is_object( $block ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => $wrapper_class,
		)
	);
} else {
	// Non-block context fallback.
	$wrapper_attributes = sprintf( 'class="%s"', esc_attr( $wrapper_class ) );
}

echo '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode handles escaping.
echo do_shortcode( $shortcode );
echo '</div>';
