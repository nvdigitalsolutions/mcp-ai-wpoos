<?php
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
			return __( 'Elementor Integration', 'wp-mcp-ai' );
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
				return __( 'Elementor is not active. Install and activate Elementor to enable AI chat widgets.', 'wp-mcp-ai' );
			}
			return __( 'Elementor integration provides AI Chat widgets for page building with real-time streaming support.', 'wp-mcp-ai' );
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
					'label'          => __( 'Enable Elementor Widgets', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Elementor widgets', 'wp-mcp-ai' ),
					'description'    => __( 'Activate AI Chat widget for Elementor page builder with SSE streaming support.', 'wp-mcp-ai' ),
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
				$content .= '<h4 style="margin-top: 0; color: #0a5f1a;">' . esc_html__( '✓ Elementor Active', 'wp-mcp-ai' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'Elementor is installed and active. AI Chat widgets are available.', 'wp-mcp-ai' ) . '</p>';
				if ( defined( 'ELEMENTOR_VERSION' ) ) {
					$content .= '<p><strong>' . esc_html__( 'Version:', 'wp-mcp-ai' ) . '</strong> ' . esc_html( ELEMENTOR_VERSION ) . '</p>';
				}
			} else {
				$content .= '<h4 style="margin-top: 0; color: #646970;">' . esc_html__( 'Elementor Not Active', 'wp-mcp-ai' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'Elementor is not installed or not active. To enable Elementor integration:', 'wp-mcp-ai' ) . '</p>';
				$content .= '<ol>';
				$content .= '<li>' . esc_html__( 'Install Elementor plugin from WordPress.org', 'wp-mcp-ai' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Activate the plugin', 'wp-mcp-ai' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Return to this page to configure integration settings', 'wp-mcp-ai' ) . '</li>';
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
			$content .= '<h4>' . esc_html__( 'Available Elementor Widgets', 'wp-mcp-ai' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li><strong>WP oOS Chat</strong> - ' . esc_html__( 'Interactive AI chat interface with streaming responses', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>Assistant Selector</strong> - ' . esc_html__( 'Dropdown to switch between available assistants', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li><strong>Chat History</strong> - ' . esc_html__( 'Display conversation history with filtering options', 'wp-mcp-ai' ) . '</li>';
			$content .= '</ul>';
			$content .= '<h4>' . esc_html__( 'Widget Features', 'wp-mcp-ai' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li>' . esc_html__( 'Real-time SSE streaming for instant responses', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Customizable styling and appearance', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Tool execution with visual feedback', 'wp-mcp-ai' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Markdown rendering and code syntax highlighting', 'wp-mcp-ai' ) . '</li>';
			$content .= '</ul>';

			// Debug information.
			$is_base_version  = wp_mcp_ai_is_base_version();
			$constant_defined = defined( 'WP_MCP_AI_BASE_VERSION' );
			$constant_value   = $constant_defined ? WP_MCP_AI_BASE_VERSION : 'not defined';

			$content .= '<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1rem; margin-top: 1rem;">';
			$content .= '<p style="margin: 0;"><strong>' . esc_html__( 'Current Configuration:', 'wp-mcp-ai' ) . '</strong></p>';
			$content .= '<ul style="margin: 0.5rem 0 0 1.5rem;">';
			$content .= '<li><strong>WP_MCP_AI_BASE_VERSION:</strong> ';
			if ( $constant_defined ) {
				$content .= '<code>' . esc_html( $constant_value ? 'true' : 'false' ) . '</code>';
			} else {
				$content .= '<code>not defined</code> (defaults to base version)';
			}
			$content .= '</li>';
			$content .= '<li><strong>Mode:</strong> ';
			$content .= $is_base_version ? '<strong style="color: #8b6c00;">Base Version</strong> (widgets disabled)' : '<strong style="color: #0a5f1a;">Full Version</strong> (widgets enabled)';
			$content .= '</li>';
			$content .= '</ul>';
			$content .= '</div>';

			if ( $is_base_version ) {
				$content .= '<p style="margin-top: 1rem; background: #fef7e0; border-left: 4px solid #8b6c00; padding: 1rem;"><strong>' . esc_html__( 'To Enable Elementor Widgets:', 'wp-mcp-ai' ) . '</strong><br>' . esc_html__( 'Add this to wp-config.php:', 'wp-mcp-ai' ) . '<br><code style="background: #fff; padding: 0.25rem 0.5rem; display: inline-block; margin-top: 0.5rem;">define( \'WP_MCP_AI_BASE_VERSION\', false );</code></p>';
			} else {
				$content .= '<p style="margin-top: 1rem; background: #d5f0db; border-left: 4px solid #0a5f1a; padding: 1rem;"><strong style="color: #0a5f1a;">✓ ' . esc_html__( 'Elementor Widgets Enabled', 'wp-mcp-ai' ) . '</strong><br>' . esc_html__( 'Full Version mode is active. All widgets are available.', 'wp-mcp-ai' ) . '</p>';
			}

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
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped in helper methods.
					echo $field['content'];
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
