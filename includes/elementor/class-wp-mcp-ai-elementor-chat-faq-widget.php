<?php
/**
 * Elementor widget for displaying a FAQ block alongside the chat interface.
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
 * Elementor widget definition for chat FAQs.
 */
class WP_MCP_AI_Elementor_Chat_FAQ_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_chat_faq';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Chat FAQ', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-question';
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
		return array( 'ai', 'chat', 'faq', 'support', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'FAQ Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'How the chat works', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'question',
			array(
				'label'       => __( 'Question', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'What can I ask the assistant?', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'answer',
			array(
				'label'   => __( 'Answer', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'The assistant can draft plans, generate summaries, and connect to MCP tools you have enabled.', 'wp-mcp-ai' ),
				'rows'    => 4,
			)
		);

		$this->add_control(
			'faq_items',
			array(
				'label'       => __( 'FAQ Items', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'question' => __( 'Do I need to sign in to chat?', 'wp-mcp-ai' ),
						'answer'   => __( 'Guests can start chatting when temporary tokens are allowed, or you can require authenticated users only.', 'wp-mcp-ai' ),
					),
					array(
						'question' => __( 'How do I provide more context?', 'wp-mcp-ai' ),
						'answer'   => __( 'Upload files or paste notes directly into the conversation to give the assistant additional detail.', 'wp-mcp-ai' ),
					),
				),
				'title_field' => '{{{ question }}}',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_chat_faq',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-chat-faq',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-faq__title',
						'{{WRAPPER}} .wp-mcp-ai-chat-faq__question',
					),
					'text'      => '{{WRAPPER}} .wp-mcp-ai-chat-faq__answer',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-chat-faq a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		$items = isset( $settings['faq_items'] ) ? $settings['faq_items'] : array();

		if ( empty( $title ) && empty( $items ) ) {
			return;
		}

		echo '<div class="wp-mcp-ai-chat-faq">';

		if ( ! empty( $title ) ) {
			echo '<h2 class="wp-mcp-ai-chat-faq__title">' . esc_html( $title ) . '</h2>';
		}

		if ( ! empty( $items ) && is_array( $items ) ) {
			echo '<dl class="wp-mcp-ai-chat-faq__list">';
			foreach ( $items as $item ) {
				if ( empty( $item['question'] ) && empty( $item['answer'] ) ) {
					continue;
				}

				if ( ! empty( $item['question'] ) ) {
					echo '<dt class="wp-mcp-ai-chat-faq__question">' . esc_html( $item['question'] ) . '</dt>';
				}

				if ( ! empty( $item['answer'] ) ) {
					$answer = $this->format_text_block( $item['answer'] );

					if ( '' !== $answer ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
						echo '<dd class="wp-mcp-ai-chat-faq__answer">' . $answer . '</dd>';
					}
				}
			}
			echo '</dl>';
		}

		echo '</div>';
	}
}
