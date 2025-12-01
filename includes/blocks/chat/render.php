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

$shortcode = '[mcp_ai_chat ' . implode( ' ', $shortcode_atts ) . ']';

// Get wrapper attributes - handle both block and non-block contexts.
$wrapper_class = 'wp-block-wp-mcp-ai-chat';
if ( $show_build_button ) {
	$wrapper_class .= ' wp-block-wp-mcp-ai-chat--with-build';
}

if ( function_exists( 'get_block_wrapper_attributes' ) ) {
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
echo do_shortcode( $shortcode );
echo '</div>';
