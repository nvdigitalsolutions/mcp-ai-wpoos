<?php
/**
 * Abstract base class for settings sections.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
	/**
	 * Base class that all settings sections must extend.
	 */
	abstract class WP_MCP_AI_Settings_Section {
		/**
		 * Get the section ID.
		 *
		 * @return string
		 */
		abstract public function get_id();

		/**
		 * Get the section title.
		 *
		 * @return string
		 */
		abstract public function get_title();

		/**
		 * Get the tab this section belongs to.
		 *
		 * @return string
		 */
		abstract public function get_tab();

		/**
		 * Get field definitions for this section.
		 *
		 * @return array
		 */
		abstract public function get_fields();

		/**
		 * Render the section content.
		 */
		abstract public function render();

		/**
		 * Get section priority (for ordering within tab).
		 *
		 * @return int Lower numbers render first.
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return '';
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * Override this method in child classes to provide section-specific documentation links.
		 *
		 * @return string Documentation URL or empty string if no documentation available.
		 */
		public function get_documentation_url() {
			return '';
		}

		/**
		 * Validate input for this section.
		 *
		 * @param array $input Raw input from form.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			return $input;
		}

		/**
		 * Sanitize input for this section.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			// Check if this section has subtabs by looking for get_subtab_groups method.
			if ( method_exists( $this, 'get_subtab_groups' ) ) {
				return $this->sanitize_with_subtabs( $input );
			}

			// Default sanitization for sections without subtabs.
			return $this->sanitize_fields( $input, $this->get_fields() );
		}

		/**
		 * Sanitize input for sections with sub-tabs.
		 *
		 * Only processes fields from the active sub-tab to prevent clearing
		 * settings from inactive sub-tabs when saving.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input for active sub-tab only.
		 */
		protected function sanitize_with_subtabs( $input ) {
			$active_subtab = $this->get_active_subtab();
			$subtab_groups = $this->get_subtab_groups();

			// Get fields that belong to the active sub-tab.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return array();
			}

			$active_field_keys = $subtab_groups[ $active_subtab ]['fields'];
			$all_fields        = $this->get_fields();

			// Filter to only active fields.
			$active_fields = array();
			foreach ( $active_field_keys as $field_key ) {
				if ( isset( $all_fields[ $field_key ] ) ) {
					$active_fields[ $field_key ] = $all_fields[ $field_key ];
				}
			}

			// Check if we're actually processing a form submission for this subtab.
			// Use section-specific subtab field name to avoid conflicts when multiple sections have subtabs.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			$subtab_field_name = 'subtab_' . $this->get_id();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			$submitted_subtab  = isset( $_POST[ $subtab_field_name ] ) ? sanitize_key( $_POST[ $subtab_field_name ] ) : '';

			// Only consider this a form submit if the submitted subtab matches the active subtab.
			// AND the submitted subtab actually exists in this section's subtab groups.
			// This prevents cross-subtab data clearing when saving one subtab shouldn't affect others.
			$is_form_submit = ( $submitted_subtab === $active_subtab ) && isset( $subtab_groups[ $submitted_subtab ] );

			// Debug logging for subtab sanitization.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
				$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
				if ( $enable_logging ) {
					error_log(
						sprintf(
							'[NV oOS Subtab Sanitize] Section: %s, Active: %s, Submitted: %s, Is Form Submit: %s, Field Count: %d, Fields: %s',
							$this->get_id(),
							$active_subtab,
							$submitted_subtab,
							$is_form_submit ? 'YES' : 'NO',
							count( $active_field_keys ),
							implode( ', ', array_slice( $active_field_keys, 0, 10 ) )
						)
					);
				}
			}

			// If this is not the subtab being submitted, return empty array to avoid.
			// processing fields from inactive subtabs and preserve their existing values.
			if ( ! $is_form_submit ) {
				return array();
			}

			return $this->sanitize_fields( $input, $active_fields, $is_form_submit );
		}

		/**
		 * Sanitize fields based on their type.
		 *
		 * @param array $input         Raw input from form.
		 * @param array $fields        Field definitions to sanitize.
		 * @param bool  $is_form_submit Whether this is an actual form submission (for checkbox handling).
		 * @return array Sanitized input.
		 */
		protected function sanitize_fields( $input, $fields, $is_form_submit = true ) {
			$sanitized = array();

			// DEFENSIVE: Filter input to only include fields that are defined in $fields.
			// This prevents fields from other subtabs from being processed if they somehow
			// end up in the POST data (e.g., browser autofill, JavaScript manipulation, etc.).
			$filtered_input = array();
			foreach ( $fields as $key => $field ) {
				if ( isset( $input[ $key ] ) ) {
					$filtered_input[ $key ] = $input[ $key ];
				}
			}

			foreach ( $fields as $key => $field ) {
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				// Skip display-only field types (html, custom, hidden) as they don't have user input.
				// Hidden fields are used to preserve OAuth tokens and other programmatically-set values.
				if ( in_array( $type, array( 'html', 'custom', 'hidden' ), true ) ) {
					continue;
				}

				// Special handling for checkboxes.
				if ( 'checkbox' === $type ) {
					// Only process checkboxes if this is actually the form being submitted.
					// This prevents checkboxes from other subtabs from being set to false.
					if ( $is_form_submit ) {
						// Checkbox is checked if present in input, unchecked otherwise.
						$sanitized[ $key ] = isset( $filtered_input[ $key ] ) ? (bool) $filtered_input[ $key ] : false;
					}
					// If not the submitted form, skip this checkbox entirely to preserve existing value.
					continue;
				}

				// For other field types, skip if not present in filtered input.
				if ( ! isset( $filtered_input[ $key ] ) ) {
					continue;
				}

				$value = $filtered_input[ $key ];

				switch ( $type ) {
					case 'text':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_text_field( $value );
						}
						break;

					case 'password':
						// CRITICAL: Only save password fields if they contain a value.
						// Empty password fields should not overwrite existing saved values.
						// This prevents accidental deletion of API keys when saving other subtabs.
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							// Arrays should not be password fields, skip.
							break;
						}
						$trimmed_value = trim( sanitize_text_field( $value ) );
						if ( '' !== $trimmed_value ) {
							$sanitized[ $key ] = $trimmed_value;
						}
						// If empty, skip adding to sanitized array to preserve existing value.
						break;

					case 'url':
						// Keep empty strings, only sanitize non-empty values with proper URL function.
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = '' === $value ? '' : esc_url_raw( $value );
						}
						break;

					case 'textarea':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_textarea_field( $value );
						}
						break;

					case 'email':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_email( $value );
						}
						break;

					case 'number':
						// Handle empty strings differently based on field definition:
						// 1. If field explicitly allows empty string (e.g., filter fields with default=''),
						// preserve the empty string for "use auto-detection" functionality.
						// 2. Otherwise, skip empty values to prevent overwriting existing settings.
						if ( '' === $value ) {
							// Check if this field explicitly allows empty strings by checking if default is ''.
							if ( isset( $fields[ $key ]['default'] ) && '' === $fields[ $key ]['default'] ) {
								// Field intentionally uses empty string (e.g., filter fields for auto-detection).
								$sanitized[ $key ] = '';
							}
							// Otherwise skip - don't overwrite existing value with empty string.
							break;
						}
						$sanitized[ $key ] = absint( $value );
						break;

					case 'range':
					case 'slider':
						$min               = isset( $field['min'] ) ? (int) $field['min'] : 0;
						$max               = isset( $field['max'] ) ? (int) $field['max'] : 100;
						$sanitized_value   = absint( $value );
						$sanitized[ $key ] = max( $min, min( $max, $sanitized_value ) );
						break;

					case 'select':
						$options = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
						// Convert value to match the type of option keys for proper comparison.
						// Form submissions send all values as strings, but option keys might be integers.
						$typed_value = $value;
						if ( ! empty( $options ) ) {
							// Check if we have numeric option keys - if so, convert value to int for comparison.
							$first_key = $options[0];
							if ( is_int( $first_key ) && is_numeric( $value ) ) {
								$typed_value = absint( $value );
							}
						}
						// Use non-strict comparison to handle string/int type juggling.
						if ( in_array( $typed_value, $options, false ) ) {
							$sanitized[ $key ] = $typed_value;
						}
						break;

					default:
						// Handle array values to prevent "Array to string conversion" warnings.
						if ( is_array( $value ) ) {
							// If value is an array, serialize it to JSON for storage.
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_text_field( $value );
						}
						break;
				}
			}

			return $sanitized;
		}

		/**
		 * Render a field based on its configuration.
		 *
		 * @param string $key Field key.
		 * @param array  $field Field configuration.
		 */
		protected function render_field( $key, $field ) {
			$type         = isset( $field['type'] ) ? $field['type'] : 'text';
			$label        = isset( $field['label'] ) ? $field['label'] : '';
			$description  = isset( $field['description'] ) ? $field['description'] : '';
			$value        = WP_MCP_AI_Settings_Registry::get_setting( $key, isset( $field['default'] ) ? $field['default'] : '' );
			$placeholder  = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$required     = isset( $field['required'] ) ? $field['required'] : false;
			$autocomplete = isset( $field['autocomplete'] ) ? $field['autocomplete'] : '';
			$disabled     = isset( $field['disabled'] ) ? $field['disabled'] : false;
			$pro_badge    = isset( $field['pro_badge'] ) ? $field['pro_badge'] : false;

			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( $required ) : ?>
							<span class="required">*</span>
						<?php endif; ?>
						<?php if ( $pro_badge ) : ?>
							<span class="wp-mcp-ai-pro-badge" style="display: inline-block; margin-left: 8px; padding: 3px 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 3px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; vertical-align: middle;">
								<?php esc_html_e( 'PRO', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php endif; ?>
					</label>
				</th>
				<td>
					<?php
					switch ( $type ) {
						case 'text':
						case 'email':
						case 'url':
						case 'number':
							?>
							<input
								type="<?php echo esc_attr( $type ); ?>"
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php if ( ! empty( $autocomplete ) ) : ?>
									autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
								<?php endif; ?>
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							/>
							<?php
							break;

						case 'password':
							?>
							<input
								type="password"
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								autocomplete="<?php echo esc_attr( ! empty( $autocomplete ) ? $autocomplete : 'new-password' ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							/>
							<?php
							break;

						case 'textarea':
							// Handle array values - convert to JSON string for display.
							if ( is_array( $value ) ) {
								$json_value = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
								// Handle encoding failure gracefully.
								$textarea_value = ( false !== $json_value ) ? $json_value : wp_json_encode( $value );
							} else {
								$textarea_value = $value;
							}
							?>
							<textarea
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								rows="5"
								class="large-text code"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							><?php echo esc_textarea( $textarea_value ); ?></textarea>
							<?php
							break;

						case 'checkbox':
							?>
							<label>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $key ); ?>"
									name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
									value="1"
									<?php checked( $value, true ); ?>
									<?php disabled( $disabled ); ?>
								/>
								<?php echo isset( $field['checkbox_label'] ) ? esc_html( $field['checkbox_label'] ) : ''; ?>
							</label>
							<?php
							break;

						case 'select':
							$options = isset( $field['options'] ) ? $field['options'] : array();
							?>
							<select
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							>
								<?php foreach ( $options as $option_value => $option_label ) : ?>
									<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php
							break;

						case 'custom':
							// For custom field types, call the provided callback function.
							if ( isset( $field['callback'] ) && is_callable( $field['callback'] ) ) {
								call_user_func( $field['callback'], $field );
							}
							break;
					}

					if ( $description && 'custom' !== $type ) :
						?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
						<?php
					endif;
					?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render the section wrapper.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
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
				<table class="form-table" role="presentation">
					<?php $this->render(); ?>
				</table>
			</div>
			<?php
		}
	}
}
