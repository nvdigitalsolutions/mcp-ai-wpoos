<?php
/**
 * Remote Capabilities Loader (ecosystem port — Wave F2, MCP settings-base slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/remote-capabilities/class-wp-mcp-ai-remote-capabilities-loader.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; path refs resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`. The remote-site-manager
 * require is file-gated (F6 forward-reference) and the research-add
 * probe resolves to the not-yet-ported `src/research-add/` path — both
 * degrade through the byte-identical `file_exists`/`class_exists`
 * guards.
 *
 * @package NvoosContentGraphPro
 * @since 2.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and provides access to remote capabilities for all toolkits.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Remote_Capabilities_Loader {

	/**
	 * Get remote capabilities for a specific toolkit.
	 *
	 * @since 2.0.0
	 *
	 * @param string $toolkit_slug Toolkit slug (e.g., 'ecommerce', 'social_media').
	 * @return array Array of capability descriptions.
	 */
	public static function get_capabilities( $toolkit_slug ) {
		$capabilities = array();

		switch ( $toolkit_slug ) {
			case 'ecommerce':
				$capabilities = array(
					__( 'Query remote product inventory across all connected sites', 'nvoos-content-graph-pro' ),
					__( 'Sync product data between local and remote WooCommerce stores', 'nvoos-content-graph-pro' ),
					__( 'Aggregate customer data from multiple sites', 'nvoos-content-graph-pro' ),
					__( 'Cross-site order tracking and fulfillment', 'nvoos-content-graph-pro' ),
					__( 'Unified inventory management across mesh network', 'nvoos-content-graph-pro' ),
					__( 'Remote product search and availability checks', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'social_media':
				$capabilities = array(
					__( 'Cross-post content to multiple sites simultaneously', 'nvoos-content-graph-pro' ),
					__( 'Aggregate social media analytics from all sites', 'nvoos-content-graph-pro' ),
					__( 'Share content calendar across mesh network', 'nvoos-content-graph-pro' ),
					__( 'Sync post templates between sites', 'nvoos-content-graph-pro' ),
					__( 'Unified social media campaign management', 'nvoos-content-graph-pro' ),
					__( 'Network-wide hashtag and trend analysis', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'multilingual':
				$capabilities = array(
					__( 'Share translation memory across all sites in mesh network', 'nvoos-content-graph-pro' ),
					__( 'Sync glossaries between multilingual sites', 'nvoos-content-graph-pro' ),
					__( 'Network-wide translation quality scoring', 'nvoos-content-graph-pro' ),
					__( 'Aggregate translation statistics and usage', 'nvoos-content-graph-pro' ),
					__( 'Remote translation service integration', 'nvoos-content-graph-pro' ),
					__( 'Cross-site language consistency checks', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'analytics':
				$capabilities = array(
					__( 'Aggregate analytics data from all sites in mesh network', 'nvoos-content-graph-pro' ),
					__( 'Cross-site performance comparisons and benchmarking', 'nvoos-content-graph-pro' ),
					__( 'Network-wide visitor tracking and attribution', 'nvoos-content-graph-pro' ),
					__( 'Unified dashboard for multi-site analytics', 'nvoos-content-graph-pro' ),
					__( 'Remote site health monitoring', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'calendar_booking':
				$capabilities = array(
					__( 'Sync availability across multiple booking sites', 'nvoos-content-graph-pro' ),
					__( 'Cross-site appointment scheduling', 'nvoos-content-graph-pro' ),
					__( 'Aggregate booking analytics from all sites', 'nvoos-content-graph-pro' ),
					__( 'Share staff schedules across mesh network', 'nvoos-content-graph-pro' ),
					__( 'Unified calendar management', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'dj_management':
				$capabilities = array(
					__( 'Share equipment inventory across sites', 'nvoos-content-graph-pro' ),
					__( 'Sync playlists between venues', 'nvoos-content-graph-pro' ),
					__( 'Cross-site booking and event management', 'nvoos-content-graph-pro' ),
					__( 'Network-wide equipment availability tracking', 'nvoos-content-graph-pro' ),
					__( 'Share packages and pricing across locations', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'financial_planner':
				$capabilities = array(
					__( 'Share budget categories across multiple sites', 'nvoos-content-graph-pro' ),
					__( 'Sync financial goal templates between sites', 'nvoos-content-graph-pro' ),
					__( 'Aggregate financial data from mesh network', 'nvoos-content-graph-pro' ),
					__( 'Cross-site budget tracking and reporting', 'nvoos-content-graph-pro' ),
					__( 'Network-wide financial health monitoring', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'ai_tool_builder':
				$capabilities = array(
					__( 'Share tool templates across all sites in network', 'nvoos-content-graph-pro' ),
					__( 'Sync parameter schemas between sites', 'nvoos-content-graph-pro' ),
					__( 'Network-wide tool library management', 'nvoos-content-graph-pro' ),
					__( 'Cross-site tool deployment and updates', 'nvoos-content-graph-pro' ),
					__( 'Aggregate tool usage statistics', 'nvoos-content-graph-pro' ),
				);
				break;

			case 'media_toolkit':
			case 'image_production':
			case 'video_production':
				$capabilities = array(
					__( 'Share media libraries across all sites', 'nvoos-content-graph-pro' ),
					__( 'Cross-site media synchronization', 'nvoos-content-graph-pro' ),
					__( 'Aggregate storage usage and analytics', 'nvoos-content-graph-pro' ),
					__( 'Remote media optimization and processing', 'nvoos-content-graph-pro' ),
					__( 'Network-wide media search and discovery', 'nvoos-content-graph-pro' ),
				);
				break;
		}

		/**
		 * Filter remote capabilities for a specific toolkit.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $capabilities Array of capability descriptions.
		 * @param string $toolkit_slug Toolkit slug.
		 */
		return apply_filters( "wp_mcp_ai_{$toolkit_slug}_remote_capabilities", $capabilities, $toolkit_slug );
	}
}
