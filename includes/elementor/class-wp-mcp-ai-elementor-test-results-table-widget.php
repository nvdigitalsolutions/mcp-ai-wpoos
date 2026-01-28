// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget for displaying test results in a table format.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Shows recent test results to help AI assistants quickly identify failures
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * and performance issues.
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
 * Elementor widget definition for the test results table.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
class WP_MCP_AI_Elementor_Test_Results_Table_Widget extends \Elementor\Widget_Base {
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
		return 'wp_mcp_ai_test_results_table';
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
		return __( 'NV oOS Test Results Table', 'mcp-ai-wpoos' );
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
		return 'eicon-table-of-contents';
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
		return array( 'performance', 'test', 'results', 'table', 'monitoring', 'mcp' );
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
				'label' => __( 'Table Settings', 'mcp-ai-wpoos' ),
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
				'default'     => __( 'Recent Test Results', 'mcp-ai-wpoos' ),
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
			'test_type_filter',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Filter by Test Type', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options'     => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					''             => __( 'All Tests', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'stress'       => __( 'Stress Tests', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'security'     => __( 'Security Tests', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'speed'        => __( 'Speed Benchmarks', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'optimization' => __( 'Optimization Tests', 'mcp-ai-wpoos' ),
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
			'status_filter',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'Filter by Status', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options' => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					''        => __( 'All Statuses', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'passed'  => __( 'Passed', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'warning' => __( 'Warning', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'failed'  => __( 'Failed', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => '',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'limit',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'Number of Results', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::NUMBER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => 10,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'min'     => 5,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'max'     => 50,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'show_details_button',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'        => __( 'Show Details Button', 'mcp-ai-wpoos' ),
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
				'section_id' => 'section_style_table',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectors'  => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'container' => '{{WRAPPER}} .wp-mcp-ai-test-results',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-test-results__title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'text'      => '{{WRAPPER}} .wp-mcp-ai-test-results__cell',
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
			echo '<div class="wp-mcp-ai-test-results">';
			echo '<p>' . esc_html__( 'You do not have permission to view test results.', 'mcp-ai-wpoos' ) . '</p>';
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
		$test_type_filter = isset( $settings['test_type_filter'] ) ? $settings['test_type_filter'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$status_filter    = isset( $settings['status_filter'] ) ? $settings['status_filter'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$limit            = isset( $settings['limit'] ) ? absint( $settings['limit'] ) : 10;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$show_details     = ! empty( $settings['show_details_button'] ) && 'yes' === $settings['show_details_button'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Get test results.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$results = $this->get_test_results( $test_type_filter, $status_filter, $limit );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-test-results">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-test-results__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( empty( $results ) ) {
			echo '<p class="wp-mcp-ai-test-results__empty">' . esc_html__( 'No test results found.', 'mcp-ai-wpoos' ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-test-results__table-container">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<table class="wp-mcp-ai-test-results__table">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<thead>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<tr>';
			echo '<th>' . esc_html__( 'Test Type', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'Component', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'Response Time', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'Memory', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'DB Queries', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th>' . esc_html__( 'Tested At', 'mcp-ai-wpoos' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( $show_details ) {
				echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</tr>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</thead>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<tbody>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			foreach ( $results as $result ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$status_class = 'status-' . sanitize_html_class( $result['test_status'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '<tr>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( ucfirst( $result['test_type'] ) ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['component'] ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell"><span class="wp-mcp-ai-test-status ' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $result['test_status'] ) ) . '</span></td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( number_format( $result['response_time_ms'], 2 ) ) . ' ms</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( number_format( $result['memory_usage_bytes'] / 1024 / 1024, 2 ) ) . ' MB</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['db_queries'] ) . '</td>';
				echo '<td class="wp-mcp-ai-test-results__cell">' . esc_html( $result['tested_at'] ) . '</td>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( $show_details ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					echo '<td class="wp-mcp-ai-test-results__cell">';
					echo '<button class="wp-mcp-ai-test-results__details-btn" data-test-id="' . esc_attr( $result['_ID'] ) . '">' . esc_html__( 'View', 'mcp-ai-wpoos' ) . '</button>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					echo '</td>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '</tr>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</tbody>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</table>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $show_details ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$this->enqueue_details_modal_script();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get test results from CCT or fallback storage.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $test_type_filter Test type filter.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $status_filter    Status filter.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param int    $limit            Result limit.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array Test results.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_test_results( $test_type_filter, $status_filter, $limit ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$args = array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $test_type_filter ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$args['test_type'] = sanitize_key( $test_type_filter );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $status_filter ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$args['test_status'] = sanitize_key( $status_filter );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$items = WP_MCP_AI_Performance_Monitor_CCT::query_items( $args, $limit );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Fallback to WordPress options if JetEngine is unavailable.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( empty( $items ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return $this->get_test_results_fallback( $test_type_filter, $status_filter, $limit );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return $items;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Get test results from WordPress options fallback.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $test_type_filter Test type filter.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $status_filter    Status filter.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param int    $limit            Result limit.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array Test results.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_test_results_fallback( $test_type_filter, $status_filter, $limit ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$option_key = 'wp_mcp_ai_performance_tests';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$tests      = get_option( $option_key, array() );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! is_array( $tests ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Filter and limit.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$filtered = array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		foreach ( $tests as $test_id => $test ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! empty( $test_type_filter ) && isset( $test['test_type'] ) && $test['test_type'] !== $test_type_filter ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				continue;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! empty( $status_filter ) && isset( $test['test_status'] ) && $test['test_status'] !== $status_filter ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				continue;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$test['_ID'] = $test_id;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$filtered[]  = $test;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( count( $filtered ) >= $limit ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				break;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return $filtered;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Enqueue details modal script.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function enqueue_details_modal_script() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for Elementor widget functionality with dynamic data
		?>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		<script>
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		(function($) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$(document).ready(function() {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$('.wp-mcp-ai-test-results__details-btn').on('click', function(e) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					e.preventDefault();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					var testId = $(this).data('test-id');
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					// In a real implementation, this would fetch and display full test details.
					alert('<?php echo esc_js( __( 'Test ID:', 'mcp-ai-wpoos' ) ); ?> ' + testId);
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
		.wp-mcp-ai-test-results {
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
		.wp-mcp-ai-test-results__title {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-top: 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			margin-bottom: 20px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__table-container {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			overflow-x: auto;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__table {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			width: 100%;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-collapse: collapse;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 14px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__table th,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__table td {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			text-align: left;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-bottom: 1px solid #ddd;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__table th {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f9f9f9;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-weight: 600;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-status {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 4px 8px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 3px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-weight: 500;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-passed {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #d4edda;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #155724;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-warning {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #fff3cd;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #856404;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.status-failed {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #f8d7da;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #721c24;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__details-btn {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			padding: 4px 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #2271b1;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			color: #fff;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border: none;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			border-radius: 3px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			cursor: pointer;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			font-size: 12px;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		.wp-mcp-ai-test-results__details-btn:hover {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			background: #135e96;
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
