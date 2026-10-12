<?php
/**
 * GTmetrix tool: compare reports.
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
 * Before/after comparison of two GTmetrix reports with per-metric verdicts.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Compare_Reports extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_compare_reports';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Compare Reports', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compare two GTmetrix reports (or a URL against its previous snapshot) and return per-metric deltas with regressed/improved/steady verdicts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Measuring the before/after effect of an optimization change or deploy.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Getting the fix list for a single report — use gtmetrix_analyze_report.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_analyze_report', 'gtmetrix_retest_page' ),
			'notes'           => __( 'Only meaningful when both reports used the same analysis options (location, browser, device).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id_a' => array(
					'type'        => 'string',
					'description' => __( 'Baseline (before) GTmetrix report ID. Required unless url is given.', 'mcp-ai-wpoos-pro' ),
				),
				'report_id_b' => array(
					'type'        => 'string',
					'description' => __( 'Follow-up (after) GTmetrix report ID. Defaults to the URL latest snapshot.', 'mcp-ai-wpoos-pro' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'Alternative: compare the two most recent snapshots for this URL.', 'mcp-ai-wpoos-pro' ),
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

		$before = null;
		$after  = null;

		$url = $this->arg_url( $arguments );
		if ( '' !== $url ) {
			$store = new WP_MCP_AI_GTmetrix_Snapshot_Store();
			$list  = $store->list_for_url( $url, 2 );
			if ( count( $list ) >= 2 ) {
				$before = $list[1];
				$after  = $list[0];
			} elseif ( 1 === count( $list ) ) {
				$after  = $list[0];
				$before = $this->client()->get_report( isset( $arguments['report_id_a'] ) ? sanitize_text_field( $arguments['report_id_a'] ) : '' );
				if ( is_wp_error( $before ) || '' === $this->arg_report_id( $arguments ) ) {
					return new WP_Error( 'wp_mcp_ai_gtmetrix_no_baseline', __( 'Only one snapshot exists for this URL; provide report_id_a as the baseline.', 'mcp-ai-wpoos-pro' ) );
				}
			} else {
				return new WP_Error( 'wp_mcp_ai_gtmetrix_no_snapshots', __( 'No local snapshots for this URL. Run tests first, or pass two report IDs.', 'mcp-ai-wpoos-pro' ) );
			}
		} else {
			$report_a = $this->arg_report_id( $arguments );
			$report_b = isset( $arguments['report_id_b'] ) ? sanitize_text_field( (string) $arguments['report_id_b'] ) : '';
			if ( '' === $report_a || '' === $report_b ) {
				return new WP_Error( 'wp_mcp_ai_gtmetrix_missing_ids', __( 'Both report_id_a and report_id_b (or a url) are required.', 'mcp-ai-wpoos-pro' ) );
			}
			$before = $this->client()->get_report( $report_a );
			if ( is_wp_error( $before ) ) {
				return $before;
			}
			$after = $this->client()->get_report( $report_b );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
		}

		$flat_before = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $before );
		$flat_after  = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $after );

		$deltas = WP_MCP_AI_GTmetrix_Score_Engine::report_delta( $flat_before, $flat_after );

		$regressed = 0;
		foreach ( $deltas as $row ) {
			if ( 'regressed' === $row['status'] ) {
				++$regressed;
			}
		}

		return array(
			'success'         => true,
			'before'          => array(
				'report_id' => isset( $flat_before['id'] ) ? $flat_before['id'] : '',
				'grade'     => isset( $flat_before['gtmetrix_grade'] ) ? $flat_before['gtmetrix_grade'] : '',
				'score'     => isset( $flat_before['performance_score'] ) ? $flat_before['performance_score'] : null,
			),
			'after'           => array(
				'report_id' => isset( $flat_after['id'] ) ? $flat_after['id'] : '',
				'grade'     => isset( $flat_after['gtmetrix_grade'] ) ? $flat_after['gtmetrix_grade'] : '',
				'score'     => isset( $flat_after['performance_score'] ) ? $flat_after['performance_score'] : null,
			),
			'deltas'          => array_values( $deltas ),
			'regressed_count' => $regressed,
			'verdict'         => 0 === $regressed ? __( 'No regressions detected.', 'mcp-ai-wpoos-pro' ) : sprintf(
				/* translators: %d: number of regressed metrics */
				__( '%d metric(s) regressed — review the deltas.', 'mcp-ai-wpoos-pro' ),
				$regressed
			),
		);
	}
}
