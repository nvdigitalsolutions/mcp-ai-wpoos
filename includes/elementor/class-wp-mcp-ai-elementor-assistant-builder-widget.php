<?php
/**
 * Elementor widget for Assistant Builder functionality.
 *
 * Provides a comprehensive interface for building new assistants using AI,
 * with integrated assistant selection, tools configuration, and chat interface.
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
 * Assistant Builder Elementor widget definition.
 */
class WP_MCP_AI_Elementor_Assistant_Builder_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wp_mcp_ai_assistant_builder';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'WP oOS Assistant Builder', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-tools';
	}

	/**
	 * Widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'ai', 'assistant', 'builder', 'tools', 'create', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		$deps = array( 'wp-mcp-ai-assistant-builder' );

		if ( class_exists( 'WP_MCP_AI_Shortcode' ) && defined( 'WP_MCP_AI_Shortcode::SCRIPT_HANDLE' ) ) {
			$deps[] = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
		}

		return $deps;
	}

	/**
	 * Declare style dependencies for this widget.
	 *
	 * @return array List of style handles this widget depends on.
	 */
	public function get_style_depends() {
		$deps = array( 'wp-mcp-ai-assistant-builder' );

		if ( class_exists( 'WP_MCP_AI_Shortcode' ) && defined( 'WP_MCP_AI_Shortcode::STYLE_HANDLE' ) ) {
			$deps[] = WP_MCP_AI_Shortcode::STYLE_HANDLE;
		}

		return $deps;
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_builder_settings',
			array(
				'label' => __( 'Builder Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'default_assistant',
			array(
				'label'       => __( 'Default Assistant', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Pre-select an assistant when the widget loads. Users can still change this.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_assistant_selector',
			array(
				'label'        => __( 'Show Assistant Selector', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Allow users to select from available assistants.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_tools_grid',
			array(
				'label'        => __( 'Show Tools Grid', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Display a grid of available tools that users can enable/disable.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_build_button',
			array(
				'label'        => __( 'Show Build Button', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the Build button to create assistants from conversations.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'stacked'    => __( 'Stacked (Vertical)', 'wp-mcp-ai' ),
					'side-by-side' => __( 'Side by Side', 'wp-mcp-ai' ),
				),
				'default' => 'stacked',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_tools_settings',
			array(
				'label'     => __( 'Tools Grid Settings', 'wp-mcp-ai' ),
				'condition' => array( 'show_tools_grid' => 'yes' ),
			)
		);

		$this->add_control(
			'tools_collapsed',
			array(
				'label'        => __( 'Start Collapsed', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Start with tool groups collapsed.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_tool_descriptions',
			array(
				'label'        => __( 'Show Tool Descriptions', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'wp-mcp-ai' ),
				'label_off'    => __( 'Hide', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'tools_title',
			array(
				'label'       => __( 'Tools Section Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Available Tools', 'wp-mcp-ai' ),
				'label_block' => true,
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
			'enable_streaming',
			array(
				'label'        => __( 'Enable SSE Streaming', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'true',
			)
		);

		$this->add_control(
			'save_transcript',
			array(
				'label'        => __( 'Save Transcripts', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'false',
			)
		);

		$this->add_control(
			'chat_placeholder',
			array(
				'label'       => __( 'Chat Placeholder', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Describe the assistant you want to create...', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		// Style controls.
		$this->start_controls_section(
			'section_style_container',
			array(
				'label' => __( 'Container', 'wp-mcp-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'container_background',
			array(
				'label'     => __( 'Background', 'wp-mcp-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .wp-mcp-ai-assistant-builder' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'container_padding',
			array(
				'label'      => __( 'Padding', 'wp-mcp-ai' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-assistant-builder' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'container_border',
				'selector' => '{{WRAPPER}} .wp-mcp-ai-assistant-builder',
			)
		);

		$this->add_control(
			'container_border_radius',
			array(
				'label'      => __( 'Border Radius', 'wp-mcp-ai' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .wp-mcp-ai-assistant-builder' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
		$options = array( '' => __( '— Select an Assistant —', 'wp-mcp-ai' ) );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $options;
		}

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

		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			echo '<div class="wp-mcp-ai-assistant-builder__notice">';
			echo '<p>' . esc_html__( 'You do not have permission to use the Assistant Builder.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$layout                  = isset( $settings['layout'] ) ? $settings['layout'] : 'stacked';
		$show_assistant_selector = ! empty( $settings['show_assistant_selector'] ) && 'yes' === $settings['show_assistant_selector'];
		$show_tools_grid         = ! empty( $settings['show_tools_grid'] ) && 'yes' === $settings['show_tools_grid'];
		$show_build_button       = ! empty( $settings['show_build_button'] ) && 'yes' === $settings['show_build_button'];
		$default_assistant       = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$tools_collapsed         = ! empty( $settings['tools_collapsed'] ) && 'yes' === $settings['tools_collapsed'];
		$show_tool_descriptions  = ! empty( $settings['show_tool_descriptions'] ) && 'yes' === $settings['show_tool_descriptions'];
		$tools_title             = isset( $settings['tools_title'] ) ? $settings['tools_title'] : '';
		$enable_streaming        = ! empty( $settings['enable_streaming'] ) && 'true' === $settings['enable_streaming'];
		$save_transcript         = ! empty( $settings['save_transcript'] ) && 'true' === $settings['save_transcript'];
		$chat_placeholder        = isset( $settings['chat_placeholder'] ) ? $settings['chat_placeholder'] : '';

		$container_classes = array( 'wp-mcp-ai-assistant-builder' );
		$container_classes[] = 'wp-mcp-ai-assistant-builder--' . sanitize_html_class( $layout );

		if ( $show_build_button ) {
			$container_classes[] = 'wp-mcp-ai-assistant-builder--with-build';
		}

		echo '<div class="' . esc_attr( implode( ' ', $container_classes ) ) . '" data-widget-id="' . esc_attr( $this->get_id() ) . '">';

		// Assistant Selector.
		if ( $show_assistant_selector ) {
			$this->render_assistant_selector( $default_assistant );
		}

		// Tools Grid.
		if ( $show_tools_grid ) {
			$this->render_tools_grid( $tools_title, $tools_collapsed, $show_tool_descriptions );
		}

		// Chat Container.
		$this->render_chat_container( $enable_streaming, $save_transcript, $show_build_button, $chat_placeholder );

		echo '</div>';

		// Inline configuration for JavaScript.
		$this->render_inline_config( $settings );
	}

	/**
	 * Render the assistant selector.
	 *
	 * @param int $default_assistant Default assistant ID.
	 */
	protected function render_assistant_selector( $default_assistant ) {
		$assistants = $this->get_assistants_data();

		echo '<div class="wp-mcp-ai-assistant-builder__selector">';
		echo '<label for="wp-mcp-ai-builder-assistant-' . esc_attr( $this->get_id() ) . '">';
		echo esc_html__( 'Select an Assistant:', 'wp-mcp-ai' );
		echo '</label>';
		echo '<select id="wp-mcp-ai-builder-assistant-' . esc_attr( $this->get_id() ) . '" class="wp-mcp-ai-assistant-builder__select">';
		echo '<option value="">' . esc_html__( '— Select an assistant —', 'wp-mcp-ai' ) . '</option>';

		foreach ( $assistants as $assistant ) {
			$selected = $default_assistant === $assistant['id'] ? ' selected' : '';
			printf(
				'<option value="%d" data-title="%s" data-tools="%s" data-shortcuts="%s" data-provider="%s" data-model="%s"%s>%s</option>',
				esc_attr( $assistant['id'] ),
				esc_attr( $assistant['title'] ),
				esc_attr( wp_json_encode( $assistant['tools'] ) ),
				esc_attr( wp_json_encode( $assistant['shortcuts'] ) ),
				esc_attr( $assistant['provider'] ),
				esc_attr( $assistant['model'] ),
				$selected,
				esc_html( $assistant['title'] )
			);
		}

		echo '</select>';
		echo '<button type="button" class="wp-mcp-ai-assistant-builder__start-btn button button-primary" disabled>';
		echo esc_html__( 'Start Chat', 'wp-mcp-ai' );
		echo '</button>';
		echo '</div>';
	}

	/**
	 * Render the tools grid.
	 *
	 * @param string $title                Section title.
	 * @param bool   $collapsed            Start collapsed.
	 * @param bool   $show_descriptions    Show tool descriptions.
	 */
	protected function render_tools_grid( $title, $collapsed, $show_descriptions ) {
		$tools_data = $this->get_tools_data();

		if ( empty( $tools_data['groups'] ) ) {
			return;
		}

		echo '<div class="wp-mcp-ai-assistant-builder__tools" style="display: none;">';

		if ( $title ) {
			echo '<h3 class="wp-mcp-ai-assistant-builder__tools-title">' . esc_html( $title ) . '</h3>';
		}

		echo '<p class="wp-mcp-ai-assistant-builder__tools-description">';
		echo esc_html__( 'Select or deselect tools to customize what capabilities the assistant can use.', 'wp-mcp-ai' );
		echo '</p>';

		// Tool actions.
		echo '<div class="wp-mcp-ai-assistant-builder__tools-actions">';
		echo '<button type="button" class="button wp-mcp-ai-assistant-builder__select-all">' . esc_html__( 'Select All', 'wp-mcp-ai' ) . '</button>';
		echo '<button type="button" class="button wp-mcp-ai-assistant-builder__deselect-all">' . esc_html__( 'Deselect All', 'wp-mcp-ai' ) . '</button>';
		echo '<span class="wp-mcp-ai-assistant-builder__tools-count"><strong class="wp-mcp-ai-assistant-builder__selected-count">0</strong> ' . esc_html__( 'tools selected', 'wp-mcp-ai' ) . '</span>';
		echo '</div>';

		echo '<div class="wp-mcp-ai-assistant-builder__tools-grid">';

		foreach ( $tools_data['groups'] as $group_id => $group ) {
			$open_attr = $collapsed ? '' : ' open';
			echo '<details class="wp-mcp-ai-assistant-builder__tools-group"' . $open_attr . '>';
			echo '<summary>';
			echo '<span class="wp-mcp-ai-assistant-builder__group-title">' . esc_html( $group['label'] ) . '</span>';
			echo '<span class="wp-mcp-ai-assistant-builder__group-count"><span class="wp-mcp-ai-assistant-builder__group-selected">0</span> / ' . esc_html( count( $group['tools'] ) ) . '</span>';
			echo '</summary>';
			echo '<ul class="wp-mcp-ai-assistant-builder__tools-list">';

			foreach ( $group['tools'] as $tool ) {
				echo '<li class="wp-mcp-ai-assistant-builder__tool-item" data-tool-slug="' . esc_attr( $tool['slug'] ) . '">';
				echo '<div class="wp-mcp-ai-assistant-builder__tool-header">';
				echo '<input type="checkbox" class="wp-mcp-ai-assistant-builder__tool-checkbox" id="tool-' . esc_attr( $this->get_id() ) . '-' . esc_attr( $tool['slug'] ) . '" value="' . esc_attr( $tool['slug'] ) . '">';
				echo '<label for="tool-' . esc_attr( $this->get_id() ) . '-' . esc_attr( $tool['slug'] ) . '">';
				echo '<span class="wp-mcp-ai-assistant-builder__tool-name">' . esc_html( $tool['name'] ) . '</span>';
				echo '</label>';
				echo '</div>';

				if ( $show_descriptions && ! empty( $tool['description'] ) ) {
					echo '<p class="wp-mcp-ai-assistant-builder__tool-desc">' . esc_html( $tool['description'] ) . '</p>';
				}

				echo '</li>';
			}

			echo '</ul>';
			echo '</details>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the chat container.
	 *
	 * @param bool   $enable_streaming  Enable SSE streaming.
	 * @param bool   $save_transcript   Save transcripts.
	 * @param bool   $show_build_button Show build button.
	 * @param string $placeholder       Chat placeholder text.
	 */
	protected function render_chat_container( $enable_streaming, $save_transcript, $show_build_button, $placeholder ) {
		echo '<div class="wp-mcp-ai-assistant-builder__chat" style="display: none;">';
		echo '<div class="wp-mcp-ai-assistant-builder__chat-container" ';
		echo 'data-streaming="' . ( $enable_streaming ? 'true' : 'false' ) . '" ';
		echo 'data-save-transcript="' . ( $save_transcript ? 'true' : 'false' ) . '" ';
		echo 'data-show-build="' . ( $show_build_button ? 'true' : 'false' ) . '" ';
		echo 'data-placeholder="' . esc_attr( $placeholder ) . '">';
		echo '<!-- Chat interface will be initialized here -->';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render inline configuration for JavaScript.
	 *
	 * @param array $settings Widget settings.
	 */
	protected function render_inline_config( $settings ) {
		$config = array(
			'widgetId'             => $this->get_id(),
			'showAssistantSelector' => ! empty( $settings['show_assistant_selector'] ) && 'yes' === $settings['show_assistant_selector'],
			'showToolsGrid'        => ! empty( $settings['show_tools_grid'] ) && 'yes' === $settings['show_tools_grid'],
			'showBuildButton'      => ! empty( $settings['show_build_button'] ) && 'yes' === $settings['show_build_button'],
			'defaultAssistant'     => isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0,
			'restUrl'              => rest_url( 'mcp-ai/v1' ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'createNonce'          => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
		);

		echo '<script type="application/json" class="wp-mcp-ai-assistant-builder-config">';
		echo wp_json_encode( $config );
		echo '</script>';
	}

	/**
	 * Get assistants data for the selector.
	 *
	 * @return array
	 */
	protected function get_assistants_data() {
		$assistants = array();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $assistants;
		}

		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$tools = get_post_meta( $post->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
			if ( ! is_array( $tools ) ) {
				$tools = array();
			}

			$shortcuts = array();
			if ( class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
				$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $post->ID );
			}

			$config   = array();
			$provider = '';
			$model    = '';
			if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
				$config   = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post->ID );
				$provider = ! empty( $config['provider'] ) ? $config['provider'] : '';
				$model    = ! empty( $config['model'] ) ? $config['model'] : '';
			}

			$assistants[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'tools'     => $tools,
				'shortcuts' => $shortcuts,
				'provider'  => $provider,
				'model'     => $model,
			);
		}

		return $assistants;
	}

	/**
	 * Get tools data organized by groups.
	 *
	 * @return array
	 */
	protected function get_tools_data() {
		$data = array(
			'groups' => array(),
		);

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $data;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		$group_map    = array();
		$group_labels = array();

		if ( method_exists( $registry, 'get_tool_group_map' ) ) {
			$group_map = $registry->get_tool_group_map();
		}
		if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
			$group_labels = $registry->get_tool_group_labels();
		}

		if ( ! is_array( $group_map ) ) {
			$group_map = array();
		}
		if ( ! is_array( $group_labels ) ) {
			$group_labels = array();
		}
		if ( ! isset( $group_labels['other'] ) ) {
			$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
		}

		$grouped = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();
			if ( '' === $slug ) {
				continue;
			}

			$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';
			if ( '' === $group_id ) {
				$group_id = 'other';
			}

			if ( ! isset( $grouped[ $group_id ] ) ) {
				$grouped[ $group_id ] = array(
					'label' => isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucfirst( $group_id ),
					'tools' => array(),
				);
			}

			$definition = $tool->get_definition();

			$grouped[ $group_id ]['tools'][] = array(
				'slug'        => $slug,
				'name'        => isset( $definition['name'] ) ? $definition['name'] : $slug,
				'description' => isset( $definition['description'] ) ? $definition['description'] : '',
			);
		}

		// Order groups.
		$ordered = array();
		foreach ( $group_labels as $group_id => $label ) {
			if ( isset( $grouped[ $group_id ] ) ) {
				$ordered[ $group_id ] = $grouped[ $group_id ];
			}
		}
		foreach ( $grouped as $group_id => $group ) {
			if ( ! isset( $ordered[ $group_id ] ) ) {
				$ordered[ $group_id ] = $group;
			}
		}

		$data['groups'] = $ordered;

		return $data;
	}
}
