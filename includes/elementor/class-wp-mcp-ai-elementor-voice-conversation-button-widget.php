<?php
/**
 * Elementor widget for voice conversation button.
 *
 * Provides a button that enables 2-way voice conversations using
 * the "interview me" pattern with orchestration.
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
 * Voice Conversation Button widget for Elementor.
 */
class WP_MCP_AI_Elementor_Voice_Conversation_Button_Widget extends \Elementor\Widget_Base {

	/**
	 * Get script dependencies.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( 'wp-mcp-ai-voice-conversation' );
	}

	/**
	 * Get style dependencies.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( 'wp-mcp-ai-voice-conversation' );
	}

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_voice_conversation_button';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Voice Conversation', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-play';
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
		return array( 'ai', 'voice', 'conversation', 'interview', 'audio', 'microphone' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_settings',
			array(
				'label' => __( 'Voice Conversation Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'assistant',
			array(
				'label'       => __( 'Assistant', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Select the assistant to use for voice conversations. Leave empty to use the default assistant.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => __( 'Button Text', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Start Voice Conversation', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter button text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'recording_text',
			array(
				'label'       => __( 'Recording Text', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Recording…', 'wp-mcp-ai' ),
				'placeholder' => __( 'Text shown while recording…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'processing_text',
			array(
				'label'       => __( 'Processing Text', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Processing…', 'wp-mcp-ai' ),
				'placeholder' => __( 'Text shown while processing…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'max_recording_duration',
			array(
				'label'       => __( 'Max Recording Duration (seconds)', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 60,
				'min'         => 5,
				'max'         => 300,
				'step'        => 5,
				'description' => __( 'Maximum recording duration in seconds.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'auto_play_response',
			array(
				'label'        => __( 'Auto-play Response', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Automatically play the AI response audio.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_transcript',
			array(
				'label'        => __( 'Show Transcript', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Display the conversation transcript.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'allow_guests',
			array(
				'label'        => __( 'Allow Guests', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'Enable guest access using temporary tokens when the assistant allows it.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		// Style section for button.
		$this->start_controls_section(
			'section_button_style',
			array(
				'label' => __( 'Button Style', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .wp-mcp-ai-voice-button',
			)
		);

		$this->start_controls_tabs( 'tabs_button_style' );

		$this->start_controls_tab(
			'tab_button_normal',
			array(
				'label' => __( 'Normal', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_background_color',
			array(
				'label'     => __( 'Background Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#3b5bff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_button_hover',
			array(
				'label' => __( 'Hover', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_background_hover_color',
			array(
				'label'     => __( 'Background Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#324cf8',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'wp-mcp-ai' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'wp-mcp-ai' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-voice-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		$options = array( '' => __( 'Default Assistant', 'wp-mcp-ai' ) );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $options;
		}

		// Check if the post type is registered before querying.
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
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
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
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$assistant_id        = ! empty( $settings['assistant'] ) ? absint( $settings['assistant'] ) : 0;
		$button_text         = ! empty( $settings['button_text'] ) ? $settings['button_text'] : __( 'Start Voice Conversation', 'wp-mcp-ai' );
		$recording_text      = ! empty( $settings['recording_text'] ) ? $settings['recording_text'] : __( 'Recording…', 'wp-mcp-ai' );
		$processing_text     = ! empty( $settings['processing_text'] ) ? $settings['processing_text'] : __( 'Processing…', 'wp-mcp-ai' );
		$max_duration        = ! empty( $settings['max_recording_duration'] ) ? absint( $settings['max_recording_duration'] ) : 60;
		$auto_play           = ! empty( $settings['auto_play_response'] ) && 'yes' === $settings['auto_play_response'];
		$show_transcript     = ! empty( $settings['show_transcript'] ) && 'yes' === $settings['show_transcript'];
		$allow_guests        = ! empty( $settings['allow_guests'] ) && 'yes' === $settings['allow_guests'];

		// Build data attributes for JavaScript.
		$data_attrs = array(
			'data-assistant-id'      => $assistant_id,
			'data-recording-text'    => esc_attr( $recording_text ),
			'data-processing-text'   => esc_attr( $processing_text ),
			'data-max-duration'      => $max_duration,
			'data-auto-play'         => $auto_play ? 'true' : 'false',
			'data-show-transcript'   => $show_transcript ? 'true' : 'false',
			'data-allow-guests'      => $allow_guests ? 'true' : 'false',
		);

		?>
		<div class="wp-mcp-ai-voice-conversation-widget">
			<button 
				class="wp-mcp-ai-voice-button" 
				<?php
				foreach ( $data_attrs as $key => $value ) {
					echo $key . '="' . $value . '" ';
				}
				?>
			>
				<span class="wp-mcp-ai-voice-button__icon">🎤</span>
				<span class="wp-mcp-ai-voice-button__text"><?php echo esc_html( $button_text ); ?></span>
			</button>
			
			<?php if ( $show_transcript ) : ?>
				<div class="wp-mcp-ai-voice-transcript" style="display: none;">
					<div class="wp-mcp-ai-voice-transcript__messages"></div>
				</div>
			<?php endif; ?>
			
			<div class="wp-mcp-ai-voice-status" style="display: none;"></div>
		</div>
		<?php
	}
}
