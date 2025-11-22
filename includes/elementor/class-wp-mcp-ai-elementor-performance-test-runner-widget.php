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
		return __( 'WP oOS Performance Test Runner', 'wp-mcp-ai' );
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
			'component',
			array(
				'label'       => __( 'Component to Test', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'elementor'     => __( 'Elementor (Default)', 'wp-mcp-ai' ),
					'rest_api'      => __( 'REST API', 'wp-mcp-ai' ),
					'chat_ui'       => __( 'Chat UI', 'wp-mcp-ai' ),
					'mcp_core'      => __( 'MCP Core', 'wp-mcp-ai' ),
					'cpt_ai_peer'   => __( 'CPT: AI Peer', 'wp-mcp-ai' ),
					'cpt_assistant' => __( 'CPT: Assistant', 'wp-mcp-ai' ),
				),
				'default'     => 'elementor',
				'description' => __( 'Specify which plugin component this test is targeting. This helps organize test results in the CCT.', 'wp-mcp-ai' ),
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
	 * Check if currently in Elementor editor mode.
	 *
	 * Following SoC: Separate editor detection logic from rendering logic.
	 *
	 * @return bool True if in Elementor editor mode, false otherwise.
	 */
	protected function is_elementor_editor() {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();
			if ( $elementor && $elementor->editor && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the widget on the front-end.
	 *
	 * Following SoC: This method orchestrates rendering, delegating to helper methods.
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
		$component     = isset( $settings['component'] ) ? $settings['component'] : 'elementor';

		// Following SoC: Use data attributes to pass configuration to JavaScript.
		echo '<div class="wp-mcp-ai-test-runner" data-component="' . esc_attr( $component ) . '">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-test-runner__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			echo '<p class="wp-mcp-ai-test-runner__description">' . esc_html( $description ) . '</p>';
		}

		// Show placeholder in Elementor editor mode to prevent JavaScript conflicts.
		// Following SoC: Separate editor preview from live functionality.
		if ( $this->is_elementor_editor() ) {
			echo '<div class="wp-mcp-ai-test-runner__editor-placeholder" style="padding: 40px 20px; background: #f0f0f1; border: 2px dashed #c3c4c7; text-align: center; border-radius: 4px;">';
			echo '<span class="dashicons dashicons-performance" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>';
			echo '<p style="margin: 10px 0 0; font-size: 14px; color: #50575e;">';
			echo esc_html__( 'Performance Test Runner Widget', 'wp-mcp-ai' );
			echo '<br><small>' . esc_html__( 'Test buttons will be functional on the live page.', 'wp-mcp-ai' ) . '</small>';
			echo '</p>';
			echo '</div>';
			echo '</div>';
			return;
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
	 *
	 * Following SoC: This method is responsible only for rendering the client-side
	 * test runner interface and AJAX handling. Business logic lives in AJAX handlers.
	 */
	protected function enqueue_test_runner_script() {
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				/**
				 * Format test result message.
				 * 
				 * Following SoC: Separate formatting logic from AJAX handling.
				 *
				 * @param {Object} data Response data from server.
				 * @return {string} Formatted HTML message.
				 */
				function formatTestResultMessage(data) {
					var message = '<p class="wp-mcp-ai-success">';
					message += data.message || '<?php echo esc_js( __( 'Test completed successfully!', 'wp-mcp-ai' ) ); ?>';
					
					// Add CCT save confirmation if available.
					if (data.saved_to_cct && data.cct_item_id) {
						message += '<br><span class="wp-mcp-ai-cct-saved">';
						message += '<?php echo esc_js( __( '✓ Results saved to database (ID: ', 'wp-mcp-ai' ) ); ?>';
						message += data.cct_item_id + ')';
						message += '</span>';
					} else if (data.saved_to_cct === false) {
						message += '<br><span class="wp-mcp-ai-cct-warning">';
						message += '<?php echo esc_js( __( '⚠ Results not saved (CCT unavailable)', 'wp-mcp-ai' ) ); ?>';
						message += '</span>';
					}
					
					message += '</p>';
					return message;
				}

				/**
				 * Format error message.
				 *
				 * Following SoC: Separate error formatting from AJAX handling.
				 *
				 * @param {Object} response Response object from server.
				 * @return {string} Formatted HTML error message.
				 */
				function formatErrorMessage(response) {
					var errorData = response.data || {};
					var message = '<p class="wp-mcp-ai-error">';
					message += '<?php echo esc_js( __( 'Test failed:', 'wp-mcp-ai' ) ); ?> ';
					message += errorData.message || '<?php echo esc_js( __( 'Unknown error', 'wp-mcp-ai' ) ); ?>';
					message += '</p>';
					
					// Show additional details if available.
					if (errorData.details) {
						message += '<p class="wp-mcp-ai-error-details">' + errorData.details + '</p>';
					}
					
					return message;
				}

				/**
				 * Handle test button click.
				 *
				 * Following SoC: This function orchestrates the test execution flow,
				 * delegating to formatters and AJAX handlers.
				 */
				$('.wp-mcp-ai-test-runner__button').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var testType = button.data('test-type');
					var runner = button.closest('.wp-mcp-ai-test-runner');
					var statusDiv = runner.find('.wp-mcp-ai-test-runner__status');
					var resultsDiv = runner.find('.wp-mcp-ai-test-runner__results');
					
					// Following SoC: Read component from widget configuration.
					var component = runner.data('component') || 'elementor';

					// Show loading state.
					button.prop('disabled', true).addClass('wp-mcp-ai-loading');
					statusDiv.show().html(
						'<div class="wp-mcp-ai-spinner"></div>' +
						'<p><?php echo esc_js( __( 'Running test...', 'wp-mcp-ai' ) ); ?></p>'
					);

					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_run_performance_test',
							test_type: testType,
							component: component,
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_performance' ) ); ?>'
						},
						success: function(response) {
							button.prop('disabled', false).removeClass('wp-mcp-ai-loading');
							
							if (response.success) {
								// Format and display success message.
								statusDiv.html(formatTestResultMessage(response.data));
								
								// Display test results if available.
								if (response.data && resultsDiv.length) {
									resultsDiv.show();
									var resultsContent = '<pre>' + JSON.stringify(response.data, null, 2) + '</pre>';
									resultsDiv.find('.wp-mcp-ai-test-runner__results-content').html(resultsContent);
								}
								
								// Trigger event for other widgets to refresh (e.g., Test Results Table).
								$(document).trigger('wp-mcp-ai-test-completed', [response.data]);
							} else {
								// Format and display error message.
								statusDiv.html(formatErrorMessage(response));
							}
						},
						error: function(xhr, status, error) {
							button.prop('disabled', false).removeClass('wp-mcp-ai-loading');
							statusDiv.html(
								'<p class="wp-mcp-ai-error">' +
								'<?php echo esc_js( __( 'An error occurred while running the test.', 'wp-mcp-ai' ) ); ?>' +
								'<br><small>' + error + '</small>' +
								'</p>'
							);
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
		.wp-mcp-ai-test-runner__button.wp-mcp-ai-loading {
			position: relative;
			color: transparent;
		}
		.wp-mcp-ai-spinner {
			display: inline-block;
			width: 16px;
			height: 16px;
			border: 2px solid rgba(255,255,255,0.3);
			border-top-color: #fff;
			border-radius: 50%;
			animation: wp-mcp-ai-spin 0.6s linear infinite;
			margin-right: 8px;
		}
		@keyframes wp-mcp-ai-spin {
			to { transform: rotate(360deg); }
		}
		.wp-mcp-ai-test-runner__status {
			margin-top: 15px;
			padding: 12px;
			border-radius: 4px;
			background: #f0f0f1;
		}
		.wp-mcp-ai-success {
			color: #46b450;
		}
		.wp-mcp-ai-error {
			color: #dc3232;
		}
		.wp-mcp-ai-error-details {
			margin-top: 8px;
			font-size: 12px;
			color: #666;
		}
		.wp-mcp-ai-cct-saved {
			font-size: 12px;
			color: #46b450;
			font-weight: 600;
		}
		.wp-mcp-ai-cct-warning {
			font-size: 12px;
			color: #f0b849;
			font-weight: 600;
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
		</style>
		<?php
	}
}
