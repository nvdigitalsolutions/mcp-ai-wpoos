<?php
/**
 * Server-side rendering of the `wp-mcp-ai/chat` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assistant_id          = isset( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
$allow_guests          = ! empty( $attributes['allowGuests'] );
$save_transcript       = isset( $attributes['saveTranscript'] ) ? $attributes['saveTranscript'] : true;
$enable_streaming      = isset( $attributes['enableStreaming'] ) ? $attributes['enableStreaming'] : true;
$allow_sensitive_tools = ! empty( $attributes['allowSensitiveTools'] );
$show_build_button     = ! empty( $attributes['showBuildButton'] );
$template              = isset( $attributes['template'] ) ? sanitize_key( $attributes['template'] ) : 'classic';

// Build shortcode attributes.
$shortcode_atts = array();

if ( $assistant_id ) {
	$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
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

$shortcode = '[mcp_ai_chat ' . implode( ' ', $shortcode_atts ) . ']';

// Get wrapper attributes - handle both block and non-block contexts.
// Check if we're in a proper block rendering context (has $block object).
$wrapper_class = 'wp-block-wp-mcp-ai-chat';
if ( $show_build_button ) {
	$wrapper_class .= ' wp-block-wp-mcp-ai-chat--with-build';
}

if ( function_exists( 'get_block_wrapper_attributes' ) && isset( $block ) && is_object( $block ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => $wrapper_class,
		)
	);
} else {
	// Non-block context fallback (e.g., direct include in admin pages).
	$wrapper_attributes = sprintf( 'class="%s"', esc_attr( $wrapper_class ) );
}

echo '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode handles escaping.
echo do_shortcode( $shortcode );
echo '</div>';
