<?php
/**
 * Server-side rendering of the `mcp-ai-wpoos/chat-bubble` block.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ── Extract attributes ───────────────────────────────────────────────
 */

// Chat / shortcode attributes.
$assistant_id          = isset( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
$allow_guests          = ! empty( $attributes['allowGuests'] );
$save_transcript       = isset( $attributes['saveTranscript'] ) ? (bool) $attributes['saveTranscript'] : true;
$enable_streaming      = isset( $attributes['enableStreaming'] ) ? (bool) $attributes['enableStreaming'] : true;
$allow_sensitive_tools = ! empty( $attributes['allowSensitiveTools'] );
$template              = isset( $attributes['template'] ) ? sanitize_key( $attributes['template'] ) : 'compact';

// Bubble appearance.
$bubble_position  = isset( $attributes['bubblePosition'] ) ? sanitize_key( $attributes['bubblePosition'] ) : 'bottom-right';
$bubble_size      = isset( $attributes['bubbleSize'] ) ? sanitize_key( $attributes['bubbleSize'] ) : 'medium';
$bubble_animation = isset( $attributes['bubbleAnimation'] ) ? sanitize_key( $attributes['bubbleAnimation'] ) : 'bounce';
$bubble_tooltip   = isset( $attributes['bubbleTooltip'] ) ? trim( sanitize_text_field( $attributes['bubbleTooltip'] ) ) : '';

// Panel settings.
$panel_title  = isset( $attributes['panelTitle'] ) ? sanitize_text_field( $attributes['panelTitle'] ) : __( 'Chat with AI', 'mcp-ai-wpoos' );
$panel_width  = isset( $attributes['panelWidth'] ) ? absint( $attributes['panelWidth'] ) : 400;
$panel_height = isset( $attributes['panelHeight'] ) ? absint( $attributes['panelHeight'] ) : 550;

// Behavior.
$auto_open_delay    = isset( $attributes['autoOpenDelay'] ) ? absint( $attributes['autoOpenDelay'] ) : 0;
$remember_state     = ! empty( $attributes['rememberState'] );
$notification_badge = ! empty( $attributes['notificationBadge'] );

// Colors.
$bubble_color      = isset( $attributes['bubbleColor'] ) ? sanitize_hex_color( $attributes['bubbleColor'] ) : '#4f46e5';
$bubble_text_color = isset( $attributes['bubbleTextColor'] ) ? sanitize_hex_color( $attributes['bubbleTextColor'] ) : '#ffffff';
$header_background = isset( $attributes['headerBackground'] ) ? sanitize_hex_color( $attributes['headerBackground'] ) : '';
$header_text_color = isset( $attributes['headerTextColor'] ) ? sanitize_hex_color( $attributes['headerTextColor'] ) : '#ffffff';

/*
 * ── Enqueue bubble assets ────────────────────────────────────────────
 */

wp_enqueue_script( 'wp-mcp-ai-chat-bubble' );
wp_enqueue_style( 'wp-mcp-ai-chat-bubble-style' );

/*
 * ── Build shortcode ──────────────────────────────────────────────────
 */

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

/*
 * ── Build CSS custom properties ──────────────────────────────────────
 */

$css_vars = array();

if ( '#4f46e5' !== $bubble_color && '' !== $bubble_color ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-color:' . $bubble_color;
}

if ( '#ffffff' !== $bubble_text_color && '' !== $bubble_text_color ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-text-color:' . $bubble_text_color;
}

if ( '' !== $header_background ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-header-background:' . $header_background;
}

if ( '#ffffff' !== $header_text_color && '' !== $header_text_color ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-header-text-color:' . $header_text_color;
}

if ( 400 !== $panel_width ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-panel-width:' . $panel_width . 'px';
}

if ( 550 !== $panel_height ) {
	$css_vars[] = '--wp-mcp-ai-chat-bubble-panel-height:' . $panel_height . 'px';
}

$css_vars_string = implode( ';', $css_vars );

/*
 * ── Build classes and data attributes ────────────────────────────────
 */

$bubble_id = 'wp-mcp-ai-bubble-' . wp_unique_id();

$classes  = 'wp-mcp-ai-chat-bubble';
$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_position;
$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_size;
if ( 'none' !== $bubble_animation ) {
	$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_animation;
}

$data_attrs  = 'data-bubble-id="' . esc_attr( $bubble_id ) . '"';
$data_attrs .= ' data-auto-open-delay="' . esc_attr( (string) $auto_open_delay ) . '"';
$data_attrs .= ' data-remember-state="' . esc_attr( $remember_state ? 'true' : 'false' ) . '"';
$data_attrs .= ' data-notification-badge="' . esc_attr( $notification_badge ? 'true' : 'false' ) . '"';

/*
 * ── Block wrapper ────────────────────────────────────────────────────
 */

$wrapper_class = 'wp-block-mcp-ai-wpoos-chat-bubble';

if ( function_exists( 'get_block_wrapper_attributes' ) && isset( $block ) && is_object( $block ) ) {
	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => $wrapper_class,
		)
	);
} else {
	$wrapper_attributes = sprintf( 'class="%s"', esc_attr( $wrapper_class ) );
}

/*
 * ── Output HTML ──────────────────────────────────────────────────────
 */

echo '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wrapper_attributes is sanitized by get_block_wrapper_attributes() (WP core) or via esc_attr() in the non-block fallback.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs built with esc_attr(), $css_vars_string built with sanitize_hex_color() and absint().
echo '<div class="' . esc_attr( $classes ) . '" ' . $data_attrs . ' style="' . esc_attr( $css_vars_string ) . '">';

echo '<button class="wp-mcp-ai-chat-bubble__trigger" aria-expanded="false" aria-label="' . esc_attr__( 'Open chat', 'mcp-ai-wpoos' ) . '">';
echo '<span class="wp-mcp-ai-chat-bubble__trigger-icon">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
echo '</span>';
echo '<span class="wp-mcp-ai-chat-bubble__trigger-close-icon">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
echo '</span>';
echo '<span class="wp-mcp-ai-chat-bubble__badge" aria-hidden="true"' . ( $notification_badge ? '' : ' hidden' ) . '></span>';
echo '</button>';

if ( '' !== $bubble_tooltip ) {
	echo '<span class="wp-mcp-ai-chat-bubble__tooltip">' . esc_html( $bubble_tooltip ) . '</span>';
}

echo '<div class="wp-mcp-ai-chat-bubble__panel" role="dialog" aria-label="' . esc_attr( $panel_title ) . '" aria-hidden="true" inert>';

echo '<div class="wp-mcp-ai-chat-bubble__panel-header">';
echo '<span class="wp-mcp-ai-chat-bubble__panel-title">' . esc_html( $panel_title ) . '</span>';
echo '<button class="wp-mcp-ai-chat-bubble__panel-close" aria-label="' . esc_attr__( 'Close chat', 'mcp-ai-wpoos' ) . '">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
echo '</button>';
echo '</div>';

/*
 * ── Capture config and process shortcode ─────────────────────────────
 */

$configs_before = isset( $GLOBALS['wp_mcp_ai_chat_configs'] )
	? array_keys( $GLOBALS['wp_mcp_ai_chat_configs'] )
	: array();

$shortcode_output = do_shortcode( $shortcode );

/*
 * Identify new chat instance config(s) created by the shortcode.
 */
$inline_configs = array();
if ( isset( $GLOBALS['wp_mcp_ai_chat_configs'] ) ) {
	foreach ( $GLOBALS['wp_mcp_ai_chat_configs'] as $config_id => $cfg ) {
		if ( ! in_array( $config_id, $configs_before, true ) ) {
			$inline_configs[ $config_id ] = $cfg;
		}
	}
}

echo '<div class="wp-mcp-ai-chat-bubble__panel-body">';

/*
 * Defer chat initialisation: replace the discovery attribute so chat.js
 * does not initialise the container on its DOMContentLoaded pass (while
 * the bubble panel is hidden).  chat-bubble.js _lazyInitChat() renames
 * the attribute back right before calling init() when the bubble opens.
 */
$safe_html = $shortcode_output;
$safe_html = preg_replace( '/data-wp-mcp-ai-chat(?![-\w])/', 'data-wp-mcp-ai-chat-deferred', $safe_html );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is generated by WP_MCP_AI_Shortcode::render_shortcode() which individually escapes all values with esc_attr()/esc_html(). wp_kses_post() strips data-* attributes, SVGs, form elements, and <script type="application/json"> config blocks that the chat UI requires. The regex only renames a data attribute for lazy init.
echo $safe_html;

echo '</div>';

echo '</div>';

/*
 * Output the chat instance config as an inline <script> tag.
 *
 * This guarantees the config is in window.wpMcpAiChatInstances even
 * when wp_add_inline_script() (called inside the shortcode) does not
 * print — e.g. with aggressive script deferral or caching plugins.
 */
if ( ! empty( $inline_configs ) ) {
	// JSON_HEX_TAG prevents </script> breakout; JSON_HEX_AMP prevents HTML entity injection.
	$json_flags = JSON_HEX_TAG | JSON_HEX_AMP;
	$js         = 'window.wpMcpAiChatInstances=window.wpMcpAiChatInstances||{};';
	foreach ( $inline_configs as $config_id => $cfg ) {
		$js .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $config_id, $json_flags ) . ']=' . wp_json_encode( $cfg, $json_flags ) . ';';
	}
	// Plugin requires WP 6.0+; wp_print_inline_script_tag() (added in WP 5.7) is always available.
	wp_print_inline_script_tag( $js );
}

echo '</div>';

echo '</div>';
