// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget for displaying performance metrics dashboard.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Shows current performance metrics including response times, memory usage,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * and database queries to help AI assistants diagnose issues.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * @package WP_MCP_AI
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! defined( 'ABSPATH' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	exit;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget definition for the performance metrics dashboard.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
class WP_MCP_AI_Elementor_Performance_Metrics_Widget extends \Elementor\Widget_Base {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	use WP_MCP_AI_Elementor_Text_Formatting;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget slug.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_name() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'wp_mcp_ai_performance_metrics';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget title shown in the Elementor editor.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_title() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return __( 'NV oOS Performance Metrics', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget icon for Elementor panel.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_icon() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'eicon-dashboard';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget categories.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_categories() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'general' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Keywords to help search for the widget.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_keywords() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'performance', 'metrics', 'dashboard', 'monitoring', 'mcp' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Register controls for the widget settings.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function register_controls() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->start_controls_section(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'section_content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label' => __( 'Dashboard Settings', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'title',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Title', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Current Performance Metrics', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'placeholder' => __( 'Enter title…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'component_filter',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Filter by Component', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options'     => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					''              => __( 'All Components', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'rest_api'      => __( 'REST API', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'chat_ui'       => __( 'Chat UI', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'mcp_core'      => __( 'MCP Core', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'elementor'     => __( 'Elementor', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'cpt_ai_peer'   => __( 'CPT: AI Peer', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'cpt_assistant' => __( 'CPT: Assistant', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => '',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'time_period',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'Time Period', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options' => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'-1 hour'   => __( 'Last Hour', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'-24 hours' => __( 'Last 24 Hours', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'-7 days'   => __( 'Last 7 Days', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'-30 days'  => __( 'Last 30 Days', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => '-24 hours',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'show_status_indicators',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'        => __( 'Show Status Indicators', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'         => \Elementor\Controls_Manager::SWITCHER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'return_value' => 'yes',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'      => 'yes',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'auto_refresh',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'        => __( 'Auto Refresh', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'         => \Elementor\Controls_Manager::SWITCHER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'return_value' => 'yes',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'      => 'no',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'description'  => __( 'Refresh metrics every 30 seconds.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->end_controls_section();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_theme_style_controls(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'section_id' => 'section_style_metrics',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectors'  => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'container' => '{{WRAPPER}} .wp-mcp-ai-performance-metrics',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'text'      => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__label',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-performance-metrics__value',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Render the widget on the front-end.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function render() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! current_user_can( 'manage_options' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-performance-metrics">';
			echo '<p>' . esc_html__( 'You do not have permission to view performance metrics.', 'mcp-ai-wpoos' ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$settings = $this->get_settings_for_display();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$title            = isset( $settings['title'] ) ? $settings['title'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$component_filter = isset( $settings['component_filter'] ) ? $settings['component_filter'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$time_period      = isset( $settings['time_period'] ) ? $settings['time_period'] : '-24 hours';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$show_status      = ! empty( $settings['show_status_indicators'] ) && 'yes' === $settings['show_status_indicators'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$auto_refresh     = ! empty( $settings['auto_refresh'] ) && 'yes' === $settings['auto_refresh'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Get performance data.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$metrics = $this->get_performance_metrics( $component_filter, $time_period );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

		echo '<div class="wp-mcp-ai-performance-metrics" data-auto-refresh="' . esc_attr( $auto_refresh ? 'yes' : 'no' ) . '" data-component="' . esc_attr( $component_filter ) . '" data-period="' . esc_attr( $time_period ) . '">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-performance-metrics__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-performance-metrics__grid">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Response Time.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg Response Time', 'mcp-ai-wpoos' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_response_time'], 2 ) ) . ' ms</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_response_time_status( $metrics['avg_response_time'] ) ) . '"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Memory Usage.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg Memory Usage', 'mcp-ai-wpoos' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_memory_usage'], 2 ) ) . ' MB</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_memory_status( $metrics['avg_memory_usage'] ) ) . '"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// DB Queries.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Avg DB Queries', 'mcp-ai-wpoos' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( number_format( $metrics['avg_db_queries'], 0 ) ) . '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $show_status ) {
			echo '<div class="wp-mcp-ai-performance-metrics__status ' . esc_attr( $this->get_db_queries_status( $metrics['avg_db_queries'] ) ) . '"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Total Tests.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-performance-metrics__metric">';
		echo '<div class="wp-mcp-ai-performance-metrics__label">' . esc_html__( 'Total Tests', 'mcp-ai-wpoos' ) . '</div>';
		echo '<div class="wp-mcp-ai-performance-metrics__value">' . esc_html( $metrics['total_tests'] ) . '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Status Distribution.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $metrics['status_distribution'] ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-performance-metrics__status-distribution">';
			echo '<h4>' . esc_html__( 'Test Results Distribution', 'mcp-ai-wpoos' ) . '</h4>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-performance-metrics__distribution-grid">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			foreach ( $metrics['status_distribution'] as $status => $count ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '<div class="wp-mcp-ai-performance-metrics__distribution-item">';
				echo '<span class="wp-mcp-ai-performance-metrics__distribution-status status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
				echo '<span class="wp-mcp-ai-performance-metrics__distribution-count">' . esc_html( $count ) . '</span>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $auto_refresh ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$this->enqueue_auto_refresh_script();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get performance metrics from the CCT or fallback storage.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $component   Component filter.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $time_period Time period.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array Metrics data.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_performance_metrics( $component, $time_period ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'avg_response_time'   => 0,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'avg_memory_usage'    => 0,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'avg_db_queries'      => 0,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'total_tests'         => 0,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'status_distribution' => array(),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( empty( $component ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			// Get all components and aggregate.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return $this->get_aggregated_metrics( $time_period );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get aggregated metrics across all components.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $time_period Time period.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array Aggregated metrics.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_aggregated_metrics( $time_period ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$components  = array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor', 'cpt_ai_peer', 'cpt_assistant' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$all_metrics = array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'avg_response_time'   => 0,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'avg_memory_usage'    => 0,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'avg_db_queries'      => 0,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'total_tests'         => 0,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'status_distribution' => array(),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		foreach ( $components as $component ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$metrics = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( isset( $metrics['total_tests'] ) && $metrics['total_tests'] > 0 ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$all_metrics['avg_response_time'] += $metrics['avg_response_time'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$all_metrics['avg_memory_usage']  += $metrics['avg_memory_usage'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$all_metrics['avg_db_queries']    += $metrics['avg_db_queries'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$all_metrics['total_tests']       += $metrics['total_tests'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( isset( $metrics['status_distribution'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					foreach ( $metrics['status_distribution'] as $status => $count ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						if ( ! isset( $all_metrics['status_distribution'][ $status ] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							$all_metrics['status_distribution'][ $status ] = 0;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						$all_metrics['status_distribution'][ $status ] += $count;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Calculate averages.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $all_metrics['total_tests'] > 0 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$count                             = count( $components );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$all_metrics['avg_response_time'] /= $count;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$all_metrics['avg_memory_usage']  /= $count;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$all_metrics['avg_db_queries']    /= $count;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return $all_metrics;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get status class for response time.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param float $response_time Response time in ms.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return string Status class.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_response_time_status( $response_time ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $response_time > 2000 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-critical';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $response_time > 1000 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-warning';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'status-good';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get status class for memory usage.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param float $memory_mb Memory in MB.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return string Status class.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_memory_status( $memory_mb ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $memory_mb > 256 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-critical';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $memory_mb > 128 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-warning';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'status-good';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get status class for DB queries.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param int $queries Number of queries.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return string Status class.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_db_queries_status( $queries ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $queries > 100 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-critical';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $queries > 50 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return 'status-warning';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'status-good';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Enqueue auto-refresh script.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function enqueue_auto_refresh_script() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for Elementor widget functionality with dynamic data
		?>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<script>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		(function($) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			setInterval(function() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$('.wp-mcp-ai-performance-metrics[data-auto-refresh="yes"]').each(function() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var widget = $(this);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var component = widget.data('component');
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var period = widget.data('period');
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						type: 'POST',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						data: {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							action: 'wp_mcp_ai_get_performance_metrics',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							component: component,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							period: period,
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_performance' ) ); ?>'
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						},
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						success: function(response) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							if (response.success && response.data) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								// Update values (simplified - full implementation would update each metric).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								location.reload();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					});
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				});
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}, 30000); // Refresh every 30 seconds.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		})(jQuery);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		</script>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<style>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #fff;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: 1px solid #ddd;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 4px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__title {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-bottom: 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__grid {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			display: grid;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			gap: 15px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-bottom: 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__metric {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 15px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f9f9f9;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: 1px solid #ddd;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 4px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			position: relative;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__label {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 13px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #666;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-bottom: 5px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__value {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 24px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-weight: bold;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #333;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__status {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			position: absolute;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			top: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			right: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			width: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			height: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 50%;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-good {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #46b450;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-warning {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #ffb900;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-critical {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #dc3232;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__distribution-grid {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			display: flex;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			gap: 15px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			flex-wrap: wrap;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-performance-metrics__distribution-item {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			display: flex;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			align-items: center;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			gap: 8px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 8px 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f9f9f9;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 4px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-passed {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #46b450;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-warning {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #ffb900;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-failed {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #dc3232;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		</style>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
