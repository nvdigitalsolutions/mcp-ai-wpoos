<?php
/**
 * Model Foundry — Corpus Foundry init (Pro, Phase 1).
 *
 * Registers the Corpus Foundry export tools via the `wp_mcp_ai_pro_tools`
 * filter. Kept in its own init file so the foundry slice can be
 * deactivated independently of the rest of the Pro addon (same pattern as
 * `includes/harness-init.php`). Tool classes are lazy-loaded by the tool
 * registry — nothing heavy runs at init.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_pro_register_model_foundry_tools' ) ) {
	/**
	 * Append Model Foundry Pro tools to the registration map.
	 *
	 * @param array $tools Existing class-name → file-path map.
	 * @return array
	 */
	function wp_mcp_ai_pro_register_model_foundry_tools( $tools ) {
		$base = WP_MCP_AI_PRO_PATH . 'includes/model-foundry/';
		$tools['WP_MCP_AI_Tool_Export_Trajectory_Corpus'] = $base . 'class-wp-mcp-ai-tool-export-trajectory-corpus.php';
		$tools['WP_MCP_AI_Tool_Export_Preference_Pairs']  = $base . 'class-wp-mcp-ai-tool-export-preference-pairs.php';
		$tools['WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus'] = $base . 'class-wp-mcp-ai-tool-export-plugin-docs-corpus.php';
		return $tools;
	}
}

add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_model_foundry_tools', 10 );
