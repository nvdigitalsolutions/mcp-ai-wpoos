<?php
/**
 * F&B tool: waste analysis (M-18).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Waste by item and reason, month on month (M-18, A2-08).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Waste_Analysis extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_waste_analysis';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Waste Analysis', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Waste analysis for a month: waste % (M-18), total logged waste value, and the breakdown by logged reason. Report reasons as logged — never assume reasons that are not logged (A2-08).', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Comparison month (Y-m) for month-on-month change.', 'mcp-ai-wpoos-pro' ),
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

		$result = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $month );
		$mo_m   = null;
		if ( '' !== $prev_month ) {
			$prev = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( $prev_month );
			if ( isset( $prev['waste_value'] ) ) {
				$mo_m = array(
					'prev_value' => $prev['waste_value'],
					'change'     => round( $result['waste_value'] - $prev['waste_value'], 2 ),
					'change_pct' => 0.0 !== $prev['waste_value'] ? round( ( $result['waste_value'] - $prev['waste_value'] ) / $prev['waste_value'] * 100, 2 ) : null,
				);
			}
		}

		return array_merge(
			array(
				'success'        => true,
				'month'          => $month,
				'month_on_month' => $mo_m,
			),
			$result
		);
	}
}
