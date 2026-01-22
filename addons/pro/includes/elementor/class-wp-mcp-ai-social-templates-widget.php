<?php
/**
 * Social Media Templates Elementor Widget
 *
 * Displays social media templates from the Social Media toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social Media Templates Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Social_Templates_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_social_templates';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Social Media Templates', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-post-list';
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
		return array( 'social', 'media', 'templates', 'posts', 'content', 'mcp' );
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
			'category',
			array(
				'label'       => __( 'Template Category', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., promotional, engagement', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter templates by category (leave empty for all)', 'mcp-ai-wpoos-pro' ),
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
			'limit',
			array(
				'label'   => __( 'Number of Templates', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 9,
				'min'     => 1,
				'max'     => 50,
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

		if ( ! empty( $settings['category'] ) ) {
			$attributes[] = 'category="' . esc_attr( $settings['category'] ) . '"';
		}

		if ( ! empty( $settings['display'] ) ) {
			$attributes[] = 'display="' . esc_attr( $settings['display'] ) . '"';
		}

		if ( ! empty( $settings['columns'] ) && 'grid' === $settings['display'] ) {
			$attributes[] = 'columns="' . absint( $settings['columns'] ) . '"';
		}

		if ( ! empty( $settings['limit'] ) ) {
			$attributes[] = 'limit="' . absint( $settings['limit'] ) . '"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_social_media_templates ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
