<?php
/**
 * Elementor widget for surfacing provider quick links.
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
 * Elementor widget definition for the provider quick links block.
 */
class WP_MCP_AI_Elementor_Dashboard_Provider_Links_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_provider_links';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Provider Quick Links', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-external-link-square';
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
		return array( 'mcp', 'provider', 'openai', 'links', 'dashboard' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Quick Links', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Provider quick links', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Jump straight to billing and request logs for rapid diagnostics.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_provider_links',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-provider-links',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-provider-links__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-provider-links__description',
						'{{WRAPPER}} .wp-mcp-ai-provider-links__notice',
					),
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-provider-links__card-description',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-provider-links__card',
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

		$links = $this->collect_links();

		echo '<div class="wp-mcp-ai-provider-links">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-provider-links__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-provider-links__description">' . $description_output . '</div>';
			}
		}

		if ( empty( $links ) ) {
			echo '<p class="wp-mcp-ai-provider-links__notice">' . esc_html__( 'No quick links are available for the current user.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wp-mcp-ai-provider-links__grid">';
		foreach ( $links as $link ) {
			$target = ! empty( $link['is_external'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $target is a safe literal string.
			echo '<a class="wp-mcp-ai-provider-links__card" href="' . esc_url( $link['url'] ) . '"' . $target . '>';
			echo '<span class="wp-mcp-ai-provider-links__card-title">' . esc_html( $link['label'] ) . '</span>';
			if ( ! empty( $link['description'] ) ) {
				echo '<span class="wp-mcp-ai-provider-links__card-description">' . esc_html( $link['description'] ) . '</span>';
			}
			echo '</a>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Gather quick links using the existing tool implementations.
	 *
	 * @return array
	 */
	protected function collect_links() {
		$links   = array();
		$context = array( 'user_id' => get_current_user_id() );

		if ( class_exists( 'WP_MCP_AI_Tool_Open_OpenAI_Usage' ) ) {
			$usage_tool = new WP_MCP_AI_Tool_Open_OpenAI_Usage();
			$usage      = $usage_tool->execute( array(), $context );
			$link       = $this->normalise_link_result( $usage );
			if ( ! empty( $link ) ) {
				$links[] = $link;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Tool_Open_OpenAI_Logs' ) ) {
			$logs_tool = new WP_MCP_AI_Tool_Open_OpenAI_Logs();
			$logs      = $logs_tool->execute( array(), $context );
			$link      = $this->normalise_link_result( $logs );
			if ( ! empty( $link ) ) {
				$links[] = $link;
			}
		}

		/**
		 * Filter the quick links displayed in the provider widget.
		 *
		 * @param array $links   Prepared link array.
		 * @param array $context Execution context including user ID.
		 */
		return apply_filters( 'wp_mcp_ai_provider_links_widget_links', $links, $context );
	}

	/**
	 * Normalise the tool result into a link structure.
	 *
	 * @param mixed $result Tool execution result.
	 * @return array
	 */
	protected function normalise_link_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return array();
		}

		if ( ! is_array( $result ) || empty( $result['url'] ) ) {
			return array();
		}

		return array(
			'label'       => isset( $result['label'] ) ? (string) $result['label'] : __( 'Open link', 'wp-mcp-ai' ),
			'url'         => esc_url_raw( $result['url'] ),
			'description' => isset( $result['description'] ) ? (string) $result['description'] : '',
			'is_external' => true,
		);
	}
}
