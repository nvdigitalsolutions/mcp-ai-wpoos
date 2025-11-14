<?php
/**
 * Site Creator Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Site_Creator' ) ) {
	/**
	 * Site Creator settings section.
	 */
	class WP_MCP_AI_Section_Site_Creator extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'site_creator';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Site Creator', 'wp-mcp-ai' );
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
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 35;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Enable AI-powered automated site creation from plans. When enabled, AI assistants can programmatically create complete WordPress sites by installing themes, plugins, configuring options, and creating content.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_site_creator'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Site Creator', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow AI to create and configure sites', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content. This feature requires manage_options capability.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_plugin_install' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Plugin Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic plugin installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_theme_install' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Theme Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic theme installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_option_updates' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Option Updates', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic WordPress option updates', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Render the section.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}

			// Add informational note about capabilities and security.
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creator tools require administrative capabilities (manage_options, install_plugins, install_themes). Only users with these capabilities can execute site creator operations. All plugins and themes are installed exclusively from the official WordPress.org repository.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Performance Consideration:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creation operations (especially plugin/theme installation) can take several minutes to complete and may temporarily impact site performance. These operations are marked as long-running and should be executed with appropriate timeouts.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}
	}
}
