<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-healthcare-wellness-mcp-server.php` for the standalone
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
 * Healthcare Wellness & Vitals MCP server.
 *
 * Exposes vital-sign logging, wellness check-in, vaccination schedule, and
 * prescription interaction tools. Tools-only server — the wellness/vitals
 * dashboards are aggregation views without a CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Healthcare_Wellness_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'healthcare-wellness';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Healthcare Wellness & Vitals', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Vital-sign tracking, wellness check-ins, BMI/growth percentile, vaccination schedules, prescription interaction verification, and abnormal vital flagging. Tools-only server.',
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
		 * Filter the candidate tool slugs the Healthcare Wellness & Vitals MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_healthcare_wellness_candidate_tools',
			array(
				'analyze_vital_trends',
				'flag_abnormal_vitals',
				'compute_bmi_and_growth_percentile',
				'get_vaccination_schedule',
				'check_member_allergies',
				'verify_prescription_interactions',
				'get_health_timeline',
				'generate_visit_summary',
				'link_prescription_to_record',
				'merge_duplicate_members',
			)
		);
	}
}
