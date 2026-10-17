<?php
/**
 * GTmetrix tool: get report.
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
 * Read a GTmetrix report's scores and metrics (snapshot-preferred).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Report extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read a GTmetrix report: grades, scores, Core Web Vitals timings, page size and request count, plus available resource links.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'GTmetrix report ID.', 'mcp-ai-wpoos-pro' ),
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

		$report = null;
		if ( class_exists( 'WP_MCP_AI_GTmetrix_Snapshot_Store' ) ) {
			$store = new WP_MCP_AI_GTmetrix_Snapshot_Store();
			$local = $store->get_report( $report_id );
			if ( ! is_wp_error( $local ) ) {
				$report = $local;
			}
		}

		if ( null === $report ) {
			$report = $this->client()->get_report( $report_id );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
		}

		$attrs = isset( $report['attributes'] ) ? $report['attributes'] : $report;
		$flat  = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $report );

		$links = isset( $attrs['links'] ) && is_array( $attrs['links'] ) ? $attrs['links'] : array();

		return array(
			'success'   => true,
			'report_id' => $report_id,
			'url'       => isset( $flat['url'] ) ? $flat['url'] : '',
			'grade'     => isset( $flat['gtmetrix_grade'] ) ? $flat['gtmetrix_grade'] : '',
			'scores'    => array(
				'gtmetrix'    => isset( $flat['gtmetrix_score'] ) ? $flat['gtmetrix_score'] : null,
				'performance' => isset( $flat['performance_score'] ) ? $flat['performance_score'] : null,
				'structure'   => isset( $flat['structure_score'] ) ? $flat['structure_score'] : null,
				'pagespeed'   => isset( $flat['pagespeed_score'] ) ? $flat['pagespeed_score'] : null,
				'yslow'       => isset( $flat['yslow_score'] ) ? $flat['yslow_score'] : null,
			),
			'metrics'   => array(
				'ttfb'         => isset( $flat['time_to_first_byte'] ) ? $flat['time_to_first_byte'] : null,
				'fcp'          => isset( $flat['first_contentful_paint'] ) ? $flat['first_contentful_paint'] : null,
				'lcp'          => isset( $flat['largest_contentful_paint'] ) ? $flat['largest_contentful_paint'] : null,
				'tti'          => isset( $flat['time_to_interactive'] ) ? $flat['time_to_interactive'] : null,
				'tbt'          => isset( $flat['total_blocking_time'] ) ? $flat['total_blocking_time'] : null,
				'cls'          => isset( $flat['cumulative_layout_shift'] ) ? $flat['cumulative_layout_shift'] : null,
				'speed_index'  => isset( $flat['speed_index'] ) ? $flat['speed_index'] : null,
				'onload_time'  => isset( $flat['onload_time'] ) ? $flat['onload_time'] : null,
				'fully_loaded' => isset( $flat['fully_loaded_time'] ) ? $flat['fully_loaded_time'] : null,
			),
			'page'      => array(
				'bytes'      => isset( $flat['page_bytes'] ) ? $flat['page_bytes'] : null,
				'requests'   => isset( $flat['page_requests'] ) ? $flat['page_requests'] : null,
				'html_bytes' => isset( $flat['html_bytes'] ) ? $flat['html_bytes'] : null,
			),
			'created'   => isset( $flat['created'] ) ? $flat['created'] : null,
			'expires'   => isset( $flat['expires'] ) ? $flat['expires'] : null,
			'resources' => array_keys( $links ),
		);
	}
}
