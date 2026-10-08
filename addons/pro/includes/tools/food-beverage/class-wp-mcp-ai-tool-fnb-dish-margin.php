<?php
/**
 * F&B tool: dish margin + menu-engineering quadrant (M-21).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Dish margin (M-21) plus the menu-engineering quadrant (decision 2026-10-08).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Dish_Margin extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_dish_margin';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Dish Margin', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Dish margin (M-21): menu price − recipe cost at current supplier prices. With a month, also returns the menu-engineering quadrant (Star/Plowhorse/Puzzle/Dog) for every item from popularity share × margin.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'item_id' => array(
					'type'        => 'string',
					'description' => __( 'Menu item ID for a single dish (omit for the full menu-engineering matrix).', 'mcp-ai-wpoos-pro' ),
				),
				'month'   => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m) for popularity share and the full matrix.', 'mcp-ai-wpoos-pro' ),
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

		$item_id = isset( $arguments['item_id'] ) ? sanitize_text_field( (string) $arguments['item_id'] ) : '';
		$month   = $this->arg_month( $arguments );

		if ( '' === $item_id ) {
			if ( '' === $month ) {
				return new WP_Error( 'wp_mcp_ai_fnb_missing_month', __( 'The full menu-engineering matrix requires a month (Y-m).', 'mcp-ai-wpoos-pro' ) );
			}

			return array_merge( array( 'success' => true ), WP_MCP_AI_Fnb_Metrics::menu_engineering( $month ) );
		}

		$result = WP_MCP_AI_Fnb_Metrics::m21_dish_margin( $item_id, $month );

		return array_merge(
			array(
				'success' => true,
				'item_id' => $item_id,
			),
			$result
		);
	}
}
