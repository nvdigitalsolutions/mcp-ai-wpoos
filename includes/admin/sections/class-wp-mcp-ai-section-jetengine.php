<?php
/**
 * JetEngine Integration Settings Section
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_JetEngine_Integration' ) ) {
	/**
	 * JetEngine integration settings section.
	 */
	class WP_MCP_AI_Section_JetEngine_Integration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'integration_jetengine';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'JetEngine Integration', 'mcp-ai-wpoos' );
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
			$jetengine_active = class_exists( 'Jet_Engine' );
			if ( ! $jetengine_active ) {
				return __( 'JetEngine is not active. Install and activate JetEngine to enable advanced CCT storage, custom post type management, and additional AI tools.', 'mcp-ai-wpoos' );
			}
			return __( 'JetEngine provides Custom Content Types (CCT) for efficient data storage, advanced post type management, and 5+ additional AI tools.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/integrations/jetengine-api-compatibility.md';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 30;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$jetengine_active = class_exists( 'Jet_Engine' );

			$fields = array(
				'jetengine_status' => array(
					'type'    => 'html',
					'content' => $this->get_status_content(),
				),
			);

			if ( $jetengine_active ) {
				$fields['enable_jetengine_cct'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine CCT Storage', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable JetEngine CCT storage', 'mcp-ai-wpoos' ),
					'description'    => __( 'Use JetEngine Custom Content Types for efficient chat transcript and assistant data storage.', 'mcp-ai-wpoos' ),
					'default'        => true,
				);

				$fields['enable_jetengine_tools'] = array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine AI Tools', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable JetEngine AI tools', 'mcp-ai-wpoos' ),
					'description'    => __( 'Activate JetEngine-specific tools for post type management, taxonomy operations, and CCT queries.', 'mcp-ai-wpoos' ),
					'default'        => true,
				);

				$fields['jetengine_tools_list'] = array(
					'type'    => 'html',
					'content' => $this->get_tools_list_content(),
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
			$jetengine_active = class_exists( 'Jet_Engine' );

			$content = '<div style="background: ' . ( $jetengine_active ? '#d5f0db' : '#f0f0f1' ) . '; border-left: 4px solid ' . ( $jetengine_active ? '#0a5f1a' : '#646970' ) . '; padding: 1rem; margin: 1rem 0;">';

			if ( $jetengine_active ) {
				$content .= '<h4 style="margin-top: 0; color: #0a5f1a;">' . esc_html__( '✓ JetEngine Active', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'JetEngine is installed and active. Advanced features are available.', 'mcp-ai-wpoos' ) . '</p>';
			} else {
				$content .= '<h4 style="margin-top: 0; color: #646970;">' . esc_html__( 'JetEngine Not Active', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'JetEngine is not installed or not active. To enable JetEngine integration features:', 'mcp-ai-wpoos' ) . '</p>';
				$content .= '<ol>';
				$content .= '<li>' . esc_html__( 'Install JetEngine plugin from Crocoblock', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Activate the plugin', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '<li>' . esc_html__( 'Return to this page to configure integration settings', 'mcp-ai-wpoos' ) . '</li>';
				$content .= '</ol>';
			}

			$content .= '</div>';

			return $content;
		}

		/**
		 * Get tools list content HTML.
		 *
		 * @return string
		 */
		private function get_tools_list_content() {
			$content  = '<div style="margin: 1rem 0;">';
			$content .= '<h4>' . esc_html__( 'Available JetEngine Tools', 'mcp-ai-wpoos' ) . '</h4>';
			$content .= '<ul style="margin-left: 1.5rem;">';
			$content .= '<li><strong>jetengine_create_post_type</strong> - ' . esc_html__( 'Create custom post types dynamically', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>jetengine_create_taxonomy</strong> - ' . esc_html__( 'Create custom taxonomies', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>jetengine_query_cct</strong> - ' . esc_html__( 'Query Custom Content Types efficiently', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>jetengine_create_cct_item</strong> - ' . esc_html__( 'Create CCT entries programmatically', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li><strong>jetengine_update_cct_item</strong> - ' . esc_html__( 'Update existing CCT items', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '</ul>';
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
