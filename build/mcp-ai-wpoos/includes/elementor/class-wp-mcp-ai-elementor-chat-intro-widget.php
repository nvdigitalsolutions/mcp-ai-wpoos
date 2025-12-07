<?php
/**
 * Elementor widget for displaying an introductory block above the chat interface.
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
 * Elementor widget definition for chat introduction content.
 */
class WP_MCP_AI_Elementor_Chat_Intro_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_chat_intro';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Chat Intro', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-info-box';
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
		return array( 'ai', 'chat', 'intro', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Intro Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Welcome to WP oOS Chat', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => __( 'Description', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Start a conversation with your AI assistant to plan tasks, explore MCP tools, or keep track of ongoing projects.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Provide a short introduction for visitors.', 'wp-mcp-ai' ),
				'rows'        => 4,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'item_text',
			array(
				'label'       => __( 'Talking Point', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Ask for status updates on active tasks', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'talking_points',
			array(
				'label'       => __( 'Talking Points', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'item_text' => __( 'Summarise the latest plan from your assistant', 'wp-mcp-ai' ),
					),
					array(
						'item_text' => __( 'Request follow-up actions or clarifications', 'wp-mcp-ai' ),
					),
				),
				'title_field' => '{{{ item_text }}}',
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => __( 'Button Text', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Open Chat', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter call-to-action text…', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'button_link',
			array(
				'label'       => __( 'Button Link', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'placeholder' => __( 'https://example.com', 'wp-mcp-ai' ),
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_chat_intro',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-chat-intro',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-chat-intro__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-intro__description',
						'{{WRAPPER}} .wp-mcp-ai-chat-intro__talking-points',
					),
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-chat-intro__talking-point',
					'link'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-intro__button',
						'{{WRAPPER}} .wp-mcp-ai-chat-intro a',
					),
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title       = isset( $settings['title'] ) ? $settings['title'] : '';
		$description = isset( $settings['description'] ) ? $settings['description'] : '';
		$points      = isset( $settings['talking_points'] ) ? $settings['talking_points'] : array();
		$button_text = isset( $settings['button_text'] ) ? $settings['button_text'] : '';
		$button_link = isset( $settings['button_link'] ) ? $settings['button_link'] : array();

		echo '<div class="wp-mcp-ai-chat-intro">';

		if ( ! empty( $title ) ) {
			echo '<h2 class="wp-mcp-ai-chat-intro__title">' . esc_html( $title ) . '</h2>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-chat-intro__description">' . $description_output . '</div>';
			}
		}

		if ( ! empty( $points ) && is_array( $points ) ) {
			echo '<ul class="wp-mcp-ai-chat-intro__list">';
			foreach ( $points as $point ) {
				if ( empty( $point['item_text'] ) ) {
					continue;
				}

				echo '<li class="wp-mcp-ai-chat-intro__list-item">' . esc_html( $point['item_text'] ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $button_text ) && ! empty( $button_link['url'] ) ) {
			$this->render_button( $button_text, $button_link );
		}

		echo '</div>';
	}

	/**
	 * Render the optional call-to-action button.
	 *
	 * @param string $button_text Button label.
	 * @param array  $button_link Link settings from Elementor.
	 */
	protected function render_button( $button_text, $button_link ) {
		$url         = isset( $button_link['url'] ) ? $button_link['url'] : '';
		$is_external = ! empty( $button_link['is_external'] );
		$nofollow    = ! empty( $button_link['nofollow'] );

		if ( empty( $url ) ) {
			return;
		}

		$attributes = array( 'href="' . esc_url( $url ) . '"' );

		if ( $is_external ) {
			$attributes[] = 'target="_blank"';
		}

		$rel = array();

		if ( $nofollow ) {
			$rel[] = 'nofollow';
		}

		if ( $is_external ) {
			$rel[] = 'noopener';
		}

		if ( ! empty( $rel ) ) {
			$attributes[] = 'rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"';
		}

		printf(
			'<a class="wp-mcp-ai-chat-intro__button" %1$s>%2$s</a>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All attributes are escaped before being added to array.
			implode( ' ', $attributes ),
			esc_html( $button_text )
		);
	}
}
