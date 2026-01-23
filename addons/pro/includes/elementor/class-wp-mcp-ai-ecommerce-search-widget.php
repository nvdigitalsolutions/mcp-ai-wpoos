<?php
/**
 * E-commerce Product Search Elementor Widget
 *
 * Displays product search from the E-commerce toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-commerce Product Search Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ecommerce_Search_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_ecommerce_search';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'E-commerce Product Search', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-search';
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
		return array( 'ecommerce', 'search', 'products', 'shop', 'mcp' );
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
			'placeholder',
			array(
				'label'       => __( 'Placeholder Text', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Search products...', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Text displayed in the search field', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'show_filters',
			array(
				'label'        => __( 'Show Filters', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sorting',
			array(
				'label'        => __( 'Show Sorting', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'results_per_page',
			array(
				'label'   => __( 'Results Per Page', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
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

		if ( ! empty( $settings['placeholder'] ) ) {
			$attributes[] = 'placeholder="' . esc_attr( $settings['placeholder'] ) . '"';
		}

		if ( ! empty( $settings['show_filters'] ) && 'yes' === $settings['show_filters'] ) {
			$attributes[] = 'show_filters="yes"';
		}

		if ( ! empty( $settings['show_sorting'] ) && 'yes' === $settings['show_sorting'] ) {
			$attributes[] = 'show_sorting="yes"';
		}

		if ( ! empty( $settings['results_per_page'] ) ) {
			$attributes[] = 'results_per_page="' . absint( $settings['results_per_page'] ) . '"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_ecommerce_product_search ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
