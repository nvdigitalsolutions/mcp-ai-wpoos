<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-analytics-mcp-server.php` for the standalone
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
 * Analytics MCP server.
 *
 * Exposes reporting, forecasting, and custom-metric collection tools.
 * Tools-only server — dashboard views have no CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Analytics_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'analytics';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Analytics', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Custom reporting, funnel analysis, revenue forecasting, cohort analysis, and ML-powered customer segmentation. Tools-only server.',
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
