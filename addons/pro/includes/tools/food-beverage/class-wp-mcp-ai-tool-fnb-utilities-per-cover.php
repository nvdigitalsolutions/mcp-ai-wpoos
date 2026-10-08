<?php
/**
 * F&B tool: utilities per cover (M-11).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * KWh/water/LPG per cover with costed totals (M-11, Assumptions rates).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Utilities_Per_Cover extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_utilities_per_cover';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Utilities Per Cover', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Utilities analysis: electricity kWh per cover (M-11), water and LPG usage, and the costed totals from the Assumptions rates (demo assumptions, G-11).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'month' => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m).', 'mcp-ai-wpoos-pro' ),
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

		$month = $this->arg_month( $arguments );
		if ( '' === $month ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_month', __( 'month (Y-m) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$utils  = WP_MCP_AI_Fnb_Metrics::utilities_totals( $month );
		$m11    = WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( $month );
		$covers = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );

		$per_cover = array(
			'electricity_kwh' => $m11['value'],
			'water_m3'        => $covers['value'] > 0 ? round( $utils['water_m3'] / $covers['value'], 4 ) : 0.0,
			'lpg_cylinders'   => $covers['value'] > 0 ? round( $utils['lpg_units'] / $covers['value'], 4 ) : 0.0,
		);

		return array(
			'success'            => true,
			'month'              => $month,
			'meters'             => array(
				'electricity_kwh' => $utils['kwh'],
				'water_m3'        => $utils['water_m3'],
				'lpg_cylinders'   => $utils['lpg_units'],
			),
			'costed_lkr'         => array(
				'Electricity' => $utils['Electricity'],
				'Water'       => $utils['Water'],
				'LPG'         => $utils['LPG'],
			),
			'per_cover'          => $per_cover,
			'covers'             => round( $covers['value'] ),
			'assumptions_source' => 'Demo assumptions sheet (G-11)',
			'source'             => 'fnb_utilities_per_cover',
		);
	}
}
