<?php
/**
 * Financial Budget Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['view'] ) ) {
	$shortcode_atts[] = 'view="' . esc_attr( $attributes['view'] ) . '"';
}

if ( ! empty( $attributes['period'] ) ) {
	$shortcode_atts[] = 'period="' . esc_attr( $attributes['period'] ) . '"';
}

if ( ! empty( $attributes['show_categories'] ) ) {
	$shortcode_atts[] = 'show_categories="yes"';
}

if ( ! empty( $attributes['show_progress'] ) ) {
	$shortcode_atts[] = 'show_progress="yes"';
}

// Build shortcode string.
$shortcode = '[mcp_financial_budget ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
