<?php
/**
 * Elementor widget for displaying performance trends chart.
 *
 * Visualizes historical performance data to help AI assistants identify
 * performance degradation or improvements over time.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget definition for the performance trends chart.
 */
class WP_MCP_AI_Elementor_Performance_Trends_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_performance_trends';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Performance Trends', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-graph';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'performance', 'trends', 'chart', 'graph', 'monitoring', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		return array( 'chartjs' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Trends Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Performance Trends', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'component',
			array(
				'label'       => __( 'Component', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'rest_api'      => __( 'REST API', 'wp-mcp-ai' ),
					'chat_ui'       => __( 'Chat UI', 'wp-mcp-ai' ),
					'mcp_core'      => __( 'MCP Core', 'wp-mcp-ai' ),
					'elementor'     => __( 'Elementor', 'wp-mcp-ai' ),
					'cpt_ai_peer'   => __( 'CPT: AI Peer', 'wp-mcp-ai' ),
					'cpt_assistant' => __( 'CPT: Assistant', 'wp-mcp-ai' ),
				),
				'default'     => 'rest_api',
				'label_block' => true,
			)
		);

		$this->add_control(
			'time_period',
			array(
				'label'   => __( 'Time Period', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'-24 hours' => __( 'Last 24 Hours', 'wp-mcp-ai' ),
					'-7 days'   => __( 'Last 7 Days', 'wp-mcp-ai' ),
					'-30 days'  => __( 'Last 30 Days', 'wp-mcp-ai' ),
					'-90 days'  => __( 'Last 90 Days', 'wp-mcp-ai' ),
				),
				'default' => '-7 days',
			)
		);

		$this->add_control(
			'chart_height',
			array(
				'label'   => __( 'Chart Height (px)', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 300,
				'min'     => 200,
				'max'     => 800,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_trends',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-performance-trends',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-performance-trends__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-performance-trends__summary',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="wp-mcp-ai-performance-trends">';
			echo '<p>' . esc_html__( 'You do not have permission to view performance trends.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title        = isset( $settings['title'] ) ? $settings['title'] : '';
		$component    = isset( $settings['component'] ) ? $settings['component'] : 'rest_api';
		$time_period  = isset( $settings['time_period'] ) ? $settings['time_period'] : '-7 days';
		$chart_height = isset( $settings['chart_height'] ) ? absint( $settings['chart_height'] ) : 300;

		// Get trend data.
		$trends = $this->get_trend_data( $component, $time_period );

		echo '<div class="wp-mcp-ai-performance-trends">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-performance-trends__title">' . esc_html( $title ) . '</h3>';
		}

		// Trend summary.
		if ( isset( $trends['trend'] ) ) {
			$trend_class = 'trend-' . sanitize_html_class( $trends['trend'] );
			$trend_icon  = $this->get_trend_icon( $trends['trend'] );
			$trend_text  = $this->get_trend_text( $trends['trend'] );

			echo '<div class="wp-mcp-ai-performance-trends__summary ' . esc_attr( $trend_class ) . '">';
			echo '<span class="dashicons dashicons-' . esc_attr( $trend_icon ) . '"></span>';
			echo '<span>' . esc_html( $trend_text ) . '</span>';
			echo '</div>';
		}

		// Chart canvas - use unique ID per widget instance to avoid conflicts.
		$chart_id = 'wp-mcp-ai-trends-chart-' . $this->get_id();
		echo '<div class="wp-mcp-ai-performance-trends__chart-container">';
		echo '<canvas id="' . esc_attr( $chart_id ) . '" height="' . esc_attr( $chart_height ) . '"></canvas>';
		echo '</div>';

		echo '</div>';

		$this->enqueue_chart_script( $trends, $chart_height, $chart_id );
	}

	/**
	 * Get trend data from the CCT.
	 *
	 * @param string $component   Component.
	 * @param string $time_period Time period.
	 * @return array Trend data.
	 */
	protected function get_trend_data( $component, $time_period ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array( 'trend' => 'no_data' );
		}

		return WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );
	}

	/**
	 * Get trend icon based on trend status.
	 *
	 * @param string $trend Trend status.
	 * @return string Dashicon name.
	 */
	protected function get_trend_icon( $trend ) {
		switch ( $trend ) {
			case 'improving':
				return 'arrow-down-alt';
			case 'degrading':
				return 'arrow-up-alt';
			case 'stable':
				return 'minus';
			default:
				return 'info';
		}
	}

	/**
	 * Get trend text based on trend status.
	 *
	 * @param string $trend Trend status.
	 * @return string Trend description.
	 */
	protected function get_trend_text( $trend ) {
		switch ( $trend ) {
			case 'improving':
				return __( 'Performance is improving over time', 'wp-mcp-ai' );
			case 'degrading':
				return __( 'Performance is degrading - attention needed', 'wp-mcp-ai' );
			case 'stable':
				return __( 'Performance is stable', 'wp-mcp-ai' );
			default:
				return __( 'No trend data available', 'wp-mcp-ai' );
		}
	}

	/**
	 * Render the chart script.
	 *
	 * @param array  $trends       Trend data.
	 * @param int    $chart_height Chart height.
	 * @param string $chart_id     Unique chart canvas ID.
	 */
	protected function enqueue_chart_script( $trends, $chart_height, $chart_id ) {
		// Chart.js is loaded via get_script_depends().
		?>
		<script>
		(function() {
			// Global registry for chart instances to enable proper cleanup.
			if (typeof window.wpMcpAiCharts === 'undefined') {
				window.wpMcpAiCharts = {};
			}

			// Wait for Chart to be available (in case of async loading).
			function initChart() {
				if (typeof Chart === 'undefined') {
					// Chart.js not loaded yet, wait a bit.
					setTimeout(initChart, 100);
					return;
				}

				// Destroy existing chart instance if it exists (prevents "canvas already in use" error).
				var chartId = '<?php echo esc_js( $chart_id ); ?>';
				if (window.wpMcpAiCharts[chartId]) {
					window.wpMcpAiCharts[chartId].destroy();
					delete window.wpMcpAiCharts[chartId];
				}

				var ctx = document.getElementById(chartId);
				if (!ctx) return;

				// Sample data - in real implementation, this would come from $trends.
				var chartData = {
					labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'],
					datasets: [{
						label: '<?php echo esc_js( __( 'Response Time (ms)', 'wp-mcp-ai' ) ); ?>',
						data: [250, 300, 280, 320, 290, 310, 275],
						borderColor: 'rgb(75, 192, 192)',
						backgroundColor: 'rgba(75, 192, 192, 0.2)',
						tension: 0.4
					}, {
						label: '<?php echo esc_js( __( 'Memory Usage (MB)', 'wp-mcp-ai' ) ); ?>',
						data: [64, 68, 66, 72, 65, 70, 63],
						borderColor: 'rgb(255, 99, 132)',
						backgroundColor: 'rgba(255, 99, 132, 0.2)',
						tension: 0.4,
						yAxisID: 'y1'
					}]
				};

				// Create and store the chart instance.
				window.wpMcpAiCharts[chartId] = new Chart(ctx, {
					type: 'line',
					data: chartData,
					options: {
						responsive: true,
						maintainAspectRatio: false,
						interaction: {
							mode: 'index',
							intersect: false,
						},
						scales: {
							y: {
								type: 'linear',
								display: true,
								position: 'left',
								title: {
									display: true,
									text: '<?php echo esc_js( __( 'Response Time (ms)', 'wp-mcp-ai' ) ); ?>'
								}
							},
							y1: {
								type: 'linear',
								display: true,
								position: 'right',
								title: {
									display: true,
									text: '<?php echo esc_js( __( 'Memory (MB)', 'wp-mcp-ai' ) ); ?>'
								},
								grid: {
									drawOnChartArea: false,
								}
							}
						}
					}
				});
			}

			// Initialize immediately if Chart is available, otherwise wait.
			if (typeof Chart !== 'undefined') {
				initChart();
			} else {
				// In Elementor editor, wait for DOM ready.
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initChart);
				} else {
					initChart();
				}
			}
		})();
		</script>
		<style>
		.wp-mcp-ai-performance-trends {
			padding: 20px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-performance-trends__title {
			margin-top: 0;
			margin-bottom: 15px;
		}
		.wp-mcp-ai-performance-trends__summary {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 10px;
			margin-bottom: 20px;
			border-radius: 4px;
			font-weight: 500;
		}
		.trend-improving {
			background: #d4edda;
			color: #155724;
		}
		.trend-degrading {
			background: #f8d7da;
			color: #721c24;
		}
		.trend-stable {
			background: #d1ecf1;
			color: #0c5460;
		}
		.trend-no_data {
			background: #f9f9f9;
			color: #666;
		}
		.wp-mcp-ai-performance-trends__chart-container {
			position: relative;
			width: 100%;
		}
		</style>
		<?php
	}
}
