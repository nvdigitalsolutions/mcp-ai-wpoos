<?php
/**
 * Elementor widget for the Professional Selector.
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
 * Professional Selector Elementor widget definition.
 */
class WP_MCP_AI_Elementor_Professional_Selector_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_professional_selector';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Professional Selector', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
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
		return array( 'ai', 'chat', 'professional', 'selector', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		return array(
			WP_MCP_AI_Shortcode::SCRIPT_HANDLE,
			WP_MCP_AI_Professional_Selector_Shortcode::SCRIPT_HANDLE,
		);
	}

	/**
	 * Declare style dependencies for this widget.
	 *
	 * @return array List of style handles this widget depends on.
	 */
	public function get_style_depends() {
		return array(
			WP_MCP_AI_Shortcode::STYLE_HANDLE,
			WP_MCP_AI_Professional_Selector_Shortcode::STYLE_HANDLE,
		);
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_settings',
			array(
				'label' => __( 'Professional Selector Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'default_professional',
			array(
				'label'       => __( 'Default Professional', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_professional_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Pre-select a professional. Leave empty for no default.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'default_provider',
			array(
				'label'       => __( 'Default Provider', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_provider_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Pre-select an AI provider. Leave empty for no default.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'default_model',
			array(
				'label'       => __( 'Default Model', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Pre-select a model ID. Leave empty for no default.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_temperature',
			array(
				'label'        => __( 'Show Temperature Control', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'false',
				'description'  => __( 'Allow users to adjust the temperature setting.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_chat_settings',
			array(
				'label' => __( 'Chat Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'allow_guests',
			array(
				'label'        => __( 'Allow Guests', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'false',
				'description'  => __( 'Enable guest access using temporary tokens.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'save_transcript',
			array(
				'label'        => __( 'Save transcripts to JetEngine', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'true',
				'description'  => __( 'Store chat requests and responses in the ai_chat_transcripts Custom Content Type.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'enable_streaming',
			array(
				'label'        => __( 'Enable SSE Streaming', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'true',
				'description'  => __( 'Enable Server-Sent Events (SSE) streaming for faster perceived response times.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'allow_sensitive_tools',
			array(
				'label'        => __( 'Allow Sensitive Tools', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'false',
				'description'  => __( 'Allow the assistant to use sensitive tools that may modify site content or settings.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'template',
			array(
				'label'       => __( 'Chat Template', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'classic'        => __( 'Classic', 'wp-mcp-ai' ),
					'speech-bubbles' => __( 'Speech Bubbles', 'wp-mcp-ai' ),
					'compact'        => __( 'Compact', 'wp-mcp-ai' ),
					'sidebar'        => __( 'Sidebar', 'wp-mcp-ai' ),
				),
				'default'     => 'classic',
				'label_block' => true,
				'description' => __( 'Select the visual template for the chat interface.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Retrieve the available professionals as select options.
	 *
	 * @return array
	 */
	protected function get_professional_options() {
		$options = array( '' => __( 'No Default', 'wp-mcp-ai' ) );

		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return $options;
		}

		$professionals = get_posts(
			array(
				'post_type'              => 'mcp_ai_profession',
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		if ( ! is_array( $professionals ) || empty( $professionals ) ) {
			return $options;
		}

		foreach ( $professionals as $professional_id ) {
			$title = get_the_title( $professional_id );
			if ( $title && ! is_wp_error( $title ) ) {
				$options[ (string) $professional_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Get available AI providers.
	 *
	 * @return array
	 */
	protected function get_provider_options() {
		$providers = apply_filters(
			'wp_mcp_ai_allowed_providers',
			array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio' )
		);

		$labels = array(
			'openai'      => __( 'OpenAI', 'wp-mcp-ai' ),
			'anthropic'   => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
			'gemini'      => __( 'Google Gemini', 'wp-mcp-ai' ),
			'huggingface' => __( 'Hugging Face', 'wp-mcp-ai' ),
			'ollama'      => __( 'Ollama (Local)', 'wp-mcp-ai' ),
			'lm_studio'   => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
		);

		$options = array( '' => __( 'No Default', 'wp-mcp-ai' ) );

		foreach ( $providers as $provider ) {
			$provider                = sanitize_key( $provider );
			$options[ $provider ] = isset( $labels[ $provider ] )
				? $labels[ $provider ]
				: ucfirst( str_replace( '_', ' ', $provider ) );
		}

		return $options;
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$attributes = array();

		if ( ! empty( $settings['default_professional'] ) ) {
			$attributes['default_professional'] = sanitize_text_field( $settings['default_professional'] );
		}

		if ( ! empty( $settings['default_provider'] ) ) {
			$attributes['default_provider'] = sanitize_key( $settings['default_provider'] );
		}

		if ( ! empty( $settings['default_model'] ) ) {
			$attributes['default_model'] = sanitize_text_field( $settings['default_model'] );
		}

		$show_temperature               = ! empty( $settings['show_temperature'] ) && 'true' === $settings['show_temperature'];
		$attributes['show_temperature'] = $show_temperature ? 'true' : 'false';

		$allow_guests               = ! empty( $settings['allow_guests'] ) && 'true' === $settings['allow_guests'];
		$attributes['allow_guests'] = $allow_guests ? 'true' : 'false';

		$save_transcript = empty( $settings['save_transcript'] ) || 'true' === $settings['save_transcript'];
		if ( ! $save_transcript ) {
			$attributes['save_transcript'] = 'false';
		}

		$enable_streaming = ! empty( $settings['enable_streaming'] ) && 'true' === $settings['enable_streaming'];
		if ( $enable_streaming ) {
			$attributes['enable_streaming'] = 'true';
		}

		$allow_sensitive_tools = ! empty( $settings['allow_sensitive_tools'] ) && 'true' === $settings['allow_sensitive_tools'];
		if ( $allow_sensitive_tools ) {
			$attributes['allow_sensitive_tools'] = 'true';
		}

		$template = isset( $settings['template'] ) ? sanitize_key( $settings['template'] ) : 'classic';
		if ( 'classic' !== $template ) {
			$attributes['template'] = $template;
		}

		// Build shortcode string.
		$shortcode = '[' . WP_MCP_AI_Professional_Selector_Shortcode::SHORTCODE;

		foreach ( $attributes as $key => $value ) {
			$shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		$shortcode .= ']';

		echo '<div class="wp-mcp-ai-professional-selector-widget">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode handles escaping.
		echo do_shortcode( $shortcode );
		echo '</div>';
	}
}
