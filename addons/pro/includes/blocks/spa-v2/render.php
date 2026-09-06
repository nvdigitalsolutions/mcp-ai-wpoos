<?php
/**
 * NV oOS Pro SPA v2 Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['assistant_id'] ) ) {
	$shortcode_atts[] = 'assistant_id="' . esc_attr( absint( $attributes['assistant_id'] ) ) . '"';
}

if ( ! empty( $attributes['mode'] ) ) {
	$shortcode_atts[] = 'mode="' . esc_attr( sanitize_key( (string) $attributes['mode'] ) ) . '"';
}

if ( ! empty( $attributes['theme'] ) ) {
	$shortcode_atts[] = 'theme="' . esc_attr( sanitize_key( (string) $attributes['theme'] ) ) . '"';
}

if ( ! empty( $attributes['height'] ) ) {
	$shortcode_atts[] = 'height="' . esc_attr( sanitize_text_field( $attributes['height'] ) ) . '"';
}

if ( ! empty( $attributes['guest'] ) ) {
	$shortcode_atts[] = 'guest="1"';
}

if ( ! empty( $attributes['allow_sensitive_tools'] ) ) {
	$shortcode_atts[] = 'allow_sensitive_tools="1"';
}

if ( isset( $attributes['show_sidebar'] ) && ! $attributes['show_sidebar'] ) {
	$shortcode_atts[] = 'show_sidebar="0"';
}

// Build shortcode string.
$shortcode = '[nvoos_pro_spa ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
