<?php
/**
 * Pro Harness Layer H — fine-tune curriculum export.
 *
 * Loads the curriculum-export Pro tool and registers it via the
 * `wp_mcp_ai_pro_tools` filter. Kept in its own init file so the
 * harness Pro slice can be deactivated independently of the rest
 * of the Pro addon if needed.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_pro_register_harness_tools' ) ) {
	/**
	 * Append harness Pro tools to the registration map.
	 *
	 * @param array $tools Existing class-name → file-path map.
	 * @return array
	 */
	function wp_mcp_ai_pro_register_harness_tools( $tools ) {
		$tools['WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum'] = WP_MCP_AI_PRO_PATH . 'includes/harness/class-wp-mcp-ai-tool-export-fine-tune-curriculum.php';
		return $tools;
	}
}

add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_harness_tools', 10 );
