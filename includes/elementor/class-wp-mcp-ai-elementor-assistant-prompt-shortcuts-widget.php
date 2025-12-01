<?php
/**
 * Elementor widget for listing an assistant's configured prompt shortcuts.
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
 * Elementor widget definition for assistant prompt shortcuts.
 */
class WP_MCP_AI_Elementor_Assistant_Prompt_Shortcuts_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_assistant_prompt_shortcuts';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Assistant Prompt Shortcuts', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-bullet-list';
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
		return array( 'assistant', 'prompt', 'shortcuts', 'tasks', 'mcp', 'ai' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Prompt shortcuts', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'assistant_id',
			array(
				'label'       => __( 'Assistant', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Choose which assistant to display shortcuts for. Only published assistants appear in this list.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_descriptions',
			array(
				'label'        => __( 'Show descriptions', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_prompt_text',
			array(
				'label'        => __( 'Show prompt text', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_tool_context',
			array(
				'label'        => __( 'Show associated tool', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'       => __( 'Empty state message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant in the widget settings to view its prompt shortcuts.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'no_shortcuts_message',
			array(
				'label'       => __( 'No shortcuts message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No prompt shortcuts have been saved for this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no shortcuts are available…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_assistant_prompt_shortcuts',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts__notice',
						'{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts__description',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts__tool',
						'{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts__payload',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-assistant-prompt-shortcuts a',
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
		$show_descriptions = ! empty( $settings['show_descriptions'] ) && 'yes' === $settings['show_descriptions'];
		$show_prompt_text  = ! empty( $settings['show_prompt_text'] ) && 'yes' === $settings['show_prompt_text'];
		$show_tool         = ! empty( $settings['show_tool_context'] ) && 'yes' === $settings['show_tool_context'];
		$empty_message     = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
		$no_shortcuts_msg  = isset( $settings['no_shortcuts_message'] ) ? $settings['no_shortcuts_message'] : '';

		echo '<div class="wp-mcp-ai-assistant-prompt-shortcuts">';

		if ( '' !== $title ) {
			$title_output = $this->format_text_inline( $title );

			if ( '' !== $title_output ) {
				echo '<h3 class="wp-mcp-ai-assistant-prompt-shortcuts__title">' . $title_output . '</h3>';
			}
		}

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$empty_output = $this->format_text_inline( $empty_message );

			if ( '' !== $empty_output ) {
				echo '<p class="wp-mcp-ai-assistant-prompt-shortcuts__notice">' . $empty_output . '</p>';
			}

			echo '</div>';
			return;
		}

		$config    = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$shortcuts = $this->get_assistant_shortcuts( $assistant_id, $config );

		if ( empty( $shortcuts ) ) {
			$no_shortcuts_output = $this->format_text_inline( $no_shortcuts_msg );

			if ( '' !== $no_shortcuts_output ) {
				echo '<p class="wp-mcp-ai-assistant-prompt-shortcuts__notice">' . $no_shortcuts_output . '</p>';
			}

			echo '</div>';
			return;
		}

		$tool_names = $show_tool ? $this->get_tool_name_map() : array();

		echo '<ul class="wp-mcp-ai-assistant-prompt-shortcuts__list">';

		foreach ( $shortcuts as $shortcut ) {
			$label       = isset( $shortcut['label'] ) ? $shortcut['label'] : '';
			$payload     = isset( $shortcut['payload'] ) ? $shortcut['payload'] : '';
			$description = isset( $shortcut['description'] ) ? $shortcut['description'] : '';
			$tool        = isset( $shortcut['tool'] ) ? $shortcut['tool'] : '';

			$label_text = '' !== $label ? $this->format_text_inline( $label ) : '';

			echo '<li class="wp-mcp-ai-assistant-prompt-shortcuts__item">';

			if ( '' !== $label_text ) {
				echo '<span class="wp-mcp-ai-assistant-prompt-shortcuts__label">' . $label_text . '</span>';
			}

			if ( $show_tool ) {
				$tool_label = $this->format_tool_label( $tool, $tool_names );

				if ( '' !== $tool_label ) {
					echo '<span class="wp-mcp-ai-assistant-prompt-shortcuts__tool">' . esc_html__( 'Tool:', 'wp-mcp-ai' ) . ' ' . esc_html( $tool_label ) . '</span>';
				}
			}

			if ( $show_descriptions ) {
				$description_output = $this->format_text_block( $description );

				if ( '' !== $description_output ) {
					echo '<div class="wp-mcp-ai-assistant-prompt-shortcuts__description">' . $description_output . '</div>';
				}
			}

			if ( $show_prompt_text && '' !== $payload ) {
				echo '<pre class="wp-mcp-ai-assistant-prompt-shortcuts__payload">' . esc_html( $payload ) . '</pre>';
			}

			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Retrieve the shortcuts that should be rendered for the supplied assistant.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $config       Assistant configuration array.
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

		if ( isset( $config['tool_shortcuts'] ) && is_array( $config['tool_shortcuts'] ) ) {
			return $config['tool_shortcuts'];
		}

		return array();
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		$options = array( '' => __( 'Select an assistant', 'wp-mcp-ai' ) );

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
	 * @param string $tool_slug Tool slug stored on the shortcut.
	 * @param array  $tool_names Map of tool slugs to human-readable names.
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
