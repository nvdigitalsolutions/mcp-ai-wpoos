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
			return __( 'Advanced Settings', 'mcp-ai-wpoos' );
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
			return __( 'Performance tuning, debugging options, and advanced configuration.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/admin/settings/new-settings-december-2025.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'profession_default_tool_count' => array(
					'type'        => 'number',
					'label'       => __( 'Recommended Default Tools per Profession', 'mcp-ai-wpoos' ),
					'description' => __( 'Recommended number of default tools to assign per profession. This is a guideline for profession configuration - actual tool count can vary based on profession needs. Default: 10', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'min'         => 3,
					'max'         => 20,
					'placeholder' => '10',
				),
				'memory_max_file_bytes'         => array(
					'type'        => 'number',
					'label'       => __( 'Max Memory File Size (bytes)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum file size for memory operations. Default: 5242880 (5 MB)', 'mcp-ai-wpoos' ),
					'default'     => 5242880,
					'placeholder' => '5242880',
				),
				'enable_opcache_reset'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto OPcache Reset', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically reset OPcache when needed', 'mcp-ai-wpoos' ),
					'description'    => __( 'Automatically clears OPcache when plugin files are updated. Helps ensure code changes take effect immediately without manually clearing cache. Recommended for development environments.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				// Federation & Mesh Settings.
				'enable_federation'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable federated discovery', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables federation features including well-known endpoints (/.well-known/ai-peer, /.well-known/jwks.json) for AI peer discovery.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'enable_federation_directory'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation Directory', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable federation directory service', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows this site to participate in the federation directory, making it discoverable by other sites in the network. Required for federated AI operations and resource sharing.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'federation_regions'            => array(
					'type'        => 'text',
					'label'       => __( 'Federation Regions', 'mcp-ai-wpoos' ),
					'description' => __( 'Comma-separated list of geographic regions where this site operates (e.g., "us-east,us-west,eu-central"). Used for regional routing and data residency compliance.', 'mcp-ai-wpoos' ),
					'default'     => 'global',
					'placeholder' => 'global, us-east, eu-central',
				),
				'federation_data_tags'          => array(
					'type'        => 'text',
					'label'       => __( 'Federation Data Tags', 'mcp-ai-wpoos' ),
					'description' => __( 'Comma-separated data classification tags (e.g., "public,internal,confidential"). Used for data governance and access control in federated operations.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => 'public, internal',
				),
				'federation_qps'                => array(
					'type'        => 'number',
					'label'       => __( 'Federation QPS Limit', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum queries per second (QPS) allowed for federation API requests. Prevents resource exhaustion from federated queries. Default: 5 QPS.', 'mcp-ai-wpoos' ),
					'default'     => 5,
					'min'         => 1,
					'max'         => 1000,
					'placeholder' => '5',
				),
				'federation_burst'              => array(
					'type'        => 'number',
					'label'       => __( 'Federation Burst Capacity', 'mcp-ai-wpoos' ),
					'description' => __( 'Burst capacity for federation rate limiting. Allows temporary spikes above QPS limit. Default: 10.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'min'         => 1,
					'max'         => 10000,
					'placeholder' => '10',
				),
				'federation_jwks_keys'          => array(
					'type'        => 'textarea',
					'label'       => __( 'Federation JWKS Keys', 'mcp-ai-wpoos' ),
					'description' => __( 'JSON Web Key Set (JWKS) for federation authentication. Advanced setting - only modify if implementing custom federation authentication. Must be valid JSON array of JWK objects.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => '[{"kty":"RSA","use":"sig","kid":"...","n":"...","e":"AQAB"}]',
					'rows'        => 6,
				),
				'federation_price_hints'        => array(
					'type'        => 'textarea',
					'label'       => __( 'Federation Price Hints', 'mcp-ai-wpoos' ),
					'description' => __( 'JSON object with pricing information for federation services. Used for cost attribution in federated AI operations. Advanced setting. Format: {"model": "gpt-4", "cost_per_1k_tokens": 0.03}', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => '{"gpt-4": {"input": 0.03, "output": 0.06}}',
					'rows'        => 5,
				),
				'mesh_inbound_api_key'          => array(
					'type'        => 'text',
					'label'       => __( 'Mesh Inbound API Key', 'mcp-ai-wpoos' ),
					'description' => __( 'Auto-generated API key for receiving mesh network requests. This key is used by peer sites to authenticate inbound connections. Copy this key to configure peer sites. Key is auto-generated when mesh networking is enabled.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => 'mesh_xxxxxxxx...',
					'readonly'    => true,
					'class'       => 'regular-text code',
				),
				'mesh_peer_sites'               => array(
					'type'        => 'textarea',
					'label'       => __( 'Mesh Peer Sites Configuration', 'mcp-ai-wpoos' ),
					'description' => __( 'JSON array of mesh network peer configurations. Each peer should have: url (peer site URL), api_key (their inbound key), name (friendly name), and enabled (boolean). Example: [{"url":"https://peer1.com","api_key":"mesh_xxx","name":"Peer 1","enabled":true}]', 'mcp-ai-wpoos' ),
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
					'label'  => __( 'Performance Monitoring', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-chart-line',
					'fields' => array(), // No form fields, custom content only.
				),
				'performance'            => array(
					'id'     => 'performance',
					'label'  => __( 'Performance', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-performance',
					'fields' => array( 'memory_max_file_bytes' ),
				),
				'data_management'        => array(
					'id'     => 'data_management',
					'label'  => __( 'Data Management', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-database',
					'fields' => array(), // No form fields, custom content only.
				),
				'federation_mesh'        => array(
					'id'     => 'federation_mesh',
					'label'  => __( 'Federation & Mesh', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-networking',
					'fields' => array(
						'enable_federation',
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
					'label'  => __( 'System', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-settings',
					'fields' => array( 'enable_opcache_reset' ),
				),
				'settings_management'    => array(
					'id'     => 'settings_management',
					'label'  => __( 'Settings Management', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-database-export',
					'fields' => array(), // No form fields, custom content only.
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
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

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
				echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Close the form table.
				$this->render_performance_monitoring();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Re-open hidden table for structure.
			}

			// Render data management if we're on the data_management sub-tab.
			if ( 'data_management' === $active_subtab ) {
				echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Close the form table.
				$this->render_data_management();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Re-open hidden table for structure.
			}

			// Render federation & mesh if we're on the federation_mesh sub-tab.
			if ( 'federation_mesh' === $active_subtab ) {
				echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Close the form table.
				$this->render_federation_mesh();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Re-open hidden table for structure.
			}

			// Render settings management if we're on the settings_management sub-tab.
			if ( 'settings_management' === $active_subtab ) {
				echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Close the form table.
				$this->render_settings_management();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Re-open hidden table for structure.
			}
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Advanced settings sub-tabs', 'mcp-ai-wpoos' ); ?>">
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
					<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />

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
				<h3><?php esc_html_e( 'Recent Error & Activity Log', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'mcp-ai-wpoos' ); ?></p>
				<?php if ( empty( $entries ) ) : ?>
					<p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'mcp-ai-wpoos' ); ?></p>
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
										<summary style="cursor: pointer; color: #0073aa;"><?php esc_html_e( 'Context details', 'mcp-ai-wpoos' ); ?></summary>
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
									$log_size_display = __( 'Unknown size', 'mcp-ai-wpoos' );
								}

								printf(
									/* translators: 1: Path to the PHP error log. 2: Human readable size. */
									esc_html__( 'PHP error log: %1$s (%2$s).', 'mcp-ai-wpoos' ),
									'<code>' . esc_html( $log_file_path ) . '</code>',
									esc_html( $log_size_display )
								);
							} else {
								printf(
									/* translators: %s: Path to the PHP error log. */
									esc_html__( 'PHP error log: %s (not created yet).', 'mcp-ai-wpoos' ),
									'<code>' . esc_html( $log_file_path ) . '</code>'
								);
							}
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Unable to determine the PHP error log location. Check your server configuration if you need to inspect or prune the log.', 'mcp-ai-wpoos' ); ?></p>
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
					$errors[] = __( 'Max Memory File Size must be between 1 MB (1048576 bytes) and 50 MB (52428800 bytes).', 'mcp-ai-wpoos' );
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
					<p><?php esc_html_e( 'Performance monitoring features require NV oOS Pro addon.', 'mcp-ai-wpoos' ); ?></p>
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
			$seeded_text  = $is_seeded ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' );
			$seeded_class = $is_seeded ? 'success' : 'warning';
			?>
			<div class="wp-mcp-ai-data-management-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Profession Data Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage the profession templates used when creating new AI assistants. You can reload the latest profession definitions from the plugin\'s knowledge base.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-profession-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><strong><?php esc_html_e( 'Published Professions:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $total_count ); ?></li>
						<?php if ( $draft_count > 0 ) : ?>
							<li><strong><?php esc_html_e( 'Draft Professions:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $draft_count ); ?></li>
						<?php endif; ?>
						<li><strong><?php esc_html_e( 'Initially Seeded:', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $seeded_class ); ?>">
								<?php echo esc_html( $seeded_text ); ?>
							</span>
						</li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>">
							<?php esc_html_e( 'View all professions', 'mcp-ai-wpoos' ); ?> &rarr;
						</a>
					</p>
				</div>

				<div class="wp-mcp-ai-reseed-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Reload Profession Data', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Choose how to reload profession data from the plugin\'s knowledge base:', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-update-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Update Professions', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Updates existing professions and adds new ones. Your custom professions will be preserved.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-replace-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Replace All Professions', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Deletes all existing professions and recreates them from the knowledge base. Use with caution!', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-reseed-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<div class="wp-mcp-ai-playbook-sync-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
					<h4><?php esc_html_e( 'Sync Profession Playbooks', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Regenerate playbook attachments from the modular text files. This is useful after editing playbook content in includes/knowledge-base/profession-playbooks/', 'mcp-ai-wpoos' ); ?>
					</p>

					<?php
					// Get playbook statistics.
					$playbook_stats = $this->get_playbook_statistics();
					?>

					<div class="wp-mcp-ai-playbook-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'Playbook Status', 'mcp-ai-wpoos' ); ?></h4>
						<ul style="margin: 10px 0; padding-left: 20px;">
							<li><strong><?php esc_html_e( 'Total Playbook Attachments:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $playbook_stats['total_attachments'] ); ?></li>
							<li><strong><?php esc_html_e( 'Professions with Playbooks:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $playbook_stats['professions_with_playbooks'] ); ?> / <?php echo absint( $total_count ); ?></li>
							<li><strong><?php esc_html_e( 'Playbooks Seeded:', 'mcp-ai-wpoos' ); ?></strong>
								<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $playbook_stats['seeded'] ? 'success' : 'warning' ); ?>">
									<?php echo esc_html( $playbook_stats['seeded'] ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' ) ); ?>
								</span>
							</li>
							<?php if ( $playbook_stats['last_sync'] ) : ?>
								<li><strong><?php esc_html_e( 'Last Sync:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $playbook_stats['last_sync'] ); ?></li>
							<?php endif; ?>
						</ul>
					</div>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-sync-playbooks-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Sync Changed Playbooks', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Regenerates playbooks where content has changed and removes duplicates (fast, safe).', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-sync-playbooks-force-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Force Regenerate All Playbooks', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Regenerates all playbooks even if unchanged and removes duplicates (slower, use after major updates).', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-delete-old-playbooks-btn" style="color: #a00;">
								<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Delete Old Playbooks from Media Library', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Permanently deletes orphaned playbook attachments that are no longer associated with any profession.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-playbook-sync-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
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

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
				?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performReseed(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-reseed-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-reseed-update-btn, #wp-mcp-ai-reseed-replace-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
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

									// Reload stats after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
								$('#wp-mcp-ai-reseed-update-btn, #wp-mcp-ai-reseed-replace-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-reseed-update-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update existing professions and add new ones from the knowledge base. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performReseed('update', '#wp-mcp-ai-reseed-update-btn');
						}
					});

					$('#wp-mcp-ai-reseed-replace-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'WARNING: This will DELETE all existing professions and replace them with fresh data from the knowledge base. This cannot be undone! Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performReseed('replace', '#wp-mcp-ai-reseed-replace-btn');
						}
					});
				});
				</script>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
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

			<!-- AGENT ORCHESTRATION SEEDING SECTION -->
			<?php
			// Get orchestration seeding status.
			$orchestration_version = get_option( 'wp_mcp_ai_profession_orchestration_version', false );
			$orchestration_seeded  = $orchestration_version ? __( 'Yes', 'mcp-ai-wpoos' ) . ' (v' . esc_html( $orchestration_version ) . ')' : __( 'No', 'mcp-ai-wpoos' );
			$orchestration_class   = $orchestration_version ? 'success' : 'warning';

			// Get role distribution.
			$role_counts = array();
			$roles       = array( 'planner', 'executor', 'critic', 'specialist', 'generalist' );
			foreach ( $roles as $role ) {
				$query                = new WP_Query(
					array(
						'post_type'   => 'mcp_ai_profession',
						'post_status' => 'publish',
						'meta_query'  => array(
							array(
								'key'     => '_wp_mcp_ai_profession_agent_role',
								'value'   => $role,
								'compare' => '=',
							),
						),
						'fields'      => 'ids',
					)
				);
				$role_counts[ $role ] = $query->post_count;
				wp_reset_postdata();
			}
			$total_with_roles = array_sum( $role_counts );
			?>
			<div class="wp-mcp-ai-orchestration-seeding-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Agent Orchestration Configuration', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Configure multi-agent orchestration settings for professions. This assigns agent roles (planner, executor, critic, specialist, generalist) and task patterns to enable coordinated multi-agent workflows.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-orchestration-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><strong><?php esc_html_e( 'Orchestration Seeded:', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $orchestration_class ); ?>">
								<?php echo esc_html( $orchestration_seeded ); ?>
							</span>
						</li>
						<li><strong><?php esc_html_e( 'Professions with Agent Roles:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $total_with_roles ); ?> / <?php echo absint( $total_count ); ?></li>
						<?php if ( $total_with_roles > 0 ) : ?>
							<li style="margin-left: 20px;">
								<strong><?php esc_html_e( 'Role Distribution:', 'mcp-ai-wpoos' ); ?></strong>
								<ul style="margin: 5px 0 0 20px;">
									<?php foreach ( $role_counts as $role => $count ) : ?>
										<?php if ( $count > 0 ) : ?>
											<li><?php echo esc_html( ucfirst( $role ) ); ?>: <?php echo absint( $count ); ?></li>
										<?php endif; ?>
									<?php endforeach; ?>
								</ul>
							</li>
						<?php endif; ?>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<?php
						printf(
							/* translators: %s: Link to professions page */
							esc_html__( 'Edit individual profession orchestration settings in the %s metabox.', 'mcp-ai-wpoos' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'Agent Orchestration', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
				</div>

				<div class="wp-mcp-ai-orchestration-seed-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Seed Orchestration Settings', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Automatically assign agent roles to all professions based on their category and expertise. Task patterns will be created for common professions.', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-seed-orchestration-btn">
								<span class="dashicons dashicons-networking" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Seed Agent Orchestration', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Assigns agent roles to all professions using category-based and keyword-based heuristics. Safe to run multiple times (idempotent).', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-seed-orchestration-force-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Force Re-seed Orchestration', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Re-assigns all agent roles and task patterns, overwriting any existing orchestration settings.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-orchestration-seed-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
				?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performOrchestrationSeed(force, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-orchestration-seed-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-seed-orchestration-btn, #wp-mcp-ai-seed-orchestration-force-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
						$message.hide().removeClass('notice-success notice-error notice-warning');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_seed_orchestration',
								force: force,
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_seed_orchestration' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload stats after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
								$('#wp-mcp-ai-seed-orchestration-btn, #wp-mcp-ai-seed-orchestration-force-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-seed-orchestration-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will assign agent roles to all professions based on their category and expertise. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performOrchestrationSeed(false, '#wp-mcp-ai-seed-orchestration-btn');
						}
					});

					$('#wp-mcp-ai-seed-orchestration-force-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will overwrite all existing orchestration settings. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performOrchestrationSeed(true, '#wp-mcp-ai-seed-orchestration-force-btn');
						}
					});
				});
				</script>
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
			$team_seeded_text  = $team_is_seeded ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' );
			$team_seeded_class = $team_is_seeded ? 'success' : 'warning';
			?>
			<div class="wp-mcp-ai-team-data-management-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Team Data Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage the team templates that group multiple professionals together. You can reload the latest team definitions from the plugin\'s knowledge base.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-team-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><strong><?php esc_html_e( 'Published Teams:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $team_total_count ); ?></li>
						<?php if ( $team_draft_count > 0 ) : ?>
							<li><strong><?php esc_html_e( 'Draft Teams:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $team_draft_count ); ?></li>
						<?php endif; ?>
						<li><strong><?php esc_html_e( 'Initially Seeded:', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $team_seeded_class ); ?>">
								<?php echo esc_html( $team_seeded_text ); ?>
							</span>
						</li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>">
							<?php esc_html_e( 'View all teams', 'mcp-ai-wpoos' ); ?> &rarr;
						</a>
					</p>
				</div>

				<div class="wp-mcp-ai-reseed-team-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Reload Team Data', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Choose how to reload team data from the plugin\'s knowledge base:', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-teams-update-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Update Teams', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Updates existing teams and adds new ones. Your custom teams will be preserved.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-reseed-teams-replace-btn">
								<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Replace All Teams', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Deletes all existing teams and recreates them from the knowledge base. Use with caution!', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-reseed-teams-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
				?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performTeamReseed(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-reseed-teams-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-reseed-teams-update-btn, #wp-mcp-ai-reseed-teams-replace-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
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

									// Reload stats after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
								$('#wp-mcp-ai-reseed-teams-update-btn, #wp-mcp-ai-reseed-teams-replace-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-reseed-teams-update-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update existing teams and add new ones from the knowledge base. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performTeamReseed('update', '#wp-mcp-ai-reseed-teams-update-btn');
						}
					});

					$('#wp-mcp-ai-reseed-teams-replace-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'WARNING: This will DELETE all existing teams and replace them with fresh data from the knowledge base. This cannot be undone! Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performTeamReseed('replace', '#wp-mcp-ai-reseed-teams-replace-btn');
						}
					});
				});
				</script>
			</div>

			<!-- TASK TEMPLATE LIBRARY SECTION -->
			<div class="wp-mcp-ai-task-template-management-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Task Template Library Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage the task template library used for autonomous task execution with the Ralph orchestration system. Templates provide pre-built workflows for common automation scenarios like research, content creation, and data analysis.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php
				// Get task template counts (Pro only if mcp_task_template exists).
				$has_templates          = post_type_exists( 'mcp_task_template' );
				$has_task_plans         = post_type_exists( 'mcp_task_plan' );
				$template_query         = null;
				$template_total_count   = 0;
				$template_publish_count = 0;
				$template_draft_count   = 0;

				if ( $has_templates ) {
					$template_query       = new WP_Query(
						array(
							'post_type'      => 'mcp_task_template',
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					$template_total_count = $template_query->found_posts;

					foreach ( $template_query->posts as $template_id ) {
						$status = get_post_status( $template_id );
						if ( 'publish' === $status ) {
							++$template_publish_count;
						} elseif ( 'draft' === $status ) {
							++$template_draft_count;
						}
					}
				}

				// Get task plan counts (available in base).
				$plan_query       = null;
				$plan_total_count = 0;
				if ( $has_task_plans ) {
					$plan_query       = new WP_Query(
						array(
							'post_type'      => 'mcp_task_plan',
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					$plan_total_count = $plan_query->found_posts;
				}

				// Check if templates were seeded.
				$template_is_seeded    = get_option( 'wp_mcp_ai_task_templates_seeded', false );
				$template_seeded_text  = $template_is_seeded ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' );
				$template_seeded_class = $template_is_seeded ? 'success' : 'warning';
				?>

				<div class="wp-mcp-ai-template-stats" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<?php if ( $has_task_plans ) : ?>
							<li><strong><?php esc_html_e( 'Total Task Plans:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $plan_total_count ); ?></li>
						<?php endif; ?>
						<?php if ( $has_templates ) : ?>
							<li><strong><?php esc_html_e( 'Total Templates:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $template_total_count ); ?> 
								<span class="description">(Pro)</span>
							</li>
							<li><strong><?php esc_html_e( 'Published Templates:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $template_publish_count ); ?></li>
							<?php if ( $template_draft_count > 0 ) : ?>
								<li><strong><?php esc_html_e( 'Draft Templates:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $template_draft_count ); ?></li>
							<?php endif; ?>
							<li><strong><?php esc_html_e( 'Library Seeded:', 'mcp-ai-wpoos' ); ?></strong>
								<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $template_seeded_class ); ?>">
									<?php echo esc_html( $template_seeded_text ); ?>
								</span>
							</li>
						<?php endif; ?>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<?php if ( $has_task_plans ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_task_plan' ) ); ?>">
								<?php esc_html_e( 'View all task plans', 'mcp-ai-wpoos' ); ?> &rarr;
							</a>
						<?php endif; ?>
						<?php if ( $has_templates && $has_task_plans ) : ?>
							&nbsp;|&nbsp;
						<?php endif; ?>
						<?php if ( $has_templates ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_task_template' ) ); ?>">
								<?php esc_html_e( 'View all task templates', 'mcp-ai-wpoos' ); ?> &rarr;
							</a>
						<?php endif; ?>
					</p>
				</div>

				<?php if ( $has_templates && defined( 'WP_MCP_AI_PRO_VERSION' ) ) : ?>
				<div class="wp-mcp-ai-seed-template-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Seed Template Library (Pro)', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Load pre-built professional templates for common workflows. Categories include research, content creation, data analysis, marketing, and more.', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-seed-templates-btn">
								<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Seed Template Library', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Adds pre-built templates without overwriting existing ones.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-seed-templates-overwrite-btn">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Reseed with Overwrite', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Updates existing templates with latest versions from the library.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-seed-templates-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

					<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
					?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performTemplateSeed(overwrite, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-seed-templates-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-seed-templates-btn, #wp-mcp-ai-seed-templates-overwrite-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Seeding...', 'mcp-ai-wpoos' ) ); ?>');

						// Make AJAX request.
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_seed_task_templates',
								overwrite: overwrite,
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_seed_task_templates' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload stats after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
								$('#wp-mcp-ai-seed-templates-btn, #wp-mcp-ai-seed-templates-overwrite-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}

					$('#wp-mcp-ai-seed-templates-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will seed the task template library with pre-built professional templates. Existing templates will not be modified. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performTemplateSeed(false, '#wp-mcp-ai-seed-templates-btn');
						}
					});

					$('#wp-mcp-ai-seed-templates-overwrite-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'This will update existing templates with the latest versions from the library. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performTemplateSeed(true, '#wp-mcp-ai-seed-templates-overwrite-btn');
						}
					});
				});
				</script>
				<?php endif; ?>
			</div>

			<!-- GEMINI COST TRACKING MIGRATION SECTION -->
			<div class="wp-mcp-ai-gemini-migration-section" style="margin-top: 50px;">
				<h3><?php esc_html_e( 'Gemini Cost Tracking Migration', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Fix historical cost tracking data where Gemini-specific tools were incorrectly attributed to OpenAI provider. This migration will identify tools that explicitly use Gemini API (tools with "gemini" in their name) and update their provider attribution and recalculate costs using correct Gemini pricing.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-migration-info" style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 3px solid #0073aa; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'What This Does', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li><?php esc_html_e( 'Identifies token tracking records for Gemini-specific tools that were incorrectly attributed to OpenAI', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Updates provider from OpenAI (or other providers) to Gemini', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Recalculates costs using correct Gemini pricing (which is typically lower than OpenAI)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Updates the "is_estimated" flag to mark the data as actual, not estimated', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p class="description" style="margin: 10px 0 0 0;">
						<strong><?php esc_html_e( 'Affected Tools:', 'mcp-ai-wpoos' ); ?></strong>
						generate_gemini_image, edit_gemini_image
					</p>
					<p class="description" style="margin: 10px 0 0 0;">
						<?php esc_html_e( 'Note: Tools that can use either OpenAI or Gemini based on your settings (like analyze_comment_content) are not migrated, as they may legitimately use OpenAI.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<div class="wp-mcp-ai-migration-actions" style="margin-top: 20px;">
					<h4><?php esc_html_e( 'Run Migration', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Preview the changes first to see what records would be updated, then run the migration to apply the fixes.', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<p>
							<button type="button" class="button button-secondary" id="wp-mcp-ai-migrate-gemini-preview-btn">
								<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Preview Changes', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'See what records would be updated without making any changes.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>

						<p>
							<button type="button" class="button button-primary" id="wp-mcp-ai-migrate-gemini-run-btn">
								<span class="dashicons dashicons-database-import" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Run Migration', 'mcp-ai-wpoos' ); ?>
							</button>
							<span class="description" style="margin-left: 10px;">
								<?php esc_html_e( 'Apply the fixes to your cost tracking data.', 'mcp-ai-wpoos' ); ?>
							</span>
						</p>
					</div>

					<div id="wp-mcp-ai-migrate-gemini-message" class="notice" style="display: none; margin: 15px 0;">
						<p></p>
					</div>
				</div>

				<?php
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
				?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					function performGeminiMigration(actionType, buttonId) {
						var $button = $(buttonId);
						var $message = $('#wp-mcp-ai-migrate-gemini-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-migrate-gemini-preview-btn, #wp-mcp-ai-migrate-gemini-run-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
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

									// If actual migration was successful, offer to reload.
									if (!response.data.dry_run && response.data.records_updated > 0) {
										setTimeout(function() {
											if (confirm(<?php echo wp_json_encode( __( 'Migration completed successfully! Would you like to reload the page to see updated statistics?', 'mcp-ai-wpoos' ) ); ?>)) {
												location.reload();
											}
										}, 1500);
									}
								} else {
									$message
										.removeClass('notice-success notice-warning notice-info')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning notice-info')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
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
						if (confirm(<?php echo wp_json_encode( __( 'This will update historical cost tracking data to fix Gemini provider attribution. This cannot be undone, but you can preview the changes first. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							performGeminiMigration('migrate', '#wp-mcp-ai-migrate-gemini-run-btn');
						}
					});
				});

				// Sync playbooks handlers.
				jQuery(document).ready(function($) {
					function syncPlaybooks(force) {
						var $button = force ? $('#wp-mcp-ai-sync-playbooks-force-btn') : $('#wp-mcp-ai-sync-playbooks-btn');
						var $message = $('#wp-mcp-ai-playbook-sync-message');
						var originalText = $button.html();

						// Disable both buttons.
						$('#wp-mcp-ai-sync-playbooks-btn, #wp-mcp-ai-sync-playbooks-force-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Processing...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
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

									// Reload after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
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
						if (confirm(<?php echo wp_json_encode( __( 'This will force regenerate all profession playbooks even if unchanged. This may take a moment. Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							syncPlaybooks(true);
						}
					});

					$('#wp-mcp-ai-delete-old-playbooks-btn').on('click', function(e) {
						e.preventDefault();
						if (confirm(<?php echo wp_json_encode( __( 'WARNING: This will permanently delete all orphaned playbook attachments from the media library. This cannot be undone! Continue?', 'mcp-ai-wpoos' ) ); ?>)) {
							deleteOldPlaybooks();
						}
					});

					function deleteOldPlaybooks() {
						var $button = $('#wp-mcp-ai-delete-old-playbooks-btn');
						var $message = $('#wp-mcp-ai-playbook-sync-message');
						var originalText = $button.html();

						// Disable all playbook buttons.
						$('#wp-mcp-ai-sync-playbooks-btn, #wp-mcp-ai-sync-playbooks-force-btn, #wp-mcp-ai-delete-old-playbooks-btn')
							.prop('disabled', true)
							.addClass('disabled');

						// Update button text.
						$button.html('<span class="dashicons dashicons-update spin" style="margin-top: 3px;"></span> <?php echo esc_js( __( 'Deleting...', 'mcp-ai-wpoos' ) ); ?>');

						// Hide any previous messages.
						$message.hide().removeClass('notice-success notice-error notice-warning');

						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'wp_mcp_ai_delete_old_playbooks',
								nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_delete_old_playbooks' ) ); ?>
							},
							success: function(response) {
								if (response.success) {
									$message
										.removeClass('notice-error notice-warning')
										.addClass('notice-success')
										.find('p').html(response.data.message);
									$message.show();

									// Reload after a short delay.
									setTimeout(function() {
										location.reload();
									}, 2000);
								} else {
									$message
										.removeClass('notice-success notice-warning')
										.addClass('notice-error')
										.find('p').html(response.data.message || <?php echo wp_json_encode( __( 'An error occurred.', 'mcp-ai-wpoos' ) ); ?>);
									$message.show();
								}
							},
							error: function(xhr, status, error) {
								$message
									.removeClass('notice-success notice-warning')
									.addClass('notice-error')
									.find('p').html(<?php echo wp_json_encode( __( 'AJAX error: ', 'mcp-ai-wpoos' ) ); ?> + error);
								$message.show();
							},
							complete: function() {
								// Re-enable buttons and restore text.
								$('#wp-mcp-ai-sync-playbooks-btn, #wp-mcp-ai-sync-playbooks-force-btn, #wp-mcp-ai-delete-old-playbooks-btn')
									.prop('disabled', false)
									.removeClass('disabled');
								$button.html(originalText);
							}
						});
					}
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

			// Get total number of active playbook attachments.
			// Active attachments are those still referenced in profession's memory_files.
			// This excludes orphaned attachments from previous versions.
			$active_attachments         = 0;
			$professions_with_playbooks = 0;

			// Get all professions.
			$professions = get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
			}

			// Count attachments that are in memory_files (active).
			foreach ( $professions as $profession_id ) {
				$memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );

				if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
					$has_playbook = false;

					// Filter to only count playbook attachments.
					foreach ( $memory_files as $attachment_id ) {
						$profession_id_meta = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
						if ( ! empty( $profession_id_meta ) ) {
							++$active_attachments;
							$has_playbook = true;
						}
					}

					// Count this profession if it has at least one playbook.
					if ( $has_playbook ) {
						++$professions_with_playbooks;
					}
				}
			}

			$total_attachments = $active_attachments;

			// Check if playbooks were seeded.
			$playbooks_seeded = get_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION, false );

			// Get last sync timestamp if available.
			$last_sync_timestamp = get_option( 'wp_mcp_ai_playbooks_last_sync', 0 );
			$last_sync           = '';
			if ( $last_sync_timestamp ) {
				$last_sync = human_time_diff( $last_sync_timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos' );
			}

			return array(
				'total_attachments'          => absint( $total_attachments ),
				'professions_with_playbooks' => absint( $professions_with_playbooks ),
				'seeded'                     => $playbooks_seeded,
				'last_sync'                  => $last_sync,
			);
		}

		/**
		 * Render Federation & Mesh section.
		 *
		 * Provides UI for managing mesh peer connections and AI Peers.
		 */
		private function render_federation_mesh() {
			// Get mesh computing status from tools settings.
			$settings            = WP_MCP_AI_Admin_Settings::get_settings();
			$mesh_enabled        = ! empty( $settings['enable_mesh'] );
			$federation_enabled  = ! empty( $settings['enable_federation'] );
			$directory_enabled   = ! empty( $settings['enable_federation_directory'] );

			// Get AI Peers count only if directory is enabled and post type exists.
			$total_peers = 0;
			if ( $directory_enabled && post_type_exists( 'ai_peer' ) ) {
				$ai_peer_count = wp_count_posts( 'ai_peer' );
				// Count all AI peers regardless of status to detect unpublished peers.
				// Exclude only trash and auto-draft statuses.
				foreach ( (array) $ai_peer_count as $status => $count ) {
					if ( ! in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
						$total_peers += absint( $count );
					}
				}
			}

			// Get mesh inbound API key.
			$mesh_inbound_key = ! empty( $settings['mesh_inbound_api_key'] ) ? $settings['mesh_inbound_api_key'] : '';
			?>
			<div class="wp-mcp-ai-federation-mesh-section" style="margin-top: 30px;">
				<h3><?php esc_html_e( 'Federation & Mesh Computing', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage federated AI peers and mesh computing connections. Federation enables discovery and sharing of AI capabilities across trusted sites.', 'mcp-ai-wpoos' ); ?>
				</p>

				<!-- Status Overview -->
				<div class="wp-mcp-ai-mesh-status" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
					<h4 style="margin-top: 0;"><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos' ); ?></h4>
					<ul style="margin: 10px 0; padding-left: 20px;">
						<li>
							<strong><?php esc_html_e( 'Mesh Computing:', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $mesh_enabled ? 'success' : 'warning' ); ?>">
								<?php echo esc_html( $mesh_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ); ?>
							</span>
							<?php if ( ! $mesh_enabled ) : ?>
								<span class="description" style="margin-left: 10px;">
									<?php
									printf(
										/* translators: %s: URL to tools settings */
										esc_html__( 'Enable mesh computing in %s', 'mcp-ai-wpoos' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=features' ) ) . '">' . esc_html__( 'Tools & Features', 'mcp-ai-wpoos' ) . '</a>'
									);
									?>
								</span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Federation (Well-Known Endpoints):', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $federation_enabled ? 'success' : 'warning' ); ?>">
								<?php echo esc_html( $federation_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ); ?>
							</span>
						</li>
						<li>
							<strong><?php esc_html_e( 'Federation Directory:', 'mcp-ai-wpoos' ); ?></strong>
							<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $directory_enabled ? 'success' : 'warning' ); ?>">
								<?php echo esc_html( $directory_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ); ?>
							</span>
						</li>
						<li>
							<strong><?php esc_html_e( 'Registered AI Peers:', 'mcp-ai-wpoos' ); ?></strong>
							<?php echo absint( $total_peers ); ?>
						</li>
					</ul>
				</div>

				<!-- Mesh Inbound API Key -->
				<?php if ( $mesh_enabled ) : ?>
					<?php if ( $mesh_inbound_key ) : ?>
						<div class="wp-mcp-ai-mesh-api-key" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 3px;">
							<h4 style="margin-top: 0;"><?php esc_html_e( 'Mesh Inbound API Key', 'mcp-ai-wpoos' ); ?></h4>
							<p class="description">
								<?php esc_html_e( 'This key allows other NV oOS sites to connect to this instance as a mesh peer. Share this key only with trusted administrators who need to configure their sites to communicate with yours.', 'mcp-ai-wpoos' ); ?>
							</p>
							<div style="margin: 10px 0; display: flex; align-items: center; gap: 10px;">
								<input type="text" readonly value="<?php echo esc_attr( $mesh_inbound_key ); ?>" 
									id="wp-mcp-ai-mesh-key-display" 
									style="width: 100%; max-width: 500px; font-family: monospace; font-size: 12px;" />
								<button type="button" class="button button-secondary" id="wp-mcp-ai-copy-mesh-key">
									<span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Copy', 'mcp-ai-wpoos' ); ?>
								</button>
							</div>
							<p class="description" style="margin-top: 10px;">
								<strong><?php esc_html_e( 'How to use this key:', 'mcp-ai-wpoos' ); ?></strong><br>
								<?php esc_html_e( '1. Copy this key using the button above', 'mcp-ai-wpoos' ); ?><br>
								<?php esc_html_e( '2. On the peer site that wants to connect to this instance, go to Advanced → Federation & Mesh', 'mcp-ai-wpoos' ); ?><br>
								<?php
								printf(
									/* translators: %s: JSON example */
									esc_html__( '3. Add this site to their "Mesh Peer Sites Configuration" JSON with format: %s', 'mcp-ai-wpoos' ),
									'<code>{"url":"' . esc_html( get_site_url() ) . '","api_key":"[paste key here]","name":"' . esc_html( get_bloginfo( 'name' ) ) . '","enabled":true}</code>'
								);
								?>
							</p>
						</div>

						<script>
						jQuery(document).ready(function($) {
							$('#wp-mcp-ai-copy-mesh-key').on('click', function() {
								var $input = $('#wp-mcp-ai-mesh-key-display');
								$input.select();
								document.execCommand('copy');
								
								var $button = $(this);
								var originalText = $button.html();
								$button.html('<span class="dashicons dashicons-yes" style="margin-top: 3px; color: #46b450;"></span> <?php echo esc_js( __( 'Copied!', 'mcp-ai-wpoos' ) ); ?>');
								
								setTimeout(function() {
									$button.html(originalText);
								}, 2000);
							});
						});
						</script>
					<?php else : ?>
						<div class="notice notice-warning inline" style="margin: 20px 0;">
							<p>
								<strong><?php esc_html_e( 'Mesh Inbound API Key Not Generated', 'mcp-ai-wpoos' ); ?></strong><br>
								<?php esc_html_e( 'The mesh inbound API key should be automatically generated when mesh computing is enabled. Click "Save Settings" below to generate your key.', 'mcp-ai-wpoos' ); ?>
							</p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<!-- Mesh Peer Sites Configuration -->
				<?php if ( $mesh_enabled ) : ?>
					<div class="wp-mcp-ai-mesh-peers-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
						<h4><?php esc_html_e( 'Mesh Peer Sites', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description">
							<?php esc_html_e( 'Configure peer sites for mesh computing. These are remote NV oOS instances that this site can query for distributed workload processing.', 'mcp-ai-wpoos' ); ?>
						</p>
						<?php
						// Call the existing custom field renderer for mesh_peer_sites.
						if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'render_mesh_peer_sites_field' ) ) {
							$admin_settings = new WP_MCP_AI_Admin_Settings();
							$admin_settings->render_mesh_peer_sites_field();
						}
						?>
					</div>
				<?php endif; ?>

				<!-- AI Peers Management -->
				<?php if ( $directory_enabled ) : ?>
					<div class="wp-mcp-ai-ai-peers-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
						<h4><?php esc_html_e( 'AI Peers (Federation Directory)', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description">
							<?php esc_html_e( 'AI Peers represent other NV oOS instances in the federation network. Each peer can provide capabilities, tools, and AI services that can be discovered and consumed by this site.', 'mcp-ai-wpoos' ); ?>
						</p>

						<?php if ( $total_peers > 0 ) : ?>
							<!-- AI Peers List -->
							<?php
							$ai_peers = get_posts(
								array(
									'post_type'      => 'ai_peer',
									'post_status'    => 'publish',
									'posts_per_page' => 10,
									'orderby'        => 'title',
									'order'          => 'ASC',
								)
							);

							if ( ! empty( $ai_peers ) ) :
								?>
								<div class="wp-mcp-ai-peers-list" style="margin: 15px 0;">
									<table class="widefat striped" style="margin-top: 15px;">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Peer Name', 'mcp-ai-wpoos' ); ?></th>
												<th><?php esc_html_e( 'Site URL', 'mcp-ai-wpoos' ); ?></th>
												<th><?php esc_html_e( 'Health Status', 'mcp-ai-wpoos' ); ?></th>
												<th><?php esc_html_e( 'Last Verified', 'mcp-ai-wpoos' ); ?></th>
												<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $ai_peers as $peer ) : ?>
												<?php
												$peer_id     = $peer->ID;
												$site_url    = get_post_meta( $peer_id, '_wp_mcp_ai_peer_site_url', true );
												$health      = get_post_meta( $peer_id, '_wp_mcp_ai_peer_health_status', true );
												$last_verify = get_post_meta( $peer_id, '_wp_mcp_ai_peer_last_verified', true );

												// Format health status.
												$health_class = 'unknown';
												$health_text  = __( 'Unknown', 'mcp-ai-wpoos' );
												if ( 'healthy' === $health ) {
													$health_class = 'success';
													$health_text  = __( 'Healthy', 'mcp-ai-wpoos' );
												} elseif ( 'degraded' === $health ) {
													$health_class = 'warning';
													$health_text  = __( 'Degraded', 'mcp-ai-wpoos' );
												} elseif ( 'unhealthy' === $health ) {
													$health_class = 'error';
													$health_text  = __( 'Unhealthy', 'mcp-ai-wpoos' );
												}

												// Format last verified.
												$last_verify_display = __( 'Never', 'mcp-ai-wpoos' );
												if ( $last_verify ) {
													$last_verify_display = human_time_diff( strtotime( $last_verify ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos' );
												}

												$edit_url = admin_url( 'post.php?post=' . $peer_id . '&action=edit' );
												?>
												<tr>
													<td>
														<strong>
															<a href="<?php echo esc_url( $edit_url ); ?>">
																<?php echo esc_html( $peer->post_title ); ?>
															</a>
														</strong>
													</td>
													<td>
														<?php if ( $site_url ) : ?>
															<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer">
																<?php echo esc_html( $site_url ); ?>
																<span class="dashicons dashicons-external" style="font-size: 12px; text-decoration: none;"></span>
															</a>
														<?php else : ?>
															<span class="description"><?php esc_html_e( 'Not set', 'mcp-ai-wpoos' ); ?></span>
														<?php endif; ?>
													</td>
													<td>
														<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $health_class ); ?>">
															<?php echo esc_html( $health_text ); ?>
														</span>
													</td>
													<td><?php echo esc_html( $last_verify_display ); ?></td>
													<td>
														<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
															<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
														</a>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php else : ?>
								<!-- Show message when peers exist but aren't published -->
								<div class="notice notice-warning inline" style="margin: 15px 0;">
									<p>
										<?php
										$message = sprintf(
											/* translators: %d: number of peers */
											_n(
												'You have %d AI peer registered, but it is not currently published.',
												'You have %d AI peers registered, but none are currently published.',
												$total_peers,
												'mcp-ai-wpoos'
											),
											$total_peers
										);
										$link = sprintf(
											'<a href="%s">%s</a>',
											esc_url( admin_url( 'edit.php?post_type=ai_peer' ) ),
											esc_html__( 'View all AI peers', 'mcp-ai-wpoos' )
										);
										printf(
											/* translators: 1: message about unpublished peers, 2: link to view AI peers */
											__( '%1$s %2$s to review and publish them.', 'mcp-ai-wpoos' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format string contains placeholder for escaped HTML link passed as second parameter.
											esc_html( $message ),
											$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url and esc_html__.
										);
										?>
									</p>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<!-- Show helpful info when no peers exist yet -->
							<div class="notice notice-info inline" style="margin: 15px 0;">
								<p>
									<strong><?php esc_html_e( 'No AI Peers Registered Yet', 'mcp-ai-wpoos' ); ?></strong><br>
									<?php esc_html_e( 'AI Peers allow this site to discover and consume AI capabilities from other NV oOS instances. To get started:', 'mcp-ai-wpoos' ); ?>
								</p>
								<ol style="margin: 10px 0 10px 20px;">
									<li><?php esc_html_e( 'Click "Add New AI Peer" below to register a peer site', 'mcp-ai-wpoos' ); ?></li>
									<li><?php esc_html_e( 'Provide the peer site\'s URL and well-known endpoint (e.g., https://peer-site.com/.well-known/ai-peer)', 'mcp-ai-wpoos' ); ?></li>
									<li><?php esc_html_e( 'The peer will be automatically discovered and its capabilities will be available to your assistants', 'mcp-ai-wpoos' ); ?></li>
								</ol>
							</div>
						<?php endif; ?>

						<!-- Add New Peer Button -->
						<div style="margin: 15px 0;">
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ai_peer' ) ); ?>" class="button button-primary">
								<span class="dashicons dashicons-plus" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Add New AI Peer', 'mcp-ai-wpoos' ); ?>
							</a>
							<?php if ( $total_peers > 0 ) : ?>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ai_peer' ) ); ?>" class="button button-secondary" style="margin-left: 5px;">
									<?php esc_html_e( 'View All AI Peers', 'mcp-ai-wpoos' ); ?> (<?php echo absint( $total_peers ); ?>)
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php else : ?>
					<!-- Show message when directory is disabled -->
					<div class="wp-mcp-ai-ai-peers-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
						<h4><?php esc_html_e( 'AI Peers (Federation Directory)', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description">
							<?php esc_html_e( 'AI Peers represent other NV oOS instances in the federation network. Each peer can provide capabilities, tools, and AI services that can be discovered and consumed by this site.', 'mcp-ai-wpoos' ); ?>
						</p>
						<div class="notice notice-warning inline" style="margin: 15px 0;">
							<p>
								<strong><?php esc_html_e( 'Directory Service Disabled', 'mcp-ai-wpoos' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link to federation mesh settings */
									__( 'The Federation Directory service is currently disabled. Enable it in %s to manage AI Peers.', 'mcp-ai-wpoos' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format string contains placeholder for escaped HTML link.
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh' ) ) . '">' . esc_html__( 'Federation Mesh Settings', 'mcp-ai-wpoos' ) . '</a>'
								);
								?>
							</p>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! $mesh_enabled && ! $federation_enabled ) : ?>
					<div class="notice notice-info inline" style="margin-top: 20px;">
						<p>
							<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'To use mesh computing and federation features, enable them in the Tools & Features settings.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-status-badge {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
				}
				.wp-mcp-ai-status-success {
					background: #d4edda;
					color: #155724;
				}
				.wp-mcp-ai-status-warning {
					background: #fff3cd;
					color: #856404;
				}
				.wp-mcp-ai-status-error {
					background: #f8d7da;
					color: #721c24;
				}
				.wp-mcp-ai-status-unknown {
					background: #e2e3e5;
					color: #383d41;
				}
			</style>
			<?php
		}

		/**
		 * Render Settings Management section.
		 *
		 * Provides tools for backup, restore, import, export, and maintenance of plugin settings.
		 */
		private function render_settings_management() {
			// Get current settings count.
			$settings       = WP_MCP_AI_Admin_Settings::get_settings();
			$settings_count = count( $settings );

			// Get backup count.
			global $wpdb;
			$backup_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					'wp_mcp_ai_settings_backup_%'
				)
			);
			?>
			<div class="wp-mcp-ai-settings-management" style="padding: 20px; max-width: 800px;">
				<h3><?php esc_html_e( 'Settings Management', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Manage plugin settings: export for backup, import from file, clear caches, check system health, or reset to defaults.', 'mcp-ai-wpoos' ); ?>
				</p>

				<!-- Settings Health Status -->
				<div class="wp-mcp-ai-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0;">
					<h4 style="margin-top: 0;">
						<span class="dashicons dashicons-heart" style="color: #d63638;"></span>
						<?php esc_html_e( 'Settings Health', 'mcp-ai-wpoos' ); ?>
					</h4>
					<div id="wp-mcp-ai-settings-health-status">
						<p><em><?php esc_html_e( 'Click "Check Health" to run diagnostics...', 'mcp-ai-wpoos' ); ?></em></p>
					</div>
					<p>
						<button type="button" id="wp-mcp-ai-check-settings-health" class="button button-secondary">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Check Settings Health', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				</div>

				<!-- Export/Import Section -->
				<div class="wp-mcp-ai-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0;">
					<h4 style="margin-top: 0;">
						<span class="dashicons dashicons-database-export"></span>
						<?php esc_html_e( 'Backup & Restore', 'mcp-ai-wpoos' ); ?>
					</h4>
					<p>
						<strong><?php esc_html_e( 'Current Settings:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						printf(
							/* translators: %d: Number of settings fields */
							esc_html( _n( '%d field configured', '%d fields configured', $settings_count, 'mcp-ai-wpoos' ) ),
							absint( $settings_count )
						);
						?>
						<br>
						<strong><?php esc_html_e( 'Backups Available:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html( absint( $backup_count ) ); ?>
						<?php esc_html_e( '(automatic backups from recent saves)', 'mcp-ai-wpoos' ); ?>
					</p>

					<div style="margin: 15px 0;">
						<button type="button" id="wp-mcp-ai-export-settings" class="button button-primary">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Export Settings (JSON)', 'mcp-ai-wpoos' ); ?>
						</button>
						<p class="description" style="margin: 5px 0 0 0;">
							<?php esc_html_e( 'Download all plugin settings as a JSON file for backup or migration to another site.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>

					<div style="margin: 15px 0;">
						<label for="wp-mcp-ai-import-file" style="display: inline-block; margin-right: 10px;">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Import Settings:', 'mcp-ai-wpoos' ); ?>
						</label>
						<input type="file" id="wp-mcp-ai-import-file" accept=".json,application/json" />
						<button type="button" id="wp-mcp-ai-import-settings" class="button button-secondary" disabled>
							<?php esc_html_e( 'Upload & Import', 'mcp-ai-wpoos' ); ?>
						</button>
						<p class="description" style="margin: 5px 0 0 0;">
							<?php esc_html_e( 'Import settings from a previously exported JSON file. Current settings will be backed up before import.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
				</div>

				<!-- Cache Management Section -->
				<div class="wp-mcp-ai-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0;">
					<h4 style="margin-top: 0;">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Cache Management', 'mcp-ai-wpoos' ); ?>
					</h4>
					<p><?php esc_html_e( 'If settings changes are not taking effect, clear all settings-related caches.', 'mcp-ai-wpoos' ); ?></p>
					<p>
						<button type="button" id="wp-mcp-ai-clear-cache" class="button button-secondary">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Clear All Caches', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				</div>

				<!-- Reset Section -->
				<div class="wp-mcp-ai-card" style="background: #fff; border: 1px solid #f0c36d; padding: 15px; margin: 20px 0;">
					<h4 style="margin-top: 0; color: #996800;">
						<span class="dashicons dashicons-warning" style="color: #996800;"></span>
						<?php esc_html_e( 'Reset to Defaults', 'mcp-ai-wpoos' ); ?>
					</h4>
					<p class="description">
						<strong><?php esc_html_e( 'Warning:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'This will reset ALL settings to their default values. Current settings will be backed up before reset.', 'mcp-ai-wpoos' ); ?>
					</p>
					<p>
						<button type="button" id="wp-mcp-ai-reset-settings" class="button button-secondary">
							<span class="dashicons dashicons-undo"></span>
							<?php esc_html_e( 'Reset All Settings', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				</div>

				<!-- Status Messages -->
				<div id="wp-mcp-ai-settings-management-message" class="notice" style="display: none; margin: 15px 0;">
					<p></p>
				</div>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				const nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp-mcp-ai-dashboard' ) ); ?>;

				// Enable import button when file is selected.
				$('#wp-mcp-ai-import-file').on('change', function() {
					$('#wp-mcp-ai-import-settings').prop('disabled', !this.files.length);
				});

				// Export settings.
				$('#wp-mcp-ai-export-settings').on('click', function() {
					window.location.href = ajaxurl + '?action=wp_mcp_ai_export_settings&nonce=' + nonce;
				});

				// Import settings.
				$('#wp-mcp-ai-import-settings').on('click', function() {
					const fileInput = document.getElementById('wp-mcp-ai-import-file');
					if (!fileInput.files.length) {
						alert(<?php echo wp_json_encode( __( 'Please select a JSON file to import.', 'mcp-ai-wpoos' ) ); ?>);
						return;
					}

					if (!confirm(<?php echo wp_json_encode( __( 'Import settings from file? Your current settings will be backed up first.', 'mcp-ai-wpoos' ) ); ?>)) {
						return;
					}

					const formData = new FormData();
					formData.append('action', 'wp_mcp_ai_import_settings');
					formData.append('nonce', nonce);
					formData.append('settings_file', fileInput.files[0]);

					$(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Importing...', 'mcp-ai-wpoos' ) ); ?>);

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false,
						success: function(response) {
							if (response.success) {
								$('#wp-mcp-ai-settings-management-message')
									.removeClass('notice-error')
									.addClass('notice-success')
									.find('p').text(response.data.message + ' (' + response.data.imported_count + ' fields)');
								$('#wp-mcp-ai-settings-management-message').slideDown();
								setTimeout(function() {
									window.location.reload();
								}, 2000);
							} else {
								$('#wp-mcp-ai-settings-management-message')
									.removeClass('notice-success')
									.addClass('notice-error')
									.find('p').text(response.data.message || <?php echo wp_json_encode( __( 'Import failed.', 'mcp-ai-wpoos' ) ); ?>);
								$('#wp-mcp-ai-settings-management-message').slideDown();
								$('#wp-mcp-ai-import-settings').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Upload & Import', 'mcp-ai-wpoos' ) ); ?>);
							}
						},
						error: function() {
							alert(<?php echo wp_json_encode( __( 'AJAX error occurred.', 'mcp-ai-wpoos' ) ); ?>);
							$('#wp-mcp-ai-import-settings').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Upload & Import', 'mcp-ai-wpoos' ) ); ?>);
						}
					});
				});

				// Clear cache.
				$('#wp-mcp-ai-clear-cache').on('click', function() {
					if (!confirm(<?php echo wp_json_encode( __( 'Clear all settings caches?', 'mcp-ai-wpoos' ) ); ?>)) {
						return;
					}

					$(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Clearing...', 'mcp-ai-wpoos' ) ); ?>);

					$.post(ajaxurl, {
						action: 'wp_mcp_ai_clear_settings_cache',
						nonce: nonce
					}, function(response) {
						if (response.success) {
							$('#wp-mcp-ai-settings-management-message')
								.removeClass('notice-error')
								.addClass('notice-success')
								.find('p').text(response.data.message);
							$('#wp-mcp-ai-settings-management-message').slideDown();
						} else {
							alert(response.data.message || <?php echo wp_json_encode( __( 'Failed to clear cache.', 'mcp-ai-wpoos' ) ); ?>);
						}
						$('#wp-mcp-ai-clear-cache').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Clear All Caches', 'mcp-ai-wpoos' ) ); ?>);
					});
				});

				// Reset settings.
				$('#wp-mcp-ai-reset-settings').on('click', function() {
					if (!confirm(<?php echo wp_json_encode( __( 'Reset ALL settings to defaults? This cannot be undone! (Current settings will be backed up)', 'mcp-ai-wpoos' ) ); ?>)) {
						return;
					}

					$(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Resetting...', 'mcp-ai-wpoos' ) ); ?>);

					$.post(ajaxurl, {
						action: 'wp_mcp_ai_reset_settings',
						nonce: nonce
					}, function(response) {
						if (response.success) {
							$('#wp-mcp-ai-settings-management-message')
								.removeClass('notice-error')
								.addClass('notice-success')
								.find('p').text(response.data.message);
							$('#wp-mcp-ai-settings-management-message').slideDown();
							setTimeout(function() {
								window.location.reload();
							}, 2000);
						} else {
							alert(response.data.message || <?php echo wp_json_encode( __( 'Failed to reset settings.', 'mcp-ai-wpoos' ) ); ?>);
							$('#wp-mcp-ai-reset-settings').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Reset All Settings', 'mcp-ai-wpoos' ) ); ?>);
						}
					});
				});

				// Check settings health.
				$('#wp-mcp-ai-check-settings-health').on('click', function() {
					$(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Checking...', 'mcp-ai-wpoos' ) ); ?>);

					$.post(ajaxurl, {
						action: 'wp_mcp_ai_check_settings_health',
						nonce: nonce
					}, function(response) {
						if (response.success) {
							let statusHtml = '<h5>' + response.data.message + '</h5>';
							
							if (response.data.issues && response.data.issues.length > 0) {
								statusHtml += '<div class="notice notice-error inline"><ul>';
								response.data.issues.forEach(function(issue) {
									statusHtml += '<li><strong>Issue:</strong> ' + issue + '</li>';
								});
								statusHtml += '</ul></div>';
							}

							if (response.data.warnings && response.data.warnings.length > 0) {
								statusHtml += '<div class="notice notice-warning inline"><ul>';
								response.data.warnings.forEach(function(warning) {
									statusHtml += '<li><strong>Warning:</strong> ' + warning + '</li>';
								});
								statusHtml += '</ul></div>';
							}

							if (response.data.info && response.data.info.length > 0) {
								statusHtml += '<div class="notice notice-info inline"><ul>';
								response.data.info.forEach(function(info) {
									statusHtml += '<li>' + info + '</li>';
								});
								statusHtml += '</ul></div>';
							}

							$('#wp-mcp-ai-settings-health-status').html(statusHtml);
						} else {
							$('#wp-mcp-ai-settings-health-status').html('<p class="notice notice-error inline">' + (response.data.message || <?php echo wp_json_encode( __( 'Health check failed.', 'mcp-ai-wpoos' ) ); ?>) + '</p>');
						}
						$('#wp-mcp-ai-check-settings-health').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Check Settings Health', 'mcp-ai-wpoos' ) ); ?>);
					});
				});
			});
			</script>
			<?php
		}
	}
}
