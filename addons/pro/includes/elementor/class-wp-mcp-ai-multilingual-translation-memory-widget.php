<?php
/**
 * Multilingual Translation Memory Elementor Widget
 *
 * Displays translation memory from the Multilingual toolkit Research & Add data.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multilingual Translation Memory Widget Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Multilingual_Translation_Memory_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.0.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_multilingual_translation_memory';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.0.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Translation Memory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.0.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-translation';
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
		return array( 'multilingual', 'translation', 'memory', 'language', 'mcp' );
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
			'source_language',
			array(
				'label'       => __( 'Source Language', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., en', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter by source language code (leave empty for all)', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'target_language',
			array(
				'label'       => __( 'Target Language', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g., es', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Filter by target language code (leave empty for all)', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'quality_score_min',
			array(
				'label'       => __( 'Minimum Quality Score', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'max'         => 1,
				'step'        => 0.1,
				'description' => __( 'Show only translations with quality score above this value', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Translations', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 20,
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

		if ( ! empty( $settings['source_language'] ) ) {
			$attributes[] = 'source_language="' . esc_attr( $settings['source_language'] ) . '"';
		}

		if ( ! empty( $settings['target_language'] ) ) {
			$attributes[] = 'target_language="' . esc_attr( $settings['target_language'] ) . '"';
		}

		if ( isset( $settings['quality_score_min'] ) && '' !== $settings['quality_score_min'] ) {
			$attributes[] = 'quality_score_min="' . floatval( $settings['quality_score_min'] ) . '"';
		}

		if ( ! empty( $settings['limit'] ) ) {
			$attributes[] = 'limit="' . absint( $settings['limit'] ) . '"';
		}

		// Build and render shortcode.
		$shortcode = '[mcp_multilingual_translation_memory ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode );
	}
}
