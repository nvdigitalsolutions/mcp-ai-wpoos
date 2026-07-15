<?php
/**
 * EZuite ERP Sync Toolkit MCP Server
 *
 * Phase 8 Tier-1 promotion.  Exposes EZuite ERP inventory → WooCommerce sync
 * tools via the per-toolkit MCP JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EZuite MCP server.
 *
 * Tools-only server backed by Action Scheduler + JetEngine CCT cache.
 * AI assistants query inventory, products, and alerts from local data
 * with zero EZuite API calls per query.
 */
class WP_MCP_AI_EZuite_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	use WP_MCP_AI_Scheduled_Toolkit_Server_Trait;

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ezuite';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'EZuite ERP Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'EZuite ERP inventory → WooCommerce/WordPress sync via JetEngine CCT cache. AI assistants query inventory, products, and orders instantly — zero EZuite API calls per query.',
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
		 * Filter the candidate tool slugs the EZuite MCP server exposes.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_ezuite_candidate_tools',
			array(
				'ezuite_inventory',
				'ezuite_erp_get_products',
				'ezuite_erp',
				'ezuite_settings',
				'ezuite_sync',
				'ezuite_alerts',
			)
		);
	}

	/**
	 * Get the sync engine class for the ScheduledToolkitServerTrait.
	 *
	 * @return string
	 */
	public function get_sync_engine_class() {
		return 'WP_MCP_AI_EZuite_Sync_Engine';
	}

	/**
	 * Get the Action Scheduler hook for full sync.
	 *
	 * @return string
	 */
	public function get_sync_hook_name() {
		return 'wp_mcp_ai_ezuite_full_sync';
	}

	/**
	 * Default limits for the EZuite server.
	 *
	 * ERP data is moderate — 256 KB payload allowance.
	 *
	 * @since 1.5.0
	 *
	 * @return array{requests_per_minute: int, max_payload_bytes: int, max_iterations: int}
	 */
	public function get_default_limits() {
		return array(
			'requests_per_minute' => 60,
			'max_payload_bytes'   => 262144, // 256 KB.
			'max_iterations'      => 3,
		);
	}
}
