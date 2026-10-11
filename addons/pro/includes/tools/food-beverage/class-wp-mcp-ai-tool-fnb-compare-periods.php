<?php
/**
 * F&B tool: compare two periods (M-01…M-11 MoM).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Month-vs-month comparison across the headline metrics.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Compare_Periods extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_compare_periods';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Compare Periods', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compare two months across the headline metrics (revenue, covers, average spend, cost %, cost per cover) with change and % change. Default: September vs August.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'month'      => array(
					'type'        => 'string',
					'description' => __( 'Current month (Y-m), default 2026-09.', 'mcp-ai-wpoos-pro' ),
					'default'     => '2026-09',
				),
				'prev_month' => array(
					'type'        => 'string',
					'description' => __( 'Previous month (Y-m), default 2026-08.', 'mcp-ai-wpoos-pro' ),
					'default'     => '2026-08',
				),
			),
			'required'   => array(),
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

		$month      = isset( $arguments['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $arguments['month'] ) ? sanitize_text_field( (string) $arguments['month'] ) : '2026-09';
		$prev_month = isset( $arguments['prev_month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $arguments['prev_month'] ) ? sanitize_text_field( (string) $arguments['prev_month'] ) : '2026-08';

		$metrics = array();
		foreach ( array( 'M-01', 'M-02', 'M-03', 'M-04', 'M-05', 'M-06', 'M-09', 'M-10', 'M-11', 'M-18' ) as $id ) {
			$cur  = WP_MCP_AI_Fnb_Metrics::calculate( $id, array( 'month' => $month ) );
			$prev = WP_MCP_AI_Fnb_Metrics::calculate( $id, array( 'month' => $prev_month ) );
			if ( is_wp_error( $cur ) || is_wp_error( $prev ) ) {
				continue;
			}
			$cur_v          = isset( $cur['value'] ) ? (float) $cur['value'] : 0.0;
			$prev_v         = isset( $prev['value'] ) ? (float) $prev['value'] : 0.0;
			$metrics[ $id ] = array(
				'label'      => WP_MCP_AI_Fnb_Metrics::METRICS[ $id ],
				'current'    => $cur_v,
				'previous'   => $prev_v,
				'change'     => round( $cur_v - $prev_v, 4 ),
				'change_pct' => 0.0 !== $prev_v ? round( ( $cur_v - $prev_v ) / abs( $prev_v ) * 100, 2 ) : null,
			);
		}

		// Revenue split by type for the comparison.
		foreach ( array( 'Food', 'Beverage' ) as $type ) {
			$cur                        = WP_MCP_AI_Fnb_Metrics::m01_revenue( $month, $type );
			$prev                       = WP_MCP_AI_Fnb_Metrics::m01_revenue( $prev_month, $type );
			$metrics[ 'M-01-' . $type ] = array(
				'label'      => $type . ' revenue (LKR)',
				'current'    => $cur['value'],
				'previous'   => $prev['value'],
				'change'     => round( $cur['value'] - $prev['value'], 2 ),
				'change_pct' => 0.0 !== $prev['value'] ? round( ( $cur['value'] - $prev['value'] ) / abs( $prev['value'] ) * 100, 2 ) : null,
			);
		}

		return array(
			'success'    => true,
			'month'      => $month,
			'prev_month' => $prev_month,
			'metrics'    => $metrics,
			'source'     => 'fnb_compare_periods',
		);
	}
}
