<?php
/**
 * Social Media Management Toolkit Initialization (ecosystem port — Wave F2,
 * social-media data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/social-media/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Loads the social-media optimization
 * helper behind the `enable_social_media_toolkit` gate.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. The admin settings page stays file-gated — it lands with the social
 *    admin slice.
 * 4. Monolith guard — this init declares the global helper
 *    `wp_mcp_ai_enqueue_social_media_toolkit_admin_styles()` that the base
 *    social init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime
 *    `! defined( 'WP_MCP_AI_PATH' )` block (the declarations register only
 *    when the block executes — same pattern as the calendar/financial init
 *    deviation 5). NOTE: the base tree ships this init but nothing loads it
 *    monolith (no registry module — byte-identical dormancy); the standalone
 *    registry gains a standalone-only `toolkit_social_media` module that
 *    boots it (toolkit_data_store/vector_storage/financial precedent).
 * 5. New standalone-only tool wiring (same pattern as the financial init
 *    deviation 6): a `wp_mcp_ai_pro_tools` filter carrying the ported
 *    social tool subset (fills as the tool batches land) plus
 *    `wp_mcp_ai_pro_register_social_ecosystem_tools()` registering the
 *    ported tools into the ecosystem graph ToolRegistry.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 4): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Check if Social Media toolkit is enabled.
	$nvoos_content_graph_pro_sm_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_sm_is_enabled = ! empty( $nvoos_content_graph_pro_sm_settings['enable_social_media_toolkit'] );
	$nvoos_content_graph_pro_sm_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_sm_is_enabled && ! $nvoos_content_graph_pro_sm_is_base ) {

		// Load Social Media admin pages.
		if ( is_admin() ) {
			// Deferred — file-gated until the social admin slice lands.
			$nvoos_content_graph_pro_sm_settings_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-social-media-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_sm_settings_page ) ) {
				require_once $nvoos_content_graph_pro_sm_settings_page;
			}
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/social-media/.

		// --- Performance optimization (CPT fix, cron handler, retention, autorespond cap) ---
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-social-media-optimization.php';
		WP_MCP_AI_Social_Media_Optimization::init();
	}

	/**
	 * Enqueue social media toolkit admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_social_media_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-social-media-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-social-media-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-social-media-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_social_media_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported social tool subset
	 * (inert standalone, consumed by the base plugin monolith). The map
	 * fills as the social tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_social_tools( $tools ) {
		$nvoos_content_graph_pro_sm_tools = array(
			'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-download-google-maps-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-download-facebook-page-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-download-instagram-page-images.php',
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-post-tiktok-video.php',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-post-linkedin-update.php',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-post-google-business-update.php',
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/class-wp-mcp-ai-pro-tool-get-google-business-insights.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_sm_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported social
	 * tools into the ecosystem graph ToolRegistry and the nvoos/core
	 * registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the
	 * financial/calendar/CRM inits). The list fills as the social tool
	 * batches land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_social_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images',
				'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images',
				'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images',
				'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram',
				'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video',
				'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update',
				'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update',
				'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights',
				'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights',
				'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights',
				'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 5). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_social_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_social_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
