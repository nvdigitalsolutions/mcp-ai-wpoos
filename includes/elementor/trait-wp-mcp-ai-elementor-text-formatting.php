<?php
/**
 * Shared text formatting helpers for Elementor widgets.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides helper methods for preparing rich text content in Elementor widgets.
 */
trait WP_MCP_AI_Elementor_Text_Formatting {
	/**
	 * Prepare a block of text for display.
	 *
	 * Applies basic sanitisation and paragraph formatting so that multi-line
	 * content entered in Elementor is rendered with predictable spacing.
	 *
	 * @param string $content Raw content entered in the Elementor control.
	 *
	 * @return string Sanitised and formatted HTML. Empty string when there is
	 *                no content to display.
	 */
	protected function format_text_block( $content ) {
		if ( ! is_string( $content ) ) {
			return '';
		}

		$content = trim( $content );

		if ( '' === $content ) {
			return '';
		}

		$clean_content = wp_kses_post( $content );
		$formatted     = wpautop( $clean_content );

		if ( ! is_string( $formatted ) || '' === trim( $formatted ) ) {
			return '';
		}

		$formatted = wp_kses_post( $formatted );

		return apply_filters( 'wp_mcp_ai_elementor_formatted_text', $formatted, $content, $this );
	}

	/**
	 * Prepare inline text for display.
	 *
	 * Normalises whitespace and escapes content so single-line strings that
	 * include dynamic values read cleanly within inline containers.
	 *
	 * @param string $content Raw content entered in the Elementor control.
	 *
	 * @return string Sanitised string ready for inline output. Empty string
	 *                when there is no content to display.
	 */
	protected function format_text_inline( $content ) {
		if ( ! is_string( $content ) ) {
			return '';
		}

		$content = trim( $content );

		if ( '' === $content ) {
			return '';
		}

		$normalised = preg_replace( '/\s+/u', ' ', $content );

		if ( ! is_string( $normalised ) || '' === $normalised ) {
			return '';
		}

		$sanitised = esc_html( $normalised );

		return apply_filters( 'wp_mcp_ai_elementor_inline_text', $sanitised, $content, $this );
	}

	/**
	 * Register a shared style controls section for widgets that surface theme settings.
	 *
	 * @param array $args {
	 *     Optional. Arguments used to configure the controls section.
	 *
	 *     @type string $section_id Unique controls section identifier. Defaults to
	 *                              `section_theme_styles`.
	 *     @type string $label      Section label shown in Elementor. Defaults to
	 *                              "Theme Styles".
	 *     @type array  $selectors  Map of selector keys to CSS selectors. Supported
	 *                              keys include `container`, `heading`, `text`,
	 *                              `meta`, `link`, and `link_hover`.
	 * }
	 */
	protected function register_theme_style_controls( array $args ) {
		if ( ! method_exists( $this, 'start_controls_section' ) ) {
			return;
		}

		$defaults  = array(
			'section_id' => 'section_theme_styles',
			'label'      => __( 'Theme Styles', 'wp-mcp-ai' ),
			'selectors'  => array(),
		);
		$args      = wp_parse_args( $args, $defaults );
		$selectors = isset( $args['selectors'] ) && is_array( $args['selectors'] ) ? $args['selectors'] : array();
		$selectors = array_filter( $selectors, array( $this, 'filter_theme_style_selector' ) );

		if ( empty( $selectors ) ) {
			return;
		}

		$this->start_controls_section(
			$args['section_id'],
			array(
				'label' => $args['label'],
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		if ( isset( $selectors['container'] ) ) {
			$container_selector = $this->prepare_theme_style_selector( $selectors['container'] );

			if ( '' !== $container_selector ) {
				$this->add_control(
					$args['section_id'] . '_background_color',
					array(
						'label'     => __( 'Background Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$container_selector => 'background-color: {{VALUE}};',
						),
					)
				);

				$this->add_control(
					$args['section_id'] . '_border_color',
					array(
						'label'     => __( 'Border Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$container_selector => 'border-color: {{VALUE}};',
						),
					)
				);
			}
		}

		if ( isset( $selectors['heading'] ) ) {
			$heading_selector = $this->prepare_theme_style_selector( $selectors['heading'] );

			if ( '' !== $heading_selector ) {
				$this->add_control(
					$args['section_id'] . '_heading_color',
					array(
						'label'     => __( 'Heading Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$heading_selector => 'color: {{VALUE}};',
						),
					)
				);

				$this->add_group_control(
					\Elementor\Group_Control_Typography::get_type(),
					array(
						'name'     => $args['section_id'] . '_heading_typography',
						'selector' => $heading_selector,
					)
				);
			}
		}

		if ( isset( $selectors['text'] ) ) {
			$text_selector = $this->prepare_theme_style_selector( $selectors['text'] );

			if ( '' !== $text_selector ) {
				$this->add_control(
					$args['section_id'] . '_text_color',
					array(
						'label'     => __( 'Text Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$text_selector => 'color: {{VALUE}};',
						),
					)
				);

				$this->add_group_control(
					\Elementor\Group_Control_Typography::get_type(),
					array(
						'name'     => $args['section_id'] . '_text_typography',
						'selector' => $text_selector,
					)
				);
			}
		}

		if ( isset( $selectors['meta'] ) ) {
			$meta_selector = $this->prepare_theme_style_selector( $selectors['meta'] );

			if ( '' !== $meta_selector ) {
				$this->add_control(
					$args['section_id'] . '_meta_color',
					array(
						'label'     => __( 'Meta Text Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$meta_selector => 'color: {{VALUE}};',
						),
					)
				);

				$this->add_group_control(
					\Elementor\Group_Control_Typography::get_type(),
					array(
						'name'     => $args['section_id'] . '_meta_typography',
						'selector' => $meta_selector,
					)
				);
			}
		}

		if ( isset( $selectors['link'] ) ) {
			$link_selector = $this->prepare_theme_style_selector( $selectors['link'] );

			if ( '' !== $link_selector ) {
				$this->add_control(
					$args['section_id'] . '_link_color',
					array(
						'label'     => __( 'Link Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$link_selector => 'color: {{VALUE}};',
						),
					)
				);

				$this->add_group_control(
					\Elementor\Group_Control_Typography::get_type(),
					array(
						'name'     => $args['section_id'] . '_link_typography',
						'selector' => $link_selector,
					)
				);
			}

			$hover_selector = isset( $selectors['link_hover'] ) ? $this->prepare_theme_style_selector( $selectors['link_hover'] ) : '';

			if ( '' === $hover_selector && '' !== $link_selector ) {
				$hover_selector = $link_selector . ':hover';
			}

			if ( '' !== $hover_selector ) {
				$this->add_control(
					$args['section_id'] . '_link_hover_color',
					array(
						'label'     => __( 'Link Hover Color', 'wp-mcp-ai' ),
						'type'      => \Elementor\Controls_Manager::COLOR,
						'selectors' => array(
							$hover_selector => 'color: {{VALUE}};',
						),
					)
				);
			}
		}

		$this->end_controls_section();
	}

	/**
	 * Filter out empty selectors before registering controls.
	 *
	 * @param mixed $value Selector value.
	 * @return bool
	 */
	protected function filter_theme_style_selector( $value ) {
		if ( is_array( $value ) ) {
			$value = array_filter( array_map( 'trim', $value ) );

			return ! empty( $value );
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		return false;
	}

	/**
	 * Prepare a CSS selector string from the provided value.
	 *
	 * @param mixed $selector Raw selector value.
	 * @return string
	 */
	protected function prepare_theme_style_selector( $selector ) {
		if ( is_array( $selector ) ) {
			$selector = array_filter( array_map( 'trim', $selector ) );

			if ( empty( $selector ) ) {
				return '';
			}

			return implode( ', ', $selector );
		}

		if ( is_string( $selector ) ) {
			return trim( $selector );
		}

		return '';
	}
}
