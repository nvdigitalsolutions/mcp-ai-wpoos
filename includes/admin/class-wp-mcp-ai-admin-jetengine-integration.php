<?php
/**
 * JetEngine Integration Admin Page
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_JetEngine_Integration' ) ) {
	/**
	 * Manages the JetEngine integration admin page.
	 */
	class WP_MCP_AI_Admin_JetEngine_Integration {
		const PAGE_SLUG = 'wp-mcp-ai-jetengine';

		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ) );
			add_action( 'admin_post_wp_mcp_ai_save_jetengine_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Handle settings save.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_jetengine_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			$settings['enable_jetengine_cct']            = isset( $_POST['enable_jetengine_cct'] ) ? true : false;
			$settings['enable_jetengine_tools']          = isset( $_POST['enable_jetengine_tools'] ) ? true : false;
			$settings['jetengine_mcp_enabled']           = isset( $_POST['jetengine_mcp_enabled'] ) ? true : false;
			$settings['jetengine_mcp_context_injection'] = isset( $_POST['jetengine_mcp_context_injection'] ) ? true : false;
			$settings['jetengine_mcp_cache_ttl']         = isset( $_POST['jetengine_mcp_cache_ttl'] ) ? absint( wp_unslash( $_POST['jetengine_mcp_cache_ttl'] ) ) : 300;

			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'updated' => 'true',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Register the integration page under the NV oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'JetEngine Integration - NV oOS', 'mcp-ai-wpoos' ),
				__( 'JetEngine', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Render the integration page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$jetengine_active = class_exists( 'Jet_Engine' );
			$settings         = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$cct_enabled      = isset( $settings['enable_jetengine_cct'] ) ? (bool) $settings['enable_jetengine_cct'] : true;
			$tools_enabled    = isset( $settings['enable_jetengine_tools'] ) ? (bool) $settings['enable_jetengine_tools'] : true;
			$mcp_enabled      = isset( $settings['jetengine_mcp_enabled'] ) ? (bool) $settings['jetengine_mcp_enabled'] : true;
			$mcp_context      = isset( $settings['jetengine_mcp_context_injection'] ) ? (bool) $settings['jetengine_mcp_context_injection'] : false;
			$mcp_cache_ttl    = isset( $settings['jetengine_mcp_cache_ttl'] ) ? absint( $settings['jetengine_mcp_cache_ttl'] ) : 300;

			// Detect MCP server availability.
			$has_mcp_server = false;
			if ( class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
				$has_mcp_server = WP_MCP_AI_JetEngine_Compat::has_mcp_server();
			}

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'JetEngine Integration', 'mcp-ai-wpoos' ); ?></h1>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				<?php endif; ?>

				<div style="background: <?php echo esc_attr( $jetengine_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo esc_attr( $jetengine_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
					<?php if ( $jetengine_active ) : ?>
						<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php esc_html_e( 'JetEngine Active', 'mcp-ai-wpoos' ); ?></h2>
						<p><?php esc_html_e( 'JetEngine is installed and active. Advanced CCT storage and AI tools are available.', 'mcp-ai-wpoos' ); ?></p>
					<?php else : ?>
						<h2 style="margin-top: 0; color: #646970;"><?php esc_html_e( 'JetEngine Not Active', 'mcp-ai-wpoos' ); ?></h2>
						<p><?php esc_html_e( 'JetEngine is not installed or not active.', 'mcp-ai-wpoos' ); ?></p>
						<p><strong><?php esc_html_e( 'To enable JetEngine integration:', 'mcp-ai-wpoos' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Purchase and download JetEngine from Crocoblock', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Install and activate the plugin', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Return to this page to configure integration settings', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					<?php endif; ?>
				</div>

				<?php if ( $jetengine_active ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wp_mcp_ai_save_jetengine_settings' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_save_jetengine_settings" />

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable CCT Storage', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_jetengine_cct" value="1" <?php checked( $cct_enabled ); ?> />
										<?php esc_html_e( 'Use JetEngine Custom Content Types for efficient data storage', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables CCT-based storage for chat transcripts and assistant configurations. Provides better performance than standard WordPress post types.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable AI Tools', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_jetengine_tools" value="1" <?php checked( $tools_enabled ); ?> />
										<?php esc_html_e( 'Activate JetEngine-specific AI tools', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables AI tools for post type creation, taxonomy management, and CCT queries.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Available JetEngine Tools', 'mcp-ai-wpoos' ); ?></h2>
						<table class="widefat" style="margin-top: 1rem;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>jetengine_create_post_type</code></td>
									<td><?php esc_html_e( 'Create custom post types dynamically', 'mcp-ai-wpoos' ); ?></td>
									<td>
									<?php
									echo wp_kses(
										$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>',
										array( 'span' => array( 'style' => array() ) )
									);
									?>
								</td>
								</tr>
								<tr>
									<td><code>jetengine_create_taxonomy</code></td>
									<td><?php esc_html_e( 'Create custom taxonomies', 'mcp-ai-wpoos' ); ?></td>
									<td>
									<?php
									echo wp_kses(
										$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>',
										array( 'span' => array( 'style' => array() ) )
									);
									?>
								</td>
								</tr>
								<tr>
									<td><code>jetengine_query_cct</code></td>
									<td><?php esc_html_e( 'Query Custom Content Types efficiently', 'mcp-ai-wpoos' ); ?></td>
									<td>
									<?php
									echo wp_kses(
										$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>',
										array( 'span' => array( 'style' => array() ) )
									);
									?>
								</td>
								</tr>
								<tr>
									<td><code>jetengine_create_cct_item</code></td>
									<td><?php esc_html_e( 'Create CCT entries programmatically', 'mcp-ai-wpoos' ); ?></td>
									<td>
									<?php
									echo wp_kses(
										$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>',
										array( 'span' => array( 'style' => array() ) )
									);
									?>
								</td>
								</tr>
								<tr>
									<td><code>jetengine_update_cct_item</code></td>
									<td><?php esc_html_e( 'Update existing CCT items', 'mcp-ai-wpoos' ); ?></td>
									<td>
									<?php
									echo wp_kses(
										$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>',
										array( 'span' => array( 'style' => array() ) )
									);
									?>
								</td>
								</tr>
							</tbody>
						</table>

						<h2><?php esc_html_e( 'MCP Server Integration', 'mcp-ai-wpoos' ); ?></h2>

						<div style="background: <?php echo esc_attr( $has_mcp_server ? '#d5f0db' : '#f0f6fc' ); ?>; border-left: 4px solid <?php echo esc_attr( $has_mcp_server ? '#0a5f1a' : '#2271b1' ); ?>; padding: 1.5rem; margin: 1rem 0;">
							<?php if ( $has_mcp_server ) : ?>
								<h3 style="margin-top: 0; color: #0a5f1a;">✓ <?php esc_html_e( 'JetEngine MCP Server Available', 'mcp-ai-wpoos' ); ?></h3>
								<p>
									<?php esc_html_e( 'JetEngine 3.8+ MCP Server detected. Native MCP tools, resources, and prompts are available via JSON-RPC 2.0 protocol.', 'mcp-ai-wpoos' ); ?>
								</p>
								<p><strong><?php esc_html_e( 'Endpoint:', 'mcp-ai-wpoos' ); ?></strong> <code><?php echo esc_url( rest_url( 'jet-engine/v1/mcp' ) ); ?></code></p>
							<?php else : ?>
								<h3 style="margin-top: 0; color: #2271b1;"><?php esc_html_e( 'MCP Server Not Available', 'mcp-ai-wpoos' ); ?></h3>
								<p>
									<?php esc_html_e( 'JetEngine MCP Server requires JetEngine 3.8+. Upgrade JetEngine to unlock native MCP tools for AI-powered site structure management.', 'mcp-ai-wpoos' ); ?>
								</p>
							<?php endif; ?>
						</div>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable MCP Integration', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="jetengine_mcp_enabled" value="1" <?php checked( $mcp_enabled ); ?> <?php disabled( ! $has_mcp_server ); ?> />
										<?php esc_html_e( 'Use JetEngine MCP Server for tool dispatch (recommended for 3.8+)', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, operations are routed through the native MCP server instead of direct REST API calls. Falls back gracefully if MCP is unavailable.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'AI Context Injection', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="jetengine_mcp_context_injection" value="1" <?php checked( $mcp_context ); ?> <?php disabled( ! $has_mcp_server ); ?> />
										<?php esc_html_e( 'Auto-inject JetEngine site context into AI system prompts', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Automatically includes post types, taxonomies, and relations in AI assistant context for better grounding.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Cache TTL (seconds)', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<input type="number" name="jetengine_mcp_cache_ttl" value="<?php echo esc_attr( $mcp_cache_ttl ); ?>" min="60" max="3600" class="small-text" <?php disabled( ! $has_mcp_server ); ?> />
									<p class="description">
										<?php esc_html_e( 'How long to cache MCP tool/resource discovery responses. Default: 300 seconds (5 minutes).', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<?php if ( $has_mcp_server ) : ?>
							<h3><?php esc_html_e( 'Available MCP Tools', 'mcp-ai-wpoos' ); ?></h3>
							<table class="widefat" style="margin-top: 0.5rem;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Source', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><code>jetengine_mcp</code></td>
										<td><?php esc_html_e( 'MCP Bridge — discover and call JetEngine MCP tools', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_create_post_type</code></td>
										<td><?php esc_html_e( 'Create custom post types via MCP', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_create_taxonomy</code></td>
										<td><?php esc_html_e( 'Create custom taxonomies via MCP', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_create_meta_field</code></td>
										<td><?php esc_html_e( 'Create meta fields via MCP', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_manage_relations</code></td>
										<td><?php esc_html_e( 'List and manage JetEngine relations', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_site_context</code></td>
										<td><?php esc_html_e( 'Get site structure overview for AI grounding', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
									<tr>
										<td><code>jetengine_prompts</code></td>
										<td><?php esc_html_e( 'Discover and render JetEngine prompt templates', 'mcp-ai-wpoos' ); ?></td>
										<td><span style="color: #2271b1;">MCP 3.8+</span></td>
									</tr>
								</tbody>
							</table>
						<?php endif; ?>

						<?php submit_button( __( 'Save JetEngine Settings', 'mcp-ai-wpoos' ) ); ?>
					</form>

					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'Integration Documentation', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'For detailed information about JetEngine integration capabilities:', 'mcp-ai-wpoos' ); ?></p>
						<ul>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>"><?php esc_html_e( 'View All Available Tools', 'mcp-ai-wpoos' ); ?></a></li>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ); ?>"><?php esc_html_e( 'System Overview', 'mcp-ai-wpoos' ); ?></a></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
