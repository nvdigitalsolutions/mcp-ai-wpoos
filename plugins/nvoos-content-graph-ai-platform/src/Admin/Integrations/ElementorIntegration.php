<?php
/**
 * Elementor integration screen (Wave E-UI-3, sub-cluster 3).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_Elementor_Integration`
 * (`includes/admin/class-wp-mcp-ai-admin-elementor-integration.php`):
 * byte-identical page surface — the `wp-mcp-ai-elementor` page slug,
 * the `admin_post_wp_mcp_ai_save_elementor_settings` handler with its
 * nonce action, the active/inactive status banner (ELEMENTOR_VERSION
 * probe), the enable-widgets checkbox form, the three-widget showcase
 * grid, the base-plugin note, and the usage steps.
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
 *  - The third-party active probe (`defined( 'ELEMENTOR_VERSION' )`)
 *    is byte-identical — it detects Elementor itself, not a
 *    base-owned class.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Manages the Elementor integration admin page.
 *
 * @since 2.0.0
 */
class ElementorIntegration {

	/**
	 * Page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-elementor';

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
		\add_action( 'admin_post_wp_mcp_ai_save_elementor_settings', array( $this, 'handle_save_settings' ) );
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
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_save_elementor_settings' );

		$settings = \get_option( self::settings_option_name(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		$settings['enable_elementor_widgets'] = isset( $_POST['enable_elementor_widgets'] ) ? true : false;

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
			__( 'Elementor Integration - NV oOS', 'nvoos-content-graph-ai-platform' ),
			__( 'Elementor', 'nvoos-content-graph-ai-platform' ),
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

		$elementor_active = \defined( 'ELEMENTOR_VERSION' );
		$settings         = \get_option( self::settings_option_name(), array() );
		$widgets_enabled  = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Elementor Integration', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) && 'true' === \sanitize_key( \wp_unslash( $_GET['updated'] ) ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php \esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<div style="background: <?php echo \esc_attr( $elementor_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo \esc_attr( $elementor_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
				<?php if ( $elementor_active ) : ?>
					<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php \esc_html_e( 'Elementor Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'Elementor is installed and active. AI Chat widgets can be enabled below.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<p><strong><?php \esc_html_e( 'Version:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php echo \esc_html( ELEMENTOR_VERSION ); ?></p>
				<?php else : ?>
					<h2 style="margin-top: 0; color: #646970;"><?php \esc_html_e( 'Elementor Not Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'Elementor is not installed or not active.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<p><strong><?php \esc_html_e( 'To enable Elementor integration:', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
					<ol>
						<li><?php \esc_html_e( 'Install Elementor from WordPress.org', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Activate the plugin', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Return to this page to enable widgets', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ol>
				<?php endif; ?>
			</div>

			<?php if ( $elementor_active ) : ?>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'wp_mcp_ai_save_elementor_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_elementor_settings" />

					<table class="form-table">
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable Widgets', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_elementor_widgets" value="1" <?php \checked( $widgets_enabled ); ?> />
									<?php \esc_html_e( 'Enable AI Chat widgets for Elementor page builder', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Check this box to make NV oOS widgets available in the Elementor editor. Part of base plugin (no Pro addon required).', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php \esc_html_e( 'Available Elementor Widgets', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1rem;">
					<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php \esc_html_e( 'NV oOS Chat', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php \esc_html_e( 'Interactive AI chat interface with streaming responses', 'nvoos-content-graph-ai-platform' ); ?></p>
						<ul style="margin-left: 1.5rem;">
							<li><?php \esc_html_e( 'Real-time SSE streaming', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Customizable styling', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Tool execution feedback', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Markdown rendering', 'nvoos-content-graph-ai-platform' ); ?></li>
						</ul>
					</div>

					<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php \esc_html_e( 'Assistant Selector', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php \esc_html_e( 'Dropdown to switch between available assistants', 'nvoos-content-graph-ai-platform' ); ?></p>
						<ul style="margin-left: 1.5rem;">
							<li><?php \esc_html_e( 'Dynamic assistant list', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Role-based filtering', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Seamless switching', 'nvoos-content-graph-ai-platform' ); ?></li>
						</ul>
					</div>

					<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php \esc_html_e( 'Chat History', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php \esc_html_e( 'Display conversation history with filtering', 'nvoos-content-graph-ai-platform' ); ?></p>
						<ul style="margin-left: 1.5rem;">
							<li><?php \esc_html_e( 'Persistent transcripts', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Date filtering', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Export functionality', 'nvoos-content-graph-ai-platform' ); ?></li>
						</ul>
					</div>
				</div>

				<p style="margin-top: 1.5rem; background: #d5f0db; border-left: 4px solid #0a5f1a; padding: 1rem;">
					<strong style="color: #0a5f1a;">✓ <?php \esc_html_e( 'Part of Base Plugin', 'nvoos-content-graph-ai-platform' ); ?></strong><br>
					<?php \esc_html_e( 'Elementor widgets are included in the base plugin and do not require the Pro addon. Simply check the box above to enable them.', 'nvoos-content-graph-ai-platform' ); ?>
				</p>

				<?php \submit_button( __( 'Save Elementor Settings', 'nvoos-content-graph-ai-platform' ) ); ?>
			</form>

				<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
					<h3 style="margin-top: 0;"><?php \esc_html_e( 'Using Widgets in Elementor', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<ol>
						<li><?php \esc_html_e( 'Edit a page with Elementor', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Search for "NV oOS" in the widget panel', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Drag the desired widget to your page', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Configure widget settings in the left panel', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Publish or update your page', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ol>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
