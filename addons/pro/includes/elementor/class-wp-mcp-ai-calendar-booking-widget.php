<?php
/**
 * Calendar Booking Form Elementor Widget
 *
 * Displays booking form from the Calendar toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar Booking Form Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Calendar_Booking_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_calendar_booking';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Calendar Booking Form', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Get widget categories.
	 *
	 * @since 1.0.0
	 * @return array Widget categories.
	 */
	public function get_categories() {
		return array( 'mcp-ai-toolkits' );
	}

	/**
	 * Get widget keywords.
	 *
	 * @since 1.0.0
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return array( 'calendar', 'booking', 'appointment', 'schedule', 'form', 'mcp' );
	}

	/**
	 * Register widget controls.
	 *
	 * @since 1.0.0
	 */
	protected function register_controls() {
		// Content Section.
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'mcp-ai-wpoos-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'service',
			array(
				'label'       => __( 'Service ID', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Leave empty for selection', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Pre-select a specific service', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'staff',
			array(
				'label'       => __( 'Staff ID', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Leave empty for selection', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Pre-select a specific staff member', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'show_calendar',
			array(
				'label'        => __( 'Show Calendar', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_time_slots',
			array(
				'label'        => __( 'Show Time Slots', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend.
	 *
	 * @since 1.0.0
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Build shortcode attributes.
		$attributes = array();

		if ( ! empty( $settings['service'] ) ) {
			$attributes[] = 'service="' . esc_attr( $settings['service'] ) . '"';
		}

		if ( ! empty( $settings['staff'] ) ) {
			$attributes[] = 'staff="' . esc_attr( $settings['staff'] ) . '"';
		}

		if ( ! empty( $settings['show_calendar'] ) && 'yes' === $settings['show_calendar'] ) {
			$attributes[] = 'show_calendar="yes"';
		}

		if ( ! empty( $settings['show_time_slots'] ) && 'yes' === $settings['show_time_slots'] ) {
			$attributes[] = 'show_time_slots="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_calendar_booking_form ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
