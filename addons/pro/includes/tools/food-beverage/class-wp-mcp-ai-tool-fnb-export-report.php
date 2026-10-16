<?php
/**
 * F&B tool: export report as CSV or PDF.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-exporter.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-csv-exporter.php';

/**
 * Export an F&B report as CSV or PDF.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Export_Report extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_export_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Export Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export an F&B report — including the Monthly sales, Waste & variance, and Monthly cost review templates — as CSV (data) or PDF (presentation). All figures come from the metric engine.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$report_ids = array_keys( WP_MCP_AI_Fnb_Report_Builder::REPORTS );
		$report_ids = array_merge( $report_ids, array_keys( WP_MCP_AI_Fnb_Report_Exporter::CLIENT_ALIASES ) );

		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id' => array(
					'type'        => 'string',
					'enum'        => $report_ids,
					'description' => __( 'Report ID (R-01…R-14) or client alias (client-r01 = monthly sales, client-r02 = waste & variance, client-r03 = monthly cost review).', 'mcp-ai-wpoos-pro' ),
				),
				'format'    => array(
					'type'        => 'string',
					'enum'        => array( 'csv', 'pdf' ),
					'description' => __( 'Output format.', 'mcp-ai-wpoos-pro' ),
				),
				'month'     => array(
					'type'        => 'string',
					'description' => __( 'Month (Y-m) for monthly reports.', 'mcp-ai-wpoos-pro' ),
				),
				'date'      => array(
					'type'        => 'string',
					'description' => __( 'Reference date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'prev_month' => array(
					'type'        => 'string',
					'description' => __( 'Comparison month for the monthly cost review.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'report_id', 'format' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Export writes generated files, so it requires edit_posts (the same
	 * capability the assistant packs grant) rather than the base read
	 * gate — CSV/PDF are drafts of the report, never sent or ordered.
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

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
		$format    = isset( $arguments['format'] ) ? sanitize_text_field( (string) $arguments['format'] ) : 'csv';
		$format    = in_array( $format, array( 'csv', 'pdf' ), true ) ? $format : 'csv';

		$args = array();
		foreach ( array( 'month', 'date', 'prev_month' ) as $key ) {
			if ( isset( $arguments[ $key ] ) && '' !== $arguments[ $key ] ) {
				$args[ $key ] = sanitize_text_field( (string) $arguments[ $key ] );
			}
		}

		if ( 'pdf' === $format ) {
			return WP_MCP_AI_Fnb_Report_Exporter::to_pdf( $report_id, $args );
		}

		return WP_MCP_AI_Fnb_Report_Exporter::to_csv( $report_id, $args );
	}
}
