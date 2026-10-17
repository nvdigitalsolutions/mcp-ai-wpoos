<?php
/**
 * GTmetrix tool: set alert conditions.
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
 * Replace a page's performance-alert conditions (full-replace semantics,
 * max 10 conditions).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Set_Alert_Conditions extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * Valid alert metric IDs (proposal Appendix A.3).
	 *
	 * @var string[]
	 */
	private $metric_ids = array(
		'gtmetrix_score',
		'performance_score',
		'structure_score',
		'first_contentful_paint',
		'largest_contentful_paint',
		'total_blocking_time',
		'cumulative_layout_shift',
		'time_to_interactive',
		'speed_index',
		'redirect_time',
		'connect_time',
		'backend_time',
		'ttfb',
		'onload',
		'fully_loaded',
		'total_size',
		'requests',
		'html_size',
		'first_paint',
		'dom_interactive',
		'dom_content_loaded',
		'dom_content_loaded_time',
		'onload_time',
		'rum_speed_index',
		'pagespeed_score',
		'yslow_score',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_set_alert_conditions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Set Alert Conditions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Replace the performance-alert conditions for a GTmetrix page (full replace, max 10). Each condition is a metric, operator (<, =, >) and threshold in the same units the report API uses (ms or bytes).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'external-api', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'page_id'      => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix page ID.', 'mcp-ai-wpoos-pro' ),
				),
				'conditions'   => array(
					'type'        => 'array',
					'description' => __( 'Full condition set (max 10). Empty array removes all conditions.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'metric'   => array( 'type' => 'string' ),
							'operator' => array(
								'type' => 'string',
								'enum' => array( '<', '=', '>' ),
							),
							'value'    => array(
								'type'    => 'number',
								'minimum' => 0,
							),
						),
					),
				),
				'create_video' => array(
					'type'        => 'boolean',
					'description' => __( 'Keep a load video when an alert triggers (PRO only).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'page_id', 'conditions' ),
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

		$page_id = $this->arg_page_id( $arguments );
		if ( '' === $page_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_page', __( 'A page ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$raw = isset( $arguments['conditions'] ) && is_array( $arguments['conditions'] ) ? $arguments['conditions'] : null;
		if ( null === $raw ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_conditions', __( 'The conditions array is required (empty array clears alerts).', 'mcp-ai-wpoos-pro' ) );
		}
		if ( count( $raw ) > 10 ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_too_many_conditions', __( 'GTmetrix allows at most 10 alert conditions per page.', 'mcp-ai-wpoos-pro' ) );
		}

		$conditions = array();
		foreach ( $raw as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			$metric   = isset( $condition['metric'] ) ? sanitize_key( $condition['metric'] ) : '';
			$operator = isset( $condition['operator'] ) ? sanitize_text_field( $condition['operator'] ) : '';
			$value    = isset( $condition['value'] ) ? (float) $condition['value'] : -1;

			if ( ! in_array( $metric, $this->metric_ids, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_gtmetrix_invalid_metric',
					sprintf(
					/* translators: %s: metric ID */
						__( 'Unknown alert metric: %s.', 'mcp-ai-wpoos-pro' ),
						$metric
					)
				);
			}
			if ( ! in_array( $operator, array( '<', '=', '>' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_operator', __( 'Alert operator must be <, = or >.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( $value < 0 ) {
				return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_threshold', __( 'Alert thresholds must be non-negative numbers.', 'mcp-ai-wpoos-pro' ) );
			}

			$conditions[] = array(
				'metric'   => $metric,
				'operator' => $operator,
				'value'    => $value,
			);
		}

		$create_video = ! empty( $arguments['create_video'] );

		$result = $this->client()->set_alert_conditions( $page_id, $conditions, $create_video );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attrs = isset( $result['attributes'] ) ? $result['attributes'] : array();

		return array(
			'success'      => true,
			'page_id'      => isset( $result['id'] ) ? $result['id'] : $page_id,
			'conditions'   => isset( $attrs['conditions'] ) ? $attrs['conditions'] : $conditions,
			'create_video' => ! empty( $attrs['create_video'] ),
			'count'        => count( $conditions ),
		);
	}
}
