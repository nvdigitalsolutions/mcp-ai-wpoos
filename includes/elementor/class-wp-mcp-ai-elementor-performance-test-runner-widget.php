// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget for running performance tests.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Allows administrators to execute stress, security, and speed tests
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * directly from the Elementor interface.
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
 * Elementor widget definition for the performance test runner.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
class WP_MCP_AI_Elementor_Performance_Test_Runner_Widget extends \Elementor\Widget_Base {
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
		return 'wp_mcp_ai_performance_test_runner';
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
		return __( 'NV oOS Performance Test Runner', 'mcp-ai-wpoos' );
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
		return 'eicon-play';
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
		return array( 'performance', 'test', 'benchmark', 'stress', 'security', 'mcp' );
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
				'label' => __( 'Test Runner Settings', 'mcp-ai-wpoos' ),
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
				'default'     => __( 'Performance Test Runner', 'mcp-ai-wpoos' ),
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
			'description',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Description', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Run comprehensive performance tests to help AI assistants diagnose and fix issues.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'placeholder' => __( 'Enter description…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'rows'        => 3,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'enabled_tests',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Enabled Test Types', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::SELECT2,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'multiple'    => true,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options'     => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'stress'       => __( 'Stress Tests', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'security'     => __( 'Security Tests', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'speed'        => __( 'Speed Benchmarks', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'optimization' => __( 'Optimization Comparison', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => array( 'stress', 'security', 'speed', 'optimization' ),
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
			'show_results',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'        => __( 'Show Results Immediately', 'mcp-ai-wpoos' ),
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
		$this->end_controls_section();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_theme_style_controls(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'section_id' => 'section_style_test_runner',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectors'  => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'container' => '{{WRAPPER}} .wp-mcp-ai-test-runner',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-test-runner__title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'text'      => '{{WRAPPER}} .wp-mcp-ai-test-runner__description',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'link'      => '{{WRAPPER}} .wp-mcp-ai-test-runner__button',
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
			echo '<div class="wp-mcp-ai-test-runner">';
			echo '<p class="wp-mcp-ai-test-runner__notice">' . esc_html__( 'You do not have permission to run performance tests.', 'mcp-ai-wpoos' ) . '</p>';
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
		$title         = isset( $settings['title'] ) ? $settings['title'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$description   = isset( $settings['description'] ) ? $settings['description'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$enabled_tests = isset( $settings['enabled_tests'] ) ? $settings['enabled_tests'] : array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$show_results  = ! empty( $settings['show_results'] ) && 'yes' === $settings['show_results'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-test-runner">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-test-runner__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $description ) ) {
			echo '<p class="wp-mcp-ai-test-runner__description">' . esc_html( $description ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-test-runner__controls">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$test_labels = array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'stress'       => __( 'Stress Tests', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'security'     => __( 'Security Tests', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'speed'        => __( 'Speed Benchmarks', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'optimization' => __( 'Optimization Comparison', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		foreach ( $enabled_tests as $test_type ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( isset( $test_labels[ $test_type ] ) ) {
				echo '<button class="wp-mcp-ai-test-runner__button" data-test-type="' . esc_attr( $test_type ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '<span class="dashicons dashicons-play"></span> ';
				echo esc_html( $test_labels[ $test_type ] );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '</button>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $show_results ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-test-runner__results" style="display:none;">';
			echo '<h4>' . esc_html__( 'Test Results', 'mcp-ai-wpoos' ) . '</h4>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-test-runner__results-content"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-test-runner__status" style="display:none;"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->enqueue_test_runner_script();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Enqueue the test runner JavaScript.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function enqueue_test_runner_script() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for Elementor widget functionality with dynamic data
		?>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<script>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		(function($) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			// Helper function to escape HTML and prevent XSS.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			function escapeHtml(text) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				// Handle null, undefined, and objects.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if (text === null || text === undefined) return '';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if (typeof text === 'object') return '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				var map = {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'&': '&amp;',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'<': '&lt;',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'>': '&gt;',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'"': '&quot;',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					"'": '&#039;'
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				};
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$(document).ready(function() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$('.wp-mcp-ai-test-runner__button').on('click', function(e) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					e.preventDefault();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var button = $(this);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var testType = button.data('test-type');
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var runner = button.closest('.wp-mcp-ai-test-runner');
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var statusDiv = runner.find('.wp-mcp-ai-test-runner__status');
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var resultsDiv = runner.find('.wp-mcp-ai-test-runner__results');
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					button.prop('disabled', true);
					statusDiv.show().html('<p><?php echo esc_js( __( 'Running test...', 'mcp-ai-wpoos' ) ); ?></p>');
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						type: 'POST',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						data: {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							action: 'wp_mcp_ai_run_performance_test',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							test_type: testType,
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_performance' ) ); ?>'
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						},
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						success: function(response) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							button.prop('disabled', false);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							if (response.success) {
								statusDiv.html('<p class="wp-mcp-ai-success"><?php echo esc_js( __( 'Test completed successfully!', 'mcp-ai-wpoos' ) ); ?></p>');
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								if (response.data && resultsDiv.length) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									resultsDiv.show();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									resultsDiv.find('.wp-mcp-ai-test-runner__results-content').html(
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										'<pre>' + JSON.stringify(response.data, null, 2) + '</pre>'
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								// Handle both string and object error responses.
								var errorMessage = '<?php echo esc_js( __( 'Unknown error', 'mcp-ai-wpoos' ) ); ?>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								if (response.data) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									if (typeof response.data === 'object') {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										// Extract message from object.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										errorMessage = response.data.message || errorMessage;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										// Build detailed error HTML with proper escaping.
										var errorHtml = '<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'mcp-ai-wpoos' ) ); ?> ' + escapeHtml(errorMessage) + '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										// Add additional details if available.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										if (response.data.details) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
											errorHtml += '<p class="wp-mcp-ai-error-details">' + escapeHtml(response.data.details) + '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										// Add test output if available (contains the actual failure details).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										if (response.data.output) {
											var outputLabel = '<?php echo esc_js( __( 'Test Output (Click to expand)', 'mcp-ai-wpoos' ) ); ?>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
											errorHtml += '<details class="wp-mcp-ai-test-output">' +
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
												'<summary><strong>' + outputLabel + '</strong></summary>' +
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
												'<pre>' + escapeHtml(response.data.output) + '</pre>' +
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
												'</details>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										if (response.data.cli_command) {
											errorHtml += '<p class="wp-mcp-ai-cli-command"><strong><?php echo esc_js( __( 'CLI Command:', 'mcp-ai-wpoos' ) ); ?></strong> <code>' + escapeHtml(response.data.cli_command) + '</code></p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										if (response.data.setup_command) {
											errorHtml += '<p class="wp-mcp-ai-setup-command"><strong><?php echo esc_js( __( 'Setup Command:', 'mcp-ai-wpoos' ) ); ?></strong> <code>' + escapeHtml(response.data.setup_command) + '</code></p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										statusDiv.html(errorHtml);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
										// Handle string error.
										statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'mcp-ai-wpoos' ) ); ?> ' + escapeHtml(response.data) + '</p>');
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
									}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								} else {
									statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'mcp-ai-wpoos' ) ); ?> ' + escapeHtml(errorMessage) + '</p>');
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						},
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						error: function() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
							button.prop('disabled', false);
							statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'An error occurred while running the test.', 'mcp-ai-wpoos' ) ); ?></p>');
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					});
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				});
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			});
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		})(jQuery);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		</script>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<style>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner {
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
		.wp-mcp-ai-test-runner__title {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__controls {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			display: flex;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			flex-wrap: wrap;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			gap: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin: 15px 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__button {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 10px 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #2271b1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #fff;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: none;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 4px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			cursor: pointer;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__button:hover {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #135e96;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__button:disabled {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			opacity: 0.5;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			cursor: not-allowed;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__results {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 15px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f9f9f9;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: 1px solid #ddd;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 4px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-runner__results pre {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			overflow-x: auto;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-success {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #46b450;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-error {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #dc3232;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-error-details {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #646970;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 14px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-cli-command,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-setup-command {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f0f0f1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-left: 3px solid #2271b1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 13px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-cli-command code,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-setup-command code {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #fff;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 2px 6px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 3px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-family: Consolas, Monaco, monospace;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			display: inline-block;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 5px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-output {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f0f0f1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: 1px solid #ccd0d4;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 3px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-output summary {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 10px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			cursor: pointer;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 13px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			user-select: none;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-output summary:hover {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #e5e5e5;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-output pre {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin: 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 15px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #23282d;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #f0f0f1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 0 0 3px 3px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			overflow-x: auto;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-family: Consolas, Monaco, monospace;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			line-height: 1.6;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			max-height: 400px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			overflow-y: auto;
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
