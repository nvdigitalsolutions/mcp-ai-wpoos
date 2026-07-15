<?php
/**
 * Shopify Sync Toolkit MCP Server
 *
 * Phase 8 Tier-1 promotion.  Exposes Shopify↔WooCommerce cache-first sync
 * tools via the per-toolkit MCP JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shopify Sync MCP server.
 *
 * Tools-only server backed by Action Scheduler + JetEngine CCT cache.
 * AI assistants query inventory, products, orders, and analytics from
 * local data with zero Shopify GraphQL API cost per query.
 *
 * Distinct from any future Shopify live-API MCP server — sync tools are
 * cache-first and bulk-analytics-oriented; live tools are real-time and
 * mutation-capable.
 */
class WP_MCP_AI_Shopify_Sync_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	use WP_MCP_AI_Scheduled_Toolkit_Server_Trait;

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'shopify-sync';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Shopify Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Shopify↔WooCommerce cache-first sync — AI assistants query inventory, products, orders, and analytics from local CCT cache with zero GraphQL API cost.',
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
		 * Filter the candidate tool slugs the Shopify Sync MCP server exposes.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_shopify_sync_candidate_tools',
			array(
				'shopify_sync_inventory',
				'shopify_sync_products',
				'shopify_sync_orders',
				'shopify_sync_settings',
				'shopify_sync_analytics',
			)
		);
	}

	/**
	 * Get the sync engine class for the ScheduledToolkitServerTrait.
	 *
	 * @return string
	 */
	public function get_sync_engine_class() {
		return 'WP_MCP_AI_Shopify_Sync_Engine';
	}

	/**
	 * Get the Action Scheduler hook for full sync.
	 *
	 * @return string
	 */
	public function get_sync_hook_name() {
		return 'wp_mcp_ai_shopify_sync_full_sync';
	}

	/**
	 * Default limits for the Shopify Sync server.
	 *
	 * Shopify product data can be rich — 512 KB payload allowance.
	 *
	 * @since 1.5.0
	 *
	 * @return array{requests_per_minute: int, max_payload_bytes: int, max_iterations: int}
	 */
	public function get_default_limits() {
		return array(
			'requests_per_minute' => 60,
			'max_payload_bytes'   => 524288, // 512 KB.
			'max_iterations'      => 3,
		);
	}
}
