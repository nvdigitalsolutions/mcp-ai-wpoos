<?php
/**
 * WP oOS Settings Dashboard Diagnostic Page
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
		 * Note: Changed from submenu of 'wp-mcp-ai-dashboard' to 'tools.php'
		 * to ensure the diagnostic page is always accessible, even when the
		 * main dashboard isn't loaded (e.g., when WP_MCP_AI_USE_OLD_SETTINGS is true).
		 */
		public static function register_page() {
			add_submenu_page(
				'tools.php',
				__( 'WP oOS Dashboard Diagnostic', 'wp-mcp-ai' ),
				__( 'WP oOS Diagnostic', 'wp-mcp-ai' ),
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
				<h1><?php esc_html_e( 'WP oOS Settings Dashboard Diagnostic', 'wp-mcp-ai' ); ?></h1>
				
				<div class="card">
					<h2><?php esc_html_e( '1. Constants', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Constant', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Value', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$constants = array(
								'WP_MCP_AI_PATH',
								'WP_MCP_AI_URL',
								'WP_MCP_AI_VERSION',
								'WP_MCP_AI_USE_OLD_SETTINGS',
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
									<td><?php echo $defined ? '<span style="color: green;">✓ Defined</span>' : '<span style="color: red;">✗ Not Defined</span>'; ?></td>
									<td><code><?php echo esc_html( $value ); ?></code></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '2. Required Classes', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Class', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
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
									<td><?php echo $exists ? '<span style="color: green;">✓ Exists</span>' : '<span style="color: red;">✗ Not Found</span>'; ?></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '3. Required Functions', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Function', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
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
									<td><?php echo $exists ? '<span style="color: green;">✓ Exists</span>' : '<span style="color: red;">✗ Not Found</span>'; ?></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '4. Global Variables', 'wp-mcp-ai' ); ?></h2>
					<?php
					// Check which settings system is active.
					$using_old_settings = defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) && WP_MCP_AI_USE_OLD_SETTINGS;
					?>
					<p>
						<strong><?php esc_html_e( 'Active Settings System:', 'wp-mcp-ai' ); ?></strong>
						<?php
						if ( $using_old_settings ) {
							echo '<span style="color: orange;">' . esc_html__( 'Legacy Settings (WP_MCP_AI_USE_OLD_SETTINGS = true)', 'wp-mcp-ai' ) . '</span>';
						} else {
							echo '<span style="color: green;">' . esc_html__( 'New Settings Dashboard (default)', 'wp-mcp-ai' ) . '</span>';
						}
						?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Variable', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Notes', 'wp-mcp-ai' ); ?></th>
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
										echo '<span style="color: green;">✓ Set</span>';
									} else {
										echo '<span style="color: ' . ( $using_old_settings ? 'gray' : 'red' ) . ';">Not Set</span>';
									}
									?>
								</td>
								<td>
									<?php
									if ( $using_old_settings ) {
										esc_html_e( 'Not needed (legacy mode)', 'wp-mcp-ai' );
									} else {
										esc_html_e( 'New settings dashboard instance', 'wp-mcp-ai' );
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
										echo '<span style="color: green;">✓ Set</span>';
									} else {
										echo '<span style="color: ' . ( $using_old_settings ? 'red' : 'gray' ) . ';">Not Set</span>';
									}
									?>
								</td>
								<td>
									<?php
									if ( $using_old_settings ) {
										esc_html_e( 'Legacy admin settings instance', 'wp-mcp-ai' );
									} else {
										esc_html_e( 'Not needed (new dashboard mode)', 'wp-mcp-ai' );
									}
									?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '5. Admin Menu Pages', 'wp-mcp-ai' ); ?></h2>
					<?php
					global $menu, $submenu;
					$found_old = false;
					$found_new = false;
					$found_auth0 = false;

					// Check for old settings under Settings submenu.
					if ( isset( $submenu['options-general.php'] ) ) {
						foreach ( $submenu['options-general.php'] as $item ) {
							if ( isset( $item[2] ) && 'wp-mcp-ai-settings' === $item[2] ) {
								$found_old = true;
								break;
							}
						}
					}

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
								<th><?php esc_html_e( 'Menu Item', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
								<th><?php esc_html_e( 'Expected?', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Old Settings (Settings > WP oOS)', 'wp-mcp-ai' ); ?></td>
								<td><?php echo $found_old ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not Found</span>'; ?></td>
								<td><?php echo ( defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) && WP_MCP_AI_USE_OLD_SETTINGS ) ? 'Yes' : 'No'; ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'New Dashboard (Top-level WP oOS)', 'wp-mcp-ai' ); ?></td>
								<td><?php echo $found_new ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not Found</span>'; ?></td>
								<td><?php echo ( ! defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) || ! WP_MCP_AI_USE_OLD_SETTINGS ) ? 'Yes' : 'No'; ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Auth0 Setup (Under WP oOS)', 'wp-mcp-ai' ); ?></td>
								<td><?php echo $found_auth0 ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not Found</span>'; ?></td>
								<td><?php echo ( ! defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) || ! WP_MCP_AI_USE_OLD_SETTINGS ) ? 'Yes' : 'No'; ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '6. Registered Sections', 'wp-mcp-ai' ); ?></h2>
					<?php if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) : ?>
						<?php
						$sections = WP_MCP_AI_Settings_Registry::get_sections();
						?>
						<p><?php printf( esc_html__( 'Total sections registered: %d', 'wp-mcp-ai' ), count( $sections ) ); ?></p>
						<?php if ( ! empty( $sections ) ) : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Section ID', 'wp-mcp-ai' ); ?></th>
										<th><?php esc_html_e( 'Class', 'wp-mcp-ai' ); ?></th>
										<th><?php esc_html_e( 'Tab', 'wp-mcp-ai' ); ?></th>
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
						<p style="color: red;"><?php esc_html_e( 'WP_MCP_AI_Settings_Registry class not found!', 'wp-mcp-ai' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '7. Diagnosis', 'wp-mcp-ai' ); ?></h2>
					<?php
					$use_old = defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) && WP_MCP_AI_USE_OLD_SETTINGS;
					?>
					<div style="padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
						<?php if ( $use_old ) : ?>
							<p><strong><?php esc_html_e( 'Mode: Legacy Settings (OLD)', 'wp-mcp-ai' ); ?></strong></p>
							<p><?php esc_html_e( 'The WP_MCP_AI_USE_OLD_SETTINGS constant is set to TRUE.', 'wp-mcp-ai' ); ?></p>
							<p><?php esc_html_e( 'Expected behavior: Settings page should appear under Settings > WP oOS', 'wp-mcp-ai' ); ?></p>
							<p><strong><?php esc_html_e( 'Actual:', 'wp-mcp-ai' ); ?></strong> <?php echo $found_old ? '<span style="color: green;">✓ Working correctly</span>' : '<span style="color: red;">✗ Not working - settings page not found</span>'; ?></p>
						<?php else : ?>
							<p><strong><?php esc_html_e( 'Mode: New Dashboard (DEFAULT)', 'wp-mcp-ai' ); ?></strong></p>
							<p><?php esc_html_e( 'The WP_MCP_AI_USE_OLD_SETTINGS constant is FALSE or not set.', 'wp-mcp-ai' ); ?></p>
							<p><?php esc_html_e( 'Expected behavior: Top-level menu "WP oOS" with Auth0 submenu', 'wp-mcp-ai' ); ?></p>
							<p><strong><?php esc_html_e( 'Dashboard:', 'wp-mcp-ai' ); ?></strong> <?php echo $found_new ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not found</span>'; ?></p>
							<p><strong><?php esc_html_e( 'Auth0 Setup:', 'wp-mcp-ai' ); ?></strong> <?php echo $found_auth0 ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Not found</span>'; ?></p>
							
							<?php if ( ! $found_new ) : ?>
								<hr>
								<h3 style="color: red;"><?php esc_html_e( 'Issue Detected: Dashboard Not Loading', 'wp-mcp-ai' ); ?></h3>
								<p><?php esc_html_e( 'Possible causes:', 'wp-mcp-ai' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'PHP error during dashboard initialization (check error logs)', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Missing or corrupted dashboard files', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Hook timing issue', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'WordPress version incompatibility', 'wp-mcp-ai' ); ?></li>
								</ul>
								
								<h4><?php esc_html_e( 'Troubleshooting Steps:', 'wp-mcp-ai' ); ?></h4>
								<ol>
									<li><?php esc_html_e( 'Check WordPress error log (wp-content/debug.log if WP_DEBUG_LOG is enabled)', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Deactivate and reactivate the plugin', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Check file permissions on includes/admin/ directory', 'wp-mcp-ai' ); ?></li>
									<li><?php esc_html_e( 'Try switching to legacy mode by adding to wp-config.php: define(\'WP_MCP_AI_USE_OLD_SETTINGS\', true);', 'wp-mcp-ai' ); ?></li>
								</ol>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="card">
					<h2><?php esc_html_e( '8. System Information', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'WordPress Version', 'wp-mcp-ai' ); ?></th>
								<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'PHP Version', 'wp-mcp-ai' ); ?></th>
								<td><?php echo esc_html( PHP_VERSION ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Plugin Version', 'wp-mcp-ai' ); ?></th>
								<td><?php echo defined( 'WP_MCP_AI_VERSION' ) ? esc_html( WP_MCP_AI_VERSION ) : 'N/A'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'is_admin()', 'wp-mcp-ai' ); ?></th>
								<td><?php echo is_admin() ? 'true' : 'false'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Current Screen', 'wp-mcp-ai' ); ?></th>
								<td><?php $screen = get_current_screen(); echo $screen ? esc_html( $screen->id ) : 'null'; ?></td>
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
