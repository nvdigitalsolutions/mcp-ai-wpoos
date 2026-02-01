<?php
/**
 * Simple Settings Page for NV oOS
 *
 * A diagnostic page under Settings > NV oOS that shows a flat list
 * of all saved settings values for easy verification and editing.
 *
 * This page displays fields from multiple tabs (General and Providers)
 * organized in logical groups. Uses the save_all_tabs flag to ensure
 * all visible fields are saved together, preventing data loss.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Simple_Settings_Page' ) ) {
	/**
	 * Simple diagnostic settings page.
	 */
	class WP_MCP_AI_Simple_Settings_Page {
		const PAGE_SLUG = 'wp-mcp-ai-simple-settings';

		/**
		 * Field key for provider priority list (array field that needs special handling).
		 */
		const PROVIDER_PRIORITY_FIELD = 'provider_priority_list';

		/**
		 * Initialize the simple settings page.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		}

		/**
		 * Register the simple settings page under Settings menu.
		 */
		public function register_settings_page() {
			add_options_page(
				__( 'NV oOS Settings', 'mcp-ai-wpoos' ),
				__( 'NV oOS', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Render the simple settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Get all saved settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Get field definitions from General and Providers sections.
			$general_fields   = $this->get_general_fields();
			$providers_fields = $this->get_providers_fields();

			// Get active tab from query parameter, default to 'general'.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for tab switching.
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

			// Validate tab value.
			if ( ! in_array( $active_tab, array( 'general', 'providers' ), true ) ) {
				$active_tab = 'general';
			}

			// Determine which fields to display based on active tab.
			$current_fields = ( 'providers' === $active_tab ) ? $providers_fields : $general_fields;

			// Group fields by logical categories instead of alphabetical.
			$grouped_fields = $this->group_fields_by_category( $current_fields, $active_tab );

			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

				<?php
				// Display settings errors/updates from WordPress Settings API.
				// This automatically shows "Settings saved." message when updated=true query param is present.
				settings_errors( 'wp_mcp_ai_settings' );

				// Also show explicit success message if settings were just updated.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for success message display.
				if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for success message display.
					$saved_count = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
					?>
					<div class="notice notice-success is-dismissible">
						<p>
							<strong><?php esc_html_e( 'Settings saved successfully!', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $saved_count > 0 ) : ?>
								<?php
								printf(
									/* translators: %d: Number of settings fields saved */
									esc_html( _n( '%d field updated.', '%d fields updated.', $saved_count, 'mcp-ai-wpoos' ) ),
									esc_html( $saved_count )
								);
								?>
							<?php endif; ?>
						</p>
						<?php
						// Show link to view logs if logging is enabled.
						$current_settings = WP_MCP_AI_Admin_Settings::get_settings();
						if ( ! empty( $current_settings['enable_logging'] ) || ! empty( $current_settings['enable_extended_logging'] ) ) {
							$advanced_url = add_query_arg(
								array(
									'page' => WP_MCP_AI_Settings_Dashboard::PAGE_SLUG,
									'tab'  => 'advanced',
								),
								admin_url( 'admin.php' )
							);
							?>
							<p style="margin-top: 10px;">
								<em>
									<?php
									printf(
										/* translators: %s: Link to Advanced tab */
										esc_html__( 'Logging is enabled. View activity logs in the %s.', 'mcp-ai-wpoos' ),
										'<a href="' . esc_url( $advanced_url ) . '">' . esc_html__( 'Advanced tab', 'mcp-ai-wpoos' ) . '</a>'
									);
									?>
								</em>
							</p>
							<?php
						}
						?>
					</div>
					<?php
				}
				?>

				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: Link to main settings dashboard */
							esc_html__( 'This page provides a flat, editable view of all plugin settings. Changes made here are saved to the same location as the %s.', 'mcp-ai-wpoos' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ) . '">' . esc_html__( 'main settings dashboard', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
				</div>

				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'mcp-ai-wpoos' ); ?>">
					<?php
					$tabs = array(
						'general'   => array(
							'title' => __( 'General Settings', 'mcp-ai-wpoos' ),
							'icon'  => 'dashicons-admin-settings',
						),
						'providers' => array(
							'title' => __( 'AI Providers', 'mcp-ai-wpoos' ),
							'icon'  => 'dashicons-admin-generic',
						),
					);

					foreach ( $tabs as $tab_id => $tab ) :
						$tab_url      = add_query_arg(
							array(
								'page' => self::PAGE_SLUG,
								'tab'  => $tab_id,
							),
							admin_url( 'options-general.php' )
						);
						$active_class = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
						?>
						<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active_class ); ?>">
							<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
							<?php echo esc_html( $tab['title'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
					<input type="hidden" name="redirect_page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<input type="hidden" name="save_all_tabs" value="1" />

					<?php foreach ( $grouped_fields as $group_name => $group_fields ) : ?>
						<?php if ( ! empty( $group_fields ) ) : ?>
							<h2 style="margin-top: 30px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #ccc;">
								<?php echo esc_html( $group_name ); ?>
							</h2>

							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th style="width: 35%;"><?php esc_html_e( 'Setting', 'mcp-ai-wpoos' ); ?></th>
										<th style="width: 45%;"><?php esc_html_e( 'Value', 'mcp-ai-wpoos' ); ?></th>
										<th style="width: 20%;"><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $group_fields as $key => $field ) {
										$this->render_setting_row( $key, $field, $settings );
									}
									?>
								</tbody>
							</table>
						<?php endif; ?>
					<?php endforeach; ?>

					<div style="margin-top: 20px;">
						<p>
							<strong><?php esc_html_e( 'Settings on This Tab:', 'mcp-ai-wpoos' ); ?></strong>
							<?php echo esc_html( count( $current_fields ) ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Settings with Values:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							$count_with_values = 0;
							foreach ( $current_fields as $key => $field ) {
								if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
									++$count_with_values;
								}
							}
							echo esc_html( $count_with_values );
							?>
						</p>
					</div>

					<?php submit_button( __( 'Save Settings', 'mcp-ai-wpoos' ) ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Render a single setting row.
		 *
		 * @param string $key      Setting key.
		 * @param array  $field    Field definition.
		 * @param array  $settings All settings.
		 */
		private function render_setting_row( $key, $field, $settings ) {
			$label = isset( $field['label'] ) ? $field['label'] : $key;
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

			// Check if this is a sensitive field.
			$is_sensitive       = false;
			$sensitive_patterns = array( 'key', 'token', 'secret', 'password', 'api' );
			foreach ( $sensitive_patterns as $pattern ) {
				if ( false !== stripos( $key, $pattern ) || 'password' === $type ) {
					$is_sensitive = true;
					break;
				}
			}

			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $label ); ?></strong><br>
					<code style="font-size: 11px; color: #666;"><?php echo esc_html( $key ); ?></code>
				</td>
				<td>
					<?php $this->render_field_input( $key, $field, $value, $is_sensitive ); ?>
				</td>
				<td>
					<code><?php echo esc_html( $type ); ?></code>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render the input field for a setting.
		 *
		 * @param string $key          Setting key.
		 * @param array  $field        Field definition.
		 * @param mixed  $value        Current value.
		 * @param bool   $is_sensitive Whether this is a sensitive field.
		 */
		private function render_field_input( $key, $field, $value, $is_sensitive ) {
			$type        = isset( $field['type'] ) ? $field['type'] : 'text';
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$description = isset( $field['description'] ) ? $field['description'] : '';

			// Build the input name attribute.
			$input_name = 'wp_mcp_ai_settings[' . esc_attr( $key ) . ']';

			// Special handling for provider_priority_list - it's an array field.
			if ( self::PROVIDER_PRIORITY_FIELD === $key ) {
				$dashboard_url = add_query_arg(
					array(
						'page'   => WP_MCP_AI_Settings_Dashboard::PAGE_SLUG,
						'tab'    => 'providers',
						'subtab' => 'priority',
					),
					admin_url( 'admin.php' )
				);
				?>
				<div class="notice notice-warning inline" style="margin: 0; padding: 8px 12px;">
					<p style="margin: 0;">
						<?php
						printf(
							/* translators: %s: Link to main dashboard */
							esc_html__( 'This field uses a drag-and-drop interface. Please use the %s to modify the provider priority order.', 'mcp-ai-wpoos' ),
							'<a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'main settings dashboard', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
					<?php if ( is_array( $value ) && ! empty( $value ) ) : ?>
						<p style="margin: 8px 0 0 0; color: #666;">
							<strong><?php esc_html_e( 'Current order:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							// Ensure all array elements are strings to prevent conversion warnings.
							$safe_values = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
							echo esc_html( implode( ' > ', $safe_values ) );
							?>
						</p>
					<?php endif; ?>
				</div>
				<?php
				return;
			}

			// Render different input types.
			switch ( $type ) {
				case 'checkbox':
					?>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $input_name ); ?>"
							value="1"
							<?php checked( $value, true ); ?>
						/>
						<?php echo esc_html( $description ); ?>
					</label>
					<?php
					break;

				case 'textarea':
					?>
					<textarea
						name="<?php echo esc_attr( $input_name ); ?>"
						rows="3"
						style="width: 100%;"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
					><?php echo esc_textarea( $value ); ?></textarea>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				case 'select':
					$options = isset( $field['options'] ) ? $field['options'] : array();
					?>
					<select name="<?php echo esc_attr( $input_name ); ?>" style="width: 100%;">
						<?php if ( empty( $value ) ) : ?>
							<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $options as $option_value => $option_label ) : ?>
							<option
								value="<?php echo esc_attr( $option_value ); ?>"
								<?php selected( $value, $option_value ); ?>
							>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				case 'password':
					// For password fields, show a note about existing values.
					if ( ! empty( $value ) && $is_sensitive ) {
						echo '<p style="margin: 0 0 5px 0; color: #4caf50;"><em>' . esc_html__( '••• Current value is set (leave blank to keep existing)', 'mcp-ai-wpoos' ) . '</em></p>';
					}
					?>
					<input
						type="password"
						name="<?php echo esc_attr( $input_name ); ?>"
						value=""
						placeholder="<?php echo esc_attr( $placeholder ? $placeholder : __( 'Enter new value or leave blank to keep existing', 'mcp-ai-wpoos' ) ); ?>"
						style="width: 100%;"
						autocomplete="off"
					/>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				case 'number':
				case 'range':
				case 'slider':
					$min = isset( $field['min'] ) ? $field['min'] : '';
					$max = isset( $field['max'] ) ? $field['max'] : '';
					// Ensure value is numeric to prevent array-to-string conversion.
					$numeric_value = is_numeric( $value ) ? $value : '';
					?>
					<input
						type="number"
						name="<?php echo esc_attr( $input_name ); ?>"
						value="<?php echo esc_attr( $numeric_value ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php
						if ( '' !== $min ) :
							?>
							min="<?php echo esc_attr( $min ); ?>"<?php endif; ?>
						<?php
						if ( '' !== $max ) :
							?>
							max="<?php echo esc_attr( $max ); ?>"<?php endif; ?>
						style="width: 100%;"
					/>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				case 'url':
					?>
					<input
						type="url"
						name="<?php echo esc_attr( $input_name ); ?>"
						value="<?php echo esc_url( $value ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						style="width: 100%;"
					/>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				case 'email':
					?>
					<input
						type="email"
						name="<?php echo esc_attr( $input_name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						style="width: 100%;"
					/>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;

				default:
					// Default to text input.
					// For sensitive non-password fields, show masked value indicator.
					if ( $is_sensitive && ! empty( $value ) ) {
						echo '<p style="margin: 0 0 5px 0; color: #4caf50;"><em>' . esc_html__( '••• Current value is set', 'mcp-ai-wpoos' ) . '</em></p>';
					}
					// Ensure value is scalar (string, int, float, bool) to prevent array-to-string conversion.
					$safe_value = is_scalar( $value ) ? $value : '';
					?>
					<input
						type="text"
						name="<?php echo esc_attr( $input_name ); ?>"
						value="<?php echo esc_attr( $safe_value ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						style="width: 100%;"
					/>
					<?php
					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					break;
			}
		}


		/**
		 * Group fields by logical categories for better organization.
		 *
		 * @param array  $fields     Field definitions.
		 * @param string $active_tab Active tab name.
		 * @return array Grouped fields array.
		 */
		private function group_fields_by_category( $fields, $active_tab ) {
			$grouped = array();

			if ( 'providers' === $active_tab ) {
				// Group provider fields by provider type.
				$provider_groups = array(
					'OpenAI'          => array(),
					'Google Gemini'   => array(),
					'Anthropic'       => array(),
					'Ollama'          => array(),
					'LM Studio'       => array(),
					'Cloudflare'      => array(),
					'Other Providers' => array(),
				);

				foreach ( $fields as $key => $field ) {
					if ( false !== stripos( $key, 'openai' ) ) {
						$provider_groups['OpenAI'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'gemini' ) || false !== stripos( $key, 'google' ) ) {
						$provider_groups['Google Gemini'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'anthropic' ) || false !== stripos( $key, 'claude' ) ) {
						$provider_groups['Anthropic'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'ollama' ) ) {
						$provider_groups['Ollama'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'lm_studio' ) || false !== stripos( $key, 'lmstudio' ) ) {
						$provider_groups['LM Studio'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'cloudflare' ) ) {
						$provider_groups['Cloudflare'][ $key ] = $field;
					} else {
						$provider_groups['Other Providers'][ $key ] = $field;
					}
				}

				// Only include non-empty groups.
				foreach ( $provider_groups as $group_name => $group_fields ) {
					if ( ! empty( $group_fields ) ) {
						$grouped[ $group_name ] = $group_fields;
					}
				}
			} else {
				// Group general settings by category.
				$general_groups = array(
					'Core Settings'        => array(),
					'Authentication'       => array(),
					'Features & Tools'     => array(),
					'Debugging & Logging'  => array(),
					'Performance'          => array(),
					'Integration Settings' => array(),
					'Other Settings'       => array(),
				);

				foreach ( $fields as $key => $field ) {
					if ( false !== stripos( $key, 'auth' ) || false !== stripos( $key, 'secret' ) || false !== stripos( $key, 'key_rotation' ) ) {
						$general_groups['Authentication'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'debug' ) || false !== stripos( $key, 'log' ) || false !== stripos( $key, 'error' ) ) {
						$general_groups['Debugging & Logging'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'cache' ) || false !== stripos( $key, 'performance' ) || false !== stripos( $key, 'timeout' ) ) {
						$general_groups['Performance'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'tool' ) || false !== stripos( $key, 'feature' ) || false !== stripos( $key, 'enable' ) ) {
						$general_groups['Features & Tools'][ $key ] = $field;
					} elseif ( false !== stripos( $key, 'integration' ) || false !== stripos( $key, 'webhook' ) || false !== stripos( $key, 'api_endpoint' ) ) {
						$general_groups['Integration Settings'][ $key ] = $field;
					} elseif ( in_array( $key, array( 'default_model', 'default_provider', 'max_tokens', 'temperature' ), true ) ) {
						$general_groups['Core Settings'][ $key ] = $field;
					} else {
						$general_groups['Other Settings'][ $key ] = $field;
					}
				}

				// Only include non-empty groups.
				foreach ( $general_groups as $group_name => $group_fields ) {
					if ( ! empty( $group_fields ) ) {
						$grouped[ $group_name ] = $group_fields;
					}
				}
			}

			// If no groups were created, return all fields under a default group.
			if ( empty( $grouped ) ) {
				$grouped[ __( 'All Settings', 'mcp-ai-wpoos' ) ] = $fields;
			}

			return $grouped;
		}

		/**
		 * Get field definitions from General section.
		 *
		 * @return array Field definitions.
		 */
		private function get_general_fields() {
			// Try to get fields from the General section.
			$sections = WP_MCP_AI_Settings_Registry::get_sections( 'general' );
			$fields   = array();

			foreach ( $sections as $section ) {
				if ( method_exists( $section, 'get_fields' ) ) {
					$section_fields = $section->get_fields();
					if ( is_array( $section_fields ) ) {
						$fields = array_merge( $fields, $section_fields );
					}
				}
			}

			return $fields;
		}

		/**
		 * Get field definitions from Providers section.
		 *
		 * @return array Field definitions.
		 */
		private function get_providers_fields() {
			// Try to get fields from the Providers section.
			$sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );
			$fields   = array();

			foreach ( $sections as $section ) {
				if ( method_exists( $section, 'get_fields' ) ) {
					$section_fields = $section->get_fields();
					if ( is_array( $section_fields ) ) {
						$fields = array_merge( $fields, $section_fields );
					}
				}
			}

			return $fields;
		}
	}
}
