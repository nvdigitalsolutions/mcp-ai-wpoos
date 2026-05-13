<?php
/**
 * Analytics Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics MCP server.
 *
 * Exposes reporting, forecasting, and custom-metric collection tools.
 * Tools-only server — dashboard views have no CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Analytics_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * @return string
	 */
	public function get_slug() {
		return 'analytics';
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return __( 'Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * @return string
	 */
	public function get_description() {
		return __(
			'Custom reporting, funnel analysis, revenue forecasting, cohort analysis, and ML-powered customer segmentation. Tools-only server.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Analytics MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_analytics_candidate_tools',
			array(
				'create_custom_report',
				'generate_executive_dashboard',
				'funnel_analysis',
				'cohort_analysis',
				'revenue_forecast',
				'attribution_modeling',
				'churn_prediction',
				'customer_segmentation_ml',
				'collect_custom_metrics',
				'real_time_event_tracking',
				'export_analytics_api',
				'data_warehouse_sync',
			)
		);
	}
}
