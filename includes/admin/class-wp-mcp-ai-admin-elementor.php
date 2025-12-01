<?php
/**
 * Elementor Integration Admin Page
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Elementor_Integration' ) ) {
	/**
	 * Manages the Elementor integration admin page.
	 */
	class WP_MCP_AI_Admin_Elementor_Integration {
		const PAGE_SLUG = 'wp-mcp-ai-elementor';

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
			add_action( 'admin_post_wp_mcp_ai_save_elementor_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Handle settings save.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_elementor_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			$settings['enable_elementor_widgets'] = isset( $_POST['enable_elementor_widgets'] ) ? true : false;

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
		 * Register the integration page under the WP oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Elementor Integration - WP oOS', 'wp-mcp-ai' ),
				__( 'Elementor', 'wp-mcp-ai' ),
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

			$elementor_active = defined( 'ELEMENTOR_VERSION' );
			$settings         = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$widgets_enabled  = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Elementor Integration', 'wp-mcp-ai' ); ?></h1>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<div style="background: <?php echo esc_attr( $elementor_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo esc_attr( $elementor_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
					<?php if ( $elementor_active ) : ?>
						<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php esc_html_e( 'Elementor Active', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'Elementor is installed and active. AI Chat widgets are available.', 'wp-mcp-ai' ); ?></p>
						<p><strong><?php esc_html_e( 'Version:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( ELEMENTOR_VERSION ); ?></p>
					<?php else : ?>
						<h2 style="margin-top: 0; color: #646970;"><?php esc_html_e( 'Elementor Not Active', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'Elementor is not installed or not active.', 'wp-mcp-ai' ); ?></p>
						<p><strong><?php esc_html_e( 'To enable Elementor integration:', 'wp-mcp-ai' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Install Elementor from WordPress.org', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Activate the plugin', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Return to this page to configure integration settings', 'wp-mcp-ai' ); ?></li>
						</ol>
					<?php endif; ?>
				</div>

				<?php if ( $elementor_active ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wp_mcp_ai_save_elementor_settings' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_save_elementor_settings" />

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Widgets', 'wp-mcp-ai' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_elementor_widgets" value="1" <?php checked( $widgets_enabled ); ?> />
										<?php esc_html_e( 'Activate AI Chat widget for Elementor page builder', 'wp-mcp-ai' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables the WP oOS Chat widget with real-time SSE streaming support in Elementor.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Available Elementor Widgets', 'wp-mcp-ai' ); ?></h2>
						<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1rem;">
							<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
								<h3 style="margin-top: 0;"><?php esc_html_e( 'WP oOS Chat', 'wp-mcp-ai' ); ?></h3>
								<p><?php esc_html_e( 'Interactive AI chat interface with streaming responses', 'wp-mcp-ai' ); ?></p>
								<ul style="margin-left: 1.5rem;">
									<li><?php esc_html_e( 'Real-time SSE streaming', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Customizable styling', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Tool execution feedback', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Markdown rendering', 'wp-mcp-ai' ); ?></li>
								</ul>
							</div>

							<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
								<h3 style="margin-top: 0;"><?php esc_html_e( 'Assistant Selector', 'wp-mcp-ai' ); ?></h3>
								<p><?php esc_html_e( 'Dropdown to switch between available assistants', 'wp-mcp-ai' ); ?></p>
								<ul style="margin-left: 1.5rem;">
									<li><?php esc_html_e( 'Dynamic assistant list', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Role-based filtering', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Seamless switching', 'wp-mcp-ai' ); ?></li>
								</ul>
							</div>

							<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; border-radius: 4px;">
								<h3 style="margin-top: 0;"><?php esc_html_e( 'Chat History', 'wp-mcp-ai' ); ?></h3>
								<p><?php esc_html_e( 'Display conversation history with filtering', 'wp-mcp-ai' ); ?></p>
								<ul style="margin-left: 1.5rem;">
									<li><?php esc_html_e( 'Persistent transcripts', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Date filtering', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Export functionality', 'wp-mcp-ai' ); ?></li>
								</ul>
							</div>
						</div>

						<?php
						// Debug information to help diagnose configuration issues.
						$is_base_version  = wp_mcp_ai_is_base_version();
						$constant_defined = defined( 'WP_MCP_AI_BASE_VERSION' );
						$constant_value   = $constant_defined ? WP_MCP_AI_BASE_VERSION : 'not defined';
						?>

						<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1rem; margin-top: 1.5rem;">
							<p style="margin: 0;"><strong><?php esc_html_e( 'Current Configuration:', 'wp-mcp-ai' ); ?></strong></p>
							<ul style="margin: 0.5rem 0 0 1.5rem;">
								<li>
									<strong>WP_MCP_AI_BASE_VERSION constant:</strong> 
									<?php
									if ( $constant_defined ) {
										echo '<code>' . esc_html( $constant_value ? 'true' : 'false' ) . '</code>';
									} else {
										echo '<code>not defined</code> (defaults to base version)';
									}
									?>
								</li>
								<li>
									<strong>Mode:</strong> 
									<?php echo $is_base_version ? '<strong style="color: #8b6c00;">Base Version</strong> (Elementor widgets disabled)' : '<strong style="color: #0a5f1a;">Full Version</strong> (Elementor widgets enabled)'; ?>
								</li>
							</ul>
						</div>

						<?php if ( $is_base_version ) : ?>
							<div style="background: #fef7e0; border-left: 4px solid #8b6c00; padding: 1rem; margin-top: 1.5rem;">
								<p style="margin: 0;"><strong><?php esc_html_e( 'To Enable Elementor Widgets:', 'wp-mcp-ai' ); ?></strong></p>
								<p style="margin: 0.5rem 0;">
									<?php esc_html_e( 'Add this line to your wp-config.php file (before the "stop editing" comment):', 'wp-mcp-ai' ); ?>
								</p>
								<p style="margin: 0.5rem 0 0 0;">
									<code style="background: #fff; padding: 0.25rem 0.5rem; display: inline-block;">define( 'WP_MCP_AI_BASE_VERSION', false );</code>
								</p>
								<p style="margin: 0.5rem 0 0 0; font-size: 0.9em; color: #646970;">
									<?php esc_html_e( 'After adding this line, refresh this page to verify the change.', 'wp-mcp-ai' ); ?>
								</p>
							</div>
						<?php else : ?>
							<div style="background: #d5f0db; border-left: 4px solid #0a5f1a; padding: 1rem; margin-top: 1.5rem;">
								<p style="margin: 0;"><strong style="color: #0a5f1a;">✓ <?php esc_html_e( 'Elementor Widgets Enabled', 'wp-mcp-ai' ); ?></strong></p>
								<p style="margin: 0.5rem 0 0 0;">
									<?php esc_html_e( 'Full Version mode is active. All Elementor widgets are available in the editor.', 'wp-mcp-ai' ); ?>
								</p>
							</div>
						<?php endif; ?>

						<?php submit_button( __( 'Save Elementor Settings', 'wp-mcp-ai' ) ); ?>
					</form>

					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'Using Widgets in Elementor', 'wp-mcp-ai' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'Edit a page with Elementor', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Search for "WP oOS" in the widget panel', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Drag the desired widget to your page', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Configure widget settings in the left panel', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Publish or update your page', 'wp-mcp-ai' ); ?></li>
						</ol>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
