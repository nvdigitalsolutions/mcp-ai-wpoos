<?php
/**
 * F&B tool: stock used (M-15).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Stock used: opening + bought − closing (G-06, A2-01).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Stock_Used extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_stock_used';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Stock Used', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate stock used for an ingredient over a period: opening + bought − closing (M-15). Keeps stock bought separate from stock used (G-06).', 'mcp-ai-wpoos-pro' );
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
				'from'          => array(
					'type'        => 'string',
					'description' => __( 'Period start (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'to'            => array(
					'type'        => 'string',
					'description' => __( 'Period end (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'ingredient_id' ),
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
		$from       = isset( $arguments['from'] ) ? sanitize_text_field( (string) $arguments['from'] ) : null;
		$to         = isset( $arguments['to'] ) ? sanitize_text_field( (string) $arguments['to'] ) : null;
		if ( '' === $ingredient ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'ingredient_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_Fnb_Metrics::m15_stock_used( $ingredient, $from, $to );

		return array_merge(
			array(
				'success'       => true,
				'ingredient_id' => $ingredient,
			),
			$result
		);
	}
}
