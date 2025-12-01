<?php
/**
 * Elementor widget for previewing the configured chat theme tokens.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget definition for the theme and branding preview.
 */
class WP_MCP_AI_Elementor_Dashboard_Theme_Preview_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_theme_preview';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Theme Preview', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-color-picker';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'mcp', 'theme', 'branding', 'preview', 'colors' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Preview Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Chat theme preview', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Visualise the current chat tokens to confirm bubble, container, and status styling.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_token_legend',
			array(
				'label'        => __( 'Show token legend', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_theme_preview',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-theme-preview',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-theme-preview__title',
						'{{WRAPPER}} .wp-mcp-ai-theme-preview__legend-title',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-theme-preview__description',
						'{{WRAPPER}} .wp-mcp-ai-theme-preview__legend-label',
						'{{WRAPPER}} .wp-mcp-ai-theme-preview__legend-value',
					),
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-theme-preview__legend-token',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-theme-preview a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title         = isset( $settings['title'] ) ? $settings['title'] : '';
		$description   = isset( $settings['description'] ) ? $settings['description'] : '';
		$show_legend   = ! empty( $settings['show_token_legend'] ) && 'yes' === $settings['show_token_legend'];
		$colors        = $this->get_chat_colors();
		$definitions   = $this->get_color_definitions();
		$grouped       = $this->group_color_tokens( $definitions, $colors );
		$container_css = $this->build_container_style( $colors );

		echo '<div class="wp-mcp-ai-theme-preview">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-theme-preview__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				echo '<div class="wp-mcp-ai-theme-preview__description">' . $description_output . '</div>';
			}
		}

		echo '<div class="wp-mcp-ai-theme-preview__chat"' . $container_css . '>';
		echo $this->render_sample_message( __( 'Assistant response preview', 'wp-mcp-ai' ), 'assistant', $colors );
		echo $this->render_sample_message( __( 'User confirmation bubble', 'wp-mcp-ai' ), 'user', $colors );
		echo $this->render_sample_message( __( 'Tool summary example', 'wp-mcp-ai' ), 'tool', $colors );
		echo $this->render_sample_message( __( 'System status notice', 'wp-mcp-ai' ), 'system', $colors );
		echo '</div>';

		if ( $show_legend && ! empty( $grouped ) ) {
			echo '<div class="wp-mcp-ai-theme-preview__legend">';
			foreach ( $grouped as $group => $tokens ) {
				echo '<div class="wp-mcp-ai-theme-preview__legend-group">';
				echo '<h4 class="wp-mcp-ai-theme-preview__legend-title">' . esc_html( $group ) . '</h4>';
				echo '<ul class="wp-mcp-ai-theme-preview__legend-list">';
				foreach ( $tokens as $token ) {
					$swatch_style = '';
					if ( '' !== $token['value'] ) {
						$swatch_style = ' style="background:' . esc_attr( $token['value'] ) . ';"';
					}
					echo '<li class="wp-mcp-ai-theme-preview__legend-item">';
					echo '<span class="wp-mcp-ai-theme-preview__legend-swatch"' . $swatch_style . '></span>';
					echo '<span class="wp-mcp-ai-theme-preview__legend-token"><code>' . esc_html( $token['token'] ) . '</code></span>';
					echo '<span class="wp-mcp-ai-theme-preview__legend-label">' . esc_html( $token['label'] ) . '</span>';
					if ( '' !== $token['value'] ) {
						echo '<span class="wp-mcp-ai-theme-preview__legend-separator">: </span>';
						echo '<span class="wp-mcp-ai-theme-preview__legend-value">' . esc_html( $token['value'] ) . '</span>';
					}
					echo '</li>';
				}
				echo '</ul>';
				echo '</div>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Retrieve merged chat colors.
	 *
	 * @return array
	 */
	protected function get_chat_colors() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return WP_MCP_AI_Admin_Settings::get_chat_colors();
		}

		return array();
	}

	/**
	 * Retrieve color definitions.
	 *
	 * @return array
	 */
	protected function get_color_definitions() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return WP_MCP_AI_Admin_Settings::get_chat_color_definitions();
		}

		return array();
	}

	/**
	 * Group tokens by their definition group.
	 *
	 * @param array $definitions Token definitions.
	 * @param array $colors      Saved colors.
	 * @return array
	 */
	protected function group_color_tokens( $definitions, $colors ) {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return array();
		}

		$groups  = WP_MCP_AI_Admin_Settings::get_chat_color_groups();
		$grouped = array();

		foreach ( $definitions as $token => $definition ) {
			$group_id = isset( $definition['group'] ) ? $definition['group'] : 'other';
			$group    = isset( $groups[ $group_id ] ) ? $groups[ $group_id ] : $group_id;

			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}

			$grouped[ $group ][] = array(
				'token' => $token,
				'label' => isset( $definition['label'] ) ? $definition['label'] : $token,
				'value' => isset( $colors[ $token ] ) ? $colors[ $token ] : '',
			);
		}

		return $grouped;
	}

	/**
	 * Build a style attribute for the container preview.
	 *
	 * @param array $colors Saved colors.
	 * @return string
	 */
	protected function build_container_style( $colors ) {
		$styles = array();

		if ( ! empty( $colors['container-background'] ) ) {
			$styles[] = 'background:' . $colors['container-background'];
		}

		if ( ! empty( $colors['container-border'] ) ) {
			$styles[] = 'border:1px solid ' . $colors['container-border'];
		}

		if ( ! empty( $colors['container-shadow'] ) ) {
			$styles[] = 'box-shadow:0 10px 20px -12px ' . $colors['container-shadow'];
		}

		if ( empty( $styles ) ) {
			return '';
		}

		return ' style="' . esc_attr( implode( ';', $styles ) ) . '"';
	}

	/**
	 * Render a sample message bubble.
	 *
	 * @param string $label  Bubble label.
	 * @param string $type   Bubble type (assistant|user|tool|system).
	 * @param array  $colors Saved colors.
	 * @return string
	 */
	protected function render_sample_message( $label, $type, $colors ) {
		$styles = array();

		switch ( $type ) {
			case 'user':
				if ( ! empty( $colors['user-bubble-gradient-start'] ) && ! empty( $colors['user-bubble-gradient-end'] ) ) {
					$styles[] = 'background-image:linear-gradient(135deg,' . $colors['user-bubble-gradient-start'] . ',' . $colors['user-bubble-gradient-end'] . ')';
				} elseif ( ! empty( $colors['user-bubble-gradient-start'] ) ) {
					$styles[] = 'background:' . $colors['user-bubble-gradient-start'];
				}
				if ( ! empty( $colors['user-bubble-text'] ) ) {
					$styles[] = 'color:' . $colors['user-bubble-text'];
				}
				if ( ! empty( $colors['user-bubble-shadow'] ) ) {
					$styles[] = 'box-shadow:0 12px 24px -16px ' . $colors['user-bubble-shadow'];
				}
				break;
			case 'tool':
				if ( ! empty( $colors['tool-bubble-background'] ) ) {
					$styles[] = 'background:' . $colors['tool-bubble-background'];
				}
				if ( ! empty( $colors['tool-bubble-text'] ) ) {
					$styles[] = 'color:' . $colors['tool-bubble-text'];
				}
				if ( ! empty( $colors['tool-bubble-border'] ) ) {
					$styles[] = 'border:1px solid ' . $colors['tool-bubble-border'];
				}
				if ( ! empty( $colors['tool-bubble-inner-shadow'] ) ) {
					$styles[] = 'box-shadow:inset 0 0 0 1px ' . $colors['tool-bubble-inner-shadow'];
				}
				break;
			case 'system':
				if ( ! empty( $colors['system-bubble-background'] ) ) {
					$styles[] = 'background:' . $colors['system-bubble-background'];
				}
				if ( ! empty( $colors['system-bubble-text'] ) ) {
					$styles[] = 'color:' . $colors['system-bubble-text'];
				}
				if ( ! empty( $colors['system-bubble-border'] ) ) {
					$styles[] = 'border:1px solid ' . $colors['system-bubble-border'];
				}
				break;
			case 'assistant':
			default:
				if ( ! empty( $colors['bubble-neutral-background'] ) ) {
					$styles[] = 'background:' . $colors['bubble-neutral-background'];
				}
				if ( ! empty( $colors['bubble-neutral-text'] ) ) {
					$styles[] = 'color:' . $colors['bubble-neutral-text'];
				}
				if ( ! empty( $colors['bubble-neutral-border'] ) ) {
					$styles[] = 'border:1px solid ' . $colors['bubble-neutral-border'];
				}
				if ( ! empty( $colors['bubble-neutral-shadow'] ) ) {
					$styles[] = 'box-shadow:0 8px 18px -16px ' . $colors['bubble-neutral-shadow'];
				}
				break;
		}

		$class = 'wp-mcp-ai-theme-preview__bubble wp-mcp-ai-theme-preview__bubble--' . $type;
		$style = empty( $styles ) ? '' : ' style="' . esc_attr( implode( ';', $styles ) ) . '"';

		return '<div class="' . esc_attr( $class ) . '"' . $style . '><span class="wp-mcp-ai-theme-preview__bubble-label">' . esc_html( $label ) . '</span></div>';
	}
}
