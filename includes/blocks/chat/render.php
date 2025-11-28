<?php
/**
 * Server-side rendering of the `wp-mcp-ai/chat` block.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the `wp-mcp-ai/chat` block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block HTML.
 */
function wp_mcp_ai_render_chat_block( $attributes, $content, $block ) {
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

	// Get wrapper attributes.
	$wrapper_class = 'wp-block-wp-mcp-ai-chat';
	if ( $show_build_button ) {
		$wrapper_class .= ' wp-block-wp-mcp-ai-chat--with-build';
	}

	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => $wrapper_class,
		)
	);

	$output = '<div ' . $wrapper_attributes . '>';
	$output .= do_shortcode( $shortcode );
	$output .= '</div>';

	return $output;
}
