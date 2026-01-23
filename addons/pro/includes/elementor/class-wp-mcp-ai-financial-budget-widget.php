<?php
/**
 * Financial Budget Elementor Widget
 *
 * Displays budget tracking from the Financial toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Financial Budget Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Financial_Budget_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_financial_budget';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Financial Budget', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-budget';
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
		return array( 'financial', 'budget', 'money', 'expenses', 'tracking', 'mcp' );
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
				'default' => 'summary',
				'options' => array(
					'summary' => __( 'Summary', 'mcp-ai-wpoos-pro' ),
					'chart'   => __( 'Chart', 'mcp-ai-wpoos-pro' ),
					'table'   => __( 'Table', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'period',
			array(
				'label'   => __( 'Time Period', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'month',
				'options' => array(
					'week'  => __( 'Week', 'mcp-ai-wpoos-pro' ),
					'month' => __( 'Month', 'mcp-ai-wpoos-pro' ),
					'year'  => __( 'Year', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'show_categories',
			array(
				'label'        => __( 'Show Categories', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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

		if ( ! empty( $settings['view'] ) ) {
			$attributes[] = 'view="' . esc_attr( $settings['view'] ) . '"';
		}

		if ( ! empty( $settings['period'] ) ) {
			$attributes[] = 'period="' . esc_attr( $settings['period'] ) . '"';
		}

		if ( ! empty( $settings['show_categories'] ) && 'yes' === $settings['show_categories'] ) {
			$attributes[] = 'show_categories="yes"';
		}

		if ( ! empty( $settings['show_progress'] ) && 'yes' === $settings['show_progress'] ) {
			$attributes[] = 'show_progress="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_financial_budget ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
