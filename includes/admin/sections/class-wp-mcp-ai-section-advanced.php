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
				'memory_max_file_bytes'       => array(
					'type'        => 'number',
					'label'       => __( 'Max Memory File Size (bytes)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum file size for memory operations. Default: 5242880 (5 MB)', 'wp-mcp-ai' ),
					'default'     => 5242880,
					'placeholder' => '5242880',
				),
				'enable_opcache_reset'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto OPcache Reset', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically reset OPcache when needed', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically clears OPcache when plugin files are updated. Helps ensure code changes take effect immediately without manually clearing cache. Recommended for development environments.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				// Federation & Mesh Settings.
				'enable_federation_directory' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation Directory', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable federation directory service', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this site to participate in the federation directory, making it discoverable by other sites in the network. Required for federated AI operations and resource sharing.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'federation_regions'          => array(
					'type'        => 'text',
					'label'       => __( 'Federation Regions', 'wp-mcp-ai' ),
					'description' => __( 'Comma-separated list of geographic regions where this site operates (e.g., "us-east,us-west,eu-central"). Used for regional routing and data residency compliance.', 'wp-mcp-ai' ),
					'default'     => 'global',
					'placeholder' => 'global, us-east, eu-central',
				),
				'federation_data_tags'        => array(
					'type'        => 'text',
					'label'       => __( 'Federation Data Tags', 'wp-mcp-ai' ),
					'description' => __( 'Comma-separated data classification tags (e.g., "public,internal,confidential"). Used for data governance and access control in federated operations.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'public, internal',
				),
				'federation_qps'              => array(
					'type'        => 'number',
					'label'       => __( 'Federation QPS Limit', 'wp-mcp-ai' ),
					'description' => __( 'Maximum queries per second (QPS) allowed for federation API requests. Prevents resource exhaustion from federated queries. Default: 5 QPS.', 'wp-mcp-ai' ),
					'default'     => 5,
					'min'         => 1,
					'max'         => 1000,
					'placeholder' => '5',
				),
				'federation_burst'            => array(
					'type'        => 'number',
					'label'       => __( 'Federation Burst Capacity', 'wp-mcp-ai' ),
					'description' => __( 'Burst capacity for federation rate limiting. Allows temporary spikes above QPS limit. Default: 10.', 'wp-mcp-ai' ),
					'default'     => 10,
					'min'         => 1,
					'max'         => 10000,
					'placeholder' => '10',
				),
				'federation_jwks_keys'        => array(
					'type'        => 'textarea',
					'label'       => __( 'Federation JWKS Keys', 'wp-mcp-ai' ),
					'description' => __( 'JSON Web Key Set (JWKS) for federation authentication. Advanced setting - only modify if implementing custom federation authentication. Must be valid JSON array of JWK objects.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '[{"kty":"RSA","use":"sig","kid":"...","n":"...","e":"AQAB"}]',
					'rows'        => 6,
				),
				'federation_price_hints'      => array(
					'type'        => 'textarea',
					'label'       => __( 'Federation Price Hints', 'wp-mcp-ai' ),
					'description' => __( 'JSON object with pricing information for federation services. Used for cost attribution in federated AI operations. Advanced setting. Format: {"model": "gpt-4", "cost_per_1k_tokens": 0.03}', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '{"gpt-4": {"input": 0.03, "output": 0.06}}',
					'rows'        => 5,
				),
				'mesh_inbound_api_key'        => array(
					'type'        => 'text',
					'label'       => __( 'Mesh Inbound API Key', 'wp-mcp-ai' ),
					'description' => __( 'Auto-generated API key for receiving mesh network requests. This key is used by peer sites to authenticate inbound connections. Copy this key to configure peer sites. Key is auto-generated when mesh networking is enabled.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'mesh_xxxxxxxx...',
					'readonly'    => true,
					'class'       => 'regular-text code',
				),
				'mesh_peer_sites'             => array(
					'type'        => 'textarea',
					'label'       => __( 'Mesh Peer Sites Configuration', 'wp-mcp-ai' ),
					'description' => __( 'JSON array of mesh network peer configurations. Each peer should have: url (peer site URL), api_key (their inbound key), name (friendly name), and enabled (boolean). Example: [{"url":"https://peer1.com","api_key":"mesh_xxx","name":"Peer 1","enabled":true}]', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '[{"url":"https://peer.example.com","api_key":"mesh_xxx","name":"Peer Site","enabled":true}]',
					'rows'        => 8,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
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
				'data_management'        => array(
					'id'     => 'data_management',
					'label'  => __( 'Data Management', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-database',
					'fields' => array(), // No form fields, custom content only.
				),
				'federation_mesh'        => array(
					'id'     => 'federation_mesh',
					'label'  => __( 'Federation & Mesh', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-networking',
					'fields' => array(
						'enable_federation_directory',
						'federation_regions',
						'federation_data_tags',
						'federation_qps',
						'federation_burst',
						'federation_jwks_keys',
						'federation_price_hints',
						'mesh_inbound_api_key',
						'mesh_peer_sites',
					),
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
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'performance_monitoring' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
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

			// Render performance monitoring if we're on the performance_monitoring sub-tab.
			if ( 'performance_monitoring' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_performance_monitoring();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}

			// Render data management if we're on the data_management sub-tab.
			if ( 'data_management' === $active_subtab ) {
				echo '</table>'; // Close the form table.
				$this->render_data_management();
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
								class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

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

			// Performance section moved to Pro addon.
			// Check if Pro addon is active before attempting to instantiate.
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) && class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
				$performance_section = new WP_MCP_AI_Section_Performance();
				$performance_section->render();
			} else {
				?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Performance monitoring features require WP oOS Pro addon.', 'wp-mcp-ai' ); ?></p>
				</div>
				<?php
			}
		}

		/**
		 * Render the data management content.
		 */
		private function render_data_management() {
			// Get profession counts.
			$profession_count = wp_count_posts( 'mcp_ai_profession' );
			$total_count      = isset( $profession_count->publish ) ? $profession_count->publish : 0;
			$draft_count      = isset( $profession_count->draft ) ? $profession_count->draft : 0;

			// Check if professions were seeded.
			$is_seeded    = get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false );
			$seeded_text  = $is_seeded ? __( 'Yes', 'wp-mcp-ai' ) : __( 'No', 'wp-mcp-ai' );
			$seeded_class = $is_seeded ? 'success' : 'warning';
			?>
			<div class="wp-mcp-ai-data-management-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Profession Data Management', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage the profession templates used when creating new AI assistants. You can reload the latest profession definitions from the plugin\'s knowledge base.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-profession-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'wp-mcp-ai' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><strong><?php esc_html_e( 'Published Professions:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $total_count ); ?></li>
						<?php if ( $draft_count > 0 ) : ?>
							<li><strong><?php esc_html_e( 'Draft Professions:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $draft_count ); ?></li>
						<?php endif; ?>
						<li><strong><?php esc_html_e( 'Initially Seeded:', 'wp-mcp-ai' ); ?></strong> 
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $seeded_class ); ?>">
								<?php echo esc_html( $seeded_text ); ?>
							</span>
						</li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>">
							<?php esc_html_e( 'View all professions', 'wp-mcp-ai' ); ?> &rarr;
						</a>
					</p>
				</div>

				<div class="wp-mcp-ai-reseed-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Reload Profession Data', 'wp-mcp-ai' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Choose how to reload profession data from the plugin\'s knowledge base:', 'wp-mcp-ai' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-update-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Update Professions', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Updates existing professions and adds new ones. Your custom professions will be preserved.', 'wp-mcp-ai' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-replace-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Replace All Professions', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Deletes all existing professions and recreates them from the knowledge base. Use with caution!', 'wp-mcp-ai' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-reseed-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<div class="wp-mcp-ai-playbook-sync-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
					<h4><?php esc_html_e( 'Sync Profession Playbooks', 'wp-mcp-ai' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Regenerate playbook attachments from the modular text files. This is useful after editing playbook content in includes/knowledge-base/profession-playbooks/', 'wp-mcp-ai' ); ?>
					</p>

					<?php
					// Get playbook statistics.
					$playbook_stats = $this->get_playbook_statistics();
					?>

					<div class="wp-mcp-ai-playbook-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'Playbook Status', 'wp-mcp-ai' ); ?></h4>
						<ul style="margin: 10px 0; padding-left: 20px;">
							<li><strong><?php esc_html_e( 'Total Playbook Attachments:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $playbook_stats['total_attachments'] ); ?></li>
							<li><strong><?php esc_html_e( 'Professions with Playbooks:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $playbook_stats['professions_with_playbooks'] ); ?> / <?php echo absint( $total_count ); ?></li>
							<li><strong><?php esc_html_e( 'Playbooks Seeded:', 'wp-mcp-ai' ); ?></strong> 
								<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $playbook_stats['seeded'] ? 'success' : 'warning' ); ?>">
									<?php echo esc_html( $playbook_stats['seeded'] ? __( 'Yes', 'wp-mcp-ai' ) : __( 'No', 'wp-mcp-ai' ) ); ?>
								</span>
							</li>
							<?php if ( $playbook_stats['last_sync'] ) : ?>
								<li><strong><?php esc_html_e( 'Last Sync:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( $playbook_stats['last_sync'] ); ?></li>
							<?php endif; ?>
						</ul>
					</div>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-sync-playbooks-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Sync Changed Playbooks', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Only regenerates playbooks where content has changed (fast, safe).', 'wp-mcp-ai' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-sync-playbooks-force-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Force Regenerate All Playbooks', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Regenerates all playbooks even if unchanged (slower, use after major updates).', 'wp-mcp-ai' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-playbook-sync-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<style>
					.wp-mcp-ai-status-badge {
						display: inline-block;
						padding: 2px 8px;
						border-radius: 3px;
						font-size: 12px;
						font-weight: bold;
					}
					.wp-mcp-ai-status-success {
						background: #d4edda;
						color: #155724;
					}
					.wp-mcp-ai-status-warning {
						background: #fff3cd;
						color: #856404;
					}
					.wp-mcp-ai-reseed-actions button .dashicons {
						vertical-align: middle;
					}
					.wp-mcp-ai-reseed-actions button.disabled {
						opacity: 0.6;
						cursor: not-allowed;
					}
				</style>

				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performReseed(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-reseed-message');
						var originalText = $button.html();

						// Disable both buttons
						$('#wp-mcp-ai-reseed-update-btn, #wp-mcp-ai-reseed-replace-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'wp-mcp-ai' ) ); ?>');

						// Hide any previous messages
						$message.hide().removeClass('notice-success notice-error notice-warning');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_reseed_professions',
								action_type: actionType,
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_reseed_professions' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload stats after a short delay
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'wp-mcp-ai' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'wp-mcp-ai' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text
								$('#wp-mcp-ai-reseed-update-btn, #wp-mcp-ai-reseed-replace-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-reseed-update-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update existing professions and add new ones from the knowledge base. Continue?', 'wp-mcp-ai' ) ); ?>)) {
							performReseed('update', '#wp-mcp-ai-reseed-update-btn');
						}
					});

					$('#wp-mcp-ai-reseed-replace-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'WARNING: This will DELETE all existing professions and replace them with fresh data from the knowledge base. This cannot be undone! Continue?', 'wp-mcp-ai' ) ); ?>)) {
							performReseed('replace', '#wp-mcp-ai-reseed-replace-btn');
						}
					});
				});
				</script>

				<style>
					@keyframes spin {
						from { transform: rotate(0deg); }
						to { transform: rotate(360deg); }
					}
					.dashicons.spin {
						animation: spin 1s linear infinite;
					}
				</style>
			</div>

			<!-- TEAM DATA MANAGEMENT SECTION -->
			<?php
			// Get team counts for the section.
			$team_count       = wp_count_posts( 'mcp_ai_team' );
			$team_total_count = isset( $team_count->publish ) ? $team_count->publish : 0;
			$team_draft_count = isset( $team_count->draft ) ? $team_count->draft : 0;

			// Check if teams were seeded.
			if ( ! class_exists( 'WP_MCP_AI_Team_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-seeder.php';
			}
			$team_is_seeded    = get_option( WP_MCP_AI_Team_Seeder::SEEDED_OPTION, false );
			$team_seeded_text  = $team_is_seeded ? __( 'Yes', 'wp-mcp-ai' ) : __( 'No', 'wp-mcp-ai' );
			$team_seeded_class = $team_is_seeded ? 'success' : 'warning';
			?>
			<div class="wp-mcp-ai-team-data-management-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Team Data Management', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage the team templates that group multiple professionals together. You can reload the latest team definitions from the plugin\'s knowledge base.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-team-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'wp-mcp-ai' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><strong><?php esc_html_e( 'Published Teams:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $team_total_count ); ?></li>
						<?php if ( $team_draft_count > 0 ) : ?>
							<li><strong><?php esc_html_e( 'Draft Teams:', 'wp-mcp-ai' ); ?></strong> <?php echo absint( $team_draft_count ); ?></li>
						<?php endif; ?>
						<li><strong><?php esc_html_e( 'Initially Seeded:', 'wp-mcp-ai' ); ?></strong> 
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $team_seeded_class ); ?>">
								<?php echo esc_html( $team_seeded_text ); ?>
							</span>
						</li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>">
							<?php esc_html_e( 'View all teams', 'wp-mcp-ai' ); ?> &rarr;
						</a>
					</p>
				</div>

				<div class="wp-mcp-ai-reseed-team-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Reload Team Data', 'wp-mcp-ai' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Choose how to reload team data from the plugin\'s knowledge base:', 'wp-mcp-ai' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-teams-update-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Update Teams', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Updates existing teams and adds new ones. Your custom teams will be preserved.', 'wp-mcp-ai' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-teams-replace-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Replace All Teams', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Deletes all existing teams and recreates them from the knowledge base. Use with caution!', 'wp-mcp-ai' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-reseed-teams-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performTeamReseed(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-reseed-teams-message');
						var originalText = $button.html();

						// Disable both buttons
						$('#wp-mcp-ai-reseed-teams-update-btn, #wp-mcp-ai-reseed-teams-replace-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'wp-mcp-ai' ) ); ?>');

						// Hide any previous messages
						$message.hide().removeClass('notice-success notice-error notice-warning');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_reseed_teams',
								action_type: actionType,
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_reseed_teams' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload stats after a short delay
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'wp-mcp-ai' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'wp-mcp-ai' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text
								$('#wp-mcp-ai-reseed-teams-update-btn, #wp-mcp-ai-reseed-teams-replace-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-reseed-teams-update-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update existing teams and add new ones from the knowledge base. Continue?', 'wp-mcp-ai' ) ); ?>)) {
							performTeamReseed('update', '#wp-mcp-ai-reseed-teams-update-btn');
						}
					});

					$('#wp-mcp-ai-reseed-teams-replace-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'WARNING: This will DELETE all existing teams and replace them with fresh data from the knowledge base. This cannot be undone! Continue?', 'wp-mcp-ai' ) ); ?>)) {
							performTeamReseed('replace', '#wp-mcp-ai-reseed-teams-replace-btn');
						}
					});
				});
				</script>
			</div>

			<!-- GEMINI COST TRACKING MIGRATION SECTION -->
			<div class="wp-mcp-ai-gemini-migration-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Gemini Cost Tracking Migration', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Fix historical cost tracking data where Gemini-specific tools were incorrectly attributed to OpenAI provider. This migration will identify tools that explicitly use Gemini API (tools with "gemini" in their name) and update their provider attribution and recalculate costs using correct Gemini pricing.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-migration-info" style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 3px solid #0073aa; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'What This Does', 'wp-mcp-ai' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><?php esc_html_e( 'Identifies token tracking records for Gemini-specific tools that were incorrectly attributed to OpenAI', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Updates provider from OpenAI (or other providers) to Gemini', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Recalculates costs using correct Gemini pricing (which is typically lower than OpenAI)', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Updates the "is_estimated" flag to mark the data as actual, not estimated', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<strong><?php esc_html_e( 'Affected Tools:', 'wp-mcp-ai' ); ?></strong>
						generate_gemini_image, edit_gemini_image
					</p>
					<p class="description" style="margin: 10px 0 0 0;">
						<?php esc_html_e( 'Note: Tools that can use either OpenAI or Gemini based on your settings (like analyze_comment_content) are not migrated, as they may legitimately use OpenAI.', 'wp-mcp-ai' ); ?>
					</p>
				</div>

				<div class="wp-mcp-ai-migration-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Run Migration', 'wp-mcp-ai' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Preview the changes first to see what records would be updated, then run the migration to apply the fixes.', 'wp-mcp-ai' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-migrate-gemini-preview-btn">
								<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Preview Changes', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'See what records would be updated without making any changes.', 'wp-mcp-ai' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-primary" id="wp-mcp-ai-migrate-gemini-run-btn">
								<span class="dashicons dashicons-database-import" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Run Migration', 'wp-mcp-ai' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Apply the fixes to your cost tracking data.', 'wp-mcp-ai' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-migrate-gemini-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performGeminiMigration(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-migrate-gemini-message');
						var originalText = $button.html();

						// Disable both buttons
						$('#wp-mcp-ai-migrate-gemini-preview-btn, #wp-mcp-ai-migrate-gemini-run-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'wp-mcp-ai' ) ); ?>');

						// Hide any previous messages
						$message.hide().removeClass('notice-success notice-error notice-warning notice-info');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_migrate_gemini_costs',
								action_type: actionType,
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									var noticeClass = 'notice-success';
									if (response.data.dry_run && response.data.records_updated > 0) {
										noticeClass = 'notice-info';
									} else if (response.data.records_updated === 0) {
										noticeClass = 'notice-warning';
									}

									$message
										.removeClass('notice-error notice-success notice-warning notice-info')
										.addClass(noticeClass)
										.find('p').html(response.data.message);
									$message.show();

									// If actual migration was successful, offer to reload
									if (!response.data.dry_run && response.data.records_updated > 0) {
										setTimeout(function() {
											if (confirm(<?php echo wp_json_encode( __( 'Migration completed successfully! Would you like to reload the page to see updated statistics?', 'wp-mcp-ai' ) ); ?>)) {
												location.reload();
											}
										}, 1500);
									}
								} else {
									$message
										.removeClass('notice-success notice-warning notice-info')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'wp-mcp-ai' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning notice-info')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'wp-mcp-ai' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text
								$('#wp-mcp-ai-migrate-gemini-preview-btn, #wp-mcp-ai-migrate-gemini-run-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-migrate-gemini-preview-btn').on('click', function(e) {
						e.preventDefault();
						performGeminiMigration('preview', '#wp-mcp-ai-migrate-gemini-preview-btn');
					});

					$('#wp-mcp-ai-migrate-gemini-run-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update historical cost tracking data to fix Gemini provider attribution. This cannot be undone, but you can preview the changes first. Continue?', 'wp-mcp-ai' ) ); ?>)) {
							performGeminiMigration('migrate', '#wp-mcp-ai-migrate-gemini-run-btn');
						}
					});
				});

				// Sync playbooks handlers
				jQuery(document).ready(function($) {
					function syncPlaybooks(force) {
						var $button = force ? $('#wp-mcp-ai-sync-playbooks-force-btn') : $('#wp-mcp-ai-sync-playbooks-btn');
						var $message = $('#wp-mcp-ai-playbook-sync-message');
						var originalText = $button.html();

						// Disable both buttons
						$('#wp-mcp-ai-sync-playbooks-btn, #wp-mcp-ai-sync-playbooks-force-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'wp-mcp-ai' ) ); ?>');

						// Hide any previous messages
						$message.hide().removeClass('notice-success notice-error notice-warning');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_sync_all_playbooks',
								force: force ? 'true' : 'false',
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_sync_all_playbooks' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload after a short delay
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'wp-mcp-ai' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'wp-mcp-ai' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text
								$('#wp-mcp-ai-sync-playbooks-btn, #wp-mcp-ai-sync-playbooks-force-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-sync-playbooks-btn').on('click', function(e) {
						e.preventDefault();
						syncPlaybooks(false);
					});

					$('#wp-mcp-ai-sync-playbooks-force-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will force regenerate all profession playbooks even if unchanged. This may take a moment. Continue?', 'wp-mcp-ai' ) ); ?>)) {
							syncPlaybooks(true);
						}
					});
				});
				</script>
			</div>
			<?php
		}

		/**
		 * Get playbook statistics for display.
		 *
		 * @return array Array with playbook statistics.
		 */
		private function get_playbook_statistics() {
			global $wpdb;

			// Load required class if not loaded.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
			}

			// Get total number of playbook attachments.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_attachments = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = %s
					AND p.post_status = %s
					AND pm.meta_key = %s",
					'attachment',
					'inherit',
					'_wp_mcp_ai_playbook_profession_id'
				)
			);

			// Get number of unique professions with playbooks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$professions_with_playbooks = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.meta_value)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = %s
					AND p.post_status = %s
					AND pm.meta_key = %s
					AND pm.meta_value != ''",
					'attachment',
					'inherit',
					'_wp_mcp_ai_playbook_profession_id'
				)
			);

			// Check if playbooks were seeded.
			$playbooks_seeded = get_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION, false );

			// Get last sync timestamp if available.
			$last_sync_timestamp = get_option( 'wp_mcp_ai_playbooks_last_sync', 0 );
			$last_sync           = '';
			if ( $last_sync_timestamp ) {
				$last_sync = human_time_diff( $last_sync_timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wp-mcp-ai' );
			}

			return array(
				'total_attachments'          => absint( $total_attachments ),
				'professions_with_playbooks' => absint( $professions_with_playbooks ),
				'seeded'                     => $playbooks_seeded,
				'last_sync'                  => $last_sync,
			);
		}
	}
}
