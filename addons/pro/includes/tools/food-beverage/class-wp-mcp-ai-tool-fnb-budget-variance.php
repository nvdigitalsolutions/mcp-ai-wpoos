<?php
/**
 * F&B tool: budget variance (M-20).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Actual vs budget by line, largest gaps first (M-20, A3-02).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Budget_Variance extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_budget_variance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Budget Variance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Budget variance by line for a month: actual − budget (M-20), sorted largest gap first. Actuals are valued on stock used (M-04/M-05) and month-incurred expenses (G-07).', 'mcp-ai-wpoos-pro' );
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

		$result = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( $month );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = $result['rows'];
		usort(
			$rows,
			static function ( $a, $b ) {
				$av = null === $a['variance'] ? PHP_FLOAT_MIN : $a['variance'];
				$bv = null === $b['variance'] ? PHP_FLOAT_MIN : $b['variance'];

				return $bv <=> $av;
			}
		);

		return array(
			'success' => true,
			'month'   => $month,
			'rows'    => $rows,
			'source'  => 'fnb_budget_variance',
		);
	}
}
