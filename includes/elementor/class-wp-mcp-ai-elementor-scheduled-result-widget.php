<?php
/**
 * Elementor widget — Scheduled Result Display.
 *
 * Binds to any NV oOS Pro Schedule and renders the latest run's structured
 * envelope as a dashboard tile. Delegates rendering to the shared
 * {@see WP_MCP_AI_Scheduled_Result_Renderer} so editor and front end stay in
 * sync with the Gutenberg block.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_Elementor_Scheduled_Result_Widget.
 */
class WP_MCP_AI_Elementor_Scheduled_Result_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_scheduled_result';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'NV oOS Scheduled Result', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-clock';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'mcp', 'schedule', 'cron', 'result', 'dashboard', 'nv oos' );
	}

	/**
	 * Build a list of selectable schedules for the control.
	 *
	 * @return array Map of schedule_id => display label.
	 */
	protected function get_schedule_options() {
		$options = array( '' => __( '— Select a schedule —', 'mcp-ai-wpoos' ) );
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return $options;
		}
		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		foreach ( $schedules as $id => $schedule ) {
			$name             = isset( $schedule['name'] ) ? $schedule['name'] : $id;
			$type             = isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : '';
			$options[ (string) $id ] = $name . ( $type ? ' (' . $type . ')' : '' );
		}
		return $options;
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Scheduled Result', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'schedule_id',
			array(
				'label'       => __( 'Schedule', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_schedule_options(),
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'render_mode',
			array(
				'label'   => __( 'Render mode', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'summary-card' => __( 'Summary card', 'mcp-ai-wpoos' ),
					'list'         => __( 'List', 'mcp-ai-wpoos' ),
					'table'        => __( 'Table', 'mcp-ai-wpoos' ),
					'metric'       => __( 'Metric', 'mcp-ai-wpoos' ),
					'timeline'     => __( 'Timeline', 'mcp-ai-wpoos' ),
					'raw'          => __( 'Raw', 'mcp-ai-wpoos' ),
				),
				'default' => 'summary-card',
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title (overrides schedule name)', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'show_last_run',
			array(
				'label'        => __( 'Show last-run timestamp', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'Hide', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'refresh_interval',
			array(
				'label'   => __( 'Auto-refresh interval (seconds)', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 3600,
				'step'    => 30,
				'default' => 0,
			)
		);

		$this->add_control(
			'truncate_chars',
			array(
				'label'   => __( 'Truncate raw text (characters)', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 4000,
				'step'    => 100,
				'default' => 0,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the front end / editor.
	 */
	protected function render() {
		if ( ! class_exists( 'WP_MCP_AI_Scheduled_Result_Renderer' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
		}

		$settings    = $this->get_settings_for_display();
		$schedule_id = isset( $settings['schedule_id'] ) ? (string) $settings['schedule_id'] : '';
		$opts        = array(
			'render_mode'      => isset( $settings['render_mode'] ) ? (string) $settings['render_mode'] : 'summary-card',
			'title'            => isset( $settings['title'] ) ? (string) $settings['title'] : '',
			'show_last_run'    => isset( $settings['show_last_run'] ) ? ( 'yes' === $settings['show_last_run'] ) : true,
			'refresh_interval' => isset( $settings['refresh_interval'] ) ? (int) $settings['refresh_interval'] : 0,
			'truncate_chars'   => isset( $settings['truncate_chars'] ) ? (int) $settings['truncate_chars'] : 0,
		);

		echo WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all output internally.
	}
}
