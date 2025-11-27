<?php
/**
 * Site Creator Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Site_Creator' ) ) {
	/**
	 * Site Creator settings section.
	 */
	class WP_MCP_AI_Section_Site_Creator extends WP_MCP_AI_Settings_Section {

		/**
		 * Transient key for import results.
		 *
		 * @var string
		 */
		const IMPORT_RESULT_TRANSIENT = 'wp_mcp_ai_elementor_kit_import_result';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_init', array( $this, 'handle_elementor_kit_import' ) );
		}

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'site_creator';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Site Creator', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 35;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Enable AI-powered automated site creation from plans. When enabled, AI assistants can programmatically create complete WordPress sites by installing themes, plugins, configuring options, and creating content.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_site_creator'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Site Creator', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow AI to create and configure sites', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content. This feature requires manage_options capability.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_plugin_install' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Plugin Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic plugin installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_theme_install'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Theme Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic theme installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_option_updates' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Option Updates', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic WordPress option updates', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Check if Elementor is active.
		 *
		 * @return bool
		 */
		protected function is_elementor_active() {
			return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false );
		}

		/**
		 * Handle Elementor template kit import form submission.
		 */
		public function handle_elementor_kit_import() {
			// Check if this is our form submission.
			if ( ! isset( $_POST['wp_mcp_ai_elementor_kit_import'] ) ) {
				return;
			}

			// Verify nonce first before any other processing.
			if ( ! isset( $_POST['wp_mcp_ai_elementor_kit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_elementor_kit_nonce'] ) ), 'wp_mcp_ai_elementor_kit_import' ) ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Security check failed. Please try again.', 'wp-mcp-ai' ),
					),
					60
				);
				return;
			}

			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'You do not have permission to import template kits.', 'wp-mcp-ai' ),
					),
					60
				);
				return;
			}

			// Check if Elementor is active.
			if ( ! $this->is_elementor_active() ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Elementor must be active to import template kits.', 'wp-mcp-ai' ),
					),
					60
				);
				return;
			}

			// Get and sanitize form values.
			$attachment_id      = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
			$max_pages          = isset( $_POST['max_pages'] ) ? min( 5, max( 1, absint( $_POST['max_pages'] ) ) ) : 5;
			$page_status_raw    = isset( $_POST['page_status'] ) ? sanitize_text_field( wp_unslash( $_POST['page_status'] ) ) : 'draft';
			$page_status        = in_array( $page_status_raw, array( 'draft', 'publish' ), true ) ? $page_status_raw : 'draft';
			$set_front_page     = ! empty( $_POST['set_front_page'] );
			$overwrite_existing = ! empty( $_POST['overwrite_existing'] );
			$action_type        = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
			$dry_run            = 'test' === $action_type;

			if ( ! $attachment_id ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Please select a template kit ZIP file.', 'wp-mcp-ai' ),
					),
					60
				);
				return;
			}

			// Load the tool class if not already loaded.
			$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}

			if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Elementor_Template_Kit' ) ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Import tool not available.', 'wp-mcp-ai' ),
					),
					60
				);
				return;
			}

			// Execute the import.
			$tool   = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();
			$result = $tool->execute(
				array(
					'attachment_id'      => $attachment_id,
					'max_pages'          => $max_pages,
					'page_status'        => $page_status,
					'set_front_page'     => $set_front_page,
					'overwrite_existing' => $overwrite_existing,
					'dry_run'            => $dry_run,
				),
				array(
					'user_id' => get_current_user_id(),
				)
			);

			if ( is_wp_error( $result ) ) {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					),
					60
				);
			} else {
				set_transient(
					self::IMPORT_RESULT_TRANSIENT,
					array(
						'success' => true,
						'data'    => $result,
					),
					60
				);
			}

			// Redirect to avoid form resubmission.
			wp_safe_redirect( add_query_arg( 'elementor_kit_imported', '1', wp_get_referer() ) );
			exit;
		}

		/**
		 * Render the section.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}

			// Add informational note about capabilities and security.
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creator tools require administrative capabilities (manage_options, install_plugins, install_themes). Only users with these capabilities can execute site creator operations. All plugins and themes are installed exclusively from the official WordPress.org repository.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Performance Consideration:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creation operations (especially plugin/theme installation) can take several minutes to complete and may temporarily impact site performance. These operations are marked as long-running and should be executed with appropriate timeouts.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php

			// Render Elementor Template Kit Import section.
			$this->render_elementor_kit_import_section();
		}

		/**
		 * Render Elementor Template Kit Import section.
		 */
		protected function render_elementor_kit_import_section() {
			$is_elementor_active = $this->is_elementor_active();

			// Check for import results.
			$import_result = get_transient( self::IMPORT_RESULT_TRANSIENT );
			if ( $import_result ) {
				delete_transient( self::IMPORT_RESULT_TRANSIENT );
			}
			?>
			<tr>
				<th scope="row" colspan="2">
					<h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ccd0d4;">
						<span class="dashicons dashicons-layout" style="margin-right: 5px;"></span>
						<?php esc_html_e( 'Import Elementor Template Kit', 'wp-mcp-ai' ); ?>
					</h3>
				</th>
			</tr>

			<?php if ( ! $is_elementor_active ) : ?>
				<tr>
					<th scope="row"></th>
					<td>
						<div class="notice notice-warning inline" style="margin: 0;">
							<p>
								<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
								<?php esc_html_e( 'Elementor must be installed and activated to use this feature.', 'wp-mcp-ai' ); ?>
							</p>
						</div>
					</td>
				</tr>
			<?php else : ?>

				<?php if ( $import_result ) : ?>
					<tr>
						<th scope="row"></th>
						<td>
							<?php $this->render_import_result( $import_result ); ?>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"></th>
					<td>
						<p class="description" style="margin-bottom: 15px;">
							<?php esc_html_e( 'Import an Elementor template kit ZIP file from your Media Library to quickly create pages with pre-designed layouts.', 'wp-mcp-ai' ); ?>
						</p>

						<form method="post" id="wp-mcp-ai-elementor-kit-form">
							<?php wp_nonce_field( 'wp_mcp_ai_elementor_kit_import', 'wp_mcp_ai_elementor_kit_nonce' ); ?>
							<input type="hidden" name="wp_mcp_ai_elementor_kit_import" value="1">

							<table class="form-table" role="presentation" style="margin: 0;">
								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-kit-attachment">
											<?php esc_html_e( 'Template Kit ZIP', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<div style="display: flex; align-items: center; gap: 10px;">
											<input type="hidden" name="attachment_id" id="wp-mcp-ai-kit-attachment-id" value="">
											<input type="text" id="wp-mcp-ai-kit-attachment" class="regular-text" readonly placeholder="<?php esc_attr_e( 'No file selected', 'wp-mcp-ai' ); ?>">
											<button type="button" class="button" id="wp-mcp-ai-select-kit">
												<?php esc_html_e( 'Select File', 'wp-mcp-ai' ); ?>
											</button>
										</div>
										<p class="description">
											<?php esc_html_e( 'Select a ZIP file containing an Elementor template kit from your Media Library.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-max-pages">
											<?php esc_html_e( 'Max Pages', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<select name="max_pages" id="wp-mcp-ai-max-pages">
											<option value="1">1</option>
											<option value="2">2</option>
											<option value="3">3</option>
											<option value="4">4</option>
											<option value="5" selected>5</option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Maximum number of pages to create from the template kit.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-page-status">
											<?php esc_html_e( 'Page Status', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<select name="page_status" id="wp-mcp-ai-page-status">
											<option value="draft" selected><?php esc_html_e( 'Draft', 'wp-mcp-ai' ); ?></option>
											<option value="publish"><?php esc_html_e( 'Published', 'wp-mcp-ai' ); ?></option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Status for created pages.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row"><?php esc_html_e( 'Options', 'wp-mcp-ai' ); ?></th>
									<td>
										<label style="display: block; margin-bottom: 8px;">
											<input type="checkbox" name="overwrite_existing" value="1">
											<?php esc_html_e( 'Overwrite existing pages with the same title', 'wp-mcp-ai' ); ?>
										</label>
										<label style="display: block;">
											<input type="checkbox" name="set_front_page" value="1">
											<?php esc_html_e( 'Set Home page as static front page', 'wp-mcp-ai' ); ?>
										</label>
									</td>
								</tr>

								<tr>
									<th scope="row"></th>
									<td>
										<div style="display: flex; gap: 10px; margin-top: 10px;">
											<button type="submit" name="action_type" value="test" class="button button-secondary">
												<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
												<?php esc_html_e( 'Test Import', 'wp-mcp-ai' ); ?>
											</button>
											<button type="submit" name="action_type" value="import" class="button button-primary">
												<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
												<?php esc_html_e( 'Run Import', 'wp-mcp-ai' ); ?>
											</button>
										</div>
										<p class="description" style="margin-top: 10px;">
											<?php esc_html_e( 'Test Import simulates the operation without creating pages. Run Import creates the actual pages.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</form>
					</td>
				</tr>
			<?php endif; ?>

			<?php
			// Add JavaScript for media library selection.
			$this->render_elementor_kit_import_script();
		}

		/**
		 * Render import result notice.
		 *
		 * @param array $result Import result data.
		 */
		protected function render_import_result( $result ) {
			if ( ! $result['success'] ) {
				?>
				<div class="notice notice-error inline" style="margin: 0 0 15px;">
					<p>
						<strong><?php esc_html_e( 'Import Failed:', 'wp-mcp-ai' ); ?></strong>
						<?php echo esc_html( isset( $result['message'] ) ? $result['message'] : __( 'Unknown error occurred.', 'wp-mcp-ai' ) ); ?>
					</p>
				</div>
				<?php
				return;
			}

			$data       = isset( $result['data'] ) ? $result['data'] : array();
			$is_dry_run = ! empty( $data['dry_run'] );
			?>
			<div class="notice notice-success inline" style="margin: 0 0 15px;">
				<p>
					<strong>
						<?php
						if ( $is_dry_run ) {
							esc_html_e( 'Test Import Complete:', 'wp-mcp-ai' );
						} else {
							esc_html_e( 'Import Complete:', 'wp-mcp-ai' );
						}
						?>
					</strong>
					<?php echo esc_html( isset( $data['summary'] ) ? $data['summary'] : '' ); ?>
				</p>

				<?php if ( ! empty( $data['pages_created'] ) || ! empty( $data['pages_updated'] ) ) : ?>
					<div style="margin-top: 10px;">
						<?php if ( ! empty( $data['pages_created'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Pages Created:', 'wp-mcp-ai' ); ?></strong></p>
							<ul style="margin-left: 20px; list-style: disc;">
								<?php foreach ( $data['pages_created'] as $page ) : ?>
									<li>
										<?php echo esc_html( $page['title'] ); ?>
										<?php if ( ! $is_dry_run && ! empty( $page['edit_link'] ) ) : ?>
											- <a href="<?php echo esc_url( $page['edit_link'] ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'wp-mcp-ai' ); ?></a>
											<?php if ( ! empty( $page['permalink'] ) ) : ?>
												| <a href="<?php echo esc_url( $page['permalink'] ); ?>" target="_blank"><?php esc_html_e( 'View', 'wp-mcp-ai' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $data['pages_updated'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Pages Updated:', 'wp-mcp-ai' ); ?></strong></p>
							<ul style="margin-left: 20px; list-style: disc;">
								<?php foreach ( $data['pages_updated'] as $page ) : ?>
									<li>
										<?php echo esc_html( $page['title'] ); ?>
										<?php if ( ! $is_dry_run && ! empty( $page['edit_link'] ) ) : ?>
											- <a href="<?php echo esc_url( $page['edit_link'] ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'wp-mcp-ai' ); ?></a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['pages_skipped'] ) ) : ?>
					<div style="margin-top: 10px;">
						<p><strong><?php esc_html_e( 'Pages Skipped:', 'wp-mcp-ai' ); ?></strong></p>
						<ul style="margin-left: 20px; list-style: disc;">
							<?php foreach ( $data['pages_skipped'] as $page ) : ?>
								<li>
									<?php echo esc_html( $page['title'] ); ?>
									<?php if ( ! empty( $page['reason'] ) ) : ?>
										- <em><?php echo esc_html( $page['reason'] ); ?></em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['errors'] ) ) : ?>
					<div style="margin-top: 10px;">
						<p><strong style="color: #d63638;"><?php esc_html_e( 'Errors:', 'wp-mcp-ai' ); ?></strong></p>
						<ul style="margin-left: 20px; list-style: disc; color: #d63638;">
							<?php foreach ( $data['errors'] as $error ) : ?>
								<li>
									<?php
									if ( is_array( $error ) ) {
										echo esc_html( $error['template'] . ': ' . $error['message'] );
									} else {
										echo esc_html( $error );
									}
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['front_page'] ) ) : ?>
					<p style="margin-top: 10px;">
						<span class="dashicons dashicons-admin-home" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Front page has been set.', 'wp-mcp-ai' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render JavaScript for media library selection.
		 */
		protected function render_elementor_kit_import_script() {
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				var mediaFrame;

				$('#wp-mcp-ai-select-kit').on('click', function(e) {
					e.preventDefault();

					if (mediaFrame) {
						mediaFrame.open();
						return;
					}

					mediaFrame = wp.media({
						title: '<?php echo esc_js( __( 'Select Template Kit ZIP', 'wp-mcp-ai' ) ); ?>',
						button: {
							text: '<?php echo esc_js( __( 'Use This File', 'wp-mcp-ai' ) ); ?>'
						},
						library: {
							type: 'application/zip'
						},
						multiple: false
					});

					mediaFrame.on('select', function() {
						var attachment = mediaFrame.state().get('selection').first().toJSON();
						$('#wp-mcp-ai-kit-attachment-id').val(attachment.id);
						$('#wp-mcp-ai-kit-attachment').val(attachment.filename || attachment.title);
					});

					mediaFrame.open();
				});
			});
			</script>
			<?php
		}
	}
}
