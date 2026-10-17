<?php
/**
 * GTmetrix tool: get Lighthouse audits.
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
 * Read Lighthouse audits from a report, impact-ordered, optionally filtered.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Lighthouse_Audits extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_lighthouse_audits';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Lighthouse Audits', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the Lighthouse audits behind a GTmetrix report, sorted by GTmetrix impact score. Filter by minimum impact band (Low, Med-Low, Med, Med-High, High) to focus on what matters.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id'  => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID.', 'mcp-ai-wpoos-pro' ),
				),
				'min_impact' => array(
					'type'        => 'string',
					'enum'        => array( 'Low', 'Med-Low', 'Med', 'Med-High', 'High' ),
					'description' => __( 'Only return audits at or above this impact band.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum audits to return (default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
			),
			'required'   => array( 'report_id' ),
		);
	}

	/**
	 * Impact band ordering (index for comparison).
	 *
	 * @var array<string, int>
	 */
	private $band_order = array(
		'None'     => 0,
		'Low'      => 1,
		'Med-Low'  => 2,
		'Med'      => 3,
		'Med-High' => 4,
		'High'     => 5,
	);

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

		$lighthouse = $this->client()->get_resource( $report_id, 'lighthouse.json' );
		if ( is_wp_error( $lighthouse ) ) {
			return $lighthouse;
		}

		$audits = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$rows   = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $audits );

		$min_impact = isset( $arguments['min_impact'] ) ? sanitize_text_field( $arguments['min_impact'] ) : '';
		$min_index  = isset( $this->band_order[ $min_impact ] ) ? $this->band_order[ $min_impact ] : 0;

		$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;
		$filtered = array();
		foreach ( $rows as $row ) {
			if ( $this->band_order[ $row['impact_band'] ] < $min_index ) {
				continue;
			}
			$filtered[] = $row;
			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		$lcp_phases = WP_MCP_AI_GTmetrix_Score_Engine::lcp_phases( $lighthouse );

		return array(
			'success'      => true,
			'report_id'    => $report_id,
			'audits'       => $filtered,
			'count'        => count( $filtered ),
			'total_audits' => count( $rows ),
			'lcp_phases'   => $lcp_phases,
		);
	}
}
