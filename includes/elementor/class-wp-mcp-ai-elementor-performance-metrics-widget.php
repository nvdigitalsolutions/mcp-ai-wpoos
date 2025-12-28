<?php
/**
 * Elementor widget for displaying performance metrics dashboard.
 *
 * Shows current performance metrics including response times, memory usage,
 * and database queries to help AI assistants diagnose issues.
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
 * Elementor widget definition for the performance metrics dashboard.
 */
class WP_MCP_AI_Elementor_Performance_Metrics_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_performance_metrics';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Performance Metrics', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-dashboard';
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
		return array( 'performance', 'metrics', 'dashboard', 'monitoring', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Dashboard Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Current Performance Metrics', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'component_filter',
			array(
				'label'       => __( 'Filter by Component', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					''              => __( 'All Components', 'wp-mcp-ai' ),
					'rest_api'      => __( 'REST API', 'wp-mcp-ai' ),
					'chat_ui'       => __( 'Chat UI', 'wp-mcp-ai' ),
					'mcp_core'      => __( 'MCP Core', 'wp-mcp-ai' ),
					'elementor'     => __( 'Elementor', 'wp-mcp-ai' ),
					'cpt_ai_peer'   => __( 'CPT: AI Peer', 'wp-mcp-ai' ),
					'cpt_assistant' => __( 'CPT: Assistant', 'wp-mcp-ai' ),
				),
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'time_period',
			array(
				'label'   => __( 'Time Period', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'-1 hour'   => __( 'Last Hour', 'wp-mcp-ai' ),
					'-24 hours' => __( 'Last 24 Hours', 'wp-mcp-ai' ),
					'-7 days'   => __( 'Last 7 Days', 'wp-mcp-ai' ),
					'-30 days'  => __( 'Last 30 Days', 'wp-mcp-ai' ),
				),
				'default' => '-24 hours',
			)
		);

		$this->add_control(
			'show_status_indicators',
			array(
				'label'        => __( 'Show Status Indicators', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'auto_refresh',
			array(
				'label'        => __( 'Auto Refresh', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'Refresh metrics every 30 seconds.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_metrics',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-performance-metrics',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__label',
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__value',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="wp-mcp-ai-performance-metrics">';
			echo '<p>' . esc_html__( 'You do not have permission to view performance metrics.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title            = isset( $settings['title'] ) ? $settings['title'] : '';
		$component_filter = isset( $settings['component_filter'] ) ? $settings['component_filter'] : '';
		$time_period      = isset( $settings['time_period'] ) ? $settings['time_period'] : '-24 hours';
		$show_status      = ! empty( $settings['show_status_indicators'] ) && 'yes' === $settings['show_status_indicators'];
		$auto_refresh     = ! empty( $settings['auto_refresh'] ) && 'yes' === $settings['auto_refresh'];

		// Get performance data.
		$metrics = $this->get_performance_metrics( $component_filter, $time_period );

		echo '<div class="wp-mcp-ai-performance-metrics" data-auto-refresh="' . esc_attr( $auto_refresh ? 'yes' : 'no' ) . '" data-component="' . esc_attr( $component_filter ) . '" data-period="' . esc_attr( $time_period ) . '">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-performance-metrics__title">' . esc_html( $title ) . '</h3>';
		}

		echo '<div class="wp-mcp-ai-performance-metrics__grid">';

		// Response Time.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg Response Time', 'wp-mcp-ai' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_response_time'], 2 ) ) . ' ms</div>';
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_response_time_status( $metrics['avg_response_time'] ) ) . '"></div>';
		}
		echo '</div>';

		// Memory Usage.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg Memory Usage', 'wp-mcp-ai' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_memory_usage'], 2 ) ) . ' MB</div>';
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_memory_status( $metrics['avg_memory_usage'] ) ) . '"></div>';
		}
		echo '</div>';

		// DB Queries.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg DB Queries', 'wp-mcp-ai' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_db_queries'], 0 ) ) . '</div>';
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_db_queries_status( $metrics['avg_db_queries'] ) ) . '"></div>';
		}
		echo '</div>';

		// Total Tests.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Total Tests', 'wp-mcp-ai' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( $metrics['total_tests'] ) . '</div>';
		echo '</div>';

		echo '</div>';

		// Status Distribution.
		if ( ! empty( $metrics['status_distribution'] ) ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status-distribution">';
			echo '<h4>' . esc_html__( 'Test Results Distribution', 'wp-mcp-ai' ) . '</h4>';
			echo '<div class="wp-mcp-ai-performance-metrics__distribution-grid">';
			foreach ( $metrics['status_distribution'] as $status => $count ) {
				echo '<div class="wp-mcp-ai-performance-metrics__distribution-item">';
				echo '<span class="wp-mcp-ai-performance-metrics__distribution-status status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
				echo '<span class="wp-mcp-ai-performance-metrics__distribution-count">' . esc_html( $count ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		if ( $auto_refresh ) {
			$this->enqueue_auto_refresh_script();
		}
	}

	/**
	 * Get performance metrics from the CCT or fallback storage.
	 *
	 * @param string $component   Component filter.
	 * @param string $time_period Time period.
	 * @return array Metrics data.
	 */
	protected function get_performance_metrics( $component, $time_period ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array(
				'avg_response_time'   => 0,
				'avg_memory_usage'    => 0,
				'avg_db_queries'      => 0,
				'total_tests'         => 0,
				'status_distribution' => array(),
			);
		}

		if ( empty( $component ) ) {
			// Get all components and aggregate.
			return $this->get_aggregated_metrics( $time_period );
		}

		return WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );
	}

	/**
	 * Get aggregated metrics across all components.
	 *
	 * @param string $time_period Time period.
	 * @return array Aggregated metrics.
	 */
	protected function get_aggregated_metrics( $time_period ) {
		$components  = array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor', 'cpt_ai_peer', 'cpt_assistant' );
		$all_metrics = array(
			'avg_response_time'   => 0,
			'avg_memory_usage'    => 0,
			'avg_db_queries'      => 0,
			'total_tests'         => 0,
			'status_distribution' => array(),
		);

		foreach ( $components as $component ) {
			$metrics = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );

			if ( isset( $metrics['total_tests'] ) && $metrics['total_tests'] > 0 ) {
				$all_metrics['avg_response_time'] += $metrics['avg_response_time'];
				$all_metrics['avg_memory_usage']  += $metrics['avg_memory_usage'];
				$all_metrics['avg_db_queries']    += $metrics['avg_db_queries'];
				$all_metrics['total_tests']       += $metrics['total_tests'];

				if ( isset( $metrics['status_distribution'] ) ) {
					foreach ( $metrics['status_distribution'] as $status => $count ) {
						if ( ! isset( $all_metrics['status_distribution'][ $status ] ) ) {
							$all_metrics['status_distribution'][ $status ] = 0;
						}
						$all_metrics['status_distribution'][ $status ] += $count;
					}
				}
			}
		}

		// Calculate averages.
		if ( $all_metrics['total_tests'] > 0 ) {
			$count                             = count( $components );
			$all_metrics['avg_response_time'] /= $count;
			$all_metrics['avg_memory_usage']  /= $count;
			$all_metrics['avg_db_queries']    /= $count;
		}

		return $all_metrics;
	}

	/**
	 * Get status class for response time.
	 *
	 * @param float $response_time Response time in ms.
	 * @return string Status class.
	 */
	protected function get_response_time_status( $response_time ) {
		if ( $response_time > 2000 ) {
			return 'status-critical';
		}
		if ( $response_time > 1000 ) {
			return 'status-warning';
		}
		return 'status-good';
	}

	/**
	 * Get status class for memory usage.
	 *
	 * @param float $memory_mb Memory in MB.
	 * @return string Status class.
	 */
	protected function get_memory_status( $memory_mb ) {
		if ( $memory_mb > 256 ) {
			return 'status-critical';
		}
		if ( $memory_mb > 128 ) {
			return 'status-warning';
		}
		return 'status-good';
	}

	/**
	 * Get status class for DB queries.
	 *
	 * @param int $queries Number of queries.
	 * @return string Status class.
	 */
	protected function get_db_queries_status( $queries ) {
		if ( $queries > 100 ) {
			return 'status-critical';
		}
		if ( $queries > 50 ) {
			return 'status-warning';
		}
		return 'status-good';
	}

	/**
	 * Enqueue auto-refresh script.
	 */
	protected function enqueue_auto_refresh_script() {
		?>
		<script>
		(function($) {
			setInterval(function() {
				$('.wp-mcp-ai-performance-metrics[data-auto-refresh="yes"]').each(function() {
					var widget = $(this);
					var component = widget.data('component');
					var period = widget.data('period');

					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_get_performance_metrics',
							component: component,
							period: period,
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_performance' ) ); ?>'
						},
						success: function(response) {
							if (response.success && response.data) {
								// Update values (simplified - full implementation would update each metric).
								location.reload();
							}
						}
					});
				});
			}, 30000); // Refresh every 30 seconds.
		})(jQuery);
		</script>
		<style>
		.wp-mcp-ai-performance-metrics {
			padding: 20px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-performance-metrics__title {
			margin-top: 0;
			margin-bottom: 20px;
		}
		.wp-mcp-ai-performance-metrics__grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 15px;
			margin-bottom: 20px;
		}
		.wp-mcp-ai-performance-metrics__metric {
			padding: 15px;
			background: #f9f9f9;
			border: 1px solid #ddd;
			border-radius: 4px;
			position: relative;
		}
		.wp-mcp-ai-performance-metrics__label {
			font-size: 13px;
			color: #666;
			margin-bottom: 5px;
		}
		.wp-mcp-ai-performance-metrics__value {
			font-size: 24px;
			font-weight: bold;
			color: #333;
		}
		.wp-mcp-ai-performance-metrics__status {
			position: absolute;
			top: 10px;
			right: 10px;
			width: 12px;
			height: 12px;
			border-radius: 50%;
		}
		.status-good {
			background: #46b450;
		}
		.status-warning {
			background: #ffb900;
		}
		.status-critical {
			background: #dc3232;
		}
		.wp-mcp-ai-performance-metrics__distribution-grid {
			display: flex;
			gap: 15px;
			flex-wrap: wrap;
		}
		.wp-mcp-ai-performance-metrics__distribution-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 8px 12px;
			background: #f9f9f9;
			border-radius: 4px;
		}
		.status-passed {
			color: #46b450;
		}
		.status-warning {
			color: #ffb900;
		}
		.status-failed {
			color: #dc3232;
		}
		</style>
		<?php
	}
}
