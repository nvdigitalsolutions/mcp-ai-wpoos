<?php
/**
 * GTmetrix tool: generate WPCode snippet.
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

/**
 * Generate a WPCode-ready PHP optimization snippet from a report's findings.
 * Read-only: returns the code; nothing is installed.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Generate_WPCode_Snippet extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_generate_wpcode_snippet';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Generate WPCode Snippet', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a WPCode-ready PHP optimization snippet containing only the blocks that match a GTmetrix report\'s findings. Safe blocks are enabled; risky blocks are commented with staging warnings. Returns code only — nothing is installed.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Producing the deployable snippet for a reviewed report before installing it.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Installing the snippet automatically — use gtmetrix_install_wpcode_fix.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_analyze_report', 'gtmetrix_install_wpcode_fix' ),
			'notes'           => __( 'Verify handle names and URLs against the live page view-source before activating. Test risky blocks on staging.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id'     => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID whose findings drive the snippet.', 'mcp-ai-wpoos-pro' ),
				),
				'include_risky' => array(
					'type'        => 'boolean',
					'description' => __( 'Include risky blocks (commented out with staging warnings). Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'report_id' ),
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

		$report_id = $this->arg_report_id( $arguments );
		if ( '' === $report_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'A report ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$report = $this->client()->get_report( $report_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$flat = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $report );

		$lighthouse = $this->client()->get_resource( $report_id, 'lighthouse.json' );
		if ( is_wp_error( $lighthouse ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_no_lighthouse', __( 'This report has no Lighthouse JSON (metrics-only or legacy report).', 'mcp-ai-wpoos-pro' ) );
		}

		$audits_block = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$audits       = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $audits_block );
		$matched      = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );

		$include_risky = array_key_exists( 'include_risky', $arguments ) ? (bool) $arguments['include_risky'] : true;
		$fixes         = $matched['fixes'];
		if ( ! $include_risky ) {
			$safe = array();
			foreach ( $fixes as $fix ) {
				if ( 'high' !== $fix['risk'] ) {
					$safe[] = $fix;
				}
			}
			$fixes = $safe;
		}

		if ( empty( $fixes ) ) {
			return array(
				'success'   => true,
				'report_id' => $report_id,
				'code'      => '',
				'blocks'    => array(),
				'count'     => 0,
				'note'      => __( 'No known fixes matched this report\'s failing audits. Unfixable and unknown audits are listed separately.', 'mcp-ai-wpoos-pro' ),
				'unfixable' => $matched['unfixable'],
				'unknown'   => $matched['unknown'],
			);
		}

		$code = WP_MCP_AI_GTmetrix_Fix_Engine::generate_snippet(
			$fixes,
			array(
				'report_id' => $report_id,
				'url'       => isset( $flat['url'] ) ? $flat['url'] : '',
			)
		);

		$blocks = array();
		foreach ( $fixes as $fix ) {
			$blocks[] = array(
				'block'         => $fix['block'],
				'risk'          => $fix['risk'],
				'symptom'       => $fix['symptom'],
				'matched_audit' => $fix['matched_audit'],
			);
		}

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'code'      => $code,
			'blocks'    => $blocks,
			'count'     => count( $blocks ),
			'deploy'    => __( 'WPCode → Add Snippet → PHP → Insert method: Auto Insert → Run Everywhere. Enable blocks one at a time and re-test.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
