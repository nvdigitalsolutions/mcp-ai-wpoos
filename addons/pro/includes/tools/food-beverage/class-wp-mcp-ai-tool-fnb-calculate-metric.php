<?php
/**
 * F&B tool: calculate a metric (M-01…M-21).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Deterministic metric calculation — the only calculation surface (G-02, G-08).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Calculate_Metric extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_calculate_metric';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Calculate Metric', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate a Surf Club metric (M-01…M-21) using the Metric definitions sheet formulas. Use this for every total, average, percentage and comparison — never compute figures yourself.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'metric_id'     => array(
					'type'        => 'string',
					'enum'        => array_keys( WP_MCP_AI_Fnb_Metrics::METRICS ),
					'description' => __( 'Metric ID from the Metric definitions sheet.', 'mcp-ai-wpoos-pro' ),
				),
				'month'         => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m), e.g. 2026-09.', 'mcp-ai-wpoos-pro' ),
				),
				'date'          => array(
					'type'        => 'string',
					'description' => __( 'Reference date (Y-m-d) for M-12/M-13.', 'mcp-ai-wpoos-pro' ),
				),
				'item_id'       => array(
					'type'        => 'string',
					'description' => __( 'Menu item ID for M-13/M-21.', 'mcp-ai-wpoos-pro' ),
				),
				'ingredient_id' => array(
					'type'        => 'string',
					'description' => __( 'Ingredient ID for M-14…M-17.', 'mcp-ai-wpoos-pro' ),
				),
				'type'          => array(
					'type'        => 'string',
					'enum'        => array( 'Food', 'Beverage' ),
					'description' => __( 'Revenue type filter for M-01.', 'mcp-ai-wpoos-pro' ),
				),
				'from'          => array(
					'type'        => 'string',
					'description' => __( 'Period start (Y-m-d) for M-15/M-17.', 'mcp-ai-wpoos-pro' ),
				),
				'to'            => array(
					'type'        => 'string',
					'description' => __( 'Period end (Y-m-d) for M-15/M-17.', 'mcp-ai-wpoos-pro' ),
				),
				'prev_cost'     => array(
					'type'        => 'number',
					'description' => __( 'Previous period cost for M-07/M-08.', 'mcp-ai-wpoos-pro' ),
				),
				'prev_covers'   => array(
					'type'        => 'number',
					'description' => __( 'Previous period covers for M-07/M-08.', 'mcp-ai-wpoos-pro' ),
				),
				'cur_cost'      => array(
					'type'        => 'number',
					'description' => __( 'Current period cost for M-08.', 'mcp-ai-wpoos-pro' ),
				),
				'cur_covers'    => array(
					'type'        => 'number',
					'description' => __( 'Current period covers for M-07/M-08.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'metric_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$metric_id = isset( $arguments['metric_id'] ) ? sanitize_text_field( (string) $arguments['metric_id'] ) : '';

		$result = WP_MCP_AI_Fnb_Metrics::calculate( $metric_id, $arguments );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( array( 'success' => true ), $result );
	}
}
