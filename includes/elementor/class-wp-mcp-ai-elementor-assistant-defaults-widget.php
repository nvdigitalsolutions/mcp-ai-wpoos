<?php
/**
 * Elementor widget for surfacing an assistant's default model settings.
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
 * Elementor widget definition for assistant defaults.
 */
class WP_MCP_AI_Elementor_Assistant_Defaults_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_assistant_defaults';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Assistant Defaults', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-settings';
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
		return array( 'assistant', 'defaults', 'model', 'settings', 'mcp', 'ai' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant model defaults', 'mcp-ai-wpoos' ),
				'placeholder' => __( 'Enter heading text…', 'mcp-ai-wpoos' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'assistant_id',
			array(
				'label'       => __( 'Assistant', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Choose which assistant to display defaults for. Only published assistants appear in this list.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'show_system_prompt',
			array(
				'label'        => __( 'Show system prompt', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'       => __( 'Empty state message', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant in the widget settings to view its defaults.', 'mcp-ai-wpoos' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'mcp-ai-wpoos' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_assistant_defaults',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-assistant-defaults',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-defaults__title',
						'{{WRAPPER}} .wp-mcp-ai-assistant-defaults__system-prompt-heading',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-defaults__notice',
						'{{WRAPPER}} .wp-mcp-ai-assistant-defaults__value',
						'{{WRAPPER}} .wp-mcp-ai-assistant-defaults__system-prompt-content',
					),
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-assistant-defaults__label',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-assistant-defaults a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title             = isset( $settings['title'] ) ? $settings['title'] : '';
		$assistant_setting = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : '';
		$assistant_id      = '' !== $assistant_setting ? absint( $assistant_setting ) : 0;
		$show_prompt       = ! empty( $settings['show_system_prompt'] ) && 'yes' === $settings['show_system_prompt'];
		$empty_message     = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';

		echo '<div class="wp-mcp-ai-assistant-defaults">';

		if ( '' !== $title ) {
			$title_output = $this->format_text_inline( $title );

			if ( '' !== $title_output ) {
				echo '<h3 class="wp-mcp-ai-assistant-defaults__title">' . wp_kses_post( $title_output ) . '</h3>';
			}
		}

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$empty_output = $this->format_text_inline( $empty_message );

			if ( '' !== $empty_output ) {
				echo '<p class="wp-mcp-ai-assistant-defaults__notice">' . $empty_output . '</p>';
			}

			echo '</div>';
			return;
		}

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$provider_label = $this->get_provider_label( isset( $config['provider'] ) ? $config['provider'] : '' );
		$model          = isset( $config['model'] ) ? $config['model'] : '';
		$temperature    = isset( $config['temperature'] ) ? $config['temperature'] : null;
		$prompt         = isset( $config['system_prompt'] ) ? $config['system_prompt'] : '';

		echo '<dl class="wp-mcp-ai-assistant-defaults__list">';

		if ( '' !== $provider_label ) {
			echo '<dt class="wp-mcp-ai-assistant-defaults__label">' . esc_html__( 'Provider', 'mcp-ai-wpoos' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-assistant-defaults__value">' . esc_html( $provider_label ) . '</dd>';
		}

		if ( '' !== $model ) {
			echo '<dt class="wp-mcp-ai-assistant-defaults__label">' . esc_html__( 'Model', 'mcp-ai-wpoos' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-assistant-defaults__value">' . esc_html( $model ) . '</dd>';
		}

		if ( null !== $temperature && '' !== $temperature ) {
			$temperature_value = number_format_i18n( floatval( $temperature ), 2 );
			echo '<dt class="wp-mcp-ai-assistant-defaults__label">' . esc_html__( 'Temperature', 'mcp-ai-wpoos' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-assistant-defaults__value">' . esc_html( $temperature_value ) . '</dd>';
		}

		echo '</dl>';

		if ( $show_prompt ) {
			$prompt_output = $this->format_text_block( $prompt );

			if ( '' !== $prompt_output ) {
				echo '<div class="wp-mcp-ai-assistant-defaults__system-prompt">';
				echo '<h4 class="wp-mcp-ai-assistant-defaults__system-prompt-heading">' . esc_html__( 'System prompt', 'mcp-ai-wpoos' ) . '</h4>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-assistant-defaults__system-prompt-content">' . $prompt_output . '</div>';
				echo '</div>';
			}
		}

		echo '</div>';
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		$options = array( '' => __( 'Select an assistant', 'mcp-ai-wpoos' ) );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $options;
		}

		// Check if the post type is registered before querying.
		// During Elementor AJAX requests, the post type may not be registered yet.
		if ( ! post_type_exists( WP_MCP_AI_Assistant_CPT::POST_TYPE ) ) {
			return $options;
		}

		$assistants = get_posts(
			array(
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'fields'                 => 'ids',
				'no_found_rows'          => true,  // Performance: Skip counting total rows.
				'update_post_term_cache' => false, // Performance: Skip term cache.
			)
		);

		if ( ! is_array( $assistants ) || empty( $assistants ) ) {
			return $options;
		}

		foreach ( $assistants as $assistant_id ) {
			$title = get_the_title( $assistant_id );
			if ( $title && ! is_wp_error( $title ) ) {
				$options[ (string) $assistant_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Convert the provider slug into a readable label.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	protected function get_provider_label( $provider ) {
		$provider = sanitize_key( $provider );

		if ( '' === $provider ) {
			return '';
		}

		switch ( $provider ) {
			case 'openai':
				return __( 'OpenAI', 'mcp-ai-wpoos' );
			case 'gemini':
				return __( 'Gemini', 'mcp-ai-wpoos' );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );
	}
}
