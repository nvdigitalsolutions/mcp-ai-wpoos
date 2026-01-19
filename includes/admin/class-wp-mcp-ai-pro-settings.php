<?php
/**
 * Pro Settings Page
 *
 * Displays system information including npm package versions and pro toolkit settings.
 * Read-only status display for monitoring active packages and dependencies.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Settings' ) ) {
	/**
	 * Pro Settings page for displaying system information.
	 *
	 * Provides a centralized view of:
	 * - NPM package versions (dependencies and devDependencies)
	 * - Pro toolkit configuration status
	 * - System information relevant to pro features
	 *
	 * @since 1.1.0
	 */
	class WP_MCP_AI_Pro_Settings {
		/**
		 * Parent page slug (Pro Dashboard).
		 */
		const PARENT_SLUG = 'nvoos-pro-dashboard';

		/**
		 * Pro Settings page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-settings';

		/**
		 * Get npm package information from package.json.
		 *
		 * Parses package.json to extract dependencies and devDependencies.
		 * Now includes Pro addon packages as well.
		 * Lightweight approach that doesn't require npm CLI.
		 *
		 * @return array Array containing dependencies and devDependencies.
		 */
		public static function get_npm_packages() {
			$package_json_path = WP_MCP_AI_PATH . 'package.json';
			$packages          = array(
				'dependencies'    => array(),
				'devDependencies' => array(),
				'error'           => null,
			);

			if ( ! file_exists( $package_json_path ) ) {
				$packages['error'] = 'package.json not found';
				return $packages;
			}

			$json_content = file_get_contents( $package_json_path );
			if ( false === $json_content ) {
				$packages['error'] = 'Unable to read package.json';
				return $packages;
			}

			$package_data = json_decode( $json_content, true );
			if ( null === $package_data ) {
				$packages['error'] = 'Invalid JSON in package.json';
				return $packages;
			}

			// Extract dependencies.
			if ( isset( $package_data['dependencies'] ) && is_array( $package_data['dependencies'] ) ) {
				$packages['dependencies'] = $package_data['dependencies'];
			}

			// Extract devDependencies.
			if ( isset( $package_data['devDependencies'] ) && is_array( $package_data['devDependencies'] ) ) {
				$packages['devDependencies'] = $package_data['devDependencies'];
			}

			// Extract project metadata.
			$packages['name']    = isset( $package_data['name'] ) ? $package_data['name'] : 'unknown';
			$packages['version'] = isset( $package_data['version'] ) ? $package_data['version'] : 'unknown';

			// Merge in Pro addon packages if available.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_package_json_path = WP_MCP_AI_PRO_PATH . 'package.json';
				if ( file_exists( $pro_package_json_path ) ) {
					$pro_json_content = file_get_contents( $pro_package_json_path );
					if ( false !== $pro_json_content ) {
						$pro_package_data = json_decode( $pro_json_content, true );
						if ( null !== $pro_package_data ) {
							// Merge Pro dependencies.
							if ( isset( $pro_package_data['dependencies'] ) && is_array( $pro_package_data['dependencies'] ) ) {
								$packages['dependencies'] = array_merge( $packages['dependencies'], $pro_package_data['dependencies'] );
							}
							// Pro addon doesn't have devDependencies, but check anyway.
							if ( isset( $pro_package_data['devDependencies'] ) && is_array( $pro_package_data['devDependencies'] ) ) {
								$packages['devDependencies'] = array_merge( $packages['devDependencies'], $pro_package_data['devDependencies'] );
							}
						}
					}
				}
			}

			return $packages;
		}

		/**
		 * Get individual pro toolkit status information.
		 *
		 * Returns enable/disable status of each individual pro toolkit.
		 *
		 * @return array Individual toolkit status information.
		 */
		public static function get_individual_toolkit_status() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$toolkits = array(
				'enable_media_toolkit'                => __( 'Media Toolkit', 'mcp-ai-wpoos' ),
				'enable_document_generation_toolkit'  => __( 'Document Generation Toolkit', 'mcp-ai-wpoos' ),
				'enable_quiz_system'                  => __( 'Quiz System', 'mcp-ai-wpoos' ),
				'enable_project_management'           => __( 'Project Management', 'mcp-ai-wpoos' ),
				'enable_health_wellness_management'   => __( 'Health & Wellness Management', 'mcp-ai-wpoos' ),
				'enable_places_management'            => __( 'Places Management', 'mcp-ai-wpoos' ),
				'enable_eca_management'               => __( 'ECA Management', 'mcp-ai-wpoos' ),
				'enable_woocommerce_tools'            => __( 'WooCommerce Tools', 'mcp-ai-wpoos' ),
				'enable_jetengine_tools'              => __( 'JetEngine Tools', 'mcp-ai-wpoos' ),
				'enable_site_creator'                 => __( 'Site Creator', 'mcp-ai-wpoos' ),
				'enable_ai_cpt_management'            => __( 'AI CPT Management', 'mcp-ai-wpoos' ),
			);

			$toolkit_status = array();
			foreach ( $toolkits as $setting_key => $toolkit_name ) {
				$toolkit_status[ $setting_key ] = array(
					'name'    => $toolkit_name,
					'enabled' => ! empty( $settings[ $setting_key ] ),
				);
			}

			return $toolkit_status;
		}

		/**
		 * Get pro toolkit status information.
		 *
		 * Returns status of various pro features and configurations.
		 *
		 * @return array Pro toolkit status information.
		 */
		public static function get_pro_toolkit_status() {
			$status = array(
				'pro_dashboard_enabled' => defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED,
				'base_version'          => defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION,
				'debug_mode'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'php_version'           => PHP_VERSION,
				'wp_version'            => get_bloginfo( 'version' ),
				'plugin_version'        => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			);

			// Check optional integrations.
			$status['integrations'] = array(
				'jetengine'  => class_exists( 'Jet_Engine' ),
				'woocommerce' => class_exists( 'WooCommerce' ),
				'elementor'  => defined( 'ELEMENTOR_VERSION' ),
				'rankmath'   => defined( 'RANK_MATH_VERSION' ),
				'wpcode'     => defined( 'WPCODE_VERSION' ),
			);

			return $status;
		}

		/**
		 * Render npm packages table.
		 *
		 * Displays packages in a WordPress standard table format.
		 *
		 * @param array  $packages Package list.
		 * @param string $title    Table title.
		 * @return void
		 */
		private static function render_packages_table( $packages, $title ) {
			if ( empty( $packages ) ) {
				echo '<p><em>' . esc_html__( 'No packages found.', 'mcp-ai-wpoos' ) . '</em></p>';
				return;
			}

			?>
			<h3><?php echo esc_html( $title ); ?> <span class="count">(<?php echo count( $packages ); ?>)</span></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Package Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Sort packages alphabetically.
					ksort( $packages );
					foreach ( $packages as $package => $version ) :
						// Check if package is installed (vendor file exists).
						$package_installed = self::check_package_installed( $package );
						$status_class      = $package_installed ? 'installed' : 'not-installed';
						$status_text       = $package_installed ? __( 'Installed', 'mcp-ai-wpoos' ) : __( 'Not Found', 'mcp-ai-wpoos' );
						?>
						<tr>
							<td><code><?php echo esc_html( $package ); ?></code></td>
							<td><code><?php echo esc_html( $version ); ?></code></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $status_class ); ?>">
									<?php echo esc_html( $status_text ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Check if a package is installed by looking for vendor files or bundled builds.
		 *
		 * Checks for packages in vendor directories, bundled into built JavaScript files,
		 * bundled into local scripts, or in node_modules (both base and Pro addon locations).
		 *
		 * @param string $package Package name.
		 * @return bool True if package appears to be installed.
		 */
		private static function check_package_installed( $package ) {
			// Check for vendor copies (chart.js, vectorizer).
			if ( 'chart.js' === $package ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' );
			}
			if ( '@neplex/vectorizer' === $package ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/neplex-vectorizer/' );
			}

			// Check for packages bundled into chat-bundle.min.js via esbuild.
			$bundled_packages = array(
				'@microsoft/fetch-event-source',
				'dompurify',
				'marked',
				'ky',
			);
			if ( in_array( $package, $bundled_packages, true ) ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/chat-bundle.min.js' );
			}

			// Check for document generation packages bundled into local scripts.
			$script_bundled_packages = array(
				'pdfkit'  => 'generate-pdf.bundle.js',
				'docx'    => 'generate-word.bundle.js',
				'exceljs' => 'generate-excel.bundle.js',
			);
			if ( isset( $script_bundled_packages[ $package ] ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$script_path = WP_MCP_AI_PRO_PATH . 'bin/' . $script_bundled_packages[ $package ];
				if ( file_exists( $script_path ) ) {
					return true;
				}
			}

			// Check for Pro addon packages in vendor directory.
			$pro_vendor_packages = array(
				'@turf/turf'     => 'turf/dist/esm/index.js',
				'@types/pdfkit'  => false, // TypeScript types only, no runtime file
				'fluent-ffmpeg'  => 'fluent-ffmpeg/index.js',
				'ics'            => 'ics/index.js',
				'katex'          => 'katex/dist/katex.min.js',
				'mjml'           => 'mjml/lib/index.js',
				'prettier'       => 'prettier/standalone.js',
				'sharp'          => 'sharp/lib/index.js',
			);
			if ( isset( $pro_vendor_packages[ $package ] ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// @types packages don't have runtime files
				if ( false === $pro_vendor_packages[ $package ] ) {
					return true; // TypeScript type definitions are always available
				}
				$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $pro_vendor_packages[ $package ];
				if ( file_exists( $vendor_path ) ) {
					return true;
				}
			}

			// Fallback: Check Pro node_modules (for development).
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package;
				if ( file_exists( $pro_node_modules_path ) ) {
					return true;
				}
			}

			// Check base node_modules (if present).
			$node_modules_path = WP_MCP_AI_PATH . 'node_modules/' . $package;
			return file_exists( $node_modules_path );
		}

		/**
		 * Render pro toolkit status section.
		 *
		 * @param array $status Pro toolkit status.
		 * @return void
		 */
		private static function render_pro_toolkit_status( $status ) {
			?>
			<h3><?php esc_html_e( 'Pro Toolkit Status', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Feature', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['plugin_version'] ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Pro Dashboard', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo $status['pro_dashboard_enabled'] ? 'enabled' : 'disabled'; ?>">
								<?php echo $status['pro_dashboard_enabled'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Base Version Mode', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo $status['base_version'] ? 'active' : 'inactive'; ?>">
								<?php echo $status['base_version'] ? esc_html__( 'Active (35 core tools)', 'mcp-ai-wpoos' ) : esc_html__( 'Inactive (65+ tools)', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Debug Mode', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo $status['debug_mode'] ? 'enabled' : 'disabled'; ?>">
								<?php echo $status['debug_mode'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'PHP Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['php_version'] ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WordPress Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['wp_version'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Optional Integrations', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Integration', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['integrations'] as $integration => $is_active ) : ?>
						<tr>
							<td><strong><?php echo esc_html( ucfirst( $integration ) ); ?></strong></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
									<?php echo $is_active ? esc_html__( 'Active', 'mcp-ai-wpoos' ) : esc_html__( 'Inactive', 'mcp-ai-wpoos' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render individual pro toolkits status section.
		 *
		 * @param array $toolkit_status Individual toolkit status.
		 * @return void
		 */
		private static function render_individual_toolkits_status( $toolkit_status ) {
			?>
			<h3><?php esc_html_e( 'Individual Pro Toolkits', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Toolkit Name', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $toolkit_status as $setting_key => $toolkit_info ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $toolkit_info['name'] ); ?></strong></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo $toolkit_info['enabled'] ? 'enabled' : 'disabled'; ?>">
									<?php echo $toolkit_info['enabled'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render the Pro Settings page.
		 *
		 * @return void
		 */
		public static function render_page() {
			// Verify user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			$packages         = self::get_npm_packages();
			$pro_status       = self::get_pro_toolkit_status();
			$toolkit_status   = self::get_individual_toolkit_status();
			$total_packages   = count( $packages['dependencies'] ) + count( $packages['devDependencies'] );
			?>
			<div class="wrap wp-mcp-ai-pro-settings">
				<h1>
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Pro Settings & System Information', 'mcp-ai-wpoos' ); ?>
				</h1>

				<p class="description">
					<?php esc_html_e( 'View npm package status, pro toolkit configuration, and system information. This is a read-only display for monitoring your NV oOS installation.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php if ( isset( $packages['error'] ) ) : ?>
					<div class="notice notice-error">
						<p>
							<strong><?php esc_html_e( 'Error reading package.json:', 'mcp-ai-wpoos' ); ?></strong>
							<?php echo esc_html( $packages['error'] ); ?>
						</p>
					</div>
				<?php else : ?>
					<div class="notice notice-info">
						<p>
							<strong><?php esc_html_e( 'Project:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $packages['name'] ); ?> 
							<strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $packages['version'] ); ?>
							<strong style="margin-left: 20px;"><?php esc_html_e( 'Total NPM Packages:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $total_packages ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="wp-mcp-ai-settings-columns">
					<!-- Pro Toolkit Status -->
					<div class="wp-mcp-ai-settings-column">
						<div class="wp-mcp-ai-settings-card">
							<?php self::render_pro_toolkit_status( $pro_status ); ?>
							
							<div style="margin-top: 30px;"></div>
							
							<?php self::render_individual_toolkits_status( $toolkit_status ); ?>
						</div>
					</div>

					<!-- NPM Packages -->
					<div class="wp-mcp-ai-settings-column">
						<div class="wp-mcp-ai-settings-card">
							<h2><?php esc_html_e( 'NPM Packages', 'mcp-ai-wpoos' ); ?></h2>

							<?php if ( ! isset( $packages['error'] ) ) : ?>
								<?php self::render_packages_table( $packages['dependencies'], __( 'Production Dependencies', 'mcp-ai-wpoos' ) ); ?>
								
								<div style="margin-top: 30px;"></div>
								
								<?php self::render_packages_table( $packages['devDependencies'], __( 'Development Dependencies', 'mcp-ai-wpoos' ) ); ?>

								<div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #72aee6;">
									<p style="margin: 0;">
										<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
										<?php esc_html_e( 'Package status is determined by checking for vendor files. Some packages may be installed in node_modules but not visible here after deployment.', 'mcp-ai-wpoos' ); ?>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7;">
					<h3><?php esc_html_e( 'About This Page', 'mcp-ai-wpoos' ); ?></h3>
					<p>
						<?php esc_html_e( 'This page provides a centralized view of your NV oOS Pro installation. It displays:', 'mcp-ai-wpoos' ); ?>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'NPM package versions from package.json (read-only)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Pro Dashboard and feature flags status', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Optional integration status (JetEngine, WooCommerce, etc.)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'System information (PHP, WordPress versions)', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p>
						<em><?php esc_html_e( 'This is a lightweight, read-only display. No package management functionality is included to keep the plugin size minimal.', 'mcp-ai-wpoos' ); ?></em>
					</p>
				</div>
			</div>

			<style>
				.wp-mcp-ai-pro-settings h1 .dashicons {
					font-size: 30px;
					width: 30px;
					height: 30px;
					vertical-align: middle;
					margin-right: 8px;
					color: #2271b1;
				}

				.wp-mcp-ai-settings-columns {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 20px;
					margin-top: 20px;
				}

				@media (max-width: 1280px) {
					.wp-mcp-ai-settings-columns {
						grid-template-columns: 1fr;
					}
				}

				.wp-mcp-ai-settings-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					padding: 20px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
				}

				.wp-mcp-ai-settings-card h2 {
					margin-top: 0;
					padding-bottom: 10px;
					border-bottom: 1px solid #c3c4c7;
				}

				.wp-mcp-ai-settings-card h3 {
					margin-top: 20px;
					margin-bottom: 10px;
					font-size: 14px;
					font-weight: 600;
					color: #1d2327;
				}

				.wp-mcp-ai-settings-card h3 .count {
					color: #646970;
					font-weight: 400;
				}

				.wp-mcp-ai-status-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.wp-mcp-ai-status-badge.installed,
				.wp-mcp-ai-status-badge.enabled,
				.wp-mcp-ai-status-badge.active {
					background: #00a32a;
					color: #fff;
				}

				.wp-mcp-ai-status-badge.not-installed,
				.wp-mcp-ai-status-badge.disabled,
				.wp-mcp-ai-status-badge.inactive {
					background: #dba617;
					color: #fff;
				}

				.wp-mcp-ai-pro-settings .wp-list-table {
					margin-top: 10px;
				}

				.wp-mcp-ai-pro-settings code {
					background: #f0f0f1;
					padding: 2px 6px;
					border-radius: 3px;
					font-size: 13px;
				}
			</style>
			<?php
		}

		/**
		 * Add Pro Settings page to Pro Dashboard menu.
		 *
		 * @return void
		 */
		public static function add_menu_page() {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Pro Settings', 'mcp-ai-wpoos' ),
				__( 'Pro Settings', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}
	}
}

// Register Pro Settings page in admin menu.
add_action( 'admin_menu', array( 'WP_MCP_AI_Pro_Settings', 'add_menu_page' ), 100 );
