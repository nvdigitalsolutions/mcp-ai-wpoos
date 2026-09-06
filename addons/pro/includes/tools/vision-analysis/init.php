<?php
/**
 * Vision Analysis Toolkit Initialization
 *
 * Loads the Vision Analysis Toolkit — sensor-free image understanding for AI
 * agents: object detection with per-category count breakdowns, VLM-assisted
 * open-world counting, and annotated (bounding-box) image output.
 *
 * All settings are stored under the main `wp_mcp_ai_settings` option with
 * `va_` prefixed keys (or `enable_vision_analysis_toolkit` for the toggle).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Admin settings (admin-only).
if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-vision-analysis-settings.php';
	WP_MCP_AI_Vision_Analysis_Settings::init();
}

/**
 * Check whether the Vision Analysis toolkit is enabled.
 *
 * @since 1.1.68
 *
 * @return bool
 */
function wp_mcp_ai_vision_analysis_is_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_vision_analysis_toolkit'] )
		&& ( ! function_exists( 'wp_mcp_ai_is_base_version' ) || ! wp_mcp_ai_is_base_version() );
}

/**
 * Get the Vision Analysis settings subset from the main wp_mcp_ai_settings option.
 *
 * @since 1.1.68
 *
 * @return array
 */
function wp_mcp_ai_vision_analysis_get_settings() {
	$all = get_option( 'wp_mcp_ai_settings', array() );

	return array(
		'enabled'          => ! empty( $all['enable_vision_analysis_toolkit'] ),
		'detection_model'  => isset( $all['va_detection_model'] ) && '' !== $all['va_detection_model'] ? sanitize_text_field( $all['va_detection_model'] ) : 'google/owlv2-base-patch16',
		'min_confidence'   => isset( $all['va_min_confidence'] ) ? (float) $all['va_min_confidence'] : 0.5,
		'vlm_provider'     => isset( $all['va_vlm_provider'] ) ? sanitize_text_field( $all['va_vlm_provider'] ) : 'auto',
		'vlm_model'        => isset( $all['va_vlm_model'] ) ? sanitize_text_field( $all['va_vlm_model'] ) : '',
		'annotate_default' => ! empty( $all['va_annotate_default'] ),
		'max_image_bytes'  => isset( $all['va_max_image_bytes'] ) ? absint( $all['va_max_image_bytes'] ) : 5242880,
	);
}
