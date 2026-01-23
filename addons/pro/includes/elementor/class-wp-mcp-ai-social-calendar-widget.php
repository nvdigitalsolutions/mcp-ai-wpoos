<?php
/**
 * Social Media Calendar Elementor Widget
 *
 * Displays social media calendar from the Social Media toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social Media Calendar Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Social_Calendar_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_social_calendar';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Social Media Calendar', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-calendar';
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
		return array( 'social', 'media', 'calendar', 'schedule', 'posts', 'mcp' );
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
			'view',
			array(
				'label'   => __( 'View Mode', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'month',
				'options' => array(
					'month' => __( 'Month', 'mcp-ai-wpoos-pro' ),
					'week'  => __( 'Week', 'mcp-ai-wpoos-pro' ),
					'day'   => __( 'Day', 'mcp-ai-wpoos-pro' ),
					'list'  => __( 'List', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'platform',
			array(
				'label'       => __( 'Platform Filter', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., facebook, twitter', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter by social platform (leave empty for all)', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'show_status',
			array(
				'label'        => __( 'Show Post Status', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_preview',
			array(
				'label'        => __( 'Show Post Preview', 'mcp-ai-wpoos-pro' ),
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

		if ( ! empty( $settings['view'] ) ) {
			$attributes[] = 'view="' . esc_attr( $settings['view'] ) . '"';
		}

		if ( ! empty( $settings['platform'] ) ) {
			$attributes[] = 'platform="' . esc_attr( $settings['platform'] ) . '"';
		}

		if ( ! empty( $settings['show_status'] ) && 'yes' === $settings['show_status'] ) {
			$attributes[] = 'show_status="yes"';
		}

		if ( ! empty( $settings['show_preview'] ) && 'yes' === $settings['show_preview'] ) {
			$attributes[] = 'show_preview="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_social_media_calendar ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
