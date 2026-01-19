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
	 * Get PHP function requirements status grouped by system.
	 *
	 * Checks availability of PHP functions needed for Pro features.
	 * Functions are grouped by the system/feature that requires them.
	 *
	 * @return array PHP function status information grouped by system.
	 */
	public static function get_php_requirements_status() {
		// Systems and their required PHP functions.
		$systems = array(
			'process_service' => array(
				'name'        => __( 'Process Service & Node.js Integration', 'mcp-ai-wpoos' ),
				'description' => __( 'Core service for external process execution, Node.js tools, and NPM package integration.', 'mcp-ai-wpoos' ),
				'functions'    => array( 'proc_open', 'proc_close', 'proc_terminate' ),
				'critical'     => true,
				'tools'        => array(
					__( 'All Node.js-based tools', 'mcp-ai-wpoos' ),
					__( 'NPM integration (Prettier, MJML, FFmpeg)', 'mcp-ai-wpoos' ),
					__( 'Image optimization (Sharp)', 'mcp-ai-wpoos' ),
					__( 'Video processing', 'mcp-ai-wpoos' ),
					__( 'Math equation rendering', 'mcp-ai-wpoos' ),
				),
			),
			'wp_cli'         => array(
				'name'        => __( 'WP-CLI Integration', 'mcp-ai-wpoos' ),
				'description' => __( 'Command-line interface for WordPress management and automation.', 'mcp-ai-wpoos' ),
				'functions'    => array( 'proc_open', 'proc_close' ),
				'critical'     => false,
				'tools'        => array(
					__( 'check_wp_cli tool', 'mcp-ai-wpoos' ),
					__( 'WP-CLI command execution', 'mcp-ai-wpoos' ),
				),
			),
			'performance'    => array(
				'name'        => __( 'Performance Monitoring & Testing', 'mcp-ai-wpoos' ),
				'description' => __( 'PHPUnit test execution and performance benchmarking from admin interface.', 'mcp-ai-wpoos' ),
				'functions'    => array( 'exec' ),
				'critical'     => false,
				'tools'        => array(
					__( 'Performance monitoring dashboard', 'mcp-ai-wpoos' ),
					__( 'PHPUnit test runner', 'mcp-ai-wpoos' ),
					__( 'Benchmark tests', 'mcp-ai-wpoos' ),
				),
			),
			'documents'      => array(
				'name'        => __( 'Document Generation', 'mcp-ai-wpoos' ),
				'description' => __( 'Advanced PDF, Word, and Excel document generation with external libraries.', 'mcp-ai-wpoos' ),
				'functions'    => array( 'exec' ),
				'critical'     => false,
				'tools'        => array(
					__( 'generate_pdf_document tool', 'mcp-ai-wpoos' ),
					__( 'generate_word_document tool', 'mcp-ai-wpoos' ),
					__( 'generate_excel_document tool', 'mcp-ai-wpoos' ),
				),
			),
		);

		// Check function availability.
		$results = array();
		$all_critical_ok = true;

		foreach ( $systems as $system_id => $system ) {
			$system_functions = array();
			$all_available = true;

			foreach ( $system['functions'] as $func_name ) {
				$available = function_exists( $func_name );
				$system_functions[ $func_name ] = array(
					'available' => $available,
					'name'      => $func_name,
				);

				if ( ! $available ) {
					$all_available = false;
					if ( $system['critical'] ) {
						$all_critical_ok = false;
					}
				}
			}

			$results[ $system_id ] = array(
				'name'         => $system['name'],
				'description'  => $system['description'],
				'functions'     => $system_functions,
				'tools'         => $system['tools'],
				'critical'      => $system['critical'],
				'all_available' => $all_available,
				'status'        => $all_available ? 'ok' : ( $system['critical'] ? 'critical' : 'warning' ),
			);
		}

		// Get disabled functions list.
		$disabled_functions = ini_get( 'disable_functions' );
		$disabled_list = $disabled_functions ? array_map( 'trim', explode( ',', $disabled_functions ) ) : array();

		return array(
			'systems'          => $results,
			'disabled_list'    => $disabled_list,
			'all_critical_ok'  => $all_critical_ok,
			'has_any_issues'   => ! $all_critical_ok || count( array_filter( $results, function( $s ) { return ! $s['all_available']; } ) ) > 0,
		);
	}

	/**
	 * Get comprehensive toolkit details grouped by system.
	 *
	 * Returns detailed information about each toolkit including status,
	 * PHP requirements, NPM dependencies, tools count, and categorization.
	 *
	 * @return array Toolkit details grouped by system.
	 */
	public static function get_toolkit_details() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$toolkits = array(
				'media_toolkit' => array(
					'name'        => __( 'Media Toolkit', 'mcp-ai-wpoos' ),
					'description' => __( 'Image optimization, video processing, SVG vectorization, and math equation rendering.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_media_toolkit'] ),
					'category'    => 'specialized',
					'php_functions' => array( 'proc_open', 'proc_close', 'proc_terminate' ),
					'npm_packages' => array( 'sharp', 'fluent-ffmpeg', 'katex', '@neplex/vectorizer' ),
					'tools_count' => 4,
					'tools'       => array(
						__( 'optimize_image tool', 'mcp-ai-wpoos' ),
						__( 'process_video tool', 'mcp-ai-wpoos' ),
						__( 'render_math_equation tool', 'mcp-ai-wpoos' ),
						__( 'vectorize_image tool', 'mcp-ai-wpoos' ),
					),
				),
				'document_generation' => array(
					'name'        => __( 'Document Generation Toolkit', 'mcp-ai-wpoos' ),
					'description' => __( 'Advanced PDF, Word, and Excel document generation with external libraries.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_document_generation_toolkit'] ),
					'category'    => 'specialized',
					'php_functions' => array( 'exec' ),
					'npm_packages' => array( 'pdfkit', 'docx', 'exceljs' ),
					'tools_count' => 3,
					'tools'       => array(
						__( 'generate_pdf_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_word_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_excel_document tool', 'mcp-ai-wpoos' ),
					),
				),
				'project_management' => array(
					'name'        => __( 'Project Management', 'mcp-ai-wpoos' ),
					'description' => __( 'ICS calendar file generation for project scheduling and event management.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_project_management'] ),
					'category'    => 'specialized',
					'php_functions' => array(),
					'npm_packages' => array( 'ics' ),
					'tools_count' => 1,
					'tools'       => array(
						__( 'generate_ics_calendar tool', 'mcp-ai-wpoos' ),
					),
				),
				'places_management' => array(
					'name'        => __( 'Places Management', 'mcp-ai-wpoos' ),
					'description' => __( 'Geographic data processing and spatial analysis with Turf.js.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_places_management'] ),
					'category'    => 'specialized',
					'php_functions' => array(),
					'npm_packages' => array( '@turf/turf' ),
					'tools_count' => 1,
					'tools'       => array(
						__( 'process_geospatial_data tool', 'mcp-ai-wpoos' ),
					),
				),
				'health_wellness_management' => array(
					'name'        => __( 'Health & Wellness Management', 'mcp-ai-wpoos' ),
					'description' => __( 'Health data visualization and chart generation with Chart.js.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_health_wellness_management'] ),
					'category'    => 'specialized',
					'php_functions' => array(),
					'npm_packages' => array( 'chart.js' ),
					'tools_count' => 1,
					'tools'       => array(
						__( 'generate_health_chart tool', 'mcp-ai-wpoos' ),
					),
				),
				'quiz_system' => array(
					'name'        => __( 'Quiz System', 'mcp-ai-wpoos' ),
					'description' => __( 'Interactive quiz creation with math equation support.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_quiz_system'] ),
					'category'    => 'specialized',
					'php_functions' => array(),
					'npm_packages' => array( 'katex' ),
					'tools_count' => 2,
					'tools'       => array(
						__( 'create_quiz tool', 'mcp-ai-wpoos' ),
						__( 'render_math_equation tool', 'mcp-ai-wpoos' ),
					),
				),
				'eca_management' => array(
					'name'        => __( 'ECA Management', 'mcp-ai-wpoos' ),
					'description' => __( 'Extracurricular activities management with no external dependencies.', 'mcp-ai-wpoos' ),
					'enabled'     => ! empty( $settings['enable_eca_management'] ),
					'category'    => 'core',
					'php_functions' => array(),
					'npm_packages' => array(),
					'tools_count' => 3,
					'tools'       => array(
						__( 'manage_eca tool', 'mcp-ai-wpoos' ),
						__( 'track_eca_attendance tool', 'mcp-ai-wpoos' ),
						__( 'generate_eca_report tool', 'mcp-ai-wpoos' ),
					),
				),
				'code_formatting' => array(
					'name'        => __( 'Code Formatting', 'mcp-ai-wpoos' ),
					'description' => __( 'Code and email template formatting with Prettier and MJML.', 'mcp-ai-wpoos' ),
					'enabled'     => true,
					'category'    => 'infrastructure',
					'php_functions' => array( 'proc_open', 'proc_close' ),
					'npm_packages' => array( 'prettier', 'mjml' ),
					'tools_count' => 2,
					'tools'       => array(
						__( 'format_code tool', 'mcp-ai-wpoos' ),
						__( 'compile_mjml tool', 'mcp-ai-wpoos' ),
					),
				),
			);

			// Check PHP function availability for each toolkit.
			foreach ( $toolkits as $toolkit_id => &$toolkit ) {
				$toolkit['php_available'] = true;
				$toolkit['php_status'] = array();
				
				foreach ( $toolkit['php_functions'] as $func_name ) {
					$available = function_exists( $func_name );
					$toolkit['php_status'][ $func_name ] = $available;
					if ( ! $available ) {
						$toolkit['php_available'] = false;
					}
				}

				// Check NPM package availability.
				$toolkit['npm_available'] = true;
				$toolkit['npm_status'] = array();
				
				foreach ( $toolkit['npm_packages'] as $package ) {
					$installed = self::check_package_installed( $package );
					$toolkit['npm_status'][ $package ] = $installed;
					if ( ! $installed ) {
						$toolkit['npm_available'] = false;
					}
				}

				// Overall status.
				$toolkit['fully_operational'] = $toolkit['enabled'] && $toolkit['php_available'] && $toolkit['npm_available'];
				$toolkit['has_issues'] = ! $toolkit['php_available'] || ! $toolkit['npm_available'];
			}

			return $toolkits;
		}

	/**
	 * Render a toolkit card with detailed information.
	 *
	 * Displays toolkit status, requirements, dependencies, and tools list.
	 *
	 * @param string $toolkit_id Toolkit identifier.
	 * @param array  $toolkit    Toolkit details.
	 * @return void
	 */
	private static function render_toolkit_card( $toolkit_id, $toolkit ) {
		$status_class = $toolkit['fully_operational'] ? 'operational' : ( $toolkit['enabled'] ? 'partial' : 'disabled' );
		$status_text = $toolkit['fully_operational'] ? __( 'Operational', 'mcp-ai-wpoos' ) : ( $toolkit['enabled'] ? __( 'Enabled (Issues)', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) );
		$category_badge = $toolkit['category'];
		?>
		<div class="wp-mcp-ai-toolkit-card" data-toolkit="<?php echo esc_attr( $toolkit_id ); ?>">
				<div class="toolkit-header">
					<h3>
						<?php echo esc_html( $toolkit['name'] ); ?>
						<span class="toolkit-status-badge <?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( $status_text ); ?>
						</span>
						<span class="toolkit-category-badge <?php echo esc_attr( $category_badge ); ?>">
							<?php echo esc_html( ucfirst( $category_badge ) ); ?>
						</span>
					</h3>
					<p class="toolkit-description"><?php echo esc_html( $toolkit['description'] ); ?></p>
				</div>

				<?php if ( $toolkit['has_issues'] && $toolkit['enabled'] ) : ?>
					<div class="toolkit-warning">
						<span class="dashicons dashicons-warning"></span>
						<strong><?php esc_html_e( 'Warning:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'This toolkit is enabled but has missing dependencies or PHP function requirements.', 'mcp-ai-wpoos' ); ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $toolkit['php_functions'] ) ) : ?>
					<details class="toolkit-section">
						<summary>
							<?php esc_html_e( 'PHP Requirements', 'mcp-ai-wpoos' ); ?>
							<span class="section-badge <?php echo $toolkit['php_available'] ? 'ok' : 'error'; ?>">
								<?php echo $toolkit['php_available'] ? esc_html__( 'OK', 'mcp-ai-wpoos' ) : esc_html__( 'Missing', 'mcp-ai-wpoos' ); ?>
							</span>
						</summary>
						<table class="toolkit-details-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'PHP Function', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $toolkit['php_status'] as $func_name => $available ) : ?>
									<tr>
										<td><code><?php echo esc_html( $func_name ); ?></code></td>
										<td>
											<span class="status-indicator <?php echo $available ? 'available' : 'unavailable'; ?>">
												<?php echo $available ? '✓' : '✗'; ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>

				<?php if ( ! empty( $toolkit['npm_packages'] ) ) : ?>
					<details class="toolkit-section">
						<summary>
							<?php esc_html_e( 'NPM Dependencies', 'mcp-ai-wpoos' ); ?>
							<span class="section-badge <?php echo $toolkit['npm_available'] ? 'ok' : 'error'; ?>">
								<?php echo $toolkit['npm_available'] ? esc_html__( 'OK', 'mcp-ai-wpoos' ) : esc_html__( 'Missing', 'mcp-ai-wpoos' ); ?>
							</span>
						</summary>
						<table class="toolkit-details-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Package Name', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $toolkit['npm_status'] as $package => $installed ) : ?>
									<tr>
										<td><code><?php echo esc_html( $package ); ?></code></td>
										<td>
											<span class="status-indicator <?php echo $installed ? 'available' : 'unavailable'; ?>">
												<?php echo $installed ? '✓' : '✗'; ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>

				<details class="toolkit-section">
					<summary>
						<?php esc_html_e( 'Tools', 'mcp-ai-wpoos' ); ?>
						<span class="tools-count">(<?php echo absint( $toolkit['tools_count'] ); ?>)</span>
					</summary>
					<ul class="toolkit-tools-list">
						<?php foreach ( $toolkit['tools'] as $tool ) : ?>
							<li><?php echo esc_html( $tool ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			</div>
		<?php
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
				'base_version'          => ! defined( 'WP_MCP_AI_PRO_VERSION' ),
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
				'@types/pdfkit'  => false, // TypeScript types only, no runtime file.
				'fluent-ffmpeg'  => 'fluent-ffmpeg/index.js',
				'ics'            => 'ics/index.js',
				'katex'          => 'katex/dist/katex.min.js',
				'mjml'           => 'mjml/lib/index.js',
				'prettier'       => 'prettier/standalone.js',
				'sharp'          => 'sharp/lib/index.js',
			);
			if ( isset( $pro_vendor_packages[ $package ] ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// @types packages don't have runtime files.
				if ( false === $pro_vendor_packages[ $package ] ) {
					return true; // TypeScript type definitions are always available.
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
			$toolkit_details  = self::get_toolkit_details();
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

				<!-- Toolkit Details Section -->
				<div style="margin-top: 30px;">
					<h2><?php esc_html_e( 'Comprehensive Toolkit Information', 'mcp-ai-wpoos' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Detailed view of each toolkit including status, dependencies, and tools.', 'mcp-ai-wpoos' ); ?>
					</p>
					
					<div class="wp-mcp-ai-toolkit-grid">
						<?php foreach ( $toolkit_details as $toolkit_id => $toolkit ) : ?>
							<?php self::render_toolkit_card( $toolkit_id, $toolkit ); ?>
						<?php endforeach; ?>
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
						<li><?php esc_html_e( 'Comprehensive toolkit details with dependencies and requirements', 'mcp-ai-wpoos' ); ?></li>
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

				/* Toolkit Grid */
				.wp-mcp-ai-toolkit-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}

				@media (max-width: 768px) {
					.wp-mcp-ai-toolkit-grid {
						grid-template-columns: 1fr;
					}
				}

				/* Toolkit Cards */
				.wp-mcp-ai-toolkit-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					padding: 20px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
					border-radius: 4px;
				}

				.wp-mcp-ai-toolkit-card .toolkit-header h3 {
					margin: 0 0 10px 0;
					font-size: 16px;
					font-weight: 600;
					display: flex;
					align-items: center;
					gap: 8px;
					flex-wrap: wrap;
				}

				.wp-mcp-ai-toolkit-card .toolkit-description {
					margin: 0 0 15px 0;
					color: #646970;
					font-size: 14px;
				}

				/* Toolkit Status Badges */
				.toolkit-status-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-status-badge.operational {
					background: #00a32a;
					color: #fff;
				}

				.toolkit-status-badge.partial {
					background: #d63638;
					color: #fff;
				}

				.toolkit-status-badge.disabled {
					background: #646970;
					color: #fff;
				}

				/* Category Badges */
				.toolkit-category-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-category-badge.core {
					background: #2271b1;
					color: #fff;
				}

				.toolkit-category-badge.specialized {
					background: #8c7ae6;
					color: #fff;
				}

				.toolkit-category-badge.infrastructure {
					background: #50e3c2;
					color: #000;
				}

				/* Toolkit Warning */
				.toolkit-warning {
					background: #fcf0f1;
					border-left: 4px solid #d63638;
					padding: 12px;
					margin-bottom: 15px;
					display: flex;
					align-items: center;
					gap: 8px;
					font-size: 13px;
				}

				.toolkit-warning .dashicons {
					color: #d63638;
					flex-shrink: 0;
				}

				/* Toolkit Sections (Collapsible) */
				.toolkit-section {
					margin: 15px 0;
					border: 1px solid #e0e0e0;
					border-radius: 4px;
					overflow: hidden;
				}

				.toolkit-section summary {
					padding: 12px 15px;
					background: #f6f7f7;
					cursor: pointer;
					font-weight: 600;
					font-size: 13px;
					display: flex;
					justify-content: space-between;
					align-items: center;
					user-select: none;
				}

				.toolkit-section summary:hover {
					background: #e9eaeb;
				}

				.toolkit-section[open] summary {
					border-bottom: 1px solid #e0e0e0;
				}

				.toolkit-section .section-badge {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-section .section-badge.ok {
					background: #00a32a;
					color: #fff;
				}

				.toolkit-section .section-badge.error {
					background: #d63638;
					color: #fff;
				}

				.toolkit-section .tools-count {
					color: #646970;
					font-weight: 400;
				}

				/* Toolkit Details Tables */
				.toolkit-details-table {
					width: 100%;
					border-collapse: collapse;
					margin: 0;
				}

				.toolkit-details-table thead th {
					background: #f9f9f9;
					padding: 10px 15px;
					text-align: left;
					font-size: 12px;
					font-weight: 600;
					border-bottom: 1px solid #e0e0e0;
				}

				.toolkit-details-table tbody td {
					padding: 10px 15px;
					border-bottom: 1px solid #f0f0f1;
					font-size: 13px;
				}

				.toolkit-details-table tbody tr:last-child td {
					border-bottom: none;
				}

				.toolkit-details-table .status-indicator {
					font-size: 16px;
					font-weight: bold;
				}

				.toolkit-details-table .status-indicator.available {
					color: #00a32a;
				}

				.toolkit-details-table .status-indicator.unavailable {
					color: #d63638;
				}

				/* Toolkit Tools List */
				.toolkit-tools-list {
					margin: 0;
					padding: 15px 15px 15px 40px;
					list-style: disc;
				}

				.toolkit-tools-list li {
					padding: 5px 0;
					font-size: 13px;
					color: #646970;
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
