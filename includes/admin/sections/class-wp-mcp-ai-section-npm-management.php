<?php
/**
 * NPM Management Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_NPM_Management' ) ) {
	/**
	 * NPM package management settings section.
	 */
	class WP_MCP_AI_Section_NPM_Management extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'npm_management';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'NPM Package Management', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'npm_management';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Manage Node.js packages in your WordPress installation using npm commands. The AI assistant can also use these tools programmatically.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// This section is display-only for npm management UI.
			// No persistent settings are stored.
			return array();
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$this->render_npm_status();
			$this->render_package_json_info();
			$this->render_npm_actions();
		}

		/**
		 * Render npm binary status.
		 */
		private function render_npm_status() {
			// Check if npm is available.
			$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
			$npm_install_tool = $tool_registry->get_tool( 'npm_install_package' );
			
			if ( ! $npm_install_tool ) {
				?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'NPM tools are not registered.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
				return;
			}

			// Try to get npm binary info.
			$reflection = new ReflectionClass( $npm_install_tool );
			$get_npm_binary_method = $reflection->getMethod( 'get_npm_binary' );
			$get_npm_binary_method->setAccessible( true );
			
			$get_npm_version_method = $reflection->getMethod( 'get_npm_version' );
			$get_npm_version_method->setAccessible( true );
			
			$can_execute_method = $reflection->getMethod( 'can_execute_processes' );
			$can_execute_method->setAccessible( true );

			$npm_binary = $get_npm_binary_method->invoke( $npm_install_tool );
			$can_execute = $can_execute_method->invoke( $npm_install_tool );
			
			?>
			<div class="wp-mcp-ai-npm-status" style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-left: 4px solid #2271b1;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'NPM Environment Status', 'wp-mcp-ai' ); ?></h3>
				
				<?php if ( is_wp_error( $npm_binary ) ) : ?>
					<div class="notice notice-error inline" style="margin: 0;">
						<p>
							<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
							<strong><?php esc_html_e( 'npm Not Found', 'wp-mcp-ai' ); ?></strong>
						</p>
						<p><?php echo esc_html( $npm_binary->get_error_message() ); ?></p>
						<p><?php esc_html_e( 'Please install Node.js and npm on your server to use these features.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php elseif ( ! $can_execute ) : ?>
					<div class="notice notice-error inline" style="margin: 0;">
						<p>
							<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
							<strong><?php esc_html_e( 'Process Execution Disabled', 'wp-mcp-ai' ); ?></strong>
						</p>
						<p><?php esc_html_e( 'The proc_open() function is disabled on your server. NPM commands cannot be executed.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					$npm_version = $get_npm_version_method->invoke( $npm_install_tool );
					?>
					<div class="notice notice-success inline" style="margin: 0;">
						<p>
							<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
							<strong><?php esc_html_e( 'npm Available', 'wp-mcp-ai' ); ?></strong>
						</p>
						<p>
							<?php
							printf(
								/* translators: 1: npm binary path, 2: npm version */
								esc_html__( 'Binary: %1$s | Version: %2$s', 'wp-mcp-ai' ),
								'<code>' . esc_html( $npm_binary ) . '</code>',
								$npm_version ? '<code>' . esc_html( $npm_version ) . '</code>' : esc_html__( 'Unknown', 'wp-mcp-ai' )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render package.json information.
		 */
		private function render_package_json_info() {
			$wp_root = untrailingslashit( ABSPATH );
			$package_json_path = $wp_root . '/package.json';
			
			?>
			<div class="wp-mcp-ai-package-json-info" style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-left: 4px solid #2271b1;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'package.json Status', 'wp-mcp-ai' ); ?></h3>
				
				<?php if ( file_exists( $package_json_path ) ) : ?>
					<?php
					$package_json_content = file_get_contents( $package_json_path );
					$package_data = json_decode( $package_json_content, true );
					
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $package_data ) ) {
						$dependencies = isset( $package_data['dependencies'] ) ? $package_data['dependencies'] : array();
						$dev_dependencies = isset( $package_data['devDependencies'] ) ? $package_data['devDependencies'] : array();
						?>
						<div class="notice notice-success inline" style="margin: 0;">
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
								<strong><?php esc_html_e( 'package.json Found', 'wp-mcp-ai' ); ?></strong>
							</p>
							<p>
								<?php
								printf(
									/* translators: %s: package.json file path */
									esc_html__( 'Location: %s', 'wp-mcp-ai' ),
									'<code>' . esc_html( $package_json_path ) . '</code>'
								);
								?>
							</p>
							<p>
								<?php
								printf(
									/* translators: 1: number of dependencies, 2: number of dev dependencies */
									esc_html__( 'Dependencies: %1$d | Dev Dependencies: %2$d', 'wp-mcp-ai' ),
									count( $dependencies ),
									count( $dev_dependencies )
								);
								?>
							</p>
						</div>
					<?php } else { ?>
						<div class="notice notice-warning inline" style="margin: 0;">
							<p>
								<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
								<strong><?php esc_html_e( 'Invalid package.json', 'wp-mcp-ai' ); ?></strong>
							</p>
							<p><?php esc_html_e( 'The package.json file exists but contains invalid JSON.', 'wp-mcp-ai' ); ?></p>
						</div>
					<?php } ?>
				<?php else : ?>
					<div class="notice notice-warning inline" style="margin: 0;">
						<p>
							<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
							<strong><?php esc_html_e( 'No package.json Found', 'wp-mcp-ai' ); ?></strong>
						</p>
						<p>
							<?php
							printf(
								/* translators: %s: expected package.json path */
								esc_html__( 'Expected location: %s', 'wp-mcp-ai' ),
								'<code>' . esc_html( $package_json_path ) . '</code>'
							);
							?>
						</p>
						<p><?php esc_html_e( 'You need to initialize npm first by running "npm init" in your WordPress root directory.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render npm action buttons and interface.
		 */
		private function render_npm_actions() {
			?>
			<div class="wp-mcp-ai-npm-actions" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'Quick Actions', 'wp-mcp-ai' ); ?></h3>
				
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Use these quick actions to manage npm packages, or ask the AI assistant to manage packages programmatically using the npm_install_package, npm_update_package, and npm_remove_package tools.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-npm-action-buttons" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
					<button type="button" class="button button-secondary wp-mcp-ai-npm-install-btn">
						<span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Install Package', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button button-secondary wp-mcp-ai-npm-update-btn">
						<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Update Packages', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="button button-secondary wp-mcp-ai-npm-remove-btn">
						<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Remove Package', 'wp-mcp-ai' ); ?>
					</button>
				</div>

				<div id="wp-mcp-ai-npm-action-area" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
					<!-- Dynamic content area for npm actions -->
				</div>

				<div id="wp-mcp-ai-npm-output" style="display: none; margin-top: 20px; padding: 15px; background: #000; color: #0f0; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; border-radius: 3px;">
					<!-- npm command output -->
				</div>
			</div>

			<div class="wp-mcp-ai-npm-documentation" style="margin-bottom: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0969da;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'Using NPM Tools with AI Assistants', 'wp-mcp-ai' ); ?></h3>
				
				<p><?php esc_html_e( 'The AI assistant has access to three npm management tools:', 'wp-mcp-ai' ); ?></p>
				
				<ul style="list-style-type: disc; padding-left: 20px;">
					<li>
						<strong>npm_install_package</strong> - <?php esc_html_e( 'Install npm packages with optional version specifications and dev dependency support.', 'wp-mcp-ai' ); ?>
						<br><code>Example: "Install lodash version 4.17.21 and react"</code>
					</li>
					<li>
						<strong>npm_update_package</strong> - <?php esc_html_e( 'Update packages to their latest compatible versions according to package.json semver ranges.', 'wp-mcp-ai' ); ?>
						<br><code>Example: "Update all npm packages"</code>
					</li>
					<li>
						<strong>npm_remove_package</strong> - <?php esc_html_e( 'Remove/uninstall packages and update package.json accordingly.', 'wp-mcp-ai' ); ?>
						<br><code>Example: "Remove the lodash package"</code>
					</li>
				</ul>

				<p>
					<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
					<?php esc_html_e( 'These tools require manage_options capability (administrator access) and include security measures like directory traversal prevention and input validation.', 'wp-mcp-ai' ); ?>
				</p>
			</div>
			<?php

			// Enqueue inline JavaScript for npm actions.
			$this->enqueue_npm_action_scripts();
		}

		/**
		 * Enqueue npm action scripts.
		 */
		private function enqueue_npm_action_scripts() {
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				const actionArea = $('#wp-mcp-ai-npm-action-area');
				const outputArea = $('#wp-mcp-ai-npm-output');

				// Install Package button
				$('.wp-mcp-ai-npm-install-btn').on('click', function() {
					actionArea.html(`
						<h4><?php esc_html_e( 'Install NPM Package', 'wp-mcp-ai' ); ?></h4>
						<p><?php esc_html_e( 'Enter package names (one per line). You can include version specifications like "lodash@4.17.21" or "react@^18.0.0".', 'wp-mcp-ai' ); ?></p>
						<textarea id="npm-install-packages" style="width: 100%; min-height: 100px; font-family: monospace;" placeholder="lodash&#10;react@18.0.0&#10;@babel/core"></textarea>
						<p>
							<label>
								<input type="checkbox" id="npm-install-save-dev" />
								<?php esc_html_e( 'Install as dev dependencies (--save-dev)', 'wp-mcp-ai' ); ?>
							</label>
						</p>
						<button type="button" class="button button-primary wp-mcp-ai-npm-execute" data-action="install">
							<?php esc_html_e( 'Install Packages', 'wp-mcp-ai' ); ?>
						</button>
						<button type="button" class="button button-secondary wp-mcp-ai-npm-cancel">
							<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
						</button>
					`).show();
				});

				// Update Package button
				$('.wp-mcp-ai-npm-update-btn').on('click', function() {
					actionArea.html(`
						<h4><?php esc_html_e( 'Update NPM Packages', 'wp-mcp-ai' ); ?></h4>
						<p><?php esc_html_e( 'Enter specific package names to update (one per line), or leave empty to update all packages.', 'wp-mcp-ai' ); ?></p>
						<textarea id="npm-update-packages" style="width: 100%; min-height: 100px; font-family: monospace;" placeholder="lodash&#10;react&#10;<?php esc_attr_e( '(or leave empty for all)', 'wp-mcp-ai' ); ?>"></textarea>
						<button type="button" class="button button-primary wp-mcp-ai-npm-execute" data-action="update">
							<?php esc_html_e( 'Update Packages', 'wp-mcp-ai' ); ?>
						</button>
						<button type="button" class="button button-secondary wp-mcp-ai-npm-cancel">
							<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
						</button>
					`).show();
				});

				// Remove Package button
				$('.wp-mcp-ai-npm-remove-btn').on('click', function() {
					actionArea.html(`
						<h4><?php esc_html_e( 'Remove NPM Package', 'wp-mcp-ai' ); ?></h4>
						<p><?php esc_html_e( 'Enter package names to remove (one per line).', 'wp-mcp-ai' ); ?></p>
						<textarea id="npm-remove-packages" style="width: 100%; min-height: 100px; font-family: monospace;" placeholder="lodash&#10;@babel/core"></textarea>
						<button type="button" class="button button-primary wp-mcp-ai-npm-execute" data-action="remove">
							<?php esc_html_e( 'Remove Packages', 'wp-mcp-ai' ); ?>
						</button>
						<button type="button" class="button button-secondary wp-mcp-ai-npm-cancel">
							<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
						</button>
					`).show();
				});

				// Cancel button
				$(document).on('click', '.wp-mcp-ai-npm-cancel', function() {
					actionArea.hide();
					outputArea.hide();
				});

				// Execute button
				$(document).on('click', '.wp-mcp-ai-npm-execute', function() {
					const action = $(this).data('action');
					let packages = [];
					let saveDev = false;

					if (action === 'install') {
						const packagesText = $('#npm-install-packages').val().trim();
						packages = packagesText.split('\n').map(p => p.trim()).filter(p => p.length > 0);
						saveDev = $('#npm-install-save-dev').is(':checked');
					} else if (action === 'update') {
						const packagesText = $('#npm-update-packages').val().trim();
						packages = packagesText.split('\n').map(p => p.trim()).filter(p => p.length > 0);
					} else if (action === 'remove') {
						const packagesText = $('#npm-remove-packages').val().trim();
						packages = packagesText.split('\n').map(p => p.trim()).filter(p => p.length > 0);
					}

					if (action !== 'update' && packages.length === 0) {
						alert('<?php esc_html_e( 'Please enter at least one package name.', 'wp-mcp-ai' ); ?>');
						return;
					}

					executeNpmCommand(action, packages, saveDev);
				});

				function executeNpmCommand(action, packages, saveDev) {
					outputArea.html('<?php esc_html_e( 'Executing npm command...', 'wp-mcp-ai' ); ?>').show();

					const toolName = action === 'install' ? 'npm_install_package' : 
					                action === 'update' ? 'npm_update_package' : 
					                'npm_remove_package';

					const toolArgs = {
						packages: packages
					};

					if (action === 'install') {
						toolArgs.save_dev = saveDev;
					}

					// Call the npm tool via REST API
					$.ajax({
						url: '<?php echo esc_url( rest_url( 'mcp-ai/v1/tools/execute' ) ); ?>',
						method: 'POST',
						beforeSend: function(xhr) {
							xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
						},
						contentType: 'application/json',
						data: JSON.stringify({
							tool: toolName,
							arguments: toolArgs
						}),
						success: function(response) {
							displayNpmOutput(response);
						},
						error: function(xhr, status, error) {
							outputArea.html('Error: ' + (xhr.responseJSON?.message || error));
						}
					});
				}

				function displayNpmOutput(response) {
					let output = '';

					if (response.success) {
						output += '✓ Success\n\n';
					} else {
						output += '✗ Failed\n\n';
					}

					if (response.output) {
						output += response.output + '\n\n';
					}

					if (response.packages_installed) {
						output += 'Installed: ' + response.packages_installed.join(', ') + '\n';
					}

					if (response.packages_updated) {
						output += 'Updated: ' + (Array.isArray(response.packages_updated) ? response.packages_updated.join(', ') : response.packages_updated) + '\n';
					}

					if (response.packages_removed) {
						output += 'Removed: ' + response.packages_removed.join(', ') + '\n';
					}

					if (response.npm_version) {
						output += '\nnpm version: ' + response.npm_version;
					}

					outputArea.html(output.replace(/\n/g, '<br>'));
				}
			});
			</script>
			<?php
		}
	}
}
