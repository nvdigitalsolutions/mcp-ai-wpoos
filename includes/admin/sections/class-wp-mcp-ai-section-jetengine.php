<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * JetEngine Integration Settings Section
 *
 * @package WP_MCP_AI
 */


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

				// Add JetEngine CPT AI Integration field (Pro feature).
				if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) || ! wp_mcp_ai_is_base_version() ) {
					$fields['enable_jetengine_cpt_ai'] = array(
						'type'           => 'checkbox',
						'label'          => __( 'Enable AI Assistant for JetEngine CPTs', 'mcp-ai-wpoos' ),
						'checkbox_label' => __( 'Enable AI assistant metabox for JetEngine custom post types', 'mcp-ai-wpoos' ),
						'description'    => __( 'Adds an AI assistant metabox to all JetEngine custom post type edit screens. Users can get AI help with content creation, editing, and optimization. (Pro Feature)', 'mcp-ai-wpoos' ),
						'default'        => true,
					);

					$fields['enable_jetengine_cpt_research_add'] = array(
						'type'           => 'checkbox',
						'label'          => __( 'Enable Research & Add Pages for JetEngine CPTs', 'mcp-ai-wpoos' ),
						'checkbox_label' => __( 'Enable Research & Add admin pages for JetEngine custom post types', 'mcp-ai-wpoos' ),
						'description'    => __( 'Creates dedicated "Research & Add" pages for each JetEngine CPT. These pages provide AI-powered research and data entry interfaces, similar to toolkit Research & Add pages. The pages appear as submenu items under each CPT. (Pro Feature)', 'mcp-ai-wpoos' ),
						'default'        => true,
					);

					$fields['jetengine_cpts_list'] = array(
						'type'    => 'html',
						'content' => $this->get_jetengine_cpts_list_content(),
					);
				}

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
		 * Get JetEngine CPTs list content HTML.
		 *
		 * @return string
		 */
		private function get_jetengine_cpts_list_content() {
			$jetengine_cpts       = $this->get_jetengine_cpts();
			$jetengine_taxonomies = $this->get_jetengine_taxonomies();

			$content  = '<div style="margin: 1rem 0;">';
			$content .= '<h4>' . esc_html__( 'Detected JetEngine Custom Post Types', 'mcp-ai-wpoos' ) . '</h4>';

			if ( empty( $jetengine_cpts ) ) {
				$content .= '<p style="color: #646970;">' . esc_html__( 'No JetEngine custom post types found.', 'mcp-ai-wpoos' ) . '</p>';
			} else {
				$content .= $this->render_entities_table( $jetengine_cpts, 'cpt' );
			}

			$content .= '<h4 style="margin-top: 1.5rem;">' . esc_html__( 'Detected JetEngine Custom Taxonomies', 'mcp-ai-wpoos' ) . '</h4>';

			if ( empty( $jetengine_taxonomies ) ) {
				$content .= '<p style="color: #646970;">' . esc_html__( 'No JetEngine custom taxonomies found.', 'mcp-ai-wpoos' ) . '</p>';
			} else {
				$content .= $this->render_entities_table( $jetengine_taxonomies, 'taxonomy' );
			}

			$content .= '<p class="description" style="margin-top: 0.5rem;">';
			$content .= esc_html__( 'AI Assistant: Metabox appears on edit screens. Research & Add: Dedicated submenu page under each CPT/Taxonomy.', 'mcp-ai-wpoos' );
			$content .= '</p>';

			$content .= '</div>';

			return $content;
		}

		/**
		 * Render entities table (CPTs or Taxonomies).
		 *
		 * @param array  $entities List of entities (CPTs or taxonomies).
		 * @param string $type     Entity type ('cpt' or 'taxonomy').
		 * @return string HTML table.
		 */
		private function render_entities_table( $entities, $type ) {
			$settings         = get_option( 'wp_mcp_ai_settings', array() );
			$ai_enabled       = isset( $settings['enable_jetengine_cpt_ai'] ) ? (bool) $settings['enable_jetengine_cpt_ai'] : true;
			$research_enabled = isset( $settings['enable_jetengine_cpt_research_add'] ) ? (bool) $settings['enable_jetengine_cpt_research_add'] : true;

			$table  = '<table class="widefat" style="margin-top: 0.5rem;">';
			$table .= '<thead><tr>';
			$table .= '<th>' . esc_html__( 'Slug', 'mcp-ai-wpoos' ) . '</th>';
			$table .= '<th>' . esc_html__( 'Name', 'mcp-ai-wpoos' ) . '</th>';
			$table .= '<th>' . esc_html__( 'AI Assistant', 'mcp-ai-wpoos' ) . '</th>';
			$table .= '<th>' . esc_html__( 'Research & Add', 'mcp-ai-wpoos' ) . '</th>';
			$table .= '</tr></thead><tbody>';

			foreach ( $entities as $entity_data ) {
				$slug = isset( $entity_data['slug'] ) ? $entity_data['slug'] : '';
				$name = isset( $entity_data['name'] ) ? $entity_data['name'] : $slug;

				if ( empty( $slug ) ) {
					continue;
				}

				// Get proper label from WordPress.
				if ( 'cpt' === $type ) {
					$object = get_post_type_object( $slug );
					if ( $object ) {
						$name = $object->labels->name;
					}
				} else {
					$object = get_taxonomy( $slug );
					if ( $object ) {
						$name = $object->labels->name;
					}
				}

				$ai_status       = $ai_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Enabled', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>';
				$research_status = $research_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Enabled', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>';

				$table .= '<tr>';
				$table .= '<td><code>' . esc_html( $slug ) . '</code></td>';
				$table .= '<td>' . esc_html( $name ) . '</td>';
				$table .= '<td>' . $ai_status . '</td>';
				$table .= '<td>' . $research_status . '</td>';
				$table .= '</tr>';
			}

			$table .= '</tbody></table>';

			return $table;
		}

		/**
		 * Get JetEngine custom post types.
		 *
		 * @return array Array of JetEngine CPT data.
		 */
		private function get_jetengine_cpts() {
			// Check if JetEngine is active.
			if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
				return array();
			}

			// Get JetEngine post types module.
			$module = jet_engine()->modules->get_module( 'post-type' );
			if ( ! $module || ! $module->instance ) {
				return array();
			}

			// Get registered post types.
			$post_types = $module->instance->get_items();
			if ( empty( $post_types ) || ! is_array( $post_types ) ) {
				return array();
			}

			return $post_types;
		}

		/**
		 * Get JetEngine custom taxonomies.
		 *
		 * @return array Array of JetEngine taxonomy data.
		 */
		private function get_jetengine_taxonomies() {
			// Check if JetEngine is active.
			if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
				return array();
			}

			// Get JetEngine taxonomy module.
			$module = jet_engine()->modules->get_module( 'taxonomy' );
			if ( ! $module || ! $module->instance ) {
				return array();
			}

			// Get registered taxonomies.
			$taxonomies = $module->instance->get_items();
			if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
				return array();
			}

			return $taxonomies;
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
