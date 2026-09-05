<?php
/**
 * WordPress-specific tool for visualizing workflow execution metrics.
 *
 * Lives in the WordPress adapter because it uses WordPress attachment
 * APIs (wp_upload_dir, wp_insert_attachment) and plugin asset URLs
 * (WP_MCP_AI_URL) for Chart.js.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Creates interactive Chart.js visualizations of workflow execution metrics.
 *
 * Generates doughnut, pie, and bar charts showing completion rates,
 * timing distributions, and performance data.
 */
class VisualizeWorkflowMetricsTool extends AbstractTool {

	public function getSlug(): string {
		return 'visualize_workflow_metrics';
	}

	public function getName(): string {
		return 'Visualize Workflow Metrics';
	}

	public function getDescription(): string {
		return 'Creates interactive Chart.js visualizations of workflow execution metrics including completion rates, timing, and performance data.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'workflow_results' => array(
					'type'        => 'object',
					'description' => 'Workflow execution results object with metrics.',
				),
				'chart_type'       => array(
					'type'        => 'string',
					'description' => 'Type of chart: performance, completion, timing, or all.',
					'enum'        => array( 'performance', 'completion', 'timing', 'all' ),
					'default'     => 'performance',
				),
				'save_attachment'  => array(
					'type'        => 'boolean',
					'description' => 'Save chart as HTML attachment.',
					'default'     => false,
				),
			),
			'required'   => array( 'workflow_results' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( empty( $arguments['workflow_results'] ) ) {
			return $this->errors->validationFailed(
				'Workflow results are required.',
				array( 'workflow_results' => array( 'This field is required.' ) ),
			);
		}

		$results    = $arguments['workflow_results'];
		$chart_type = $arguments['chart_type'] ?? 'performance';

		$charts = array();

		if ( 'all' === $chart_type || 'performance' === $chart_type ) {
			$charts['performance'] = $this->generatePerformanceChart( $results );
		}

		if ( 'all' === $chart_type || 'completion' === $chart_type ) {
			$charts['completion'] = $this->generateCompletionChart( $results );
		}

		if ( 'all' === $chart_type || 'timing' === $chart_type ) {
			$charts['timing'] = $this->generateTimingChart( $results );
		}

		// Standalone gate: the Chart.js bundle ships with the base plugin.
		if ( ! \defined( 'WP_MCP_AI_URL' ) ) {
			return $this->errors->create(
				'wp_mcp_ai_chart_assets_unavailable',
				'Workflow metrics charts require the NV oOS base plugin asset bundle in this install.',
			);
		}

		$html = $this->generateChartHtml( $charts );

		if ( ! empty( $arguments['save_attachment'] ) ) {
			$attachment_id = $this->saveAsAttachment( $html, 'workflow-metrics' );

			return $this->success(
				'Workflow metrics chart created and saved.',
				array(
					'html'          => $html,
					'attachment_id' => $attachment_id,
				)
			);
		}

		return $this->success(
			'Workflow metrics chart created.',
			array( 'html' => $html )
		);
	}

	/**
	 * Generate a doughnut chart showing completed vs failed steps.
	 */
	private function generatePerformanceChart( array $results ): array {
		$data = array(
			'labels'   => array( 'Completed', 'Failed' ),
			'datasets' => array(
				array(
					'label'           => 'Steps',
					'data'            => array(
						$results['steps_completed'] ?? 0,
						$results['steps_failed'] ?? 0,
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
					'legend' => array( 'position' => 'bottom' ),
				),
			),
		);
	}

	/**
	 * Generate a pie chart showing completion rate percentage.
	 */
	private function generateCompletionChart( array $results ): array {
		$metrics    = $results['metrics'] ?? array();
		$total      = $metrics['steps_executed'] ?? 0;
		$completed  = $results['steps_completed'] ?? 0;
		$rate       = $total > 0 ? \round( ( $completed / $total ) * 100, 1 ) : 0;

		$data = array(
			'labels'   => array( 'Completion Rate', 'Remaining' ),
			'datasets' => array(
				array(
					'label'           => 'Percentage',
					'data'            => array( $rate, 100 - $rate ),
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
					'legend' => array( 'position' => 'bottom' ),
				),
			),
		);
	}

	/**
	 * Generate a bar chart showing per-step execution times.
	 */
	private function generateTimingChart( array $results ): array {
		$steps     = $results['step_results'] ?? array();
		$labels    = array();
		$durations = array();
		$colors    = array();

		foreach ( $steps as $step ) {
			if ( isset( $step['duration'] ) && $step['duration'] > 0 ) {
				$labels[]    = $step['task'] ?? ( 'Step ' . ( $step['step'] ?? '?' ) );
				$durations[] = $step['duration'];
				$colors[]    = ( 'completed' === ( $step['status'] ?? '' ) ) ? '#10b981' : '#ef4444';
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
					'legend' => array( 'display' => false ),
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
	 * Wrap chart configurations in a complete HTML document with Chart.js.
	 */
	private function generateChartHtml( array $charts ): string {
		$chart_js_url = \esc_url( \WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js' );

		$html = '<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Workflow Metrics Dashboard</title>
	<script src="' . $chart_js_url . '"></script>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f9fafb; }
		.dashboard { max-width: 1200px; margin: 0 auto; }
		.chart-container { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
		canvas { max-height: 400px; }
		h1 { color: #111827; margin-bottom: 30px; }
	</style>
</head>
<body>
	<div class="dashboard">
		<h1>Workflow Execution Metrics</h1>';

		foreach ( $charts as $name => $config ) {
			$canvas_id = 'chart-' . \sanitize_key( $name );
			$html     .= \sprintf(
				'<div class="chart-container"><canvas id="%s"></canvas></div>',
				\esc_attr( $canvas_id )
			);
		}

		$html .= '<script>';

		foreach ( $charts as $name => $config ) {
			$canvas_id = 'chart-' . \sanitize_key( $name );
			$html     .= \sprintf(
				'new Chart(document.getElementById("%s"), %s);',
				\esc_js( $canvas_id ),
				\wp_json_encode( $config )
			);
		}

		$html .= '</script>
	</div>
</body>
</html>';

		return $html;
	}

	/**
	 * Save the chart HTML as a WordPress attachment.
	 *
	 * @return int|false Attachment ID or false on failure.
	 */
	private function saveAsAttachment( string $html, string $filename ) {
		$upload_dir = \wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename . '-' . \time() . '.html';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === \file_put_contents( $file_path, $html ) ) {
			return false;
		}

		$attachment    = array(
			'post_mime_type' => 'text/html',
			'post_title'     => \sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = \wp_insert_attachment( $attachment, $file_path );

		if ( \is_wp_error( $attachment_id ) ) {
			return false;
		}

		return $attachment_id;
	}
}
