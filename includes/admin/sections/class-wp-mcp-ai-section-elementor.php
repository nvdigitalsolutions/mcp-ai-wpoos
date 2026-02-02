<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Elementor Integration Settings Section
 *
 * @package WP_MCP_AI
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Elementor_Integration' ) ) {
	/**
	 * Elementor integration settings section.
	 */
	class WP_MCP_AI_Section_Elementor_Integration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'integration_elementor';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Elementor Integration', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			$elementor_active = defined( 'ELEMENTOR_VERSION' );
			if ( ! $elementor_active ) {
				return __( 'Elementor is not active. Install and activate Elementor to enable AI chat widgets.', 'mcp-ai-wpoos' );
			}
			return __( 'Elementor integration provides AI Chat widgets for page building with real-time streaming support.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/integrations/elementor-widgets.md';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 50;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$elementor_active = defined( 'ELEMENTOR_VERSION' );

			$fields = array(
				'elementor_status' => array(
					'type'    => 'html',
					'content' => $this->get_status_content(),
				),
			);

			if ( $elementor_active ) {
				$fields['enable_elementor_widgets'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Elementor Widgets', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Elementor AI widgets', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enable AI Chat widgets for Elementor page builder. Part of base plugin (no Pro addon required).', 'mcp-ai-wpoos' ),
					'default'        => true,
				);

				$fields['elementor_widgets_list'] = array(
					'type'    => 'html',
					'content' => $this->get_widgets_list_content(),
				);
			}

			return $fields;
		}

		/**
		 * Get status content HTML.
		 *
		 * @return string
		 */
		private function get_status_content() {
			$elementor_active = defined( 'ELEMENTOR_VERSION' );

			$content = '<div style="background: ' . ( $elementor_active ? '#d5f0db' : '#f0f0f1' ) . '; border-left: 4px solid ' . ( $elementor_active ? '#0a5f1a' : '#646970' ) . '; padding: 1rem; margin: 1rem 0;">';

			if ( $elementor_active ) {
				$content .= '<h4 style="margin-top: 0; color: #0a5f1a;">' . esc_html__( '✓ Elementor Active - Widgets Automatically Enabled', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'Elementor is installed and active. All AI Chat widgets are automatically available in the Elementor editor.', 'mcp-ai-wpoos' ) . '</p>';
				if ( defined( 'ELEMENTOR_VERSION' ) ) {
					$content .= '<p><strong>' . esc_html__( 'Version:', 'mcp-ai-wpoos' ) . '</strong> ' . esc_html( ELEMENTOR_VERSION ) . '</p>';
				}
			} else {
				$content .= '<h4 style="margin-top: 0; color: #646970;">' . esc_html__( 'Elementor Not Active', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'Elementor is not installed or not active. To enable Elementor integration:', 'mcp-ai-wpoos' ) . '</p>';
				$content .= '<ol>';
				$content .= '<li>' . esc_html__( 'Install Elementor plugin from WordPress.org', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Activate the plugin', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Widgets will be automatically available in Elementor editor', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '</ol>';
			}

			$content .= '</div>';

			return $content;
		}

		/**
		 * Get widgets list content HTML.
		 *
		 * @return string
		 */
		private function get_widgets_list_content() {
			$content  = '<div style="margin: 1rem 0;">';
			$content .= '<h4>' . esc_html__( 'Available Elementor Widgets', 'mcp-ai-wpoos' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li><strong>NV oOS Chat</strong> - ' . esc_html__( 'Interactive AI chat interface with streaming responses', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>Assistant Selector</strong> - ' . esc_html__( 'Dropdown to switch between available assistants', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>Chat History</strong> - ' . esc_html__( 'Display conversation history with filtering options', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '</ul>';
			$content .= '<h4>' . esc_html__( 'Widget Features', 'mcp-ai-wpoos' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li>' . esc_html__( 'Real-time SSE streaming for instant responses', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Customizable styling and appearance', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Tool execution with visual feedback', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Markdown rendering and code syntax highlighting', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '</ul>';

			// Elementor widgets are part of the base plugin and do not require Pro addon.
			// Check the "Enable Elementor Widgets" checkbox above to make them available in the editor.
			$content .= '<p style="margin-top: 1rem; background: #d5f0db; border-left: 4px solid #0a5f1a; padding: 1rem;"><strong style="color: #0a5f1a;">✓ ' . esc_html__( 'Part of Base Plugin', 'mcp-ai-wpoos' ) . '</strong><br>' . esc_html__( 'Elementor widgets are included in the base plugin (no Pro addon required). Check the "Enable Elementor Widgets" checkbox above to make them available in the Elementor editor.', 'mcp-ai-wpoos' ) . '</p>';

			$content .= '</div>';

			return $content;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				if ( 'html' === $field['type'] ) {
					echo wp_kses_post( $field['content'] );
				} else {
					$this->render_field( $key, $field );
				}
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			// All fields are boolean checkboxes, no special validation needed.
			return $input;
		}
	}
}
