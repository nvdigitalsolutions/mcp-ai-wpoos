<?php
/**
 * DJ Management Toolkit Initialization (ecosystem port — Wave F2,
 * dj-management toolkit).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/dj-management/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*`.
 * 3. Slimmed wiring — the admin settings page require is file-gated (it
 *    lands with the dj-management admin slice).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_dj_management_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_dj_management_ecosystem_tools()` carrying the
 *    ported tool subset — the base registers only get-trending-tracks +
 *    update-playlist-rotation in its gated map, so the remaining nineteen
 *    tree-only tools and the import-blueprint tool are carried here too
 *    (CRM CC-extras precedent). The two always-on jukebox tools
 *    (generate-jukebox-music/check-jukebox-status) stay deferred — they
 *    depend on the base-owned `WP_MCP_AI_Logger` + the Pro-owned jukebox
 *    service (D8 + dj-services slice).
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

	// Check if DJ Management toolkit is enabled.
	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_dj_management_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

		// Load DJ Management admin pages (deferred — file-gated until the
		// dj-management admin slice lands).
		if ( is_admin() ) {
			$nvoos_content_graph_pro_dj_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-dj-management-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_dj_settings ) ) {
				require_once $nvoos_content_graph_pro_dj_settings;
			}
		}

		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/dj-management/.
	}

	/**
	 * Enqueue DJ management toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_dj_management_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_dj_management_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-dj-management-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-dj-management-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-dj-management-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_dj_management_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported dj-management tool
	 * subset (inert standalone, consumed by the base plugin monolith).
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_dj_management_tools( $tools ) {
		$nvoos_content_graph_pro_dj_tools = array(
			'WP_MCP_AI_Tool_Add_Equipment_Item'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-add-equipment-item.php',
			'WP_MCP_AI_Tool_Analyze_Track_Bpm'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-analyze-track-bpm.php',
			'WP_MCP_AI_Tool_Client_Communication_Log'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-client-communication-log.php',
			'WP_MCP_AI_Tool_Create_Client_Profile'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-create-client-profile.php',
			'WP_MCP_AI_Tool_Create_Event_Booking'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-create-event-booking.php',
			'WP_MCP_AI_Tool_Create_Playlist'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-create-playlist.php',
			'WP_MCP_AI_Tool_Equipment_Inventory_Report'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-equipment-inventory-report.php',
			'WP_MCP_AI_Tool_Generate_Dj_Contract'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-generate-dj-contract.php',
			'WP_MCP_AI_Tool_Generate_Event_Timeline'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-generate-event-timeline.php',
			'WP_MCP_AI_Tool_Generate_Playlist_Ai'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-generate-playlist-ai.php',
			'WP_MCP_AI_Tool_Get_Trending_Tracks'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php',
			'WP_MCP_AI_Tool_Import_DJ_Management_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/examples/class-wp-mcp-ai-tool-import-dj-management-blueprint.php',
			'WP_MCP_AI_Tool_Manage_Music_Library'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-manage-music-library.php',
			'WP_MCP_AI_Tool_Mix_Transition_Planner'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-mix-transition-planner.php',
			'WP_MCP_AI_Tool_Reserve_Equipment'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-reserve-equipment.php',
			'WP_MCP_AI_Tool_Send_Client_Invoice'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-send-client-invoice.php',
			'WP_MCP_AI_Tool_Send_Event_Confirmation'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-send-event-confirmation.php',
			'WP_MCP_AI_Tool_Track_Equipment_Maintenance' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-track-equipment-maintenance.php',
			'WP_MCP_AI_Tool_Track_Event_Payments'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-track-event-payments.php',
			'WP_MCP_AI_Tool_Update_Event_Details'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-update-event-details.php',
			'WP_MCP_AI_Tool_Update_Playlist_Rotation'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-update-playlist-rotation.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_dj_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * dj-management tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_dj_management_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Add_Equipment_Item',
				'WP_MCP_AI_Tool_Analyze_Track_Bpm',
				'WP_MCP_AI_Tool_Client_Communication_Log',
				'WP_MCP_AI_Tool_Create_Client_Profile',
				'WP_MCP_AI_Tool_Create_Event_Booking',
				'WP_MCP_AI_Tool_Create_Playlist',
				'WP_MCP_AI_Tool_Equipment_Inventory_Report',
				'WP_MCP_AI_Tool_Generate_Dj_Contract',
				'WP_MCP_AI_Tool_Generate_Event_Timeline',
				'WP_MCP_AI_Tool_Generate_Playlist_Ai',
				'WP_MCP_AI_Tool_Get_Trending_Tracks',
				'WP_MCP_AI_Tool_Import_DJ_Management_Blueprint',
				'WP_MCP_AI_Tool_Manage_Music_Library',
				'WP_MCP_AI_Tool_Mix_Transition_Planner',
				'WP_MCP_AI_Tool_Reserve_Equipment',
				'WP_MCP_AI_Tool_Send_Client_Invoice',
				'WP_MCP_AI_Tool_Send_Event_Confirmation',
				'WP_MCP_AI_Tool_Track_Equipment_Maintenance',
				'WP_MCP_AI_Tool_Track_Event_Payments',
				'WP_MCP_AI_Tool_Update_Event_Details',
				'WP_MCP_AI_Tool_Update_Playlist_Rotation',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_dj_management_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_dj_management_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
