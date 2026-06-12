<?php
/**
 * DietPi Pro Toolkit MCP Server
 *
 * Exposes DietPi / Raspberry Pi server and media management tools
 * via the per-toolkit MCP JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DietPi MCP server.
 *
 * Exposes DietPi Pro Toolkit tools: SSH command execution, service
 * management, system monitoring, and per-app REST API tools for
 * Transmission, Jackett, Sonarr, Radarr, Plex/Jellyfin, plus
 * provisioning, backups, and storage management.
 */
class WP_MCP_AI_DietPi_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'dietpi';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'DietPi Pro Toolkit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'DietPi / Raspberry Pi server and media management — SSH command execution, service control, system monitoring, Transmission/Jackett/Sonarr/Radarr/Plex/Jellyfin app management, backups, storage, and provisioning.',
			'mcp-ai-wpoos-pro'
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
		 * Filter the candidate tool slugs the DietPi MCP server exposes.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_dietpi_candidate_tools',
			array(
				// Phase 1 — Read tools (system health + app query).
				'dietpi_system_info',
				'dietpi_system_stats',
				'dietpi_dashboard_summary',
				'dietpi_health_check',
				'dietpi_list_services',
				'dietpi_list_transmission',
				'dietpi_list_jackett_indexers',
				'dietpi_list_sonarr_series',
				'dietpi_list_radarr_movies',
				'dietpi_search_jackett',
				'dietpi_media_center',
				'dietpi_media_request_flow',
				// Phase 2 — Safe actions (non-destructive).
				'dietpi_send_ssh_command',
				'dietpi_control_service',
				'dietpi_add_transmission',
				'dietpi_control_transmission',
				'dietpi_add_sonarr_series',
				'dietpi_manage_sonarr',
				'dietpi_add_radarr_movie',
				'dietpi_manage_radarr',
				'dietpi_manage_storage',
				// Phase 3 — Destructive / provisioning.
				'dietpi_backup_system',
				'dietpi_update_system',
				'dietpi_provision_new_app',
			)
		);
	}
}
