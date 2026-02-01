<?php
/**
 * NV oOS Settings Dashboard Diagnostic Page
 *
 * Temporary admin page to diagnose why the settings dashboard isn't loading.
 * This creates a simple diagnostic page under Tools menu.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Dashboard_Diagnostic' ) ) {
	/**
	 * Diagnostic page for settings dashboard issues.
	 */
	class WP_MCP_AI_Dashboard_Diagnostic {
		/**
		 * Initialize the diagnostic page.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		}

		/**
		 * Register diagnostic page under Tools menu.
		 *
		 * Note: Located under Tools menu to ensure easy access for troubleshooting.
		 */
		public static function register_page() {
			add_submenu_page(
				'tools.php',
				__( 'NV oOS Dashboard Diagnostic', 'mcp-ai-wpoos' ),
				__( 'NV oOS Diagnostic', 'mcp-ai-wpoos' ),
				'manage_options',
				'wp-mcp-ai-diagnostic',
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Render the diagnostic page.
		 */
		public static function render_page() {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'NV oOS Settings Dashboard Diagnostic', 'mcp-ai-wpoos' ); ?></h1>
				<?php
				// Determine which settings system is in use by checking the admin menu.
				// This must be done early as it's referenced throughout the diagnostic.
				global $menu, $submenu;
				$using_old_settings = false;

				// Check for old settings under Settings submenu.
				if ( isset( $submenu['options-general.php'] ) ) {
					foreach ( $submenu['options-general.php'] as $item ) {
						if ( isset( $item[2] ) && 'wp-mcp-ai-settings' === $item[2] ) {
							$using_old_settings = true;
							break;
						}
					}
				}
				?>

				<div class="card">
					<h2><?php esc_html_e( '1. Constants', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Constant', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$constants = array(
								'WP_MCP_AI_PATH',
								'WP_MCP_AI_URL',
								'WP_MCP_AI_VERSION',
							);
							foreach ( $constants as $const ) {
								$defined = defined( $const );
								$value   = $defined ? constant( $const ) : 'N/A';
								if ( is_bool( $value ) ) {
									$value = $value ? 'true' : 'false';
								}
								?>
								<tr>
									<td><code><?php echo esc_html( $const ); ?></code></td>
									<td><?php echo wp_kses_post( $defined ? '<span style="color: green;">✓ Defined</span>' : '<span style="color: red;">✗ Not Defined</span>' ); ?></td>
									<td><code><?php echo esc_html( $value ); ?></code></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '2. Required Classes', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Class', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$classes = array(
								'WP_MCP_AI_Admin_Settings',
								'WP_MCP_AI_Settings_Dashboard',
								'WP_MCP_AI_Settings_Registry',
								'WP_MCP_AI_Settings_Validator',
								'WP_MCP_AI_Settings_Section',
								'WP_MCP_AI_Section_General',
								'WP_MCP_AI_Section_Providers',
								'WP_MCP_AI_Section_Authentication',
								'WP_MCP_AI_Section_Tools',
								'WP_MCP_AI_Section_Integrations',
								'WP_MCP_AI_Section_Security',
								'WP_MCP_AI_Section_Advanced',
								'WP_MCP_AI_Auth0_Setup',
							);
							foreach ( $classes as $class ) {
								$exists = class_exists( $class );
								?>
								<tr>
									<td><code><?php echo esc_html( $class ); ?></code></td>
									<td><?php echo wp_kses_post( $exists ? '<span style="color: green;">✓ Exists</span>' : '<span style="color: red;">✗ Not Found</span>' ); ?></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '3. Required Functions', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Function', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$functions = array(
								'wp_mcp_ai_init_settings_dashboard',
							);
							foreach ( $functions as $func ) {
								$exists = function_exists( $func );
								?>
								<tr>
									<td><code><?php echo esc_html( $func ); ?></code></td>
									<td><?php echo wp_kses_post( $exists ? '<span style="color: green;">✓ Exists</span>' : '<span style="color: red;">✗ Not Found</span>' ); ?></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '4. Global Variables', 'mcp-ai-wpoos' ); ?></h2>
					<p>
						<strong><?php esc_html_e( 'Active Settings System:', 'mcp-ai-wpoos' ); ?></strong>
						<span style="color: green;"><?php esc_html_e( 'Settings Dashboard', 'mcp-ai-wpoos' ); ?></span>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Variable', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Notes', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							// Check for new settings dashboard global.
							$dashboard_exists = isset( $GLOBALS['wp_mcp_ai_settings_dashboard'] );
							?>
							<tr>
								<td><code>$GLOBALS['wp_mcp_ai_settings_dashboard']</code></td>
								<td>
									<?php
									if ( $dashboard_exists ) {
										echo wp_kses_post( '<span style="color: green;">✓ Set</span>' );
									} else {
										echo wp_kses_post( '<span style="color: ' . ( $using_old_settings ? 'gray' : 'red' ) . ';">Not Set</span>' );
									}
									?>
								</td>
								<td>
									<?php
									if ( $using_old_settings ) {
										esc_html_e( 'Not needed (legacy mode - should not occur)', 'mcp-ai-wpoos' );
									} else {
										esc_html_e( 'Settings dashboard instance', 'mcp-ai-wpoos' );
									}
									?>
								</td>
							</tr>
							<?php
							// Check for old admin settings global.
							$admin_settings_exists = isset( $GLOBALS['wp_mcp_ai_admin_settings'] );
							?>
							<tr>
								<td><code>$GLOBALS['wp_mcp_ai_admin_settings']</code></td>
								<td>
									<?php
									if ( $admin_settings_exists ) {
										echo wp_kses_post( '<span style="color: orange;">⚠ Set (legacy)</span>' );
									} else {
										echo wp_kses_post( '<span style="color: gray;">Not Set</span>' );
									}
									?>
								</td>
								<td>
									<?php
									if ( $admin_settings_exists ) {
										esc_html_e( 'Legacy admin settings instance detected (should not be set)', 'mcp-ai-wpoos' );
									} else {
										esc_html_e( 'Not set (correct - using new dashboard)', 'mcp-ai-wpoos' );
									}
									?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '5. Admin Menu Pages', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					// Reuse $using_old_settings that was already determined at the top of the page.
					$found_old   = $using_old_settings;
					$found_new   = false;
					$found_auth0 = false;

					// Check for new dashboard as top-level menu.
					if ( isset( $menu ) ) {
						foreach ( $menu as $item ) {
							if ( isset( $item[2] ) && 'wp-mcp-ai-dashboard' === $item[2] ) {
								$found_new = true;
								break;
							}
						}
					}

					// Check for Auth0 setup under new dashboard.
					if ( isset( $submenu['wp-mcp-ai-dashboard'] ) ) {
						foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
							if ( isset( $item[2] ) && 'wp-mcp-ai-auth0-setup' === $item[2] ) {
								$found_auth0 = true;
								break;
							}
						}
					}
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Menu Item', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Expected?', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Settings Dashboard (Top-level NV oOS)', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo wp_kses_post( $found_new ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not Found</span>' ); ?></td>
								<td>Yes (Required)</td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Legacy Settings Page (Under Settings)', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo wp_kses_post( $found_old ? '<span style="color: orange;">⚠ Found (should not exist)</span>' : '<span style="color: green;">✓ Not Found (correct)</span>' ); ?></td>
								<td>No (Deprecated)</td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Auth0 Setup (Under NV oOS)', 'mcp-ai-wpoos' ); ?></td>
								<td><?php echo wp_kses_post( $found_auth0 ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not Found</span>' ); ?></td>
								<td>Yes</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '6. Registered Sections', 'mcp-ai-wpoos' ); ?></h2>
					<?php if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) : ?>
						<?php
						$sections = WP_MCP_AI_Settings_Registry::get_sections();
						?>
						<p><?php
						/* translators: %d: Number of registered sections */
						printf( esc_html__( 'Total sections registered: %d', 'mcp-ai-wpoos' ), count( $sections ) ); ?></p>
						<?php if ( ! empty( $sections ) ) : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Section ID', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Class', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Tab', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $sections as $section_id => $section ) : ?>
										<tr>
											<td><code><?php echo esc_html( $section_id ); ?></code></td>
											<td><code><?php echo esc_html( get_class( $section ) ); ?></code></td>
											<td><?php echo esc_html( $section->get_tab() ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					<?php else : ?>
						<p style="color: red;"><?php esc_html_e( 'WP_MCP_AI_Settings_Registry class not found!', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '7. Diagnosis', 'mcp-ai-wpoos' ); ?></h2>
					<div style="padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
						<p><strong><?php esc_html_e( 'Mode: New Dashboard', 'mcp-ai-wpoos' ); ?></strong></p>
						<p><?php esc_html_e( 'Expected behavior: Top-level menu "NV oOS" with Auth0 submenu', 'mcp-ai-wpoos' ); ?></p>
						<p><strong><?php esc_html_e( 'Dashboard:', 'mcp-ai-wpoos' ); ?></strong> <?php echo $found_new ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not found</span>'; ?></p>
						<p><strong><?php esc_html_e( 'Auth0 Setup:', 'mcp-ai-wpoos' ); ?></strong> <?php echo $found_auth0 ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not found</span>'; ?></p>

						<?php if ( ! $found_new ) : ?>
							<hr>
							<h3 style="color: red;"><?php esc_html_e( 'Issue Detected: Dashboard Not Loading', 'mcp-ai-wpoos' ); ?></h3>
							<p><?php esc_html_e( 'Possible causes:', 'mcp-ai-wpoos' ); ?></p>
							<ul>
								<li><?php esc_html_e( 'PHP error during dashboard initialization (check error logs)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Missing or corrupted dashboard files', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Hook timing issue', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'WordPress version incompatibility', 'mcp-ai-wpoos' ); ?></li>
							</ul>

							<h4><?php esc_html_e( 'Troubleshooting Steps:', 'mcp-ai-wpoos' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Check WordPress error log (wp-content/debug.log if WP_DEBUG_LOG is enabled)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Deactivate and reactivate the plugin', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check file permissions on includes/admin/ directory', 'mcp-ai-wpoos' ); ?></li>
							</ol>
						<?php endif; ?>
					</div>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '8. System Information', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'WordPress Version', 'mcp-ai-wpoos' ); ?></th>
								<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'PHP Version', 'mcp-ai-wpoos' ); ?></th>
								<td><?php echo esc_html( PHP_VERSION ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Plugin Version', 'mcp-ai-wpoos' ); ?></th>
								<td><?php echo defined( 'WP_MCP_AI_VERSION' ) ? esc_html( WP_MCP_AI_VERSION ) : 'N/A'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'is_admin()', 'mcp-ai-wpoos' ); ?></th>
								<td><?php echo esc_html( is_admin() ? 'true' : 'false' ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Current Screen', 'mcp-ai-wpoos' ); ?></th>
								<td>
								<?php
								$screen = get_current_screen();
								echo $screen ? esc_html( $screen->id ) : 'null';
								?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<?php
		}
	}

	// Initialize the diagnostic page.
	WP_MCP_AI_Dashboard_Diagnostic::init();
}
