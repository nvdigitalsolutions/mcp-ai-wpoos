<?php
/**
 * Orchestration Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Orchestration' ) ) {
	/**
	 * Orchestration settings section.
	 */
	class WP_MCP_AI_Section_Orchestration extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'orchestration';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Orchestration Layer', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'orchestration';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure the orchestration layer services for chat, tools, and file handling.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'orchestration_info' => array(
					'type'        => 'info',
					'label'       => __( 'About the Orchestration Layer', 'wp-mcp-ai' ),
					'description' => $this->get_orchestration_description(),
				),
			);
		}

		/**
		 * Get orchestration layer description.
		 *
		 * @return string
		 */
		private function get_orchestration_description() {
			return sprintf(
				'<div class="wp-mcp-ai-orchestration-info">
					<h3>%s</h3>
					<p>%s</p>
					
					<h4>%s</h4>
					<ul>
						<li><strong>%s:</strong> %s</li>
						<li><strong>%s:</strong> %s</li>
						<li><strong>%s:</strong> %s</li>
					</ul>
					
					<h4>%s</h4>
					<ul>
						<li><strong>%s:</strong> %s</li>
						<li><strong>%s:</strong> %s</li>
						<li><strong>%s:</strong> %s</li>
					</ul>
					
					<p>%s</p>
					<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>
				</div>',
				esc_html__( 'WP oOS Dynamic AI Orchestration Layer', 'wp-mcp-ai' ),
				esc_html__( 'The orchestration layer coordinates all AI operations with intelligent resource management, security enforcement, and predictive optimization.', 'wp-mcp-ai' ),
				
				esc_html__( 'Core Services', 'wp-mcp-ai' ),
				esc_html__( 'Chat Service', 'wp-mcp-ai' ),
				esc_html__( 'Orchestrates chat operations using Rate Limit Manager and Token Budget Manager for optimal performance and resource utilization.', 'wp-mcp-ai' ),
				esc_html__( 'Tool Service', 'wp-mcp-ai' ),
				esc_html__( 'Manages tool execution workflows through the Tool Registry with capability-based access control and comprehensive logging.', 'wp-mcp-ai' ),
				esc_html__( 'File Service', 'wp-mcp-ai' ),
				esc_html__( 'Handles file upload, download, and validation with security checks and MIME type restrictions.', 'wp-mcp-ai' ),
				
				esc_html__( 'Key Features', 'wp-mcp-ai' ),
				esc_html__( 'Real-Time Budget Enforcement', 'wp-mcp-ai' ),
				esc_html__( 'Monitors token usage and prevents API limit overruns with predictive allocation.', 'wp-mcp-ai' ),
				esc_html__( 'Capability-Based Gating', 'wp-mcp-ai' ),
				esc_html__( 'Controls access to tools and features based on WordPress user capabilities.', 'wp-mcp-ai' ),
				esc_html__( 'Audit Trail', 'wp-mcp-ai' ),
				esc_html__( 'Complete logging of all operations for compliance and troubleshooting.', 'wp-mcp-ai' ),
				
				esc_html__( 'Third-party integrations (Gmail, Crawl4AI, Cloudflare, etc.) are now managed through separate dedicated pages accessible from the WP oOS admin menu.', 'wp-mcp-ai' ),
				esc_url( 'https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/ORCHESTRATION-LAYER-ARCHITECTURE.md' ),
				esc_html__( 'Read the full orchestration layer architecture documentation', 'wp-mcp-ai' )
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			?>
			<div class="wp-mcp-ai-orchestration-section">
				<?php echo wp_kses_post( $this->get_orchestration_description() ); ?>
				
				<hr />
				
				<h3><?php esc_html_e( 'Service Status', 'wp-mcp-ai' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Service', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Chat Service', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td><?php esc_html_e( 'Orchestrates chat operations with rate limiting and token management.', 'wp-mcp-ai' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Tool Service', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td><?php esc_html_e( 'Manages tool execution through the tool registry.', 'wp-mcp-ai' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'File Service', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td><?php esc_html_e( 'Handles file uploads and downloads with validation.', 'wp-mcp-ai' ); ?></td>
						</tr>
					</tbody>
				</table>
				
				<hr />
				
				<h3><?php esc_html_e( 'Manager Components', 'wp-mcp-ai' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Component', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Rate Limit Manager', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td><?php esc_html_e( 'Manages API rate limiting with exponential backoff.', 'wp-mcp-ai' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Token Budget Manager', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td><?php esc_html_e( 'Prevents API limit overruns with predictive budgeting.', 'wp-mcp-ai' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Tool Registry', 'wp-mcp-ai' ); ?></strong></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></td>
							<td>
								<?php
								$tool_count = 0;
								if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
									$registry   = WP_MCP_AI_Tool_Registry::instance();
									$tool_count = count( $registry->get_all_tools() );
								}
								printf(
									/* translators: %d: number of registered tools */
									esc_html__( 'Maintains catalog of %d registered tools.', 'wp-mcp-ai' ),
									absint( $tool_count )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<style>
				.wp-mcp-ai-orchestration-info h3 {
					margin-top: 0;
				}
				.wp-mcp-ai-orchestration-info ul {
					margin-left: 20px;
				}
				.wp-mcp-ai-orchestration-section .widefat th,
				.wp-mcp-ai-orchestration-section .widefat td {
					padding: 12px;
				}
			</style>
			<?php
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			// This section has no editable fields, so just return empty array.
			return array();
		}
	}
}
