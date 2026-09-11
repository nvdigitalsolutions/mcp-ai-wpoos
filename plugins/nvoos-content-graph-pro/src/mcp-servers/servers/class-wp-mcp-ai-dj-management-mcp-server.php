<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-dj-management-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DJ Management MCP server.
 */
class WP_MCP_AI_DJ_Management_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'dj-management';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'DJ Management', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Event bookings, music libraries, equipment inventory, and client communication for DJ businesses. Tools-only server.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the DJ Management MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_dj_management_candidate_tools',
			array(
				'create_event_booking',
				'update_event_details',
				'send_event_confirmation',
				'generate_event_timeline',
				'generate_dj_contract',
				'track_event_payments',
				'send_client_invoice',
				'create_client_profile',
				'client_communication_log',
				'create_playlist',
				'generate_playlist_ai',
				'manage_music_library',
				'analyze_track_bpm',
				'mix_transition_planner',
				'add_equipment_item',
				'reserve_equipment',
				'track_equipment_maintenance',
				'equipment_inventory_report',
			)
		);
	}
}
