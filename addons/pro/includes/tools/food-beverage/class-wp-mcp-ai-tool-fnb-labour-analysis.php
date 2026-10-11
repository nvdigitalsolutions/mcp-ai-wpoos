<?php
/**
 * F&B tool: labour analysis (M-09/M-10).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Overtime per 100 covers and labour cost % (M-09, M-10).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Labour_Analysis extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_labour_analysis';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Labour Analysis', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Labour analysis for a month: payroll by department, overtime hours and overtime per 100 covers (M-09), casual shifts, and labour cost % (M-10).', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Month (Y-m).', 'mcp-ai-wpoos-pro' ),
				),
				'prev_month' => array(
					'type'        => 'string',
					'description' => __( 'Comparison month (Y-m) for overtime growth vs covers growth.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'month' ),
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

		$month      = $this->arg_month( $arguments );
		$prev_month = isset( $arguments['prev_month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $arguments['prev_month'] ) ? sanitize_text_field( (string) $arguments['prev_month'] ) : '';
		if ( '' === $month ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_month', __( 'month (Y-m) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$payroll = WP_MCP_AI_Fnb_Metrics::payroll_total( $month );
		$m09     = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( $month );
		$m10     = WP_MCP_AI_Fnb_Metrics::m10_labour_pct( $month );

		$comparison = null;
		if ( '' !== $prev_month ) {
			$prev_payroll = WP_MCP_AI_Fnb_Metrics::payroll_total( $prev_month );
			$prev_m09     = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( $prev_month );
			$covers       = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
			$prev_covers  = WP_MCP_AI_Fnb_Metrics::m02_covers( $prev_month );
			$comparison   = array(
				'overtime_change'       => round( $payroll['overtime_hours'] - $prev_payroll['overtime_hours'], 2 ),
				'covers_change'         => round( $covers['value'] - $prev_covers['value'] ),
				'overtime_per_100_prev' => $prev_m09['value'],
				'justified'             => ( $prev_covers['value'] > 0 && ( $covers['value'] - $prev_covers['value'] ) / $prev_covers['value'] >= ( $payroll['overtime_hours'] - $prev_payroll['overtime_hours'] ) / max( $prev_payroll['overtime_hours'], 1 ) ) ? 'Overtime growth ≤ covers growth' : 'Overtime growth exceeds covers growth — check R-09',
			);
		}

		return array(
			'success'              => true,
			'month'                => $month,
			'total_payroll'        => $payroll['value'],
			'departments'          => $payroll['departments'],
			'overtime_hours'       => $payroll['overtime_hours'],
			'casual_shifts'        => $payroll['casual_shifts'],
			'm09_overtime_per_100' => $m09['value'],
			'm10_labour_pct'       => $m10['value'],
			'comparison'           => $comparison,
			'source'               => 'fnb_labour_analysis',
		);
	}
}
