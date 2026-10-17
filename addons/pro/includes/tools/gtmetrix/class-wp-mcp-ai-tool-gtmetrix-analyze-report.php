<?php
/**
 * GTmetrix tool: analyze report.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-snapshot-store.php';

/**
 * Full deterministic analysis of a GTmetrix report: CWV assessment, budgets,
 * LCP phases, impact-ordered audits and the mapped fix list.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Analyze_Report extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_analyze_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Analyze Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyze a GTmetrix report against Core Web Vitals thresholds, page-weight budgets and the GTmetrix grade bands, then return an impact-ordered audit list with concrete WordPress fixes mapped to each finding.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Turning a finished GTmetrix test into an actionable, prioritized optimization plan.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Running a new test — use gtmetrix_start_test first.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_generate_wpcode_snippet', 'gtmetrix_compare_reports' ),
			'notes'           => __( 'All assessments are computed deterministically in PHP. INP cannot be certified from lab data (TBT is the proxy).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID to analyze. Required unless url is given.', 'mcp-ai-wpoos-pro' ),
				),
				'url'       => array(
					'type'        => 'string',
					'description' => __( 'Alternative: analyze the latest snapshot for this URL.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard_key();
		if ( $error ) {
			return $error;
		}

		$report    = null;
		$report_id = $this->arg_report_id( $arguments );

		$url = $this->arg_url( $arguments );
		if ( '' !== $url ) {
			$store  = new WP_MCP_AI_GTmetrix_Snapshot_Store();
			$report = $store->get_latest_for_url( $url );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			$report_id = isset( $report['id'] ) ? $report['id'] : '';
		}

		if ( null === $report ) {
			if ( '' === $report_id ) {
				return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'A report ID or url is required.', 'mcp-ai-wpoos-pro' ) );
			}
			$report = $this->client()->get_report( $report_id );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
		}

		$flat   = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $report );
		$cwv    = WP_MCP_AI_GTmetrix_Score_Engine::assess_cwv( $flat );
		$budget = WP_MCP_AI_GTmetrix_Score_Engine::budget_class(
			isset( $flat['page_bytes'] ) ? $flat['page_bytes'] : 0,
			isset( $flat['page_requests'] ) ? $flat['page_requests'] : 0
		);

		$lighthouse = $this->client()->get_resource( $report_id, 'lighthouse.json' );
		$audits     = array();
		$lcp_phases = array( 'estimated' => true );
		if ( ! is_wp_error( $lighthouse ) ) {
			$audits_block = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
			$audits       = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $audits_block );
			$lcp_phases   = WP_MCP_AI_GTmetrix_Score_Engine::lcp_phases( $lighthouse );
		}

		$matched = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );

		$score_0_1 = isset( $flat['gtmetrix_score'] ) ? ( (float) $flat['gtmetrix_score'] ) / 100 : 0.0;

		return array(
			'success'         => true,
			'report_id'       => $report_id,
			'url'             => isset( $flat['url'] ) ? $flat['url'] : '',
			'grade'           => isset( $flat['gtmetrix_grade'] ) ? $flat['gtmetrix_grade'] : WP_MCP_AI_GTmetrix_Score_Engine::band_grade( $score_0_1 ),
			'scores'          => array(
				'gtmetrix'    => isset( $flat['gtmetrix_score'] ) ? $flat['gtmetrix_score'] : null,
				'performance' => isset( $flat['performance_score'] ) ? $flat['performance_score'] : null,
				'structure'   => isset( $flat['structure_score'] ) ? $flat['structure_score'] : null,
			),
			'core_web_vitals' => $cwv,
			'budget'          => $budget,
			'lcp_phases'      => $lcp_phases,
			'top_audits'      => array_slice( $audits, 0, 10 ),
			'fixes'           => array(
				'matched'   => $matched['fixes'],
				'count'     => count( $matched['fixes'] ),
				'unfixable' => $matched['unfixable'],
				'unknown'   => $matched['unknown'],
			),
			'next'            => __( 'Use gtmetrix_generate_wpcode_snippet to turn the matched fixes into a WPCode-ready snippet.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
