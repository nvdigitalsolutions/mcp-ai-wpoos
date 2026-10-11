<?php
/**
 * F&B tool: expected use (M-16).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Expected use: units sold × recipe quantity per portion (A2-02).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Expected_Use extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_expected_use';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Expected Use', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate expected use of an ingredient for a month: units sold × recipe quantity per portion (M-16).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ingredient_id' => array(
					'type'        => 'string',
					'description' => __( 'Ingredient ID (e.g. ING-001).', 'mcp-ai-wpoos-pro' ),
				),
				'month'         => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'ingredient_id', 'month' ),
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

		$ingredient = isset( $arguments['ingredient_id'] ) ? sanitize_text_field( (string) $arguments['ingredient_id'] ) : '';
		$month      = $this->arg_month( $arguments );
		if ( '' === $ingredient || '' === $month ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_args', __( 'ingredient_id and month (Y-m) are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_Fnb_Metrics::m16_expected_use( $ingredient, $month );

		return array_merge(
			array(
				'success'       => true,
				'ingredient_id' => $ingredient,
				'month'         => $month,
			),
			$result
		);
	}
}
