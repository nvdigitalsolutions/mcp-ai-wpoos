<?php
/**
 * NV oOS MCP Server Diagnostic Page
 *
 * Comprehensive diagnostic page for testing and verifying MCP (Model Context Protocol) functionality.
 * Tests endpoints, protocol compliance, authentication, tools, and server capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_MCP_Server_Diagnostic' ) ) {
	/**
	 * Diagnostic page for MCP server functionality.
	 */
	class WP_MCP_AI_MCP_Server_Diagnostic {
		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private static $page_hook = '';

		/**
		 * Initialize the diagnostic page.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_mcp_endpoint', array( __CLASS__, 'handle_test_mcp_endpoint' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_mcp_method', array( __CLASS__, 'handle_test_mcp_method' ) );
		}

		/**
		 * Register diagnostic page under Tools menu.
		 *
		 * Note: Located under Tools menu to ensure easy access for troubleshooting.
		 */
		public static function register_page() {
			self::$page_hook = add_submenu_page(
				'tools.php',
				__( 'MCP Server Diagnostic', 'mcp-ai-wpoos' ),
				__( 'NV oOS MCP Test', 'mcp-ai-wpoos' ),
				'manage_options',
				'wp-mcp-ai-mcp-diagnostic',
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the diagnostic page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public static function enqueue_assets( $hook ) {
			if ( self::$page_hook !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-mcp-diagnostic',
				WP_MCP_AI_URL . 'assets/css/mcp-diagnostic.css',
				array(),
				WP_MCP_AI_VERSION
			);

			// Enqueue the diagnostic page JavaScript.
			wp_enqueue_script(
				'wp-mcp-ai-mcp-diagnostic',
				WP_MCP_AI_URL . 'assets/js/mcp-diagnostic.js',
				array( 'jquery' ),
				WP_MCP_AI_VERSION,
				true
			);

			// Localize script data for the diagnostic JavaScript.
			wp_localize_script(
				'wp-mcp-ai-mcp-diagnostic',
				'wpMcpAiMcpDiagnostic',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp-mcp-ai-mcp-diagnostic' ),
					'i18n'    => array(
						'testing'         => __( 'Testing...', 'mcp-ai-wpoos' ),
						'testingEndpoint' => __( 'Testing MCP endpoint...', 'mcp-ai-wpoos' ),
						'testingMethod'   => __( 'Testing method...', 'mcp-ai-wpoos' ),
						'success'         => __( 'Success!', 'mcp-ai-wpoos' ),
						'error'           => __( 'Error!', 'mcp-ai-wpoos' ),
						'unknownError'    => __( 'Unknown error occurred', 'mcp-ai-wpoos' ),
						'testEndpoint'    => __( 'Test MCP Endpoint', 'mcp-ai-wpoos' ),
						'viewResponse'    => __( 'View Response', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		/**
		 * Render the diagnostic page.
		 */
		public static function render_page() {
			$rest_url     = rest_url( 'mcp-ai/v1/mcp' );
			$nonce        = wp_create_nonce( 'wp_rest' );
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$current_user = wp_get_current_user();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'MCP Server Diagnostics', 'mcp-ai-wpoos' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Test and verify MCP (Model Context Protocol) server functionality, endpoints, and capabilities.', 'mcp-ai-wpoos' ); ?>
				</p>

				<!-- MCP Protocol Information -->
				<div class="card">
					<h2><?php esc_html_e( '1. MCP Protocol Information', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Property', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Protocol Version', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><code>2024-11-05</code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Server Name', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><code>NV oOS</code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Server Version', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><code><?php echo esc_html( WP_MCP_AI_VERSION ); ?></code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'REST Namespace', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><code>mcp-ai/v1</code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'MCP Endpoint URL', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><code><?php echo esc_html( $rest_url ); ?></code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Supported Methods', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<code>initialize</code>,
									<code>tools/list</code>,
									<code>tools/call</code>,
									<code>resources/list</code>,
									<code>prompts/list</code>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- REST Endpoint Connectivity -->
				<div class="card">
					<h2><?php esc_html_e( '2. REST Endpoint Connectivity', 'mcp-ai-wpoos' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Test basic connectivity to the MCP REST endpoint.', 'mcp-ai-wpoos' ); ?>
					</p>

					<div id="mcp-endpoint-test-result" style="margin: 15px 0;"></div>

					<button type="button" class="button button-primary" id="test-mcp-endpoint">
						<?php esc_html_e( 'Test MCP Endpoint', 'mcp-ai-wpoos' ); ?>
					</button>

					<div style="margin-top: 15px;">
						<h3><?php esc_html_e( 'Expected Response Format (JSON-RPC 2.0)', 'mcp-ai-wpoos' ); ?></h3>
						<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;"><code>{
	"jsonrpc": "2.0",
	"id": 1,
	"result": {
	"protocolVersion": "2024-11-05",
	"capabilities": { ... },
	"serverInfo": { ... }
	}
}</code></pre>
					</div>
				</div>

				<!-- MCP Methods Testing -->
				<div class="card">
					<h2><?php esc_html_e( '3. MCP Methods Testing', 'mcp-ai-wpoos' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Test individual MCP protocol methods to verify functionality.', 'mcp-ai-wpoos' ); ?>
					</p>

					<?php
					$methods = array(
						'initialize'     => array(
							'label'       => __( 'Initialize', 'mcp-ai-wpoos' ),
							'description' => __( 'Get server capabilities and protocol version', 'mcp-ai-wpoos' ),
							'params'      => array(),
						),
						'tools/list'     => array(
							'label'       => __( 'Tools List', 'mcp-ai-wpoos' ),
							'description' => __( 'List all available tools', 'mcp-ai-wpoos' ),
							'params'      => array(),
						),
						'resources/list' => array(
							'label'       => __( 'Resources List', 'mcp-ai-wpoos' ),
							'description' => __( 'List available resources', 'mcp-ai-wpoos' ),
							'params'      => array(),
						),
						'prompts/list'   => array(
							'label'       => __( 'Prompts List', 'mcp-ai-wpoos' ),
							'description' => __( 'List available prompts', 'mcp-ai-wpoos' ),
							'params'      => array(),
						),
					);

					foreach ( $methods as $method => $config ) :
						$method_id = sanitize_key( str_replace( '/', '_', $method ) );
						?>
						<div class="mcp-method-test" style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #fafafa;">
							<h3><?php echo esc_html( $config['label'] ); ?> <code><?php echo esc_html( $method ); ?></code></h3>
							<p><?php echo esc_html( $config['description'] ); ?></p>

							<button
								type="button"
								class="button button-secondary test-mcp-method"
								data-method="<?php echo esc_attr( $method ); ?>"
								data-method-id="<?php echo esc_attr( $method_id ); ?>">
								<?php
								printf(
									/* translators: %s: method label */
									esc_html__( 'Test %s', 'mcp-ai-wpoos' ),
									esc_html( $config['label'] )
								);
								?>
							</button>

							<div id="result-<?php echo esc_attr( $method_id ); ?>" class="mcp-test-result" style="margin-top: 10px;"></div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Authentication Methods -->
				<div class="card">
					<h2><?php esc_html_e( '4. Authentication Methods', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Method', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'WordPress Nonce', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span></td>
								<td><?php esc_html_e( 'Standard WordPress authentication for same-origin requests', 'mcp-ai-wpoos' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Assistant Credentials', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( class_exists( 'WP_MCP_AI_Credentials' ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php esc_html_e( 'Bearer tokens for assistant-specific access', 'mcp-ai-wpoos' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Auth0 Integration', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( ! empty( $settings['auth0_domain'] ) && ! empty( $settings['auth0_audience'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $settings['auth0_domain'] ) ) : ?>
										<?php esc_html_e( 'Domain:', 'mcp-ai-wpoos' ); ?> <code><?php echo esc_html( $settings['auth0_domain'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Auth0 domain not configured', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Guest Tokens', 'mcp-ai-wpoos' ); ?></strong></td>
								<td><span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></span></td>
								<td><?php esc_html_e( 'Temporary tokens for public chat surfaces', 'mcp-ai-wpoos' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tool Registry -->
				<div class="card">
					<h2><?php esc_html_e( '5. Tool Registry', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					$registry = null;
					if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
						try {
							$registry = WP_MCP_AI_Tool_Registry::get_instance();
						} catch ( Exception $e ) {
							$registry = null;
						}
					}

					if ( $registry ) :
						$tools       = $registry->get_tools();
						$tools_count = count( $tools );
						?>
						<p>
							<?php
							printf(
								/* translators: %d: number of tools */
								esc_html__( 'Total tools registered: %d', 'mcp-ai-wpoos' ),
								absint( $tools_count )
							);
							?>
						</p>

						<?php if ( $tools_count > 0 ) : ?>
							<details style="margin-top: 15px;">
								<summary style="cursor: pointer; font-weight: bold;">
									<?php esc_html_e( 'View All Registered Tools', 'mcp-ai-wpoos' ); ?>
								</summary>
								<table class="widefat striped" style="margin-top: 10px;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Tool Slug', 'mcp-ai-wpoos' ); ?></th>
											<th><?php esc_html_e( 'Name', 'mcp-ai-wpoos' ); ?></th>
											<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $tools as $tool ) : ?>
											<tr>
												<td><code><?php echo esc_html( $tool->get_slug() ); ?></code></td>
												<td><?php echo esc_html( $tool->get_name() ); ?></td>
												<td><?php echo esc_html( wp_trim_words( $tool->get_description(), 15 ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</details>
						<?php endif; ?>
					<?php else : ?>
						<p style="color: red;"><?php esc_html_e( 'Tool Registry not available!', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Assistants Configuration -->
				<div class="card">
					<h2><?php esc_html_e( '6. Assistants Configuration', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					$assistants = get_posts(
						array(
							'post_type'      => 'mcp_ai_assistant',
							'posts_per_page' => -1,
							'post_status'    => 'publish',
						)
					);

					if ( ! empty( $assistants ) ) :
						?>
						<p>
							<?php
							printf(
								/* translators: %d: number of assistants */
								esc_html__( 'Total assistants configured: %d', 'mcp-ai-wpoos' ),
								count( $assistants )
							);
							?>
						</p>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Assistant', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Tools Count', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $assistants as $assistant ) : ?>
									<?php
									$provider = get_post_meta( $assistant->ID, '_wp_mcp_ai_provider', true );
									$model    = get_post_meta( $assistant->ID, '_wp_mcp_ai_model', true );
									$tools    = get_post_meta( $assistant->ID, '_wp_mcp_ai_tools', true );

									// Fallback to default values if not set.
									$provider    = ! empty( $provider ) ? $provider : 'openai';
									$model       = ! empty( $model ) ? $model : 'N/A';
									$tools       = is_array( $tools ) ? $tools : array();
									$tools_count = count( $tools );
									?>
									<tr>
										<td><strong><?php echo esc_html( $assistant->post_title ); ?></strong></td>
										<td><code><?php echo esc_html( ucfirst( $provider ) ); ?></code></td>
										<td><code><?php echo esc_html( $model ); ?></code></td>
										<td><?php echo esc_html( $tools_count ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No assistants configured yet.', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Federation & Mesh -->
				<?php if ( ! empty( $settings['enable_federation_directory'] ) || ! empty( $settings['enable_mesh'] ) ) : ?>
					<div class="card">
						<h2><?php esc_html_e( '7. Federation & Mesh Networking', 'mcp-ai-wpoos' ); ?></h2>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Feature', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Federation', 'mcp-ai-wpoos' ); ?></strong></td>
									<td>
										<?php if ( ! empty( $settings['enable_federation_directory'] ) ) : ?>
											<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span>
										<?php else : ?>
											<span style="color: red;">✗ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $settings['enable_federation_directory'] ) ) : ?>
											<?php esc_html_e( 'Region:', 'mcp-ai-wpoos' ); ?>
											<code><?php echo esc_html( isset( $settings['federation_regions'] ) ? $settings['federation_regions'] : 'global' ); ?></code>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Mesh Networking', 'mcp-ai-wpoos' ); ?></strong></td>
									<td>
										<?php if ( ! empty( $settings['enable_mesh'] ) ) : ?>
											<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span>
										<?php else : ?>
											<span style="color: red;">✗ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										if ( ! empty( $settings['enable_mesh'] ) && ! empty( $settings['mesh_peer_sites'] ) ) {
											$peer_count = count( $settings['mesh_peer_sites'] );
											printf(
												/* translators: %d: number of peer sites */
												esc_html__( '%d peer sites configured', 'mcp-ai-wpoos' ),
												absint( $peer_count )
											);
										}
										?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<!-- System Requirements -->
				<div class="card">
					<h2><?php esc_html_e( '8. System Requirements', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Requirement', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'WordPress Version', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'OK', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Update Required', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?> (<?php esc_html_e( 'Minimum: 6.0', 'mcp-ai-wpoos' ); ?>)</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'PHP Version', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'OK', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Update Required', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( PHP_VERSION ); ?> (<?php esc_html_e( 'Minimum: 7.4', 'mcp-ai-wpoos' ); ?>)</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'REST API', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( function_exists( 'rest_url' ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( rest_url() ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'JSON Support', 'mcp-ai-wpoos' ); ?></strong></td>
								<td>
									<?php if ( function_exists( 'json_encode' ) && function_exists( 'json_decode' ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php esc_html_e( 'Required for JSON-RPC 2.0 protocol', 'mcp-ai-wpoos' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Recent MCP Activity -->
				<div class="card">
					<h2><?php esc_html_e( '9. Recent MCP Activity', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

					if ( ! empty( $recent_activity ) && is_array( $recent_activity ) ) {
						// Get the last 10 activity entries.
						$mcp_activity = array_slice( $recent_activity, -10 );

						if ( ! empty( $mcp_activity ) ) {
							?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Time', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Event', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_reverse( $mcp_activity ) as $activity ) : ?>
										<tr>
											<td><?php echo isset( $activity['timestamp'] ) ? esc_html( $activity['timestamp'] ) : '—'; ?></td>
											<td><code><?php echo isset( $activity['type'] ) ? esc_html( $activity['type'] ) : '—'; ?></code></td>
											<td><?php echo isset( $activity['message'] ) ? esc_html( wp_trim_words( $activity['message'], 20 ) ) : '—'; ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php } else { ?>
							<p><?php esc_html_e( 'No recent MCP activity recorded.', 'mcp-ai-wpoos' ); ?></p>
						<?php } ?>
					<?php } else { ?>
						<p><?php esc_html_e( 'Activity logging is not enabled.', 'mcp-ai-wpoos' ); ?></p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Enable logging in settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php } ?>
				</div>

				<!-- Troubleshooting Guide -->
				<div class="card">
					<h2><?php esc_html_e( '10. Troubleshooting Guide', 'mcp-ai-wpoos' ); ?></h2>

					<h3><?php esc_html_e( 'Common Issues:', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li>
							<strong><?php esc_html_e( 'MCP endpoint not responding:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Check that REST API is enabled in WordPress', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Verify permalink structure is not set to "Plain"', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check for .htaccess or server configuration issues', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Authentication failures:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify Auth0 domain and audience are correct', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check that assistant credentials are properly generated', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure CORS headers are configured for cross-origin requests', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Tools not available:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Check that tools are assigned to the assistant', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Verify user has required capabilities for the tools', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check tool registry initialization in error logs', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
					</ul>

					<h3><?php esc_html_e( 'Useful Links:', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>"><?php esc_html_e( 'Plugin Settings', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>"><?php esc_html_e( 'Manage Assistants', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-mcp-ai-diagnostic' ) ); ?>"><?php esc_html_e( 'Dashboard Diagnostic', 'mcp-ai-wpoos' ); ?></a></li>
					</ul>
				</div>
			</div>
			<?php
		}

		/**
		 * Handle AJAX request to test MCP endpoint.
		 */
		public static function handle_test_mcp_endpoint() {
			check_ajax_referer( 'wp-mcp-ai-mcp-diagnostic', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Create a JSON-RPC 2.0 initialize request body.
			$request_body = wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(),
				)
			);

			// Use internal REST request instead of HTTP call.
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $request_body );

			// Process the request internally.
			$response = rest_do_request( $request );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Request failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = $response->get_status();
			$data          = $response->get_data();

			if ( 200 !== $response_code ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: HTTP status code */
							__( 'Unexpected response code: %d', 'mcp-ai-wpoos' ),
							$response_code
						),
					)
				);
				return;
			}

			if ( ! isset( $data['jsonrpc'] ) || '2.0' !== $data['jsonrpc'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid JSON-RPC 2.0 response format.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message'  => __( 'MCP endpoint is responding correctly!', 'mcp-ai-wpoos' ),
					'response' => $data,
				)
			);
		}

		/**
		 * Handle AJAX request to test a specific MCP method.
		 */
		public static function handle_test_mcp_method() {
			check_ajax_referer( 'wp-mcp-ai-mcp-diagnostic', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : '';

			if ( empty( $method ) ) {
				wp_send_json_error( array( 'message' => __( 'Method parameter is required.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Create a JSON-RPC 2.0 request body.
			$request_body = wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => array(),
				)
			);

			// Use internal REST request instead of HTTP call.
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $request_body );

			// Process the request internally.
			$response = rest_do_request( $request );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Request failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = $response->get_status();
			$data          = $response->get_data();

			if ( 200 !== $response_code ) {
				wp_send_json_error(
					array(
						'message'  => sprintf(
							/* translators: %d: HTTP status code */
							__( 'Unexpected response code: %d', 'mcp-ai-wpoos' ),
							$response_code
						),
						'response' => $data,
					)
				);
				return;
			}

			if ( isset( $data['error'] ) ) {
				wp_send_json_error(
					array(
						'message'  => sprintf(
							/* translators: %s: error message */
							__( 'MCP error: %s', 'mcp-ai-wpoos' ),
							isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown error', 'mcp-ai-wpoos' )
						),
						'response' => $data,
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message'  => sprintf(
						/* translators: %s: method name */
						__( 'Method %s executed successfully!', 'mcp-ai-wpoos' ),
						$method
					),
					'response' => $data,
				)
			);
		}
	}

	// Initialize the diagnostic page.
	WP_MCP_AI_MCP_Server_Diagnostic::init();
}
