<?php
/**
 * Remote Capabilities Loader
 *
 * Central loader for all toolkit remote capabilities.
 * Follows WordPress Coding Standards and security best practices.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

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
__( 'Query remote product inventory across all connected sites', 'mcp-ai-wpoos-pro' ),
__( 'Sync product data between local and remote WooCommerce stores', 'mcp-ai-wpoos-pro' ),
__( 'Aggregate customer data from multiple sites', 'mcp-ai-wpoos-pro' ),
__( 'Cross-site order tracking and fulfillment', 'mcp-ai-wpoos-pro' ),
__( 'Unified inventory management across mesh network', 'mcp-ai-wpoos-pro' ),
__( 'Remote product search and availability checks', 'mcp-ai-wpoos-pro' ),
);
break;

case 'social_media':
$capabilities = array(
__( 'Cross-post content to multiple sites simultaneously', 'mcp-ai-wpoos-pro' ),
__( 'Aggregate social media analytics from all sites', 'mcp-ai-wpoos-pro' ),
__( 'Share content calendar across mesh network', 'mcp-ai-wpoos-pro' ),
__( 'Sync post templates between sites', 'mcp-ai-wpoos-pro' ),
__( 'Unified social media campaign management', 'mcp-ai-wpoos-pro' ),
__( 'Network-wide hashtag and trend analysis', 'mcp-ai-wpoos-pro' ),
);
break;

case 'multilingual':
$capabilities = array(
__( 'Share translation memory across all sites in mesh network', 'mcp-ai-wpoos-pro' ),
__( 'Sync glossaries between multilingual sites', 'mcp-ai-wpoos-pro' ),
__( 'Network-wide translation quality scoring', 'mcp-ai-wpoos-pro' ),
__( 'Aggregate translation statistics and usage', 'mcp-ai-wpoos-pro' ),
__( 'Remote translation service integration', 'mcp-ai-wpoos-pro' ),
__( 'Cross-site language consistency checks', 'mcp-ai-wpoos-pro' ),
);
break;

case 'analytics':
$capabilities = array(
__( 'Aggregate analytics data from all sites in mesh network', 'mcp-ai-wpoos-pro' ),
__( 'Cross-site performance comparisons and benchmarking', 'mcp-ai-wpoos-pro' ),
__( 'Network-wide visitor tracking and attribution', 'mcp-ai-wpoos-pro' ),
__( 'Unified dashboard for multi-site analytics', 'mcp-ai-wpoos-pro' ),
__( 'Remote site health monitoring', 'mcp-ai-wpoos-pro' ),
);
break;

case 'calendar_booking':
$capabilities = array(
__( 'Sync availability across multiple booking sites', 'mcp-ai-wpoos-pro' ),
__( 'Cross-site appointment scheduling', 'mcp-ai-wpoos-pro' ),
__( 'Aggregate booking analytics from all sites', 'mcp-ai-wpoos-pro' ),
__( 'Share staff schedules across mesh network', 'mcp-ai-wpoos-pro' ),
__( 'Unified calendar management', 'mcp-ai-wpoos-pro' ),
);
break;

case 'dj_management':
$capabilities = array(
				__( 'Share equipment inventory across sites', 'mcp-ai-wpoos-pro' ),
				__( 'Sync playlists between venues', 'mcp-ai-wpoos-pro' ),
				__( 'Cross-site booking and event management', 'mcp-ai-wpoos-pro' ),
				__( 'Network-wide equipment availability tracking', 'mcp-ai-wpoos-pro' ),
				__( 'Share packages and pricing across locations', 'mcp-ai-wpoos-pro' ),
			);
			break;

		case 'financial_planner':
			$capabilities = array(
				__( 'Share budget categories across multiple sites', 'mcp-ai-wpoos-pro' ),
				__( 'Sync financial goal templates between sites', 'mcp-ai-wpoos-pro' ),
				__( 'Aggregate financial data from mesh network', 'mcp-ai-wpoos-pro' ),
				__( 'Cross-site budget tracking and reporting', 'mcp-ai-wpoos-pro' ),
				__( 'Network-wide financial health monitoring', 'mcp-ai-wpoos-pro' ),
			);
			break;

		case 'ai_tool_builder':
			$capabilities = array(
				__( 'Share tool templates across all sites in network', 'mcp-ai-wpoos-pro' ),
				__( 'Sync parameter schemas between sites', 'mcp-ai-wpoos-pro' ),
				__( 'Network-wide tool library management', 'mcp-ai-wpoos-pro' ),
				__( 'Cross-site tool deployment and updates', 'mcp-ai-wpoos-pro' ),
				__( 'Aggregate tool usage statistics', 'mcp-ai-wpoos-pro' ),
			);
			break;

		case 'media_toolkit':
		case 'image_production':
		case 'video_production':
			$capabilities = array(
				__( 'Share media libraries across all sites', 'mcp-ai-wpoos-pro' ),
				__( 'Cross-site media synchronization', 'mcp-ai-wpoos-pro' ),
				__( 'Aggregate storage usage and analytics', 'mcp-ai-wpoos-pro' ),
				__( 'Remote media optimization and processing', 'mcp-ai-wpoos-pro' ),
				__( 'Network-wide media search and discovery', 'mcp-ai-wpoos-pro' ),
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
