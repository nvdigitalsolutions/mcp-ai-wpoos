<?php
/**
 * Elementor widget for running performance tests.
 *
 * Allows administrators to execute stress, security, and speed tests
 * directly from the Elementor interface.
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
 * Elementor widget definition for the performance test runner.
 */
class WP_MCP_AI_Elementor_Performance_Test_Runner_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_performance_test_runner';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Performance Test Runner', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-play';
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
		return array( 'performance', 'test', 'benchmark', 'stress', 'security', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Test Runner Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Performance Test Runner', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => __( 'Description', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Run comprehensive performance tests to help AI assistants diagnose and fix issues.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter description…', 'wp-mcp-ai' ),
				'rows'        => 3,
			)
		);

		$this->add_control(
			'enabled_tests',
			array(
				'label'       => __( 'Enabled Test Types', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => array(
					'stress'       => __( 'Stress Tests', 'wp-mcp-ai' ),
					'security'     => __( 'Security Tests', 'wp-mcp-ai' ),
					'speed'        => __( 'Speed Benchmarks', 'wp-mcp-ai' ),
					'optimization' => __( 'Optimization Comparison', 'wp-mcp-ai' ),
				),
				'default'     => array( 'stress', 'security', 'speed', 'optimization' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'show_results',
			array(
				'label'        => __( 'Show Results Immediately', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_test_runner',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-test-runner',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-test-runner__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-test-runner__description',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-test-runner__button',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="wp-mcp-ai-test-runner">';
			echo '<p class="wp-mcp-ai-test-runner__notice">' . esc_html__( 'You do not have permission to run performance tests.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title         = isset( $settings['title'] ) ? $settings['title'] : '';
		$description   = isset( $settings['description'] ) ? $settings['description'] : '';
		$enabled_tests = isset( $settings['enabled_tests'] ) ? $settings['enabled_tests'] : array();
		$show_results  = ! empty( $settings['show_results'] ) && 'yes' === $settings['show_results'];

		echo '<div class="wp-mcp-ai-test-runner">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-test-runner__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			echo '<p class="wp-mcp-ai-test-runner__description">' . esc_html( $description ) . '</p>';
		}

		echo '<div class="wp-mcp-ai-test-runner__controls">';

		$test_labels = array(
			'stress'       => __( 'Stress Tests', 'wp-mcp-ai' ),
			'security'     => __( 'Security Tests', 'wp-mcp-ai' ),
			'speed'        => __( 'Speed Benchmarks', 'wp-mcp-ai' ),
			'optimization' => __( 'Optimization Comparison', 'wp-mcp-ai' ),
		);

		foreach ( $enabled_tests as $test_type ) {
			if ( isset( $test_labels[ $test_type ] ) ) {
				echo '<button class="wp-mcp-ai-test-runner__button" data-test-type="' . esc_attr( $test_type ) . '">';
				echo '<span class="dashicons dashicons-play"></span> ';
				echo esc_html( $test_labels[ $test_type ] );
				echo '</button>';
			}
		}

		echo '</div>';

		if ( $show_results ) {
			echo '<div class="wp-mcp-ai-test-runner__results" style="display:none;">';
			echo '<h4>' . esc_html__( 'Test Results', 'wp-mcp-ai' ) . '</h4>';
			echo '<div class="wp-mcp-ai-test-runner__results-content"></div>';
			echo '</div>';
		}

		echo '<div class="wp-mcp-ai-test-runner__status" style="display:none;"></div>';

		echo '</div>';

		$this->enqueue_test_runner_script();
	}

	/**
	 * Enqueue the test runner JavaScript.
	 */
	protected function enqueue_test_runner_script() {
		?>
		<script>
		(function($) {
			// Helper function to escape HTML and prevent XSS
			function escapeHtml(text) {
				// Handle null, undefined, and objects
				if (text === null || text === undefined) return '';
				if (typeof text === 'object') return '';
				
				var map = {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#039;'
				};
				return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
			}

			$(document).ready(function() {
				$('.wp-mcp-ai-test-runner__button').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var testType = button.data('test-type');
					var runner = button.closest('.wp-mcp-ai-test-runner');
					var statusDiv = runner.find('.wp-mcp-ai-test-runner__status');
					var resultsDiv = runner.find('.wp-mcp-ai-test-runner__results');

					button.prop('disabled', true);
					statusDiv.show().html('<p><?php echo esc_js( __( 'Running test...', 'wp-mcp-ai' ) ); ?></p>');

					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_run_performance_test',
							test_type: testType,
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_performance' ) ); ?>'
						},
						success: function(response) {
							button.prop('disabled', false);
							if (response.success) {
								statusDiv.html('<p class="wp-mcp-ai-success"><?php echo esc_js( __( 'Test completed successfully!', 'wp-mcp-ai' ) ); ?></p>');
								if (response.data && resultsDiv.length) {
									resultsDiv.show();
									resultsDiv.find('.wp-mcp-ai-test-runner__results-content').html(
										'<pre>' + JSON.stringify(response.data, null, 2) + '</pre>'
									);
								}
							} else {
								// Handle both string and object error responses
								var errorMessage = '<?php echo esc_js( __( 'Unknown error', 'wp-mcp-ai' ) ); ?>';
								if (response.data) {
									if (typeof response.data === 'object') {
										// Extract message from object
										errorMessage = response.data.message || errorMessage;
										
										// Build detailed error HTML with proper escaping
										var errorHtml = '<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'wp-mcp-ai' ) ); ?> ' + escapeHtml(errorMessage) + '</p>';
										
										// Add additional details if available
										if (response.data.details) {
											errorHtml += '<p class="wp-mcp-ai-error-details">' + escapeHtml(response.data.details) + '</p>';
										}
										// Add test output if available (contains the actual failure details)
										if (response.data.output) {
											var outputLabel = '<?php echo esc_js( __( 'Test Output (Click to expand)', 'wp-mcp-ai' ) ); ?>';
											errorHtml += '<details class="wp-mcp-ai-test-output">' +
												'<summary><strong>' + outputLabel + '</strong></summary>' +
												'<pre>' + escapeHtml(response.data.output) + '</pre>' +
												'</details>';
										}
										if (response.data.cli_command) {
											errorHtml += '<p class="wp-mcp-ai-cli-command"><strong><?php echo esc_js( __( 'CLI Command:', 'wp-mcp-ai' ) ); ?></strong> <code>' + escapeHtml(response.data.cli_command) + '</code></p>';
										}
										if (response.data.setup_command) {
											errorHtml += '<p class="wp-mcp-ai-setup-command"><strong><?php echo esc_js( __( 'Setup Command:', 'wp-mcp-ai' ) ); ?></strong> <code>' + escapeHtml(response.data.setup_command) + '</code></p>';
										}
										
										statusDiv.html(errorHtml);
									} else {
										// Handle string error
										statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'wp-mcp-ai' ) ); ?> ' + escapeHtml(response.data) + '</p>');
									}
								} else {
									statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'Test failed:', 'wp-mcp-ai' ) ); ?> ' + escapeHtml(errorMessage) + '</p>');
								}
							}
						},
						error: function() {
							button.prop('disabled', false);
							statusDiv.html('<p class="wp-mcp-ai-error"><?php echo esc_js( __( 'An error occurred while running the test.', 'wp-mcp-ai' ) ); ?></p>');
						}
					});
				});
			});
		})(jQuery);
		</script>
		<style>
		.wp-mcp-ai-test-runner {
			padding: 20px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-test-runner__title {
			margin-top: 0;
		}
		.wp-mcp-ai-test-runner__controls {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin: 15px 0;
		}
		.wp-mcp-ai-test-runner__button {
			padding: 10px 20px;
			background: #2271b1;
			color: #fff;
			border: none;
			border-radius: 4px;
			cursor: pointer;
		}
		.wp-mcp-ai-test-runner__button:hover {
			background: #135e96;
		}
		.wp-mcp-ai-test-runner__button:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}
		.wp-mcp-ai-test-runner__results {
			margin-top: 20px;
			padding: 15px;
			background: #f9f9f9;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-test-runner__results pre {
			overflow-x: auto;
			font-size: 12px;
		}
		.wp-mcp-ai-success {
			color: #46b450;
		}
		.wp-mcp-ai-error {
			color: #dc3232;
		}
		.wp-mcp-ai-error-details {
			color: #646970;
			font-size: 14px;
			margin-top: 10px;
		}
		.wp-mcp-ai-cli-command,
		.wp-mcp-ai-setup-command {
			background: #f0f0f1;
			padding: 10px;
			margin-top: 10px;
			border-left: 3px solid #2271b1;
			font-size: 13px;
		}
		.wp-mcp-ai-cli-command code,
		.wp-mcp-ai-setup-command code {
			background: #fff;
			padding: 2px 6px;
			border-radius: 3px;
			font-family: Consolas, Monaco, monospace;
			display: inline-block;
			margin-top: 5px;
		}
		.wp-mcp-ai-test-output {
			margin-top: 10px;
			background: #f0f0f1;
			border: 1px solid #ccd0d4;
			border-radius: 3px;
		}
		.wp-mcp-ai-test-output summary {
			padding: 10px;
			cursor: pointer;
			font-size: 13px;
			user-select: none;
		}
		.wp-mcp-ai-test-output summary:hover {
			background: #e5e5e5;
		}
		.wp-mcp-ai-test-output pre {
			margin: 0;
			padding: 15px;
			background: #23282d;
			color: #f0f0f1;
			border-radius: 0 0 3px 3px;
			overflow-x: auto;
			font-family: Consolas, Monaco, monospace;
			font-size: 12px;
			line-height: 1.6;
			max-height: 400px;
			overflow-y: auto;
		}
		</style>
		<?php
	}
}
