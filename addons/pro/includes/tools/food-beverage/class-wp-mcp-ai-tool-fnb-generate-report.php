<?php
/**
 * F&B tool: generate report (R-01…R-13).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Generate a report in its Report-library layout (G-09).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Generate_Report extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_generate_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Generate Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a Surf Club report (R-01…R-13) in its Report-library layout. All figures come from the metric engine. Returns structured sections plus a Draft header for fnb_save_draft.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id'     => array(
					'type'        => 'string',
					'enum'        => array_keys( WP_MCP_AI_Fnb_Report_Builder::REPORTS ),
					'description' => __( 'Report ID from the Report library.', 'mcp-ai-wpoos-pro' ),
				),
				'month'         => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m) for monthly reports.', 'mcp-ai-wpoos-pro' ),
				),
				'date'          => array(
					'type'        => 'string',
					'description' => __( 'Reference date (Y-m-d) for R-01/R-03/R-13.', 'mcp-ai-wpoos-pro' ),
				),
				'prev_month'    => array(
					'type'        => 'string',
					'description' => __( 'Comparison month for R-08.', 'mcp-ai-wpoos-pro' ),
				),
				'supplier_id'   => array(
					'type'        => 'string',
					'description' => __( 'Supplier ID for R-04.', 'mcp-ai-wpoos-pro' ),
				),
				'ingredient_id' => array(
					'type'        => 'string',
					'description' => __( 'Ingredient ID for R-04.', 'mcp-ai-wpoos-pro' ),
				),
				'quantity'      => array(
					'type'        => 'number',
					'description' => __( 'Quantity for R-04.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'report_id' ),
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

		$report_id = isset( $arguments['report_id'] ) ? sanitize_text_field( (string) $arguments['report_id'] ) : '';

		$result = WP_MCP_AI_Fnb_Report_Builder::build( $report_id, $arguments );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( array( 'success' => true ), $result );
	}
}
