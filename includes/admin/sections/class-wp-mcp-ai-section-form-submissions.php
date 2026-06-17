<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Form Submissions Dashboard Section
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Form_Submissions' ) ) {
	/**
	 * Form Submissions dashboard section.
	 *
	 * Provides a unified view of form submissions from JetFormBuilder,
	 * Elementor Pro, and configured remote data source connections.
	 */
	class WP_MCP_AI_Section_Form_Submissions extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'form_submissions';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Form Submissions', 'mcp-ai-wpoos' );
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
			return __( 'View and manage form submissions from JetFormBuilder, Elementor Pro, and configured remote data sources in a unified dashboard.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/form-submissions.md';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 60;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// This is a display-only section; no editable fields.
			return array();
		}

		/**
		 * Render the section content.
		 *
		 * Not used — we override render_wrapper() instead.
		 */
		public function render() {
			// Not used - we override render_wrapper() instead.
		}

		/**
		 * Override render_wrapper to provide custom layout without form table.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>
				<?php echo $this->get_overview_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is escaped at generation sites. ?>
			</div>
			<?php
		}

		/**
		 * Get the overview content HTML.
		 *
		 * @return string
		 */
		private function get_overview_content() {
			$content = '';

			// Status cards.
			$content .= $this->get_status_cards();

			// Available tools and capabilities.
			$content .= $this->get_tools_overview();

			// Remote connections status.
			$content .= $this->get_connections_status();

			// Quick start guide.
			$content .= $this->get_quick_start();

			return $content;
		}

		/**
		 * Get status cards showing available data sources.
		 *
		 * @return string
		 */
		private function get_status_cards() {
			$jfb_available   = $this->is_jetformbuilder_available();
			$elementor_avail = $this->is_elementor_submissions_available();
			$all_avail       = $this->is_all_submissions_available();

			$content  = '<div style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1rem 0;">';
			$content .= $this->render_status_card(
				'JetFormBuilder',
				$jfb_available,
				__( 'Submissions from JetFormBuilder forms.', 'mcp-ai-wpoos' ),
				'get_jetformbuilder_submissions',
				'get_jetformbuilder_forms'
			);
			$content .= $this->render_status_card(
				'Elementor Pro',
				$elementor_avail,
				__( 'Submissions from Elementor Pro forms (v3.2+).', 'mcp-ai-wpoos' ),
				'get_elementor_form_submissions',
				'get_elementor_templates'
			);
			$content .= $this->render_status_card(
				__( 'Unified View', 'mcp-ai-wpoos' ),
				$all_avail,
				__( 'Cross-plugin aggregated submissions.', 'mcp-ai-wpoos' ),
				'get_all_form_submissions',
				null
			);
			$content .= '</div>';

			return $content;
		}

		/**
		 * Render a single status card.
		 *
		 * @param string      $title      Card title.
		 * @param bool        $available  Whether the source is available.
		 * @param string      $desc       Description text.
		 * @param string      $tool_slug  Primary tool slug.
		 * @param string|null $extra_slug Optional secondary tool slug.
		 * @return string
		 */
		private function render_status_card( $title, $available, $desc, $tool_slug, $extra_slug ) {
			$bg_color     = $available ? '#d5f0db' : '#f0f0f1';
			$border       = $available ? '#0a5f1a' : '#646970';
			$status_icon  = $available ? '✓' : '✗';
			$status_color = $available ? '#0a5f1a' : '#646970';

			$content  = '<div style="flex: 1; min-width: 220px; background: ' . esc_attr( $bg_color ) . '; border-left: 4px solid ' . esc_attr( $border ) . '; padding: 1rem; border-radius: 3px;">';
			$content .= '<h4 style="margin: 0 0 0.5rem; color: ' . esc_attr( $status_color ) . ';">';
			$content .= esc_html( $status_icon ) . ' ' . esc_html( $title );
			$content .= '</h4>';
			$content .= '<p style="margin: 0 0 0.5rem; font-size: 0.9em;">' . esc_html( $desc ) . '</p>';

			if ( $available ) {
				$content .= '<p style="margin: 0; font-size: 0.85em;">';
				$content .= '<strong>' . esc_html__( 'Tool:', 'mcp-ai-wpoos' ) . '</strong> ';
				$content .= '<code style="background: #fff; padding: 2px 6px; border-radius: 3px;">' . esc_html( $tool_slug ) . '</code>';
				if ( $extra_slug ) {
					$content .= ' · <code style="background: #fff; padding: 2px 6px; border-radius: 3px;">' . esc_html( $extra_slug ) . '</code>';
				}
				$content .= '</p>';
			} else {
				$content .= '<p style="margin: 0; font-size: 0.8em; color: #646970;">';
				$content .= esc_html__( 'Install and activate the required plugin to enable this data source.', 'mcp-ai-wpoos' );
				$content .= '</p>';
			}

			$content .= '</div>';
			return $content;
		}

		/**
		 * Get tools overview content.
		 *
		 * @return string
		 */
		private function get_tools_overview() {
			$content  = '<div style="margin: 1.5rem 0; padding: 1rem; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px;">';
			$content .= '<h3 style="margin-top: 0;">' . esc_html__( 'Available MCP Tools', 'mcp-ai-wpoos' ) . '</h3>';
			$content .= '<table class="widefat striped" style="margin: 1rem 0;">';
			$content .= '<thead><tr>';
			$content .= '<th>' . esc_html__( 'Tool Slug', 'mcp-ai-wpoos' ) . '</th>';
			$content .= '<th>' . esc_html__( 'Description', 'mcp-ai-wpoos' ) . '</th>';
			$content .= '<th>' . esc_html__( 'Source', 'mcp-ai-wpoos' ) . '</th>';
			$content .= '<th>' . esc_html__( 'Remote Support', 'mcp-ai-wpoos' ) . '</th>';
			$content .= '</tr></thead><tbody>';

			$tools = array(
				array(
					'get_jetformbuilder_forms',
					__( 'Lists JetFormBuilder forms with metadata.', 'mcp-ai-wpoos' ),
					'JetFormBuilder',
					'✓',
				),
				array(
					'get_jetformbuilder_submissions',
					__( 'Retrieves submissions for a given JFB form.', 'mcp-ai-wpoos' ),
					'JetFormBuilder',
					'✓',
				),
				array(
					'get_elementor_form_submissions',
					__( 'Retrieves Elementor Pro form submissions.', 'mcp-ai-wpoos' ),
					'Elementor Pro',
					'✓',
				),
				array(
					'get_all_form_submissions',
					__( 'Aggregates submissions from all sources.', 'mcp-ai-wpoos' ),
					__( 'All Sources', 'mcp-ai-wpoos' ),
					'✓',
				),
			);

			foreach ( $tools as $tool ) {
				$content .= '<tr>';
				$content .= '<td><code>' . esc_html( $tool[0] ) . '</code></td>';
				$content .= '<td>' . esc_html( $tool[1] ) . '</td>';
				$content .= '<td>' . esc_html( $tool[2] ) . '</td>';
				$content .= '<td><span style="color: #0a5f1a;">' . esc_html( $tool[3] ) . '</span></td>';
				$content .= '</tr>';
			}

			$content .= '</tbody></table>';
			$content .= '</div>';

			return $content;
		}

		/**
		 * Get remote connections status content.
		 *
		 * @return string
		 */
		private function get_connections_status() {
			$content = '';

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$content .= '<div style="margin: 1rem 0; padding: 1rem; background: #fcf9e8; border-left: 4px solid #dba617;">';
				$content .= '<h4 style="margin-top: 0;">' . esc_html__( 'Remote Connections', 'mcp-ai-wpoos' ) . '</h4>';
				$content .= '<p>' . esc_html__( 'Remote data source connections require the Pro addon. Upgrade to connect to form data on other WordPress sites.', 'mcp-ai-wpoos' ) . '</p>';
				$content .= '</div>';
				return $content;
			}

			$connections      = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			$form_connections = array();

			foreach ( $connections as $conn_id => $conn ) {
				$conn_type = isset( $conn['connection_type'] ) ? $conn['connection_type'] : '';
				if ( in_array( $conn_type, array( 'wordpress', 'form_data_source', 'generic' ), true ) && ! empty( $conn['enabled'] ) ) {
					$form_connections[ $conn_id ] = $conn;
				}
			}

			$content .= '<div style="margin: 1.5rem 0; padding: 1rem; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px;">';
			$content .= '<h3 style="margin-top: 0;">' . esc_html__( 'Remote Data Sources', 'mcp-ai-wpoos' ) . '</h3>';

			if ( empty( $form_connections ) ) {
				$content         .= '<p>' . esc_html__( 'No remote form data sources configured.', 'mcp-ai-wpoos' ) . '</p>';
				$remote_sites_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' );
				$content         .= '<p>';
				$content         .= '<a href="' . esc_url( $remote_sites_url ) . '" class="button button-secondary">';
				$content         .= esc_html__( 'Add Remote Connection', 'mcp-ai-wpoos' );
				$content         .= '</a> ';
				$content         .= esc_html__( 'Choose "Form Data Source (JFB / Elementor)" as the connection type to query form submissions from another WordPress site.', 'mcp-ai-wpoos' );
				$content         .= '</p>';
			} else {
				$content .= '<table class="widefat striped" style="margin: 1rem 0;">';
				$content .= '<thead><tr>';
				$content .= '<th>' . esc_html__( 'Connection', 'mcp-ai-wpoos' ) . '</th>';
				$content .= '<th>' . esc_html__( 'URL', 'mcp-ai-wpoos' ) . '</th>';
				$content .= '<th>' . esc_html__( 'Type', 'mcp-ai-wpoos' ) . '</th>';
				$content .= '<th>' . esc_html__( 'Connection ID', 'mcp-ai-wpoos' ) . '</th>';
				$content .= '</tr></thead><tbody>';

				foreach ( $form_connections as $conn_id => $conn ) {
					$content .= '<tr>';
					$content .= '<td>' . esc_html( isset( $conn['name'] ) ? $conn['name'] : $conn_id ) . '</td>';
					$content .= '<td>' . esc_html( isset( $conn['url'] ) ? $conn['url'] : '' ) . '</td>';
					$content .= '<td>' . esc_html( isset( $conn['connection_type'] ) ? $conn['connection_type'] : '' ) . '</td>';
					$content .= '<td><code>' . esc_html( $conn_id ) . '</code></td>';
					$content .= '</tr>';
				}

				$content .= '</tbody></table>';
			}

			$content .= '</div>';

			return $content;
		}

		/**
		 * Get quick start guide content.
		 *
		 * @return string
		 */
		private function get_quick_start() {
			$content  = '<div style="margin: 1.5rem 0; padding: 1rem; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px;">';
			$content .= '<h3 style="margin-top: 0;">' . esc_html__( 'Quick Start', 'mcp-ai-wpoos' ) . '</h3>';
			$content .= '<ol style="margin-left: 1.5rem; line-height: 1.6;">';
			$content .= '<li>' . esc_html__( 'Ensure JetFormBuilder and/or Elementor Pro (3.2+) is installed and active.', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'For Elementor Pro: enable "Collect Submissions" in your form\'s Actions After Submit.', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Use the get_elementor_templates or get_jetformbuilder_forms tools to discover form IDs.', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'Use get_elementor_form_submissions, get_jetformbuilder_submissions, or get_all_form_submissions to query submissions.', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '<li>' . esc_html__( 'To query a remote WordPress site, add a "Form Data Source" connection in Remote Sites and pass its connection_id to any submission tool.', 'mcp-ai-wpoos' ) . '</li>';
			$content .= '</ol>';

			// Example usage.
			$content .= '<h4>' . esc_html__( 'Example Tool Calls', 'mcp-ai-wpoos' ) . '</h4>';
			$content .= '<pre style="background: #f6f7f7; padding: 1rem; overflow-x: auto; font-size: 0.85em;">';

			$content .= esc_html__( '// Get all local form submissions (cross-plugin)', 'mcp-ai-wpoos' ) . "\n";
			$content .= esc_html__( 'get_all_form_submissions({ limit: 20 })', 'mcp-ai-wpoos' ) . "\n\n";

			$content .= esc_html__( '// Get Elementor submissions for a specific page', 'mcp-ai-wpoos' ) . "\n";
			$content .= esc_html__( 'get_elementor_form_submissions({ form_post_id: 42, limit: 10 })', 'mcp-ai-wpoos' ) . "\n\n";

			$content .= esc_html__( '// Get JFB submissions from a remote site', 'mcp-ai-wpoos' ) . "\n";
			$content .= esc_html__( 'get_jetformbuilder_submissions({ form_id: 5, connection_id: "my_staging_site" })', 'mcp-ai-wpoos' ) . "\n\n";

			$content .= esc_html__( '// Get only failed submissions', 'mcp-ai-wpoos' ) . "\n";
			$content .= esc_html__( 'get_all_form_submissions({ status: "failed", sources: ["jetformbuilder", "elementor"] })', 'mcp-ai-wpoos' );

			$content .= '</pre>';
			$content .= '</div>';

			return $content;
		}

		/**
		 * Check if JetFormBuilder submissions are available.
		 *
		 * @return bool
		 */
		private function is_jetformbuilder_available() {
			return class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' )
				&& WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions::is_available();
		}

		/**
		 * Check if Elementor Pro form submissions are available.
		 *
		 * @return bool
		 */
		private function is_elementor_submissions_available() {
			return class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' )
				&& WP_MCP_AI_Tool_Get_Elementor_Form_Submissions::is_available();
		}

		/**
		 * Check if the unified submissions tool is available.
		 *
		 * @return bool
		 */
		private function is_all_submissions_available() {
			return $this->is_jetformbuilder_available() || $this->is_elementor_submissions_available();
		}
	}
}
