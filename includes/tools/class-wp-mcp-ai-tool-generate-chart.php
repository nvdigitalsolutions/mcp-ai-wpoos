<?php
/**
 * Generate Chart Tool
 *
 * Creates interactive charts using Chart.js for data visualization.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Generate_Chart class.
 *
 * Generates interactive charts from data using Chart.js library.
 * Supports line, bar, pie, doughnut, scatter, and radar charts.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Chart implements WP_MCP_AI_Tool_Interface {

	/**
	 * Get tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'generate_chart';
	}

	/**
	 * Get tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'Generate interactive charts (line, bar, pie, doughnut, scatter, radar) from data using Chart.js';
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array JSON Schema for parameters.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'    => array(
					'type'        => 'string',
					'description' => 'Chart type',
					'enum'        => array( 'line', 'bar', 'pie', 'doughnut', 'scatter', 'radar' ),
				),
				'data'    => array(
					'type'        => 'object',
					'description' => 'Chart data',
					'properties'  => array(
						'labels'   => array(
							'type'        => 'array',
							'description' => 'Data labels',
							'items'       => array( 'type' => 'string' ),
						),
						'datasets' => array(
							'type'        => 'array',
							'description' => 'Data series',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'label' => array( 'type' => 'string' ),
									'data'  => array(
										'type'  => 'array',
										'items' => array( 'type' => 'number' ),
									),
								),
							),
						),
					),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => 'Chart title (optional)',
				),
				'options' => array(
					'type'        => 'object',
					'description' => 'Additional Chart.js options (optional)',
				),
			),
			'required'   => array( 'type', 'data' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate chart type.
		$valid_types = array( 'line', 'bar', 'pie', 'doughnut', 'scatter', 'radar' );
		if ( ! isset( $arguments['type'] ) || ! in_array( $arguments['type'], $valid_types, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid chart type. Must be one of: ' . implode( ', ', $valid_types ),
			);
		}

		// Validate data.
		if ( ! isset( $arguments['data'] ) || ! is_array( $arguments['data'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Chart data is required',
			);
		}

		// Generate unique ID for this chart.
		$chart_id = 'wp-mcp-ai-chart-' . wp_generate_uuid4();

		// Prepare Chart.js configuration.
		$chart_config = array(
			'type' => $arguments['type'],
			'data' => $arguments['data'],
		);

		// Add title if provided.
		if ( ! empty( $arguments['title'] ) ) {
			$chart_config['options'] = array(
				'plugins' => array(
					'title' => array(
						'display' => true,
						'text'    => sanitize_text_field( $arguments['title'] ),
					),
				),
			);
		}

		// Merge additional options if provided.
		if ( ! empty( $arguments['options'] ) && is_array( $arguments['options'] ) ) {
			if ( isset( $chart_config['options'] ) ) {
				$chart_config['options'] = array_merge_recursive( $chart_config['options'], $arguments['options'] );
			} else {
				$chart_config['options'] = $arguments['options'];
			}
		}

		// Generate HTML with embedded Chart.js code.
		$html = sprintf(
			'<div class="wp-mcp-ai-chart-container">
				<canvas id="%s" width="400" height="200"></canvas>
				<script>
				(function() {
					if (typeof Chart === "undefined") {
						console.error("Chart.js not loaded");
						return;
					}
					const ctx = document.getElementById("%s").getContext("2d");
					new Chart(ctx, %s);
				})();
				</script>
			</div>',
			esc_attr( $chart_id ),
			esc_attr( $chart_id ),
			wp_json_encode( $chart_config )
		);

		return array(
			'success'  => true,
			'chart_id' => $chart_id,
			'type'     => $arguments['type'],
			'html'     => $html,
			'message'  => sprintf( 'Generated %s chart with ID: %s', $arguments['type'], $chart_id ),
		);
	}

/**
 * Get extended tool definition including toolkit metadata.
 *
 * @since 1.1.0
 *
 * @return array Tool definition with metadata.
 */
public function get_definition() {
	return array(
		'name'                  => $this->get_name(),
		'description'           => $this->get_description(),
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'data_scientist', 'business_analyst' ),
		'risk_level'            => 'info',
	);
}

}
