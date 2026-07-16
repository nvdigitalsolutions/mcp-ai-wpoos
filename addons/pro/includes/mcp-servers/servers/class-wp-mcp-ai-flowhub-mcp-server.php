<?php
/**
 * FlowHub Inventory Sync Toolkit MCP Server
 *
 * Phase 8 Tier-1 promotion.  Exposes cannabis dispensary inventory sync tools
 * (FlowHub POS → WooCommerce via JetEngine CCT cache) via the per-toolkit MCP
 * JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlowHub MCP server.
 *
 * Tools-only server backed by Action Scheduler sync engine + JetEngine CCT
 * cache.  AI assistants query inventory, products, and locations instantly
 * from local data — zero FlowHub API calls per query.
 */
class WP_MCP_AI_FlowHub_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	use WP_MCP_AI_Scheduled_Toolkit_Server_Trait;

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'flowhub';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'FlowHub Inventory Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cannabis dispensary inventory sync — FlowHub POS → WooCommerce via JetEngine CCT cache. AI assistants query inventory, products, and locations instantly from local data.',
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
		 * Filter the candidate tool slugs the FlowHub MCP server exposes.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_flowhub_candidate_tools',
			array(
				'flowhub_inventory',
				'flowhub_products',
				'flowhub_locations',
				'flowhub_sync',
				'flowhub_settings',
				'flowhub_analytics',
			)
		);
	}

	/**
	 * Get the sync engine class for the ScheduledToolkitServerTrait.
	 *
	 * @return string
	 */
	public function get_sync_engine_class() {
		return 'WP_MCP_AI_FlowHub_Sync_Engine';
	}

	/**
	 * Get the Action Scheduler hook for full sync.
	 *
	 * @return string
	 */
	public function get_sync_hook_name() {
		return 'wp_mcp_ai_flowhub_full_sync';
	}
}
