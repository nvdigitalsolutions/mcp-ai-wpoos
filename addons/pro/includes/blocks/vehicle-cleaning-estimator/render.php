<?php
/**
 * Vehicle Cleaning Estimator Block Render
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build shortcode attributes from block attributes.
$shortcode_atts = array();

if ( ! empty( $attributes['assistant_id'] ) ) {
	$shortcode_atts[] = 'assistant_id="' . esc_attr( $attributes['assistant_id'] ) . '"';
}

if ( ! empty( $attributes['primary_color'] ) ) {
	$shortcode_atts[] = 'primary_color="' . esc_attr( $attributes['primary_color'] ) . '"';
}

if ( isset( $attributes['show_package_selector'] ) && ! $attributes['show_package_selector'] ) {
	$shortcode_atts[] = 'show_package_selector="no"';
}

if ( isset( $attributes['show_addon_selector'] ) && ! $attributes['show_addon_selector'] ) {
	$shortcode_atts[] = 'show_addon_selector="no"';
}

if ( ! empty( $attributes['currency'] ) && 'CAD' !== $attributes['currency'] ) {
	$shortcode_atts[] = 'currency="' . esc_attr( $attributes['currency'] ) . '"';
}

if ( ! empty( $attributes['tax_rate'] ) ) {
	$shortcode_atts[] = 'tax_rate="' . floatval( $attributes['tax_rate'] ) . '"';
}

if ( ! empty( $attributes['placeholder_text'] ) ) {
	$shortcode_atts[] = 'placeholder_text="' . esc_attr( $attributes['placeholder_text'] ) . '"';
}

if ( ! empty( $attributes['cta_label'] ) ) {
	$shortcode_atts[] = 'cta_label="' . esc_attr( $attributes['cta_label'] ) . '"';
}

// Build shortcode string.
$shortcode = '[mcp_vehicle_cleaning_estimator ' . implode( ' ', $shortcode_atts ) . ']';

// Render with block wrapper.
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo wp_kses_post( do_shortcode( $shortcode ) ); ?>
</div>
