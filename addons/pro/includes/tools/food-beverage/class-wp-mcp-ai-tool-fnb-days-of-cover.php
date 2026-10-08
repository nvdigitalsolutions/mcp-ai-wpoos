<?php
/**
 * F&B tool: days of cover (M-14).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Days of cover with over-ordering flags (M-14, A2-06).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Days_Of_Cover extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_days_of_cover';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Days of Cover', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Days of cover for an ingredient: stock on hand ÷ average daily use over the last 14 days (M-14). Flags over-ordering where days of cover exceed shelf life, or 14 days for long-life items (A2-06).', 'mcp-ai-wpoos-pro' );
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
				'as_of'         => array(
					'type'        => 'string',
					'description' => __( 'Reference date (Y-m-d), defaults to the configured as-of date.', 'mcp-ai-wpoos-pro' ),
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
		$as_of      = isset( $arguments['as_of'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $arguments['as_of'] ) ? sanitize_text_field( (string) $arguments['as_of'] ) : '';
		if ( '' === $ingredient ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_ingredient', __( 'ingredient_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_Fnb_Metrics::m14_days_of_cover( $ingredient, $as_of );

		$flag        = '';
		$shelf_life  = 0.0;
		$ingredients = WP_MCP_AI_Fnb_Data_Source::get_table( '3.0' );
		if ( ! is_wp_error( $ingredients ) ) {
			foreach ( $ingredients['rows'] as $row ) {
				if ( (string) WP_MCP_AI_Fnb_Data_Source::cell( '3.0', $ingredients['header_index'], $row, 'ingredient_id' ) === $ingredient ) {
					$shelf_life = WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '3.0', $ingredients['header_index'], $row, 'shelf_life_days' ) );
					break;
				}
			}
		}
		$cap = $shelf_life > 0 ? $shelf_life : 14.0;
		if ( $result['value'] > $cap ) {
			$flag = sprintf( 'Over-ordering flag: %.1f days of cover exceeds %s.', $result['value'], $shelf_life > 0 ? 'shelf life (' . $shelf_life . ' days)' : '14 days (long-life cap)' );
		}

		return array_merge(
			array(
				'success'            => true,
				'ingredient_id'      => $ingredient,
				'shelf_life_days'    => $shelf_life,
				'over_ordering_flag' => $flag,
			),
			$result
		);
	}
}
