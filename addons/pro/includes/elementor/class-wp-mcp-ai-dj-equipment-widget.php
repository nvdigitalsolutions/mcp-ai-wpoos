<?php
/**
 * DJ Equipment Elementor Widget
 *
 * Displays equipment from the DJ Management toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DJ Equipment Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Dj_Equipment_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_dj_equipment';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'DJ Equipment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-sound-cloud';
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
		return array( 'dj', 'equipment', 'gear', 'audio', 'music', 'mcp' );
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
				'max'       => 4,
				'condition' => array(
					'display' => 'grid',
				),
			)
		);

		$this->add_control(
			'category',
			array(
				'label'       => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., speakers, mixers', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter equipment by category (leave empty for all)', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'show_status',
			array(
				'label'        => __( 'Show Status', 'mcp-ai-wpoos-pro' ),
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

		if ( ! empty( $settings['category'] ) ) {
			$attributes[] = 'category="' . esc_attr( $settings['category'] ) . '"';
		}

		if ( ! empty( $settings['show_status'] ) && 'yes' === $settings['show_status'] ) {
			$attributes[] = 'show_status="yes"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_dj_equipment ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
