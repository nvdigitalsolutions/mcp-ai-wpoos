<?php
/**
 * Simple Settings Page for NV oOS
 *
 * A diagnostic page under Settings > NV oOS that shows a flat list
 * of all saved settings values for easy verification.
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

			// Merge field definitions.
			$all_fields = array_merge( $general_fields, $providers_fields );

			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				
				<?php settings_errors( 'wp_mcp_ai_settings' ); ?>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				<?php endif; ?>

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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
					<input type="hidden" name="active_tab" value="general" />
					<input type="hidden" name="redirect_page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

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
							// Sort fields alphabetically by key for easier scanning.
							ksort( $all_fields );

							foreach ( $all_fields as $key => $field ) {
								$this->render_setting_row( $key, $field, $settings );
							}
							?>
						</tbody>
					</table>

					<div style="margin-top: 20px;">
						<p>
							<strong><?php esc_html_e( 'Total Settings:', 'mcp-ai-wpoos' ); ?></strong>
							<?php echo esc_html( count( $all_fields ) ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Settings with Values:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							$count_with_values = 0;
							foreach ( $all_fields as $key => $field ) {
								if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
									$count_with_values++;
								}
							}
							echo esc_html( $count_with_values );
							?>
						</p>
					</div>

					<?php submit_button( __( 'Save All Settings', 'mcp-ai-wpoos' ) ); ?>
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
			$is_sensitive      = false;
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
					?>
					<input 
						type="number" 
						name="<?php echo esc_attr( $input_name ); ?>" 
						value="<?php echo esc_attr( $value ); ?>" 
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php if ( '' !== $min ) : ?>min="<?php echo esc_attr( $min ); ?>"<?php endif; ?>
						<?php if ( '' !== $max ) : ?>max="<?php echo esc_attr( $max ); ?>"<?php endif; ?>
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
					?>
					<input 
						type="text" 
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
			}
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
