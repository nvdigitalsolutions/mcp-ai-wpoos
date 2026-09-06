<?php
/**
 * E-commerce Toolkit Helpers
 *
 * Shared utility functions for the E-commerce Pro Toolkit.
 * Kept side-effect free so callers (including the test suite) can load
 * the enablement check without booting the full toolkit.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Ecommerce_Toolkit
 * @since      2.1.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the E-commerce Toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in plugin settings (Pro features).
 *
 * @since 2.1.0
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_ecommerce_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_ecommerce_toolkit'] );
}
