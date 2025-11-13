<?php
/**
 * Advanced Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Advanced' ) ) {
	/**
	 * Advanced settings section.
	 */
	class WP_MCP_AI_Section_Advanced extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'advanced';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Advanced Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'advanced';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Performance tuning, debugging options, and advanced configuration.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'memory_max_file_bytes'   => array(
					'type'        => 'number',
					'label'       => __( 'Max Memory File Size (bytes)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum file size for memory operations. Default: 5242880 (5 MB)', 'wp-mcp-ai' ),
					'default'     => 5242880,
					'placeholder' => '5242880',
				),
				'enable_extended_logging' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Extended Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable verbose debug logging with full request/response data', 'wp-mcp-ai' ),
					'description'    => __( 'Requires "Enable Logging" to be active in General Settings. Logs complete API request/response payloads, context data, and detailed execution traces. Warning: This can generate very large log files and may impact site performance. Only enable for short-term debugging.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_opcache_reset'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto OPcache Reset', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically reset OPcache when needed', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically clears OPcache when plugin files are updated. Helps ensure code changes take effect immediately without manually clearing cache. Recommended for development environments.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_npm_management'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'NPM Package Management', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable NPM package management tool', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI assistants to manage npm packages (list, install, update, remove). Only enable in development environments. Requires npm to be installed on the server.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		private function get_subtab_groups() {
			return array(
				'performance_monitoring' => array(
					'id'     => 'performance_monitoring',
					'label'  => __( 'Performance Monitoring', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-chart-line',
					'fields' => array(), // No form fields, custom content only.
				),
				'performance'            => array(
					'id'     => 'performance',
					'label'  => __( 'Performance', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-performance',
					'fields' => array( 'memory_max_file_bytes' ),
				),
				'debugging'              => array(
					'id'     => 'debugging',
					'label'  => __( 'Debugging & Logs', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array( 'enable_extended_logging' ),
				),
				'npm_management'         => array(
					'id'     => 'npm_management',
					'label'  => __( 'NPM Management', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-editor-code',
					'fields' => array( 'enable_npm_management' ),
				),
				'system'                 => array(
					'id'     => 'system',
					'label'  => __( 'System', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-settings',
					'fields' => array( 'enable_opcache_reset' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		private function get_active_subtab() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$subtab        = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : 'performance_monitoring';
			$subtab_groups = $this->get_subtab_groups();

			if ( ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'performance_monitoring';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}

			// Render logging table if we're on the debugging sub-tab.
			if ( 'debugging' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_logging_table();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}

			// Render performance monitoring if we're on the performance_monitoring sub-tab.
			if ( 'performance_monitoring' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_performance_monitoring();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}

			// Render NPM management UI if we're on the npm_management sub-tab.
			if ( 'npm_management' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_npm_management_ui();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Advanced settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'advanced',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>" 
							   class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
							   data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the logging table if logging is enabled.
		 */
		private function render_logging_table() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Only show the logging table if logging is enabled.
			if ( empty( $settings['enable_logging'] ) ) {
				return;
			}

			$entries = WP_MCP_AI_Logger::get_recent_error_messages();
			?>
			<div class="wp-mcp-ai-error-log-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Recent Error & Activity Log', 'wp-mcp-ai' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'wp-mcp-ai' ); ?></p>
				<?php if ( empty( $entries ) ) : ?>
					<p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'wp-mcp-ai' ); ?></p>
				<?php else : ?>
					<ul class="wp-mcp-ai-log-preview" style="list-style: none; padding: 0; margin: 15px 0;">
						<?php
						foreach ( $entries as $entry ) :
							$timestamp = '';

							if ( ! empty( $entry['timestamp'] ) ) {
								$timestamp = get_date_from_gmt(
									$entry['timestamp'],
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
								);
							}

							$type_label    = strtoupper( $entry['type'] );
							$message_label = $entry['message'];
							$context_label = '';

							if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
								$options = 0;

								if ( defined( 'JSON_PRETTY_PRINT' ) ) {
									$options |= JSON_PRETTY_PRINT;
								}

								if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
									$options |= JSON_UNESCAPED_SLASHES;
								}

								$context_json = wp_json_encode( $entry['context'], $options );

								if ( false !== $context_json ) {
									$context_label = $context_json;
								}
							}
							?>
							<li style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-left: 3px solid #dc3232; border-radius: 3px;">
								<?php if ( ! empty( $timestamp ) ) : ?>
									<span class="wp-mcp-ai-log-preview__time" style="color: #666; font-size: 0.9em;"><?php echo esc_html( $timestamp ); ?></span>
									&mdash;
								<?php endif; ?>
								<span class="wp-mcp-ai-log-preview__type" style="font-weight: bold; color: #dc3232;"><?php echo esc_html( $type_label ); ?></span>:
								<span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
								<?php if ( '' !== $context_label ) : ?>
									<details class="wp-mcp-ai-log-preview__context" style="margin-top: 10px;">
										<summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'Context details', 'wp-mcp-ai' ); ?></summary>
										<pre style="background: #fff; padding: 10px; margin-top: 10px; overflow-x: auto; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85em;"><?php echo esc_html( $context_label ); ?></pre>
									</details>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php
				$log_file_path    = WP_MCP_AI_Logger::get_log_file_path();
				$log_file_exists  = WP_MCP_AI_Logger::does_log_file_exist();
				$log_file_size    = WP_MCP_AI_Logger::get_log_file_size();
				$log_size_display = '';

				if ( null !== $log_file_size ) {
					$log_size_display = function_exists( 'size_format' )
					? size_format( $log_file_size, 2 )
					: $log_file_size . ' bytes';
				}
				?>
				<div class="wp-mcp-ai-log-meta" style="margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
					<?php if ( '' !== $log_file_path ) : ?>
						<p class="description">
							<?php
							if ( $log_file_exists ) {
								if ( '' === $log_size_display ) {
									$log_size_display = __( 'Unknown size', 'wp-mcp-ai' );
								}

								printf(
									/* translators: 1: Path to the PHP error log. 2: Human readable size. */
									esc_html__( 'PHP error log: %1$s (%2$s).', 'wp-mcp-ai' ),
									'<code>' . esc_html( $log_file_path ) . '</code>',
									esc_html( $log_size_display )
								);
							} else {
								printf(
									/* translators: %s: Path to the PHP error log. */
									esc_html__( 'PHP error log: %s (not created yet).', 'wp-mcp-ai' ),
									'<code>' . esc_html( $log_file_path ) . '</code>'
								);
							}
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Unable to determine the PHP error log location. Check your server configuration if you need to inspect or prune the log.', 'wp-mcp-ai' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate memory max file bytes.
			if ( isset( $input['memory_max_file_bytes'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['memory_max_file_bytes'],
					1024,
					104857600
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Memory File Size must be between 1 MB (1048576 bytes) and 50 MB (52428800 bytes).', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}

		/**
		 * Render the performance monitoring content.
		 * This includes both monitoring metrics and performance tests.
		 */
		private function render_performance_monitoring() {
			// Load performance reporter if available.
			if ( ! class_exists( 'WP_MCP_AI_Performance_Reporter' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
			}

			// Instantiate the performance section to use its rendering methods.
			if ( class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
				$performance_section = new WP_MCP_AI_Section_Performance();
				$performance_section->render();
			} else {
				?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Performance monitoring features are not available. The Performance section class could not be loaded.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
			}
		}

		/**
		 * Render the NPM package management UI.
		 */
		private function render_npm_management_ui() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$is_enabled = ! empty( $settings['enable_npm_management'] );

			if ( ! $is_enabled ) {
				?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'NPM package management is currently disabled. Enable it above to use this feature.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
				return;
			}

			// Check if npm is available.
			$npm_available = $this->check_npm_availability();

			?>
			<div class="wp-mcp-ai-npm-management-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'NPM Package Management', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage npm packages for the WordPress plugin development environment. The npm management tool is available to AI assistants when enabled.', 'wp-mcp-ai' ); ?>
				</p>

				<?php if ( is_wp_error( $npm_available ) ) : ?>
					<div class="notice notice-error inline">
						<p><strong><?php esc_html_e( 'NPM Not Available:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( $npm_available->get_error_message() ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-success inline">
						<p>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php
							printf(
								/* translators: %s: npm version */
								esc_html__( 'NPM is available (version: %s)', 'wp-mcp-ai' ),
								'<code>' . esc_html( $npm_available ) . '</code>'
							);
							?>
						</p>
					</div>

					<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 3px;">
						<h4><?php esc_html_e( 'Tool Information', 'wp-mcp-ai' ); ?></h4>
						<p class="description">
							<?php esc_html_e( 'The "Manage NPM Packages" tool allows AI assistants to:', 'wp-mcp-ai' ); ?>
						</p>
						<ul style="list-style: disc; padding-left: 20px; margin: 10px 0;">
							<li><?php esc_html_e( 'List all installed npm packages and their versions', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Install new packages (as regular or dev dependencies)', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Update existing packages to their latest versions', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Remove packages that are no longer needed', 'wp-mcp-ai' ); ?></li>
						</ul>
						<p class="description">
							<strong><?php esc_html_e( 'Package Directory:', 'wp-mcp-ai' ); ?></strong>
							<code><?php echo esc_html( $this->get_package_json_directory() ); ?></code>
						</p>
						<p class="description">
							<strong style="color: #d63638;"><?php esc_html_e( 'Security Warning:', 'wp-mcp-ai' ); ?></strong>
							<?php esc_html_e( 'This tool executes npm commands on your server. Only enable in development environments and ensure only trusted users have access to AI assistants with this tool enabled.', 'wp-mcp-ai' ); ?>
						</p>
					</div>

					<?php $this->render_installed_packages(); ?>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Check if npm is available on the system.
		 *
		 * @return string|WP_Error NPM version string or WP_Error.
		 */
		private function check_npm_availability() {
			if ( ! function_exists( 'proc_open' ) ) {
				return new WP_Error(
					'wp_mcp_ai_proc_disabled',
					__( 'Process execution functions (proc_open) are disabled on this server.', 'wp-mcp-ai' )
				);
			}

			$command = 'npm --version 2>&1';

			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);

			$process = proc_open( $command, $descriptors, $pipes );

			if ( ! is_resource( $process ) ) {
				return new WP_Error(
					'wp_mcp_ai_proc_failed',
					__( 'Failed to execute npm command.', 'wp-mcp-ai' )
				);
			}

			fclose( $pipes[0] );
			$output = trim( stream_get_contents( $pipes[1] ) );
			$errors = trim( stream_get_contents( $pipes[2] ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );

			$return_code = proc_close( $process );

			if ( 0 !== $return_code || empty( $output ) ) {
				return new WP_Error(
					'wp_mcp_ai_npm_not_found',
					__( 'npm is not installed or not accessible on this server.', 'wp-mcp-ai' )
				);
			}

			return $output;
		}

		/**
		 * Get the directory containing package.json.
		 *
		 * @return string Directory path.
		 */
		private function get_package_json_directory() {
			return defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_PATH : plugin_dir_path( dirname( dirname( dirname( __FILE__ ) ) ) );
		}

		/**
		 * Render the list of currently installed packages.
		 */
		private function render_installed_packages() {
			$package_dir = $this->get_package_json_directory();
			$package_json_file = $package_dir . 'package.json';

			if ( ! file_exists( $package_json_file ) ) {
				?>
				<div style="margin-top: 20px;">
					<p class="description"><?php esc_html_e( 'No package.json file found in the plugin directory.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$package_json = file_get_contents( $package_json_file );
			$package_data = json_decode( $package_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $package_data ) ) {
				?>
				<div style="margin-top: 20px;">
					<p class="description"><?php esc_html_e( 'Unable to parse package.json file.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
				return;
			}

			$dependencies = isset( $package_data['dependencies'] ) ? $package_data['dependencies'] : array();
			$dev_dependencies = isset( $package_data['devDependencies'] ) ? $package_data['devDependencies'] : array();

			?>
			<div style="margin-top: 20px;">
				<h4><?php esc_html_e( 'Currently Installed Packages', 'wp-mcp-ai' ); ?></h4>

				<?php if ( ! empty( $dependencies ) ) : ?>
					<div style="margin-top: 15px;">
						<h5><?php esc_html_e( 'Production Dependencies', 'wp-mcp-ai' ); ?></h5>
						<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
							<thead>
								<tr>
									<th scope="col" style="width: 40%;"><?php esc_html_e( 'Package Name', 'wp-mcp-ai' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Version', 'wp-mcp-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $dependencies as $name => $version ) : ?>
									<tr>
										<td><code><?php echo esc_html( $name ); ?></code></td>
										<td><?php echo esc_html( $version ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $dev_dependencies ) ) : ?>
					<div style="margin-top: 15px;">
						<h5><?php esc_html_e( 'Development Dependencies', 'wp-mcp-ai' ); ?></h5>
						<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
							<thead>
								<tr>
									<th scope="col" style="width: 40%;"><?php esc_html_e( 'Package Name', 'wp-mcp-ai' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Version', 'wp-mcp-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $dev_dependencies as $name => $version ) : ?>
									<tr>
										<td><code><?php echo esc_html( $name ); ?></code></td>
										<td><?php echo esc_html( $version ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( empty( $dependencies ) && empty( $dev_dependencies ) ) : ?>
					<p class="description"><?php esc_html_e( 'No packages are currently listed in package.json.', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
