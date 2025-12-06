<?php
/**
 * Elementor widget for rendering the WP oOS chat shortcode.
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
 * Elementor widget definition.
 */
class WP_MCP_AI_Elementor_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_chat';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Chat', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-ai';
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
		return array( 'ai', 'chat', 'assistant', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		return array( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
	}

	/**
	 * Declare style dependencies for this widget.
	 *
	 * @return array List of style handles this widget depends on.
	 */
	public function get_style_depends() {
		return array( WP_MCP_AI_Shortcode::STYLE_HANDLE );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_settings',
			array(
				'label' => __( 'Chat Settings', 'wp-mcp-ai' ),
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
				'description' => __( 'Select the assistant to use. Leave empty to use the default assistant configured in the plugin settings.', 'wp-mcp-ai' ),
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
				'description'  => __( 'Enable guest access using temporary tokens when the assistant allows it.', 'wp-mcp-ai' ),
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
				'description'  => __( 'Enable Server-Sent Events (SSE) streaming for faster perceived response times. Responses will appear progressively as they are generated.', 'wp-mcp-ai' ),
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
				'description'  => __( 'Allow the assistant to use sensitive tools that may modify site content or settings. Only enable if you trust the assistant configuration.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_assistant_defaults',
			array(
				'label' => __( 'Assistant Defaults', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_assistant_defaults',
			array(
				'label'        => __( 'Show assistant defaults', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'Display the selected assistant\'s provider, model, temperature, and system prompt above the chat UI.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'assistant_defaults_title',
			array(
				'label'       => __( 'Defaults heading', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant model defaults', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_defaults' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_defaults_show_prompt',
			array(
				'label'        => __( 'Show system prompt', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_assistant_defaults' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_defaults_empty_message',
			array(
				'label'       => __( 'Defaults empty message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant to view its default configuration.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_defaults' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_assistant_knowledge',
			array(
				'label' => __( 'Assistant Memory', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_assistant_base_knowledge',
			array(
				'label'        => __( 'Show memory files', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'List the media files and vector store assigned to the assistant for retrieval-augmented responses.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'assistant_base_knowledge_title',
			array(
				'label'       => __( 'Memory heading', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant knowledge base', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_base_knowledge' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_base_knowledge_show_sizes',
			array(
				'label'        => __( 'Show file sizes', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_assistant_base_knowledge' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_base_knowledge_empty_message',
			array(
				'label'       => __( 'Memory empty message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant to audit its knowledge base.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_base_knowledge' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_base_knowledge_no_files_message',
			array(
				'label'       => __( 'No files message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No base knowledge files have been attached to this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no knowledge files are present…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_base_knowledge' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_assistant_tools',
			array(
				'label' => __( 'Assistant Tools', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_assistant_tools',
			array(
				'label'        => __( 'Show assigned tools', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'List the registered tools that are enabled for the assistant alongside any missing registrations.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'assistant_tools_title',
			array(
				'label'       => __( 'Tools heading', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Available assistant tools', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_tools' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_tools_show_descriptions',
			array(
				'label'        => __( 'Show descriptions', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_assistant_tools' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_tools_empty_message',
			array(
				'label'       => __( 'No tools message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No tools have been assigned to this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no tools are available…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_tools' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_tools_registry_message',
			array(
				'label'       => __( 'Registry offline message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'The tool registry is currently unavailable.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when the registry cannot be reached…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_tools' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_assistant_shortcuts',
			array(
				'label' => __( 'Prompt Shortcuts', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_assistant_prompt_shortcuts',
			array(
				'label'        => __( 'Show prompt shortcuts', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'Surface the saved shortcut labels, descriptions, and payloads next to the chat interface.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_title',
			array(
				'label'       => __( 'Prompt shortcuts heading', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant prompt shortcuts', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_show_descriptions',
			array(
				'label'        => __( 'Show descriptions', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_show_prompt',
			array(
				'label'        => __( 'Show prompt payload', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'condition'    => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_show_tools',
			array(
				'label'        => __( 'Show tool context', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_empty_message',
			array(
				'label'       => __( 'Shortcuts empty message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant to view its prompt shortcuts.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->add_control(
			'assistant_prompt_shortcuts_none_message',
			array(
				'label'       => __( 'No shortcuts message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No prompt shortcuts have been saved for this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no shortcuts exist…', 'wp-mcp-ai' ),
				'label_block' => true,
				'condition'   => array( 'show_assistant_prompt_shortcuts' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_theme_notice',
			array(
				'label' => __( 'Theme Settings', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_theme_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => $this->get_chat_theme_notice(),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_chat_widget',
				'label'      => __( 'Supporting Sections', 'wp-mcp-ai' ),
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-chat-widget',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget h3',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget h4',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget p',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget li',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget dd',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget span',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget__assistant-memory-file-size',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget__assistant-tools-description',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget__assistant-shortcuts-description',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget__assistant-shortcuts-payload',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-chat-widget a',
				),
			)
		);

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_chat_interface',
				'label'      => __( 'Chat Section', 'wp-mcp-ai' ),
				'selectors'  => array(
					'container'  => '{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat',
					'heading'    => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__label',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-header',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h1',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h2',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h3',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h4',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h5',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble h6',
					),
					'text'       => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__assistant-content',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__status',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-name',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-remove',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__json-icon',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__json-label',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__json-content',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__message.wp-mcp-ai-chat__message--assistant',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__message.wp-mcp-ai-chat__message--user',
					),
					'meta'       => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-meta',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble-attachments',
					),
					'link'       => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble a',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments a',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-remove',
					),
					'link_hover' => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble a:hover',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__bubble a:focus',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-remove:hover',
						'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__attachments-remove:focus',
					),
				),
			)
		);

		$bubble_selector = '{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat';

		$this->start_controls_section(
			'section_style_chat_user_bubble',
			array(
				'label' => __( 'User Bubble', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_user_bubble_gradient_start',
			array(
				'label'     => __( 'Gradient Start', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#2747f0',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-user-bubble-gradient-start: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_user_bubble_gradient_end',
			array(
				'label'     => __( 'Gradient End', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#4855f5',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-user-bubble-gradient-end: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_user_bubble_text_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-user-bubble-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_user_bubble_shadow_color',
			array(
				'label'     => __( 'Shadow Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(39, 71, 240, 0.35)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-user-bubble-shadow: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_assistant_bubble',
			array(
				'label' => __( 'Assistant Bubble', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_assistant_bubble_background',
			array(
				'label'     => __( 'Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f8faff',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-assistant-bubble-background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_assistant_bubble_border',
			array(
				'label'     => __( 'Border', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(59, 130, 246, 0.25)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-assistant-bubble-border: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_assistant_bubble_shadow',
			array(
				'label'     => __( 'Shadow', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(59, 130, 246, 0.08)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-assistant-bubble-shadow: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_assistant_bubble_strong_text',
			array(
				'label'     => __( 'Strong Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1d4ed8',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-assistant-strong-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_assistant_bubble_em_text',
			array(
				'label'     => __( 'Emphasised Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#4338ca',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-assistant-em-text: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_tool_bubble',
			array(
				'label' => __( 'Tool Bubble', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_tool_bubble_background',
			array(
				'label'     => __( 'Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0f172a',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-bubble-background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_bubble_text',
			array(
				'label'     => __( 'Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#e2e8f0',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-bubble-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_bubble_border',
			array(
				'label'     => __( 'Border', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(96, 165, 250, 0.35)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-bubble-border: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_bubble_inner_shadow',
			array(
				'label'     => __( 'Inner Shadow', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(30, 64, 175, 0.4)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-bubble-inner-shadow: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_bubble_link_text',
			array(
				'label'     => __( 'Link Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#93c5fd',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-bubble-link-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_code_background',
			array(
				'label'     => __( 'Code Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(148, 163, 184, 0.18)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-code-background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_tool_code_text',
			array(
				'label'     => __( 'Code Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#f8fafc',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-tool-code-text: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_system_bubble',
			array(
				'label' => __( 'System Bubble', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_system_bubble_background',
			array(
				'label'     => __( 'Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fef9c3',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-system-bubble-background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_system_bubble_text',
			array(
				'label'     => __( 'Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#854d0e',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-system-bubble-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_system_bubble_border',
			array(
				'label'     => __( 'Border', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#facc15',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-system-bubble-border: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_alert_notice',
			array(
				'label' => __( 'Alert Notice', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'chat_alert_notice_background',
			array(
				'label'     => __( 'Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fef2f2',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-notice-background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_alert_notice_text',
			array(
				'label'     => __( 'Text', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#8a1f1f',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-notice-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_alert_notice_border',
			array(
				'label'     => __( 'Border', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(214, 54, 56, 0.35)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-notice-border: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_alert_notice_shadow',
			array(
				'label'     => __( 'Shadow', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(214, 54, 56, 0.12)',
				'selectors' => array(
					$bubble_selector => '--wp-mcp-ai-color-notice-shadow: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_chat_submit_button',
			array(
				'label' => __( 'Submit Button', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'tabs_chat_submit_button_states' );

		$this->start_controls_tab(
			'tab_chat_submit_button_normal',
			array(
				'label' => __( 'Normal', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'chat_submit_button_gradient_start',
			array(
				'label'     => __( 'Gradient Start', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#3b5bff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-gradient-start: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_gradient_end',
			array(
				'label'     => __( 'Gradient End', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#7c5cff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-gradient-end: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_text_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_shadow_color',
			array(
				'label'     => __( 'Shadow Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(59, 91, 255, 0.35)',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-shadow: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_chat_submit_button_hover',
			array(
				'label' => __( 'Hover', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'chat_submit_button_hover_gradient_start',
			array(
				'label'     => __( 'Gradient Start', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#324cf8',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-hover-gradient-start: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_hover_gradient_end',
			array(
				'label'     => __( 'Gradient End', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#6a4bff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-hover-gradient-end: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-hover-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_hover_shadow_color',
			array(
				'label'     => __( 'Shadow Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(50, 76, 248, 0.4)',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-hover-shadow: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_chat_submit_button_active',
			array(
				'label' => __( 'Active', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'chat_submit_button_active_gradient_start',
			array(
				'label'     => __( 'Gradient Start', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#2f44f0',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-active-gradient-start: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_active_gradient_end',
			array(
				'label'     => __( 'Gradient End', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#5b3eff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-active-gradient-end: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_active_text_color',
			array(
				'label'     => __( 'Text Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-active-text: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'chat_submit_button_active_shadow_color',
			array(
				'label'     => __( 'Shadow Color', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(47, 68, 240, 0.38)',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-chat-widget .wp-mcp-ai-chat__submit' => '--wp-mcp-ai-color-submit-active-shadow: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		// Use cache helper if available.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			return WP_MCP_AI_Cache_Helper::get_elementor_options( array( $this, 'build_assistant_options' ) );
		}

		return $this->build_assistant_options();
	}

	/**
	 * Build assistant options array (extracted for caching)
	 *
	 * @return array Assistant options for dropdown.
	 */
	public function build_assistant_options() {
		$options = array( '' => __( 'Default Assistant', 'wp-mcp-ai' ) );

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
				'no_found_rows'          => true,
				'update_post_term_cache' => false,  // Performance: Skip term cache.
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

		$assistant_setting = isset( $settings['assistant'] ) ? $settings['assistant'] : '';
		$assistant_id      = $this->resolve_assistant_id( $assistant_setting );
		$config            = $this->get_assistant_config( $assistant_id );

		$attributes = array();

		if ( '' !== $assistant_setting ) {
			$attributes['assistant'] = (string) absint( $assistant_setting );
		}

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

		$shortcode = '[' . WP_MCP_AI_Shortcode::SHORTCODE;

		foreach ( $attributes as $key => $value ) {
			$shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		$shortcode .= ']';

		echo '<div class="wp-mcp-ai-chat-widget">';

		$can_show_assistant_details = $this->can_display_assistant_details( $assistant_id, $allow_guests );

		if ( $can_show_assistant_details && isset( $settings['show_assistant_defaults'] ) && 'yes' === $settings['show_assistant_defaults'] ) {
			$this->render_assistant_defaults_section( $assistant_id, $config, $settings );
		}

		if ( $can_show_assistant_details && isset( $settings['show_assistant_base_knowledge'] ) && 'yes' === $settings['show_assistant_base_knowledge'] ) {
			$this->render_assistant_base_knowledge_section( $assistant_id, $config, $settings );
		}

		if ( $can_show_assistant_details && isset( $settings['show_assistant_tools'] ) && 'yes' === $settings['show_assistant_tools'] ) {
			$this->render_assistant_tools_section( $assistant_id, $settings );
		}

		if ( $can_show_assistant_details && isset( $settings['show_assistant_prompt_shortcuts'] ) && 'yes' === $settings['show_assistant_prompt_shortcuts'] ) {
			$this->render_assistant_prompt_shortcuts_section( $assistant_id, $config, $settings );
		}

		echo '<div class="wp-mcp-ai-chat-widget__interface">';
		echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Retrieve the notice shown before theme style controls.
	 *
	 * @return string
	 */
	protected function get_chat_theme_notice() {
		$url = $this->get_chat_theme_settings_url();

		if ( '' !== $url ) {
			return sprintf(
				/* translators: 1: Opening anchor tag, 2: closing anchor tag. */
				__( 'Global chat colors are managed under %1$sSettings → WP oOS → Theme%2$s. Update the palette there or use the controls below to override this widget.', 'wp-mcp-ai' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
		}

		return __( 'Global chat colors are managed under Settings → WP oOS → Theme. Update the palette there or use the controls below to override this widget.', 'wp-mcp-ai' );
	}

	/**
	 * Build the admin URL for the chat theme settings section.
	 *
	 * @return string
	 */
	protected function get_chat_theme_settings_url() {
		if ( ! function_exists( 'admin_url' ) ) {
			return '';
		}

		$slug = 'wp-mcp-ai-dashboard';

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && defined( 'WP_MCP_AI_Admin_Settings::PAGE_SLUG' ) ) {
			$slug = WP_MCP_AI_Admin_Settings::PAGE_SLUG;
		}

		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Resolve the assistant ID that should be used for contextual information.
	 *
	 * @param string $assistant_setting Assistant setting from the widget controls.
	 *
	 * @return int
	 */
	protected function resolve_assistant_id( $assistant_setting ) {
		if ( '' !== $assistant_setting ) {
			$assistant_id = absint( $assistant_setting );

			if ( $assistant_id ) {
				return $assistant_id;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return 0;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( isset( $settings['default_assistant'] ) ) {
			return absint( $settings['default_assistant'] );
		}

		return 0;
	}

	/**
	 * Fetch the assistant configuration array when available.
	 *
	 * @param int $assistant_id Assistant post ID.
	 *
	 * @return array|null
	 */
	protected function get_assistant_config( $assistant_id ) {
		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return null;
		}

		return WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
	}

	/**
	 * Determine whether the visitor can view assistant metadata panels.
	 *
	 * @param int  $assistant_id Assistant post ID.
	 * @param bool $allow_guests Whether guest access has been enabled for the widget instance.
	 *
	 * @return bool
	 */
	protected function can_display_assistant_details( $assistant_id, $allow_guests ) {
		if ( $allow_guests ) {
			return true;
		}

		if ( ! function_exists( 'wp_mcp_ai_get_effective_chat_capability' ) && ! function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
			return true;
		}

		// Use the effective capability (per-assistant or global).
		$capability = function_exists( 'wp_mcp_ai_get_effective_chat_capability' )
			? wp_mcp_ai_get_effective_chat_capability( absint( $assistant_id ), 'shortcode' )
			: wp_mcp_ai_get_required_chat_capability( absint( $assistant_id ), 'shortcode' );

		if ( ! $capability || 'public' === $capability ) {
			return true;
		}

		if ( function_exists( 'current_user_can' ) && current_user_can( $capability ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Render the assistant defaults section when enabled.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $config       Assistant configuration.
	 * @param array $settings     Widget settings.
	 */
	protected function render_assistant_defaults_section( $assistant_id, $config, $settings ) {
		$title         = isset( $settings['assistant_defaults_title'] ) ? $settings['assistant_defaults_title'] : '';
		$show_prompt   = ! empty( $settings['assistant_defaults_show_prompt'] ) && 'yes' === $settings['assistant_defaults_show_prompt'];
		$empty_message = isset( $settings['assistant_defaults_empty_message'] ) ? $settings['assistant_defaults_empty_message'] : '';

		echo '<div class="wp-mcp-ai-chat-widget__assistant-defaults">';

		$title_output = $this->format_text_inline( $title );
		if ( '' !== $title_output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
			echo '<h3 class="wp-mcp-ai-chat-widget__assistant-defaults-title">' . $title_output . '</h3>';
		}

		if ( ! $assistant_id || null === $config ) {
			$notice = $this->format_text_inline( $empty_message );

			if ( '' !== $notice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-chat-widget__assistant-defaults-notice">' . $notice . '</p>';
			}

			echo '</div>';
			return;
		}

		$provider_label = $this->get_provider_label( isset( $config['provider'] ) ? $config['provider'] : '' );
		$model          = isset( $config['model'] ) ? $config['model'] : '';
		$temperature    = isset( $config['temperature'] ) ? $config['temperature'] : null;
		$prompt         = isset( $config['system_prompt'] ) ? $config['system_prompt'] : '';

		echo '<dl class="wp-mcp-ai-chat-widget__assistant-defaults-list">';

		if ( '' !== $provider_label ) {
			echo '<dt class="wp-mcp-ai-chat-widget__assistant-defaults-label">' . esc_html__( 'Provider', 'wp-mcp-ai' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-chat-widget__assistant-defaults-value">' . esc_html( $provider_label ) . '</dd>';
		}

		if ( '' !== $model ) {
			echo '<dt class="wp-mcp-ai-chat-widget__assistant-defaults-label">' . esc_html__( 'Model', 'wp-mcp-ai' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-chat-widget__assistant-defaults-value">' . esc_html( $model ) . '</dd>';
		}

		if ( null !== $temperature && '' !== $temperature ) {
			$temperature_value = number_format_i18n( floatval( $temperature ), 2 );
			echo '<dt class="wp-mcp-ai-chat-widget__assistant-defaults-label">' . esc_html__( 'Temperature', 'wp-mcp-ai' ) . '</dt>';
			echo '<dd class="wp-mcp-ai-chat-widget__assistant-defaults-value">' . esc_html( $temperature_value ) . '</dd>';
		}

		echo '</dl>';

		if ( $show_prompt ) {
			$prompt_output = $this->format_text_block( $prompt );

			if ( '' !== $prompt_output ) {
				echo '<div class="wp-mcp-ai-chat-widget__assistant-defaults-prompt">';
				echo '<h4 class="wp-mcp-ai-chat-widget__assistant-defaults-prompt-heading">' . esc_html__( 'System prompt', 'wp-mcp-ai' ) . '</h4>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-chat-widget__assistant-defaults-prompt-content">' . $prompt_output . '</div>';
				echo '</div>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render the assistant memory section when enabled.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $config       Assistant configuration.
	 * @param array $settings     Widget settings.
	 */
	protected function render_assistant_base_knowledge_section( $assistant_id, $config, $settings ) {
		$title         = isset( $settings['assistant_base_knowledge_title'] ) ? $settings['assistant_base_knowledge_title'] : '';
		$show_sizes    = ! empty( $settings['assistant_base_knowledge_show_sizes'] ) && 'yes' === $settings['assistant_base_knowledge_show_sizes'];
		$empty_message = isset( $settings['assistant_base_knowledge_empty_message'] ) ? $settings['assistant_base_knowledge_empty_message'] : '';
		$no_files_msg  = isset( $settings['assistant_base_knowledge_no_files_message'] ) ? $settings['assistant_base_knowledge_no_files_message'] : '';
		$memory_files  = ( null !== $config && isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) ? $config['memory_files'] : array();
		$vector_store  = ( null !== $config && isset( $config['vector_store_id'] ) ) ? $config['vector_store_id'] : '';

		echo '<div class="wp-mcp-ai-chat-widget__assistant-memory">';

		$title_output = $this->format_text_inline( $title );
		if ( '' !== $title_output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
			echo '<h3 class="wp-mcp-ai-chat-widget__assistant-memory-title">' . $title_output . '</h3>';
		}

		if ( ! $assistant_id || null === $config ) {
			$notice = $this->format_text_inline( $empty_message );

			if ( '' !== $notice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-chat-widget__assistant-memory-notice">' . $notice . '</p>';
			}

			echo '</div>';
			return;
		}

		$entries = $this->prepare_memory_entries( $memory_files, $show_sizes );

		if ( empty( $entries ) ) {
			$notice = $this->format_text_inline( $no_files_msg );

			if ( '' !== $notice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-chat-widget__assistant-memory-notice">' . $notice . '</p>';
			}
		} else {
			echo '<ul class="wp-mcp-ai-chat-widget__assistant-memory-files">';

			foreach ( $entries as $entry ) {
				$title_text = $entry['title'];
				$url        = $entry['url'];
				$size       = $entry['size'];

				echo '<li class="wp-mcp-ai-chat-widget__assistant-memory-file">';

				if ( '' !== $url ) {
					echo '<a class="wp-mcp-ai-chat-widget__assistant-memory-file-link" href="' . esc_url( $url ) . '">' . esc_html( $title_text ) . '</a>';
				} else {
					echo '<span class="wp-mcp-ai-chat-widget__assistant-memory-file-label">' . esc_html( $title_text ) . '</span>';
				}

				if ( $show_sizes && '' !== $size ) {
					echo '<span class="wp-mcp-ai-chat-widget__assistant-memory-file-size">' . esc_html( $size ) . '</span>';
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( '' !== $vector_store ) {
			echo '<div class="wp-mcp-ai-chat-widget__assistant-memory-vector">';
			echo '<span class="wp-mcp-ai-chat-widget__assistant-memory-vector-label">' . esc_html__( 'Vector Store ID:', 'wp-mcp-ai' ) . '</span>';
			echo '<code class="wp-mcp-ai-chat-widget__assistant-memory-vector-value">' . esc_html( $vector_store ) . '</code>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render the assistant tools section when enabled.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $settings     Widget settings.
	 */
	protected function render_assistant_tools_section( $assistant_id, $settings ) {
		$title             = isset( $settings['assistant_tools_title'] ) ? $settings['assistant_tools_title'] : '';
		$show_descriptions = ! empty( $settings['assistant_tools_show_descriptions'] ) && 'yes' === $settings['assistant_tools_show_descriptions'];
		$empty_message     = isset( $settings['assistant_tools_empty_message'] ) ? $settings['assistant_tools_empty_message'] : '';
		$registry_message  = isset( $settings['assistant_tools_registry_message'] ) ? $settings['assistant_tools_registry_message'] : '';

		echo '<div class="wp-mcp-ai-chat-widget__assistant-tools">';

		$title_output = $this->format_text_inline( $title );
		if ( '' !== $title_output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
			echo '<h3 class="wp-mcp-ai-chat-widget__assistant-tools-title">' . $title_output . '</h3>';
		}

		if ( ! $assistant_id ) {
			$notice = $this->format_text_inline( $empty_message );

			if ( '' !== $notice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-chat-widget__assistant-tools-notice">' . $notice . '</p>';
			}

			echo '</div>';
			return;
		}

		$tools = $this->get_assistant_tools_data( $assistant_id );

		$registered = isset( $tools['registered'] ) ? $tools['registered'] : array();
		$missing    = isset( $tools['missing'] ) ? $tools['missing'] : array();
		$registry   = isset( $tools['registry_available'] ) ? (bool) $tools['registry_available'] : true;

		if ( empty( $registered ) ) {
			if ( ! $registry && ! empty( $tools['requested'] ) ) {
				$notice = $this->format_text_inline( $registry_message );
			} else {
				$notice = $this->format_text_inline( $empty_message );
			}

			if ( '' !== $notice ) {
				echo '<p class="wp-mcp-ai-chat-widget__assistant-tools-notice">' . $notice . '</p>';
			}
		} else {
			echo '<ul class="wp-mcp-ai-chat-widget__assistant-tools-list">';

			foreach ( $registered as $tool ) {
				$name        = isset( $tool['name'] ) ? $tool['name'] : '';
				$description = isset( $tool['description'] ) ? $tool['description'] : '';

				echo '<li class="wp-mcp-ai-chat-widget__assistant-tools-item">';
				echo '<span class="wp-mcp-ai-chat-widget__assistant-tools-name">' . esc_html( $name ) . '</span>';

				if ( $show_descriptions ) {
					$description_output = $this->format_text_block( $description );

					if ( '' !== $description_output ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
						echo '<div class="wp-mcp-ai-chat-widget__assistant-tools-description">' . $description_output . '</div>';
					}
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( ! empty( $missing ) ) {
			echo '<div class="wp-mcp-ai-chat-widget__assistant-tools-missing">';
			echo '<h4 class="wp-mcp-ai-chat-widget__assistant-tools-missing-heading">' . esc_html__( 'Missing registrations', 'wp-mcp-ai' ) . '</h4>';
			echo '<ul class="wp-mcp-ai-chat-widget__assistant-tools-missing-list">';

			foreach ( $missing as $slug ) {
				echo '<li class="wp-mcp-ai-chat-widget__assistant-tools-missing-item"><code>' . esc_html( $slug ) . '</code></li>';
			}

			echo '</ul>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render the assistant prompt shortcuts section when enabled.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $config       Assistant configuration.
	 * @param array $settings     Widget settings.
	 */
	protected function render_assistant_prompt_shortcuts_section( $assistant_id, $config, $settings ) {
		$title         = isset( $settings['assistant_prompt_shortcuts_title'] ) ? $settings['assistant_prompt_shortcuts_title'] : '';
		$show_desc     = ! empty( $settings['assistant_prompt_shortcuts_show_descriptions'] ) && 'yes' === $settings['assistant_prompt_shortcuts_show_descriptions'];
		$show_prompt   = ! empty( $settings['assistant_prompt_shortcuts_show_prompt'] ) && 'yes' === $settings['assistant_prompt_shortcuts_show_prompt'];
		$show_tool     = ! empty( $settings['assistant_prompt_shortcuts_show_tools'] ) && 'yes' === $settings['assistant_prompt_shortcuts_show_tools'];
		$empty_message = isset( $settings['assistant_prompt_shortcuts_empty_message'] ) ? $settings['assistant_prompt_shortcuts_empty_message'] : '';
		$none_message  = isset( $settings['assistant_prompt_shortcuts_none_message'] ) ? $settings['assistant_prompt_shortcuts_none_message'] : '';
		$shortcuts     = $this->get_assistant_shortcuts( $assistant_id, $config );

		echo '<div class="wp-mcp-ai-chat-widget__assistant-shortcuts">';

		$title_output = $this->format_text_inline( $title );
		if ( '' !== $title_output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
			echo '<h3 class="wp-mcp-ai-chat-widget__assistant-shortcuts-title">' . $title_output . '</h3>';
		}

		if ( ! $assistant_id || null === $config ) {
			$notice = $this->format_text_inline( $empty_message );

			if ( '' !== $notice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-chat-widget__assistant-shortcuts-notice">' . $notice . '</p>';
			}

			echo '</div>';
			return;
		}

		if ( empty( $shortcuts ) ) {
			$notice = $this->format_text_inline( $none_message );

			if ( '' !== $notice ) {
				echo '<p class="wp-mcp-ai-chat-widget__assistant-shortcuts-notice">' . $notice . '</p>';
			}

			echo '</div>';
			return;
		}

		$tool_names = $show_tool ? $this->get_tool_name_map() : array();

		echo '<ul class="wp-mcp-ai-chat-widget__assistant-shortcuts-list">';

		foreach ( $shortcuts as $shortcut ) {
			if ( ! is_array( $shortcut ) ) {
				continue;
			}

			$label       = isset( $shortcut['label'] ) ? $shortcut['label'] : '';
			$payload     = isset( $shortcut['payload'] ) ? $shortcut['payload'] : '';
			$description = isset( $shortcut['description'] ) ? $shortcut['description'] : '';
			$tool        = isset( $shortcut['tool'] ) ? $shortcut['tool'] : '';

			$label_output = $this->format_text_inline( $label );

			echo '<li class="wp-mcp-ai-chat-widget__assistant-shortcuts-item">';

			if ( '' !== $label_output ) {
				echo '<span class="wp-mcp-ai-chat-widget__assistant-shortcuts-label">' . $label_output . '</span>';
			}

			if ( $show_tool ) {
				$tool_label = $this->format_tool_label( $tool, $tool_names );

				if ( '' !== $tool_label ) {
					echo '<span class="wp-mcp-ai-chat-widget__assistant-shortcuts-tool">' . esc_html__( 'Tool:', 'wp-mcp-ai' ) . ' ' . esc_html( $tool_label ) . '</span>';
				}
			}

			if ( $show_desc ) {
				$description_output = $this->format_text_block( $description );

				if ( '' !== $description_output ) {
					echo '<div class="wp-mcp-ai-chat-widget__assistant-shortcuts-description">' . $description_output . '</div>';
				}
			}

			if ( $show_prompt && '' !== $payload ) {
				echo '<pre class="wp-mcp-ai-chat-widget__assistant-shortcuts-payload">' . esc_html( $payload ) . '</pre>';
			}

			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Retrieve the shortcuts that should be rendered for the supplied assistant.
	 *
	 * @param int        $assistant_id Assistant post ID.
	 * @param array|null $config       Assistant configuration array.
	 * @return array
	 */
	protected function get_assistant_shortcuts( $assistant_id, $config ) {
		$assistant_id = absint( $assistant_id );

		if ( $assistant_id && class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
			$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

			if ( is_array( $shortcuts ) && ! empty( $shortcuts ) ) {
				return $shortcuts;
			}
		}

		if ( null !== $config && isset( $config['tool_shortcuts'] ) && is_array( $config['tool_shortcuts'] ) ) {
			return $config['tool_shortcuts'];
		}

		return array();
	}

	/**
	 * Convert the provider slug into a readable label.
	 *
	 * @param string $provider Provider slug.
	 *
	 * @return string
	 */
	protected function get_provider_label( $provider ) {
		$provider = sanitize_key( $provider );

		if ( '' === $provider ) {
			return '';
		}

		switch ( $provider ) {
			case 'openai':
				return __( 'OpenAI', 'wp-mcp-ai' );
			case 'gemini':
				return __( 'Gemini', 'wp-mcp-ai' );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );
	}

	/**
	 * Prepare the memory file entries for display.
	 *
	 * @param array $file_ids    Attachment IDs.
	 * @param bool  $include_size Whether to calculate file sizes.
	 *
	 * @return array
	 */
	protected function prepare_memory_entries( $file_ids, $include_size ) {
		if ( ! is_array( $file_ids ) || empty( $file_ids ) ) {
			return array();
		}

		$entries = array();

		foreach ( $file_ids as $file_id ) {
			$file_id    = absint( $file_id );
			$attachment = get_post( $file_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$title = get_the_title( $attachment );
			if ( '' === $title ) {
				/* translators: %d: Attachment ID. */
				$title = sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id );
			}

			$url  = wp_get_attachment_url( $file_id );
			$size = '';

			if ( $include_size ) {
				$file_path = get_attached_file( $file_id );

				if ( $file_path && file_exists( $file_path ) ) {
					$file_size = filesize( $file_path );

					if ( false !== $file_size ) {
						$size = size_format( (int) $file_size );
					}
				}
			}

			$entries[] = array(
				'title' => $title,
				'url'   => is_string( $url ) ? $url : '',
				'size'  => $size,
			);
		}

		return $entries;
	}

	/**
	 * Prepare an assistant's tool assignments for output.
	 *
	 * @param int $assistant_id Assistant post ID.
	 *
	 * @return array
	 */
	protected function get_assistant_tools_data( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array(
				'registered'         => array(),
				'missing'            => array(),
				'requested'          => array(),
				'registry_available' => false,
			);
		}

		$stored = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$requested = array();

		foreach ( $stored as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				continue;
			}

			$requested[] = $slug;
		}

		$requested = array_values( array_unique( $requested ) );

		if ( empty( $requested ) ) {
			return array(
				'registered'         => array(),
				'missing'            => array(),
				'requested'          => array(),
				'registry_available' => true,
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array(
				'registered'         => array(),
				'missing'            => $requested,
				'requested'          => $requested,
				'registry_available' => false,
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( method_exists( $registry, 'init' ) ) {
			$registry->init();
		}

		$registered = array();
		$missing    = array();

		foreach ( $requested as $slug ) {
			$tool = $registry->get_tool( $slug );

			if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
				$registered[] = array(
					'slug'        => $slug,
					'name'        => $tool->get_name(),
					'description' => $tool->get_description(),
				);
				continue;
			}

			$missing[] = $slug;
		}

		return array(
			'registered'         => $registered,
			'missing'            => $missing,
			'requested'          => $requested,
			'registry_available' => true,
		);
	}

	/**
	 * Build a map of tool slugs to names for quick lookups.
	 *
	 * @return array<string, string>
	 */
	protected function get_tool_name_map() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$map = array();

		foreach ( $registry->get_tools() as $tool ) {
			if ( ! $tool ) {
				continue;
			}

			$slug = sanitize_key( $tool->get_slug() );

			if ( '' === $slug ) {
				continue;
			}

			$map[ $slug ] = $tool->get_name();
		}

		return $map;
	}

	/**
	 * Format a human readable tool label.
	 *
	 * @param string $tool_slug  Tool slug stored on the shortcut.
	 * @param array  $tool_names Map of tool slugs to human-readable names.
	 *
	 * @return string
	 */
	protected function format_tool_label( $tool_slug, $tool_names ) {
		$tool_slug = sanitize_key( $tool_slug );

		if ( '' === $tool_slug ) {
			return '';
		}

		if ( isset( $tool_names[ $tool_slug ] ) && '' !== $tool_names[ $tool_slug ] ) {
			return $tool_names[ $tool_slug ];
		}

		return $tool_slug;
	}
}
