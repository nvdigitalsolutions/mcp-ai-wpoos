<?php
/**
 * Elementor widget for displaying test results in a table format.
 *
 * Shows recent test results to help AI assistants quickly identify failures
 * and performance issues.
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
 * Elementor widget definition for the test results table.
 */
class WP_MCP_AI_Elementor_Test_Results_Table_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_test_results_table';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Test Results Table', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-table-of-contents';
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
		return array( 'performance', 'test', 'results', 'table', 'monitoring', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Table Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Recent Test Results', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'test_type_filter',
			array(
				'label'       => __( 'Filter by Test Type', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					''             => __( 'All Tests', 'wp-mcp-ai' ),
					'stress'       => __( 'Stress Tests', 'wp-mcp-ai' ),
					'security'     => __( 'Security Tests', 'wp-mcp-ai' ),
					'speed'        => __( 'Speed Benchmarks', 'wp-mcp-ai' ),
					'optimization' => __( 'Optimization Tests', 'wp-mcp-ai' ),
				),
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'status_filter',
			array(
				'label'   => __( 'Filter by Status', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					''        => __( 'All Statuses', 'wp-mcp-ai' ),
					'passed'  => __( 'Passed', 'wp-mcp-ai' ),
					'warning' => __( 'Warning', 'wp-mcp-ai' ),
					'failed'  => __( 'Failed', 'wp-mcp-ai' ),
				),
				'default' => '',
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Results', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 5,
				'max'     => 50,
			)
		);

		$this->add_control(
			'show_details_button',
			array(
				'label'        => __( 'Show Details Button', 'wp-mcp-ai' ),
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
				'section_id' => 'section_style_table',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-test-results',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-test-results__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-test-results__cell',
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
			echo '<div class="wp-mcp-ai-test-results">';
			echo '<p>' . esc_html__( 'You do not have permission to view test results.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title            = isset( $settings['title'] ) ? $settings['title'] : '';
		$test_type_filter = isset( $settings['test_type_filter'] ) ? $settings['test_type_filter'] : '';
		$status_filter    = isset( $settings['status_filter'] ) ? $settings['status_filter'] : '';
		$limit            = isset( $settings['limit'] ) ? absint( $settings['limit'] ) : 10;
		$show_details     = ! empty( $settings['show_details_button'] ) && 'yes' === $settings['show_details_button'];

		// Get test results.
		$results = $this->get_test_results( $test_type_filter, $status_filter, $limit );

		echo '<div class="wp-mcp-ai-test-results">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-test-results__title">' . esc_html( $title ) . '</h3>';
		}

		// Show placeholder in Elementor editor mode to prevent data loading conflicts.
		// Following SoC: Separate editor preview from live functionality.
		if ( $this->is_elementor_editor() ) {
			echo '<div class="wp-mcp-ai-test-results__editor-placeholder" style="padding: 40px 20px; background: #f0f0f1; border: 2px dashed #c3c4c7; text-align: center; border-radius: 4px;">';
			echo '<span class="dashicons dashicons-list-view" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>';
			echo '<p style="margin: 10px 0 0; font-size: 14px; color: #50575e;">';
			echo esc_html__( 'Test Results Table Widget', 'wp-mcp-ai' );
			echo '<br><small>' . esc_html__( 'Test results will be displayed here on the live page.', 'wp-mcp-ai' ) . '</small>';
			echo '</p>';
			echo '</div>'; // Close editor placeholder.
			echo '</div>'; // Close main container.
			return;
		}

		if ( empty( $results ) ) {
			echo '<p class="wp-mcp-ai-test-results__empty">' . esc_html__( 'No test results found.', 'wp-mcp-ai' ) . '</p>';
		} else {
			echo '<div class="wp-mcp-ai-test-results__table-container">';
			echo '<table class="wp-mcp-ai-test-results__table">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Test Type', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Component', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Response Time', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Memory', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'DB Queries', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Tested At', 'wp-mcp-ai' ) . '</th>';
			if ( $show_details ) {
				echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
			}
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $results as $result ) {
				$status_class = 'status-' . sanitize_html_class( $result['test_status'] );

				echo '<tr>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( ucfirst( $result['test_type'] ) ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['component'] ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell"><span class="wp-mcp-ai-test-status ' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $result['test_status'] ) ) . '</span></td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( number_format( $result['response_time_ms'], 2 ) ) . ' ms</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( number_format( $result['memory_usage_bytes'] / 1024 / 1024, 2 ) ) . ' MB</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['db_queries'] ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['tested_at'] ) . '</td>';
				if ( $show_details ) {
					echo '<td class="wp-mcp-ai-test-results__cell">';
					echo '<button class="wp-mcp-ai-test-results__details-btn" data-test-id="' . esc_attr( $result['_ID'] ) . '">' . esc_html__( 'View', 'wp-mcp-ai' ) . '</button>';
					echo '</td>';
				}
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}

		echo '</div>';

		if ( $show_details ) {
			$this->enqueue_details_modal_script();
		}
	}

	/**
	 * Get test results from CCT or fallback storage.
	 *
	 * @param string $test_type_filter Test type filter.
	 * @param string $status_filter    Status filter.
	 * @param int    $limit            Result limit.
	 * @return array Test results.
	 */
	protected function get_test_results( $test_type_filter, $status_filter, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array();
		}

		$args = array();

		if ( ! empty( $test_type_filter ) ) {
			$args['test_type'] = sanitize_key( $test_type_filter );
		}

		if ( ! empty( $status_filter ) ) {
			$args['test_status'] = sanitize_key( $status_filter );
		}

		$items = WP_MCP_AI_Performance_Monitor_CCT::query_items( $args, $limit );

		// Fallback to WordPress options if JetEngine is unavailable.
		if ( empty( $items ) ) {
			return $this->get_test_results_fallback( $test_type_filter, $status_filter, $limit );
		}

		return $items;
	}

	/**
	 * Get test results from WordPress options fallback.
	 *
	 * @param string $test_type_filter Test type filter.
	 * @param string $status_filter    Status filter.
	 * @param int    $limit            Result limit.
	 * @return array Test results.
	 */
	protected function get_test_results_fallback( $test_type_filter, $status_filter, $limit ) {
		$option_key = 'wp_mcp_ai_performance_tests';
		$tests      = get_option( $option_key, array() );

		if ( ! is_array( $tests ) ) {
			return array();
		}

		// Filter and limit.
		$filtered = array();
		foreach ( $tests as $test_id => $test ) {
			if ( ! empty( $test_type_filter ) && isset( $test['test_type'] ) && $test['test_type'] !== $test_type_filter ) {
				continue;
			}

			if ( ! empty( $status_filter ) && isset( $test['test_status'] ) && $test['test_status'] !== $status_filter ) {
				continue;
			}

			$test['_ID'] = $test_id;
			$filtered[]  = $test;

			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		return $filtered;
	}

	/**
	 * Enqueue details modal script.
	 *
	 * Following SoC: This method handles client-side interaction logic.
	 * Business logic for fetching data lives in AJAX handlers.
	 */
	protected function enqueue_details_modal_script() {
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				/**
				 * Listen for test completion events and auto-refresh table.
				 *
				 * Following SoC: React to events from other widgets without tight coupling.
				 */
				$(document).on('wp-mcp-ai-test-completed', function(event, testData) {
					// Auto-refresh the page to show new test results.
					// In a real implementation, this could use AJAX to reload just the table.
					setTimeout(function() {
						location.reload();
					}, 2000);
				});

				/**
				 * Handle details button click.
				 *
				 * Following SoC: Separate interaction handling from data fetching.
				 */
				$('.wp-mcp-ai-test-results__details-btn').on('click', function(e) {
					e.preventDefault();
					var testId = $(this).data('test-id');
					
					// TODO: Implement modal with full test details from CCT.
					// For now, show basic alert as placeholder.
					alert('<?php echo esc_js( __( 'Test ID:', 'wp-mcp-ai' ) ); ?> ' + testId + '\n\n' +
						'<?php echo esc_js( __( 'Full test details modal coming soon.', 'wp-mcp-ai' ) ); ?>');
				});
			});
		})(jQuery);
		</script>
		<style>
		.wp-mcp-ai-test-results {
			padding: 20px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-test-results__title {
			margin-top: 0;
			margin-bottom: 20px;
		}
		.wp-mcp-ai-test-results__table-container {
			overflow-x: auto;
		}
		.wp-mcp-ai-test-results__table {
			width: 100%;
			border-collapse: collapse;
			font-size: 14px;
		}
		.wp-mcp-ai-test-results__table th,
		.wp-mcp-ai-test-results__table td {
			padding: 12px;
			text-align: left;
			border-bottom: 1px solid #ddd;
		}
		.wp-mcp-ai-test-results__table th {
			background: #f9f9f9;
			font-weight: 600;
		}
		.wp-mcp-ai-test-status {
			padding: 4px 8px;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 500;
		}
		.status-passed {
			background: #d4edda;
			color: #155724;
		}
		.status-warning {
			background: #fff3cd;
			color: #856404;
		}
		.status-failed {
			background: #f8d7da;
			color: #721c24;
		}
		.wp-mcp-ai-test-results__details-btn {
			padding: 4px 12px;
			background: #2271b1;
			color: #fff;
			border: none;
			border-radius: 3px;
			cursor: pointer;
			font-size: 12px;
		}
		.wp-mcp-ai-test-results__details-btn:hover {
			background: #135e96;
		}
		</style>
		<?php
	}
}
