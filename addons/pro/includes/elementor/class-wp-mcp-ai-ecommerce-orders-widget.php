<?php
/**
 * E-commerce Orders Elementor Widget
 *
 * Displays orders from the E-commerce toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-commerce Orders Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ecommerce_Orders_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_ecommerce_orders';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'E-commerce Orders', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-cart';
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
		return array( 'ecommerce', 'orders', 'shop', 'purchases', 'mcp' );
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
			'status',
			array(
				'label'       => __( 'Order Status', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., completed, processing', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter orders by status (leave empty for all)', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Orders', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'show_customer',
			array(
				'label'        => __( 'Show Customer Info', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_total',
			array(
				'label'        => __( 'Show Order Total', 'mcp-ai-wpoos-pro' ),
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

		if ( ! empty( $settings['status'] ) ) {
			$attributes[] = 'status="' . esc_attr( $settings['status'] ) . '"';
		}

		if ( ! empty( $settings['limit'] ) ) {
			$attributes[] = 'limit="' . absint( $settings['limit'] ) . '"';
		}

		if ( ! empty( $settings['show_customer'] ) && 'yes' === $settings['show_customer'] ) {
			$attributes[] = 'show_customer="yes"';
		}

		if ( ! empty( $settings['show_total'] ) && 'yes' === $settings['show_total'] ) {
			$attributes[] = 'show_total="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_ecommerce_orders ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
