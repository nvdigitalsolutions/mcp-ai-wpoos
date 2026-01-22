<?php
/**
 * E-commerce Products Elementor Widget
 *
 * Displays products from the E-commerce toolkit Research & Add data.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-commerce Products Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ecommerce_Products_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_ecommerce_products';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'E-commerce Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-products';
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
		return array( 'ecommerce', 'products', 'shop', 'store', 'mcp' );
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
					'grid'  => __( 'Grid', 'mcp-ai-wpoos-pro' ),
					'list'  => __( 'List', 'mcp-ai-wpoos-pro' ),
					'table' => __( 'Table', 'mcp-ai-wpoos-pro' ),
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
				'max'       => 6,
				'condition' => array(
					'display' => 'grid',
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Products', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 9,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'category',
			array(
				'label'       => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Leave empty for all', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter products by category', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => __( 'Order By', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'  => __( 'Date', 'mcp-ai-wpoos-pro' ),
					'name'  => __( 'Name', 'mcp-ai-wpoos-pro' ),
					'price' => __( 'Price', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Order', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'desc',
				'options' => array(
					'asc'  => __( 'Ascending', 'mcp-ai-wpoos-pro' ),
					'desc' => __( 'Descending', 'mcp-ai-wpoos-pro' ),
				),
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

		if ( ! empty( $settings['limit'] ) ) {
			$attributes[] = 'limit="' . absint( $settings['limit'] ) . '"';
		}

		if ( ! empty( $settings['category'] ) ) {
			$attributes[] = 'category="' . esc_attr( $settings['category'] ) . '"';
		}

		if ( ! empty( $settings['orderby'] ) ) {
			$attributes[] = 'orderby="' . esc_attr( $settings['orderby'] ) . '"';
		}

		if ( ! empty( $settings['order'] ) ) {
			$attributes[] = 'order="' . esc_attr( $settings['order'] ) . '"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_ecommerce_products ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
