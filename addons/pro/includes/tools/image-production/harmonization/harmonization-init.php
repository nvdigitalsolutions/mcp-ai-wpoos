<?php
/**
 * Registration loader for the harmonization sub-toolkit.
 *
 * Registers all 14 harmonization tools through the standard
 * `wp_mcp_ai_register_tools` action so they appear in the tool registry like
 * any other Pro tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize harmonization tools.
 *
 * Loads the abstract base class and all 14 concrete tool classes, then
 * subscribes a registration callback to `wp_mcp_ai_register_tools`.
 */
function wp_mcp_ai_pro_register_harmonization_tools() {
	$dir = __DIR__ . '/';

	// Load infrastructure first.
	require_once $dir . 'trait-wp-mcp-ai-tool-harmonization.php';
	require_once $dir . 'class-wp-mcp-ai-harmonization-compositor.php';
	require_once $dir . 'class-wp-mcp-ai-lighting-analyzer.php';
	require_once $dir . 'class-wp-mcp-ai-tool-harmonization-base.php';

	// Load all tools.
	$tool_files = array(
		'class-wp-mcp-ai-tool-generate-scene-background.php',
		'class-wp-mcp-ai-tool-adapt-background-for-subject.php',
		'class-wp-mcp-ai-tool-outpaint-background.php',
		'class-wp-mcp-ai-tool-refine-subject-matte.php',
		'class-wp-mcp-ai-tool-auto-clean-white-background.php',
		'class-wp-mcp-ai-tool-harmonize-color.php',
		'class-wp-mcp-ai-tool-relight-subject.php',
		'class-wp-mcp-ai-tool-generate-shadow.php',
		'class-wp-mcp-ai-tool-generate-reflection.php',
		'class-wp-mcp-ai-tool-refine-composite-boundary.php',
		'class-wp-mcp-ai-tool-analyze-scene-lighting.php',
		'class-wp-mcp-ai-tool-suggest-placement.php',
		'class-wp-mcp-ai-tool-harmonize-image-into-background.php',
		'class-wp-mcp-ai-tool-harmonize-batch.php',
	);

	foreach ( $tool_files as $file ) {
		$path = $dir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	add_action(
		'wp_mcp_ai_register_tools',
		function ( $registry ) {
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'register_tool' ) ) {
				return;
			}

			$tool_classes = array(
				'WP_MCP_AI_Tool_Generate_Scene_Background',
				'WP_MCP_AI_Tool_Adapt_Background_For_Subject',
				'WP_MCP_AI_Tool_Outpaint_Background',
				'WP_MCP_AI_Tool_Refine_Subject_Matte',
				'WP_MCP_AI_Tool_Auto_Clean_White_Background',
				'WP_MCP_AI_Tool_Harmonize_Color',
				'WP_MCP_AI_Tool_Relight_Subject',
				'WP_MCP_AI_Tool_Generate_Shadow',
				'WP_MCP_AI_Tool_Generate_Reflection',
				'WP_MCP_AI_Tool_Refine_Composite_Boundary',
				'WP_MCP_AI_Tool_Analyze_Scene_Lighting',
				'WP_MCP_AI_Tool_Suggest_Placement',
				'WP_MCP_AI_Tool_Harmonize_Image_Into_Background',
				'WP_MCP_AI_Tool_Harmonize_Batch',
			);

			foreach ( $tool_classes as $cls ) {
				if ( class_exists( $cls ) && call_user_func( array( $cls, 'is_available' ) ) ) {
					$registry->register_tool( new $cls() );
				}
			}
		},
		20
	);
}

wp_mcp_ai_pro_register_harmonization_tools();
