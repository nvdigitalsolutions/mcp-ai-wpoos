<?php
/**
 * JetEngine Integration Admin Page
 *
 *
 * @package WP_MCP_AI
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
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_jetengine_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			$settings['enable_jetengine_cct']   = isset( $_POST['enable_jetengine_cct'] ) ? true : false;
			$settings['enable_jetengine_tools'] = isset( $_POST['enable_jetengine_tools'] ) ? true : false;

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
				__( 'JetEngine Integration - WP oOS', 'wp-mcp-ai' ),
				__( 'JetEngine', 'wp-mcp-ai' ),
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

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'JetEngine Integration', 'wp-mcp-ai' ); ?></h1>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<div style="background: <?php echo esc_attr( $jetengine_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo esc_attr( $jetengine_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
					<?php if ( $jetengine_active ) : ?>
						<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php esc_html_e( 'JetEngine Active', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'JetEngine is installed and active. Advanced CCT storage and AI tools are available.', 'wp-mcp-ai' ); ?></p>
					<?php else : ?>
						<h2 style="margin-top: 0; color: #646970;"><?php esc_html_e( 'JetEngine Not Active', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'JetEngine is not installed or not active.', 'wp-mcp-ai' ); ?></p>
						<p><strong><?php esc_html_e( 'To enable JetEngine integration:', 'wp-mcp-ai' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Purchase and download JetEngine from Crocoblock', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Install and activate the plugin', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Return to this page to configure integration settings', 'wp-mcp-ai' ); ?></li>
						</ol>
					<?php endif; ?>
				</div>

				<?php if ( $jetengine_active ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wp_mcp_ai_save_jetengine_settings' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_save_jetengine_settings" />

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable CCT Storage', 'wp-mcp-ai' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_jetengine_cct" value="1" <?php checked( $cct_enabled ); ?> />
										<?php esc_html_e( 'Use JetEngine Custom Content Types for efficient data storage', 'wp-mcp-ai' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables CCT-based storage for chat transcripts and assistant configurations. Provides better performance than standard WordPress post types.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable AI Tools', 'wp-mcp-ai' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_jetengine_tools" value="1" <?php checked( $tools_enabled ); ?> />
										<?php esc_html_e( 'Activate JetEngine-specific AI tools', 'wp-mcp-ai' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables AI tools for post type creation, taxonomy management, and CCT queries.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Available JetEngine Tools', 'wp-mcp-ai' ); ?></h2>
						<table class="widefat" style="margin-top: 1rem;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tool Name', 'wp-mcp-ai' ); ?></th>
									<th><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
									<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>jetengine_create_post_type</code></td>
									<td><?php esc_html_e( 'Create custom post types dynamically', 'wp-mcp-ai' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'wp-mcp-ai' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'wp-mcp-ai' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>jetengine_create_taxonomy</code></td>
									<td><?php esc_html_e( 'Create custom taxonomies', 'wp-mcp-ai' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'wp-mcp-ai' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'wp-mcp-ai' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>jetengine_query_cct</code></td>
									<td><?php esc_html_e( 'Query Custom Content Types efficiently', 'wp-mcp-ai' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'wp-mcp-ai' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'wp-mcp-ai' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>jetengine_create_cct_item</code></td>
									<td><?php esc_html_e( 'Create CCT entries programmatically', 'wp-mcp-ai' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'wp-mcp-ai' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'wp-mcp-ai' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>jetengine_update_cct_item</code></td>
									<td><?php esc_html_e( 'Update existing CCT items', 'wp-mcp-ai' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'wp-mcp-ai' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'wp-mcp-ai' ) . '</span>'; ?></td>
								</tr>
							</tbody>
						</table>

						<?php submit_button( __( 'Save JetEngine Settings', 'wp-mcp-ai' ) ); ?>
					</form>

					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'Integration Documentation', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'For detailed information about JetEngine integration capabilities:', 'wp-mcp-ai' ); ?></p>
						<ul>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>"><?php esc_html_e( 'View All Available Tools', 'wp-mcp-ai' ); ?></a></li>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ); ?>"><?php esc_html_e( 'System Overview', 'wp-mcp-ai' ); ?></a></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
