<?php
/**
 * F&B tool: price change impact (M-19).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Supplier price-change impact: (new − old) × quantity bought at the new price.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Price_Change_Impact extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_price_change_impact';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Price Change Impact', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'How much supplier price rises cost in a month: for each effective price change, (new price − old price) × quantity bought at the new price (M-19). Prices are matched as-of their effective date.', 'mcp-ai-wpoos-pro' );
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

		$result = WP_MCP_AI_Fnb_Metrics::m19_price_change_impact( $month );

		return array_merge(
			array(
				'success' => true,
				'month'   => $month,
			),
			$result
		);
	}
}
