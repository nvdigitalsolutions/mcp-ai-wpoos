<?php
/**
 * Food & Beverage Management Toolkit — Initialization.
 *
 * Loads the F&B toolkit services. Tools register through the central Pro tool
 * registry in mcp-ai-wpoos-pro.php, gated on `enable_fnb_toolkit`.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-metrics.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-builder.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-draft-writer.php';

/**
 * Register the `enable_fnb_toolkit` toggle in the plugin settings schema.
 *
 * @param array $settings Existing settings defaults.
 * @return array
 */
function wp_mcp_ai_fnb_settings_defaults( $settings ) {
	if ( is_array( $settings ) ) {
		$settings['enable_fnb_toolkit'] = false;
	}

	return $settings;
}
add_filter( 'wp_mcp_ai_settings_defaults', 'wp_mcp_ai_fnb_settings_defaults' );
