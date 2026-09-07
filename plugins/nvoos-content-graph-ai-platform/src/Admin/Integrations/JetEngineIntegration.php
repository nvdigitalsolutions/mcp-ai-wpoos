<?php
/**
 * JetEngine integration screen (Wave E-UI-3, sub-cluster 4 — final).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_JetEngine_Integration`
 * (`includes/admin/class-wp-mcp-ai-admin-jetengine-integration.php`):
 * byte-identical page surface — the `wp-mcp-ai-jetengine` page slug,
 * the `admin_post_wp_mcp_ai_save_jetengine_settings` handler with its
 * nonce action, the active/inactive status banner (Jet_Engine probe),
 * the CCT/tools checkbox form, the five-tool status table, the MCP
 * server section (availability banner + endpoint, MCP integration +
 * context injection + cache-TTL fields, seven-tool MCP table), the
 * documentation links, and the save redirect.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Integrations\`
 *    (the E-UI-3 wave folder).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via
 *    `Plugin::registerIntegrationsScreens()`; the base admin owns the
 *    same page monolith (the class files are not wired by the base
 *    loader — documented).
 *  - The settings store resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator — the base
 *    `WP_MCP_AI_Admin_Settings::OPTION_NAME` monolith / the
 *    byte-identical `wp_mcp_ai_settings` option key standalone).
 *  - The MCP-server probe resolves per install mode (the base
 *    `WP_MCP_AI_JetEngine_Compat::has_mcp_server()` monolith / false
 *    standalone — the compat layer is base-owned, documented).
 *  - The third-party active probe (`class_exists( 'Jet_Engine' )`) is
 *    byte-identical — it detects JetEngine itself, not a base-owned
 *    class.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Manages the JetEngine integration admin page.
 *
 * @since 2.0.0
 */
class JetEngineIntegration {

	/**
	 * Page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-jetengine';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ) );
		\add_action( 'admin_post_wp_mcp_ai_save_jetengine_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Settings option name (per-mode seam).
	 *
	 * @return string
	 */
	protected static function settings_option_name() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::OPTION_NAME;
		}

		return 'wp_mcp_ai_settings';
	}

	/**
	 * MCP server availability (per-mode seam).
	 *
	 * The compat layer is base-owned — standalone resolves false
	 * (documented).
	 *
	 * @return bool
	 */
	protected static function has_mcp_server() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			return (bool) \WP_MCP_AI_JetEngine_Compat::has_mcp_server();
		}

		return false;
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_save_jetengine_settings' );

		$settings = \get_option( self::settings_option_name(), array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		$settings['enable_jetengine_cct']            = isset( $_POST['enable_jetengine_cct'] ) ? true : false;
		$settings['enable_jetengine_tools']          = isset( $_POST['enable_jetengine_tools'] ) ? true : false;
		$settings['jetengine_mcp_enabled']           = isset( $_POST['jetengine_mcp_enabled'] ) ? true : false;
		$settings['jetengine_mcp_context_injection'] = isset( $_POST['jetengine_mcp_context_injection'] ) ? true : false;
		$settings['jetengine_mcp_cache_ttl']         = isset( $_POST['jetengine_mcp_cache_ttl'] ) ? \absint( \wp_unslash( $_POST['jetengine_mcp_cache_ttl'] ) ) : 300;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		\update_option( self::settings_option_name(), $settings );

		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				\admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Register the integration page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'JetEngine Integration - NV oOS', 'nvoos-content-graph-ai-platform' ),
			__( 'JetEngine', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the integration page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$jetengine_active = \class_exists( 'Jet_Engine' );
		$settings         = \get_option( self::settings_option_name(), array() );
		$cct_enabled      = isset( $settings['enable_jetengine_cct'] ) ? (bool) $settings['enable_jetengine_cct'] : true;
		$tools_enabled    = isset( $settings['enable_jetengine_tools'] ) ? (bool) $settings['enable_jetengine_tools'] : true;
		$mcp_enabled      = isset( $settings['jetengine_mcp_enabled'] ) ? (bool) $settings['jetengine_mcp_enabled'] : true;
		$mcp_context      = isset( $settings['jetengine_mcp_context_injection'] ) ? (bool) $settings['jetengine_mcp_context_injection'] : false;
		$mcp_cache_ttl    = isset( $settings['jetengine_mcp_cache_ttl'] ) ? \absint( $settings['jetengine_mcp_cache_ttl'] ) : 300;

		$has_mcp_server = self::has_mcp_server();

		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'JetEngine Integration', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) && 'true' === \sanitize_key( \wp_unslash( $_GET['updated'] ) ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php \esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<div style="background: <?php echo \esc_attr( $jetengine_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo \esc_attr( $jetengine_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
				<?php if ( $jetengine_active ) : ?>
					<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php \esc_html_e( 'JetEngine Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'JetEngine is installed and active. Advanced CCT storage and AI tools are available.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<?php else : ?>
					<h2 style="margin-top: 0; color: #646970;"><?php \esc_html_e( 'JetEngine Not Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'JetEngine is not installed or not active.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<p><strong><?php \esc_html_e( 'To enable JetEngine integration:', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
					<ol>
						<li><?php \esc_html_e( 'Purchase and download JetEngine from Crocoblock', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Install and activate the plugin', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Return to this page to configure integration settings', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ol>
				<?php endif; ?>
			</div>

			<?php if ( $jetengine_active ) : ?>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'wp_mcp_ai_save_jetengine_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_jetengine_settings" />

					<table class="form-table">
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable CCT Storage', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_jetengine_cct" value="1" <?php \checked( $cct_enabled ); ?> />
									<?php \esc_html_e( 'Use JetEngine Custom Content Types for efficient data storage', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Enables CCT-based storage for chat transcripts and assistant configurations. Provides better performance than standard WordPress post types.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable AI Tools', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_jetengine_tools" value="1" <?php \checked( $tools_enabled ); ?> />
									<?php \esc_html_e( 'Activate JetEngine-specific AI tools', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Enables AI tools for post type creation, taxonomy management, and CCT queries.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php \esc_html_e( 'Available JetEngine Tools', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<table class="widefat" style="margin-top: 1rem;">
						<thead>
							<tr>
								<th><?php \esc_html_e( 'Tool Name', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php \esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php \esc_html_e( 'Status', 'nvoos-content-graph-ai-platform' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>jetengine_create_post_type</code></td>
								<td><?php \esc_html_e( 'Create custom post types dynamically', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>jetengine_create_taxonomy</code></td>
								<td><?php \esc_html_e( 'Create custom taxonomies', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>jetengine_query_cct</code></td>
								<td><?php \esc_html_e( 'Query Custom Content Types efficiently', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>jetengine_create_cct_item</code></td>
								<td><?php \esc_html_e( 'Create CCT entries programmatically', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>jetengine_update_cct_item</code></td>
								<td><?php \esc_html_e( 'Update existing CCT items', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
						</tbody>
					</table>

					<h2><?php \esc_html_e( 'MCP Server Integration', 'nvoos-content-graph-ai-platform' ); ?></h2>

					<div style="background: <?php echo \esc_attr( $has_mcp_server ? '#d5f0db' : '#f0f6fc' ); ?>; border-left: 4px solid <?php echo \esc_attr( $has_mcp_server ? '#0a5f1a' : '#2271b1' ); ?>; padding: 1.5rem; margin: 1rem 0;">
						<?php if ( $has_mcp_server ) : ?>
							<h3 style="margin-top: 0; color: #0a5f1a;">✓ <?php \esc_html_e( 'JetEngine MCP Server Available', 'nvoos-content-graph-ai-platform' ); ?></h3>
							<p>
								<?php \esc_html_e( 'JetEngine 3.8+ MCP Server detected. Native MCP tools, resources, and prompts are available via JSON-RPC 2.0 protocol.', 'nvoos-content-graph-ai-platform' ); ?>
							</p>
							<p><strong><?php \esc_html_e( 'Endpoint:', 'nvoos-content-graph-ai-platform' ); ?></strong> <code><?php echo \esc_url( \rest_url( 'jet-engine/v1/mcp' ) ); ?></code></p>
						<?php else : ?>
							<h3 style="margin-top: 0; color: #2271b1;"><?php \esc_html_e( 'MCP Server Not Available', 'nvoos-content-graph-ai-platform' ); ?></h3>
							<p>
								<?php \esc_html_e( 'JetEngine MCP Server requires JetEngine 3.8+. Upgrade JetEngine to unlock native MCP tools for AI-powered site structure management.', 'nvoos-content-graph-ai-platform' ); ?>
							</p>
						<?php endif; ?>
					</div>

					<table class="form-table">
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable MCP Integration', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jetengine_mcp_enabled" value="1" <?php \checked( $mcp_enabled ); ?> <?php \disabled( ! $has_mcp_server ); ?> />
									<?php \esc_html_e( 'Use JetEngine MCP Server for tool dispatch (recommended for 3.8+)', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'When enabled, operations are routed through the native MCP server instead of direct REST API calls. Falls back gracefully if MCP is unavailable.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php \esc_html_e( 'AI Context Injection', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jetengine_mcp_context_injection" value="1" <?php \checked( $mcp_context ); ?> <?php \disabled( ! $has_mcp_server ); ?> />
									<?php \esc_html_e( 'Auto-inject JetEngine site context into AI system prompts', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Automatically includes post types, taxonomies, and relations in AI assistant context for better grounding.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php \esc_html_e( 'Cache TTL (seconds)', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<input type="number" name="jetengine_mcp_cache_ttl" value="<?php echo \esc_attr( (string) $mcp_cache_ttl ); ?>" min="60" max="3600" class="small-text" <?php \disabled( ! $has_mcp_server ); ?> />
								<p class="description">
									<?php \esc_html_e( 'How long to cache MCP tool/resource discovery responses. Default: 300 seconds (5 minutes).', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php if ( $has_mcp_server ) : ?>
						<h3><?php \esc_html_e( 'Available MCP Tools', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<table class="widefat" style="margin-top: 0.5rem;">
							<thead>
								<tr>
									<th><?php \esc_html_e( 'Tool Name', 'nvoos-content-graph-ai-platform' ); ?></th>
									<th><?php \esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
									<th><?php \esc_html_e( 'Source', 'nvoos-content-graph-ai-platform' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>jetengine_mcp</code></td>
									<td><?php \esc_html_e( 'MCP Bridge — discover and call JetEngine MCP tools', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_create_post_type</code></td>
									<td><?php \esc_html_e( 'Create custom post types via MCP', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_create_taxonomy</code></td>
									<td><?php \esc_html_e( 'Create custom taxonomies via MCP', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_create_meta_field</code></td>
									<td><?php \esc_html_e( 'Create meta fields via MCP', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_manage_relations</code></td>
									<td><?php \esc_html_e( 'List and manage JetEngine relations', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_site_context</code></td>
									<td><?php \esc_html_e( 'Get site structure overview for AI grounding', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
								<tr>
									<td><code>jetengine_prompts</code></td>
									<td><?php \esc_html_e( 'Discover and render JetEngine prompt templates', 'nvoos-content-graph-ai-platform' ); ?></td>
									<td><span style="color: #2271b1;">MCP 3.8+</span></td>
								</tr>
							</tbody>
						</table>
					<?php endif; ?>

					<?php \submit_button( __( 'Save JetEngine Settings', 'nvoos-content-graph-ai-platform' ) ); ?>
				</form>

				<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
					<h3 style="margin-top: 0;"><?php \esc_html_e( 'Integration Documentation', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<p><?php \esc_html_e( 'For detailed information about JetEngine integration capabilities:', 'nvoos-content-graph-ai-platform' ); ?></p>
					<ul>
						<li><a href="<?php echo \esc_url( \admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>"><?php \esc_html_e( 'View All Available Tools', 'nvoos-content-graph-ai-platform' ); ?></a></li>
						<li><a href="<?php echo \esc_url( \admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ); ?>"><?php \esc_html_e( 'System Overview', 'nvoos-content-graph-ai-platform' ); ?></a></li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
