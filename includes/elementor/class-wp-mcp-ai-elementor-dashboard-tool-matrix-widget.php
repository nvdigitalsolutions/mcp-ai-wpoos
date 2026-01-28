// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget for displaying a matrix of available NV oOS tools.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * @package WP_MCP_AI
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! defined( 'ABSPATH' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	exit;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget definition for the assistant tool matrix.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
class WP_MCP_AI_Elementor_Dashboard_Tool_Matrix_Widget extends \Elementor\Widget_Base {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	use WP_MCP_AI_Elementor_Text_Formatting;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget slug.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_name() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'wp_mcp_ai_tool_matrix';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget title shown in the Elementor editor.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_title() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return __( 'NV oOS Tool Matrix', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget icon for Elementor panel.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_icon() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'eicon-table';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget categories.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_categories() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'general' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Keywords to help search for the widget.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_keywords() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'mcp', 'tool', 'assistant', 'matrix', 'dashboard' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Register controls for the widget settings.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function register_controls() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->start_controls_section(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'section_content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label' => __( 'Matrix Content', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'title',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Title', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Assistant tool matrix', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'placeholder' => __( 'Enter heading text…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'description',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Description', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'placeholder' => __( 'Provide context for the tool list.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'rows'        => 4,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Review each bundled MCP tool, its slug, and the capability required before enabling it for assistants.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'show_capability_notes',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'        => __( 'Show capability notes', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'         => \Elementor\Controls_Manager::SWITCHER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'return_value' => 'yes',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'      => 'yes',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->end_controls_section();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_theme_style_controls(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'section_id' => 'section_style_tool_matrix',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectors'  => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'container' => '{{WRAPPER}} .wp-mcp-ai-tool-matrix',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'heading'   => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__group-title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'text'      => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__description',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__notice',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--description',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'meta'      => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--slug',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--capability',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'link'      => '{{WRAPPER}} .wp-mcp-ai-tool-matrix a',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Render the widget on the front-end.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function render() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$settings = $this->get_settings_for_display();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$title             = isset( $settings['title'] ) ? $settings['title'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$description       = isset( $settings['description'] ) ? $settings['description'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$show_capabilities = ! empty( $settings['show_capability_notes'] ) && 'yes' === $settings['show_capability_notes'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-tool-matrix">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! empty( $title ) ) {
				echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
			echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'The tool registry is unavailable.', 'mcp-ai-wpoos' ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( method_exists( $registry, 'init' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$registry->init();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$tools = $registry->get_tools();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$group_map    = $this->get_tool_group_map();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$group_labels = $this->get_group_labels();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$capabilities = $this->get_capability_notes();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$grouped = array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		foreach ( $tools as $tool ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				continue;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$slug     = sanitize_key( $tool->get_slug() );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_id = isset( $group_map[ $slug ] ) ? $group_map[ $slug ] : 'other';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group    = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : $group_labels['other'];
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! isset( $grouped[ $group ] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$grouped[ $group ] = array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$grouped[ $group ][] = array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'name'        => $tool->get_name(),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'slug'        => $slug,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'capability'  => isset( $capabilities[ $slug ] ) ? $capabilities[ $slug ] : $capabilities['default'],
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'description' => $tool->get_description(),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( empty( $grouped ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-tool-matrix">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! empty( $title ) ) {
				echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
			echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'No tools are currently registered.', 'mcp-ai-wpoos' ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-tool-matrix">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! empty( $description ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$description_output = $this->format_text_block( $description );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-tool-matrix__description">' . $description_output . '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		foreach ( $grouped as $group_label => $entries ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$has_descriptions     = false;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$formatted_entries    = array();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$has_capability_notes = false;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			foreach ( $entries as $entry ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$formatted_entry = array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'name'        => $entry['name'],
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'slug'        => $entry['slug'],
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'capability'  => '',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'description' => '',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( $show_capabilities ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					$capability_output = $this->format_text_inline( $entry['capability'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
					if ( '' !== $capability_output ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
						$formatted_entry['capability'] = $capability_output;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						$has_capability_notes          = true;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( ! empty( $entry['description'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					$description_output = $this->format_text_inline( $entry['description'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
					if ( '' !== $description_output ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
						$formatted_entry['description'] = $description_output;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						$has_descriptions               = true;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$formatted_entries[] = $formatted_entry;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-tool-matrix__group">';
			echo '<h4 class="wp-mcp-ai-tool-matrix__group-title">' . esc_html( $group_label ) . '</h4>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-tool-matrix__table">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<table class="wp-mcp-ai-tool-matrix__table-grid">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<thead>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<tr class="wp-mcp-ai-tool-matrix__table-row wp-mcp-ai-tool-matrix__table-row--head">';
			echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html__( 'Tool', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug">' . esc_html__( 'Slug', 'mcp-ai-wpoos' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( $has_capability_notes ) {
				echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . esc_html__( 'Required capability', 'mcp-ai-wpoos' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( $has_descriptions ) {
				echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html__( 'Description', 'mcp-ai-wpoos' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</tr>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</thead>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<tbody>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			foreach ( $formatted_entries as $formatted_entry ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '<tr class="wp-mcp-ai-tool-matrix__table-row">';
				echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html( $formatted_entry['name'] ) . '</td>';
				echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug"><code>' . esc_html( $formatted_entry['slug'] ) . '</code></td>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( $has_capability_notes ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
					echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . $formatted_entry['capability'] . '</td>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( $has_descriptions ) {
					echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html( $formatted_entry['description'] ) . '</td>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				echo '</tr>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</tbody>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</table>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Mapping of tool slugs to group identifiers.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_tool_group_map() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( method_exists( $registry, 'init' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$registry->init();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( method_exists( $registry, 'get_tool_group_map' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$registry_map = $registry->get_tool_group_map();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( is_array( $registry_map ) && ! empty( $registry_map ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					return $registry_map;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'submit_document_prompt'       => 'content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'search_content'               => 'content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'search_attachments'           => 'content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_recent_posts'             => 'content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'save_post'                    => 'content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_user_info'                => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_site_summary'             => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_system_logs'              => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'open_openai_usage'            => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'open_openai_logs'             => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'create_cron_job'              => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'purge_cloudflare_cache'       => 'operations',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'run_openai_external_action'   => 'automation',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'run_crawl4ai_job'             => 'automation',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'create_google_calendar_event' => 'automation',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'web_search'                   => 'external-data',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_gdacs_events'             => 'external-data',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_open_meteo_forecast'      => 'external-data',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_nhc_active_storms'        => 'external-data',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'reliefweb_reports'            => 'external-data',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'generate_openai_image'        => 'media',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'generate_openai_speech'       => 'media',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'transcribe_openai_audio'      => 'media',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_jetengine_items'          => 'jetengine',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'list_jetengine_rest_routes'   => 'jetengine',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'invoke_jetengine_route'       => 'jetengine',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_woo_recent_orders'        => 'commerce',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_woo_products'             => 'commerce',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'quickbooks_report'            => 'commerce',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'search_gmail'                 => 'communication',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'send_group_email'             => 'communication',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'send_mailjet_email'           => 'communication',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'send_telegram_message'        => 'communication',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Human readable labels for tool groups.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_group_labels() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( method_exists( $registry, 'init' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$registry->init();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$registry_labels = $registry->get_tool_group_labels();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				if ( is_array( $registry_labels ) && ! empty( $registry_labels ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					return $registry_labels;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'content'       => __( 'Content ingestion & search', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'media'         => __( 'Media generation & transcription', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'automation'    => __( 'Automations & workflows', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'jetengine'     => __( 'JetEngine REST utilities', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'commerce'      => __( 'Commerce & finance', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'communication' => __( 'Communications & outreach', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'external-data' => __( 'External data sources', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'operations'    => __( 'Site operations & maintenance', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'other'         => __( 'Other tools', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Capability notes for each tool slug.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_capability_notes() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$group_email = $this->get_group_email_configuration();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' === $group_email['label'] && '' !== $group_email['capability'] ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_email['label'] = $this->format_capability_label( $group_email['capability'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' === $group_email['capability'] ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_capability_note = __( 'Allows any logged-in user to send group emails.', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_capability_note = sprintf(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				/* translators: %s: capability label. */
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				__( 'Requires the %s capability configured in the settings.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$group_email['label']
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $group_email['limit'] > 0 ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_limit_note = sprintf(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				/* translators: %d: maximum recipients per request. */
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				__( 'Limited to %d recipients per request.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$group_email['limit']
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$group_limit_note = __( 'No recipient limit is enforced.', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'default'                    => __( 'Requires authenticated access.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'submit_document_prompt'     => __( 'Requires upload permissions matching attachment handling.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_recent_posts'           => __( 'Requires the "read" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_user_info'              => __( 'Requires login; "list_users" or "manage_options" to inspect other profiles.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_site_summary'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_jetengine_items'        => __( 'Requires access to the JetEngine post type (typically "edit_posts").', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_woo_recent_orders'      => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'get_woo_products'           => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'generate_openai_image'      => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'generate_openai_speech'     => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'transcribe_openai_audio'    => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'run_openai_external_action' => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'run_crawl4ai_job'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'web_search'                 => __( 'Requires the "read" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'list_jetengine_rest_routes' => __( 'Requires the "manage_options" capability and JetEngine.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'invoke_jetengine_route'     => __( 'Requires JetEngine access for the requested operation.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'create_cron_job'            => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'send_group_email'           => trim( $group_capability_note . ' ' . $group_limit_note ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'open_openai_usage'          => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'open_openai_logs'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Retrieve the Send Group Email capability and limit from settings.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array{
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *     capability: string,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *     label: string,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *     limit: int
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * }
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_group_email_configuration() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$capability = 'publish_posts';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$limit      = 100;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( isset( $settings['group_email_capability'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$capability = sanitize_key( $settings['group_email_capability'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( isset( $settings['group_email_max_recipients'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$limit = absint( $settings['group_email_max_recipients'] );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$context    = array( 'user_id' => get_current_user_id() );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$capability = apply_filters( 'wp_mcp_ai_send_group_email_capability', $capability, $context, array(), null );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$limit      = apply_filters( 'wp_mcp_ai_send_group_email_max_recipients', $limit, $context, array(), null );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! is_string( $capability ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$capability = '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$capability = sanitize_key( $capability );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! is_numeric( $limit ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$limit = 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$limit = max( 0, absint( $limit ) );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$label = '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' === $capability ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$label = __( 'Any logged-in user', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$label = $this->format_capability_label( $capability );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'capability' => $capability,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'label'      => $label,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'limit'      => $limit,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Convert a capability slug into a readable label.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $capability Capability slug.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return string
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function format_capability_label( $capability ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$capability = sanitize_key( $capability );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' === $capability ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$readable = trim( preg_replace( '/[\-_]+/', ' ', (string) $capability ) );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$readable = preg_replace( '/\s+/', ' ', $readable );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' === $readable ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return $capability;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$readable = ucwords( $readable );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( strtolower( $readable ) === strtolower( $capability ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return $readable;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return sprintf( '%1$s (%2$s)', $readable, $capability );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
