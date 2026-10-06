<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-shopify-sync-mcp-server.php` for the standalone
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
	 * Option key for the Shopify Sync toolkit settings.
	 *
	 * Matches WP_MCP_AI_Pro_Tool_Shopify_Sync_Settings::OPTION_KEY; kept as a
	 * server-level constant so the MCP server never depends on the settings
	 * tool class being loaded.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'wp_mcp_ai_shopify_sync_toolkit_settings';

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
		return __( 'Shopify Sync', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Shopify↔WooCommerce cache-first sync — AI assistants query inventory, products, orders, and analytics from local CCT cache with zero GraphQL API cost.',
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
	 * The sync engine schedules one hook per connection
	 * (HOOK_FULL_SYNC . '_' . $connection_id); the per-connection status
	 * aggregation in get_sync_status() iterates those hooks, so this returns
	 * the unsuffixed hook prefix.
	 *
	 * @return string
	 */
	public function get_sync_hook_name() {
		if ( class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			return WP_MCP_AI_Shopify_Sync_Engine::HOOK_FULL_SYNC;
		}

		return 'wp_mcp_ai_shopify_full_sync';
	}

	/**
	 * Read the configured sync interval in seconds.
	 *
	 * The toolkit stores the interval in minutes under the toolkit settings
	 * option; the trait default reads a seconds-based option under a slug-
	 * derived key that this toolkit never writes, so it is overridden here.
	 *
	 * @return int Sync interval in seconds (minimum 60).
	 */
	public function get_sync_interval() {
		$option  = get_option( self::SETTINGS_OPTION_KEY, array() );
		$minutes = isset( $option['sync_interval'] ) ? absint( $option['sync_interval'] ) : 15;
		if ( $minutes < 1 ) {
			$minutes = 15;
		}

		return max( 60, $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Get the last sync status summary across all synced connections.
	 *
	 * The engine schedules per-connection hooks, so a single-hook Action
	 * Scheduler query (the trait default) never matches. Aggregate the
	 * per-connection last-sync options instead.
	 *
	 * @return array{last_sync: int, status: string, row_count: int}
	 */
	public function get_sync_status() {
		$settings         = get_option( self::SETTINGS_OPTION_KEY, array() );
		$sync_connections = isset( $settings['sync_connections'] ) && is_array( $settings['sync_connections'] )
			? $settings['sync_connections']
			: array();

		$last_sync = 0;
		$row_count = 0;
		$has_sync  = false;

		foreach ( $sync_connections as $connection_id ) {
			$connection_id = sanitize_key( $connection_id );
			if ( empty( $connection_id ) ) {
				continue;
			}

			$stored = get_option( 'wp_mcp_ai_shopify_last_sync_' . $connection_id, '' );
			if ( ! empty( $stored ) ) {
				$has_sync  = true;
				$timestamp = strtotime( $stored );
				if ( false !== $timestamp ) {
					$last_sync = max( $last_sync, $timestamp );
				}
			}

			if ( class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
				$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
				$row_count  += $cct_manager->get_row_count();
			}
		}

		$status = 'unknown';
		if ( $has_sync ) {
			$interval = $this->get_sync_interval();
			$status   = ( $last_sync > 0 && ( time() - $last_sync ) < $interval ) ? 'completed' : 'stale';
		}

		/**
		 * Filter the sync status array for a scheduled toolkit server.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $status     {last_sync, status, row_count}.
		 * @param string $server_slug The server slug.
		 */
		return apply_filters(
			'wp_mcp_ai_scheduled_toolkit_sync_status',
			array(
				'last_sync' => $last_sync,
				'status'    => $status,
				'row_count' => $row_count,
			),
			$this->get_slug()
		);
	}

	/**
	 * Check whether at least one synced Shopify connection is enabled.
	 *
	 * The trait default reads the generic `wp_mcp_ai_remote_connections`
	 * option, which the Shopify Sync toolkit never writes. Resolve against
	 * the Remote Sites manager and the toolkit's sync_connections setting
	 * instead.
	 *
	 * @return bool
	 */
	public function get_connection_status() {
		$settings         = get_option( self::SETTINGS_OPTION_KEY, array() );
		$sync_connections = isset( $settings['sync_connections'] ) && is_array( $settings['sync_connections'] )
			? $settings['sync_connections']
			: array();

		if ( empty( $sync_connections ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $key => $connection ) {
			$connection_id = isset( $connection['id'] ) ? $connection['id'] : ( is_string( $key ) ? $key : '' );

			if (
				! empty( $connection['enabled'] )
				&& ! empty( $connection['connection_type'] )
				&& 'shopify' === $connection['connection_type']
				&& in_array( $connection_id, $sync_connections, true )
			) {
				return true;
			}
		}

		return false;
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
