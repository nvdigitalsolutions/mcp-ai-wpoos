<?php
/**
 * AI Tool Builder Parameter Schemas Elementor Widget
 *
 * Displays parameter schemas from the AI Tool Builder toolkit Research & Add data.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Tool Builder Parameter Schemas Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_AI_Tool_Builder_Schemas_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_ai_tool_builder_schemas';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'AI Tool Builder Schemas', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-document-file';
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
		return array( 'ai', 'tool', 'builder', 'schema', 'parameters', 'mcp' );
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
				'default' => 'list',
				'options' => array(
					'list'  => __( 'List', 'mcp-ai-wpoos-pro' ),
					'table' => __( 'Table', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Schemas', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 30,
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

		if ( ! empty( $settings['display'] ) ) {
			$attributes[] = 'display="' . esc_attr( $settings['display'] ) . '"';
		}

		if ( ! empty( $settings['limit'] ) ) {
			$attributes[] = 'limit="' . absint( $settings['limit'] ) . '"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_ai_tool_builder_schemas ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
