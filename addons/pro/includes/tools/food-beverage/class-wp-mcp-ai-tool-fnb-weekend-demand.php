<?php
/**
 * F&B tool: weekend demand (M-12/M-13).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Expected weekend covers, portions, shortfall and order deadlines (A2-04/05).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Weekend_Demand extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_weekend_demand';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Weekend Demand', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Weekend prep: expected covers per day (M-12: same-weekday 4-week average + group bookings of 15+), expected portions per dish (M-13), stock shortfalls, and order-by dates per supplier cut-off (A2-04, A2-05).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'friday' => array(
					'type'        => 'string',
					'description' => __( 'Weekend Friday date (Y-m-d), default 2026-10-09.', 'mcp-ai-wpoos-pro' ),
					'default'     => '2026-10-09',
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

		$friday = isset( $arguments['friday'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $arguments['friday'] ) ? sanitize_text_field( (string) $arguments['friday'] ) : '2026-10-09';

		$days = array(
			$friday,
			gmdate( 'Y-m-d', strtotime( $friday . ' +1 day' ) ),
			gmdate( 'Y-m-d', strtotime( $friday . ' +2 days' ) ),
		);

		$per_day = array();
		foreach ( $days as $day ) {
			$per_day[ $day ] = WP_MCP_AI_Fnb_Metrics::m12_expected_covers( $day );
		}

		$supplier_cutoffs = array();
		$suppliers        = WP_MCP_AI_Fnb_Data_Source::get_table( '4.0' );
		if ( ! is_wp_error( $suppliers ) ) {
			foreach ( $suppliers['rows'] as $row ) {
				$supplier_cutoffs[] = array(
					'supplier_id'  => (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'supplier_id' ),
					'supplier'     => (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'supplier' ),
					'order_cutoff' => (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'order_cutoff' ),
					'lead_time'    => (string) WP_MCP_AI_Fnb_Data_Source::cell( '4.0', $suppliers['header_index'], $row, 'lead_time' ),
				);
			}
		}

		return array(
			'success'          => true,
			'weekend'          => array(
				'friday' => $friday,
				'sunday' => end( $days ),
			),
			'per_day'          => $per_day,
			'supplier_cutoffs' => $supplier_cutoffs,
			'report_hint'      => 'Run fnb_generate_report with report_id R-03 for the full weekend prep plan layout.',
			'source'           => 'fnb_weekend_demand',
		);
	}
}
