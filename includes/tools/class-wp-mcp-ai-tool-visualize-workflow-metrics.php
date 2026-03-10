<?php
/**
 * Tool for visualizing workflow execution metrics using Chart.js
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Workflow Metrics Visualization Tool
 *
 * Creates interactive charts showing workflow execution metrics:
 * - Step completion rates
 * - Execution time distribution
 * - Success/failure ratios
 * - Parallel vs sequential comparison
 *
 * @since 1.2.2
 */
class WP_MCP_AI_Tool_Visualize_Workflow_Metrics implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'visualize_workflow_metrics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Visualize Workflow Metrics', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates interactive Chart.js visualizations of workflow execution metrics including completion rates, timing, and performance data.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'workflow_results' => array(
					'type'        => 'object',
					'description' => __( 'Workflow execution results object with metrics.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'chart_type'       => array(
					'type'        => 'string',
					'description' => __( 'Type of chart to generate: performance, completion, or timing.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'performance', 'completion', 'timing', 'all' ),
					'default'     => 'performance',
				),
				'save_attachment'  => array(
					'type'        => 'boolean',
					'description' => __( 'Save chart as HTML attachment.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'   => array( 'workflow_results' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Result array.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate results.
		if ( empty( $arguments['workflow_results'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Workflow results are required.', 'mcp-ai-wpoos' ),
			);
		}

		$results    = $arguments['workflow_results'];
		$chart_type = isset( $arguments['chart_type'] ) ? $arguments['chart_type'] : 'performance';

		// Generate chart HTML.
		$charts = array();

		if ( 'all' === $chart_type || 'performance' === $chart_type ) {
			$charts['performance'] = $this->generate_performance_chart( $results );
		}

		if ( 'all' === $chart_type || 'completion' === $chart_type ) {
			$charts['completion'] = $this->generate_completion_chart( $results );
		}

		if ( 'all' === $chart_type || 'timing' === $chart_type ) {
			$charts['timing'] = $this->generate_timing_chart( $results );
		}

		// Combine charts.
		$html = $this->generate_chart_html( $charts );

		// Save as attachment if requested.
		if ( ! empty( $arguments['save_attachment'] ) ) {
			$attachment_id = $this->save_as_attachment( $html, 'workflow-metrics' );
			return array(
				'success'       => true,
				'html'          => $html,
				'attachment_id' => $attachment_id,
				'message'       => __( 'Workflow metrics chart created and saved.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success' => true,
			'html'    => $html,
			'message' => __( 'Workflow metrics chart created.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Generate performance overview chart
	 *
	 * @param array $results Workflow results.
	 * @return array Chart configuration.
	 */
	private function generate_performance_chart( $results ) {
		$metrics = isset( $results['metrics'] ) ? $results['metrics'] : array();

		$data = array(
			'labels'   => array( 'Completed', 'Failed' ),
			'datasets' => array(
				array(
					'label'           => 'Steps',
					'data'            => array(
						isset( $results['steps_completed'] ) ? $results['steps_completed'] : 0,
						isset( $results['steps_failed'] ) ? $results['steps_failed'] : 0,
					),
					'backgroundColor' => array( '#10b981', '#ef4444' ),
				),
			),
		);

		return array(
			'type'    => 'doughnut',
			'data'    => $data,
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'plugins'             => array(
					'title'  => array(
						'display' => true,
						'text'    => 'Workflow Completion Status',
					),
					'legend' => array(
						'position' => 'bottom',
					),
				),
			),
		);
	}

	/**
	 * Generate completion rate chart
	 *
	 * @param array $results Workflow results.
	 * @return array Chart configuration.
	 */
	private function generate_completion_chart( $results ) {
		$metrics = isset( $results['metrics'] ) ? $results['metrics'] : array();

		$total_steps = isset( $metrics['steps_executed'] ) ? $metrics['steps_executed'] : 0;
		$completed   = isset( $results['steps_completed'] ) ? $results['steps_completed'] : 0;
		$failed      = isset( $results['steps_failed'] ) ? $results['steps_failed'] : 0;

		$completion_rate = $total_steps > 0 ? round( ( $completed / $total_steps ) * 100, 1 ) : 0;

		$data = array(
			'labels'   => array( 'Completion Rate', 'Remaining' ),
			'datasets' => array(
				array(
					'label'           => 'Percentage',
					'data'            => array( $completion_rate, 100 - $completion_rate ),
					'backgroundColor' => array( '#3b82f6', '#e5e7eb' ),
				),
			),
		);

		return array(
			'type'    => 'pie',
			'data'    => $data,
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'plugins'             => array(
					'title'  => array(
						'display' => true,
						'text'    => 'Workflow Completion Rate',
					),
					'legend' => array(
						'position' => 'bottom',
					),
				),
			),
		);
	}

	/**
	 * Generate timing distribution chart
	 *
	 * @param array $results Workflow results.
	 * @return array Chart configuration.
	 */
	private function generate_timing_chart( $results ) {
		$step_results = isset( $results['step_results'] ) ? $results['step_results'] : array();

		$labels    = array();
		$durations = array();
		$colors    = array();

		foreach ( $step_results as $step ) {
			if ( isset( $step['duration'] ) && $step['duration'] > 0 ) {
				$labels[]    = isset( $step['task'] ) ? $step['task'] : 'Step ' . $step['step'];
				$durations[] = $step['duration'];
				$colors[]    = 'completed' === $step['status'] ? '#10b981' : '#ef4444';
			}
		}

		$data = array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label'           => 'Duration (seconds)',
					'data'            => $durations,
					'backgroundColor' => $colors,
				),
			),
		);

		return array(
			'type'    => 'bar',
			'data'    => $data,
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'plugins'             => array(
					'title'  => array(
						'display' => true,
						'text'    => 'Step Execution Time',
					),
					'legend' => array(
						'display' => false,
					),
				),
				'scales'              => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => 'Seconds',
						),
					),
				),
			),
		);
	}

	/**
	 * Generate complete HTML with Chart.js
	 *
	 * @param array $charts Array of chart configurations.
	 * @return string HTML output.
	 */
	private function generate_chart_html( $charts ) {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Script tag is part of generated HTML output string, not enqueued via wp_enqueue_script.
		$html = '<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Workflow Metrics Dashboard</title>
	<script src="' . esc_url( WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js' ) . '"></script>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			padding: 20px;
			background: #f9fafb;
		}
		.dashboard {
			max-width: 1200px;
			margin: 0 auto;
		}
		.chart-container {
			background: white;
			padding: 20px;
			margin-bottom: 20px;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.1);
		}
		canvas {
			max-height: 400px;
		}
		h1 {
			color: #111827;
			margin-bottom: 30px;
		}
	</style>
</head>
<body>
	<div class="dashboard">
		<h1>Workflow Execution Metrics</h1>';

		foreach ( $charts as $name => $config ) {
			$canvas_id = 'chart-' . sanitize_key( $name );
			$html     .= sprintf(
				'<div class="chart-container">
					<canvas id="%s"></canvas>
				</div>',
				esc_attr( $canvas_id )
			);
		}

		$html .= '<script>';

		foreach ( $charts as $name => $config ) {
			$canvas_id = 'chart-' . sanitize_key( $name );
			$html     .= sprintf(
				'
				new Chart(document.getElementById("%s"), %s);',
				esc_js( $canvas_id ),
				wp_json_encode( $config )
			);
		}

		$html .= '</script>
	</div>
</body>
</html>';

		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
		return $html;
	}

	/**
	 * Save chart as HTML attachment
	 *
	 * @param string $html HTML content.
	 * @param string $filename Base filename.
	 * @return int|false Attachment ID or false on failure.
	 */
	private function save_as_attachment( $html, $filename ) {
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename . '-' . time() . '.html';

		// Save file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		if ( false === file_put_contents( $file_path, $html ) ) {
			return false;
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'text/html',
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		return $attachment_id;
	}
}
