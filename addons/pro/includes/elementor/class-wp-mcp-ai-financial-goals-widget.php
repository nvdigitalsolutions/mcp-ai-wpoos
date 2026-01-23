<?php
/**
 * Financial Goals Elementor Widget
 *
 * Displays financial goals from the Financial toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Financial Goals Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Financial_Goals_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_financial_goals';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Financial Goals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-trophy';
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
		return array( 'financial', 'goals', 'savings', 'targets', 'planning', 'mcp' );
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
			'display',
			array(
				'label'   => __( 'Display Mode', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => array(
					'grid' => __( 'Grid', 'mcp-ai-wpoos-pro' ),
					'list' => __( 'List', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'mcp-ai-wpoos-pro' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 3,
				'min'       => 1,
				'max'       => 4,
				'condition' => array(
					'display' => 'grid',
				),
			)
		);

		$this->add_control(
			'status',
			array(
				'label'   => __( 'Status Filter', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'all'         => __( 'All Goals', 'mcp-ai-wpoos-pro' ),
					'active'      => __( 'Active', 'mcp-ai-wpoos-pro' ),
					'completed'   => __( 'Completed', 'mcp-ai-wpoos-pro' ),
					'in_progress' => __( 'In Progress', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'show_progress',
			array(
				'label'        => __( 'Show Progress', 'mcp-ai-wpoos-pro' ),
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

		if ( ! empty( $settings['display'] ) ) {
			$attributes[] = 'display="' . esc_attr( $settings['display'] ) . '"';
		}

		if ( ! empty( $settings['columns'] ) && 'grid' === $settings['display'] ) {
			$attributes[] = 'columns="' . absint( $settings['columns'] ) . '"';
		}

		if ( ! empty( $settings['status'] ) && 'all' !== $settings['status'] ) {
			$attributes[] = 'status="' . esc_attr( $settings['status'] ) . '"';
		}

		if ( ! empty( $settings['show_progress'] ) && 'yes' === $settings['show_progress'] ) {
			$attributes[] = 'show_progress="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_financial_goals ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
